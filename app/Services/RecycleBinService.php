<?php

namespace App\Services;

use App\Models\RecycleBin;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RecycleBinService
{
    public const int RECYCLE_DAYS = 30;

    public const array MODULES = [
        'task' => 'Task',
        'project' => 'Project',
        'user' => 'User',
        'sales_pipeline' => 'Sales Pipeline',
        'deal' => 'Sales Pipeline',
        'attachment' => 'Attachment',
    ];

    /* ---------------- Helpers ---------------- */

    protected function getTableColumns(string $table): array
    {
        return Schema::getColumnListing($table);
    }

    protected function insertRow(string $table, array $row, ?string $primaryKeyCol = null): int
    {
        // 1. Filter out keys that do not exist as database columns
        $cols = $this->getTableColumns($table);
        $row = array_intersect_key($row, array_flip($cols));

        // 2. Automatically infer the primary key column if not explicitly passed
        if ($primaryKeyCol === null) {
            $primaryKeyCol = match ($table) {
                'users' => 'usr_id',
                'projects' => 'pro_id',
                'tasks' => 'tsk_id',
                'sales_pipelines' => 'ssp_id',
                'attachments' => 'atc_id',
                'task_assignees' => 'tsa_id',
                'sub_tasks' => 'stk_id',
                'task_comments' => 'tsc_id',
                'recycle_bin' => 'rbs_id',
                'user_module_permissions' => 'ump_id',
                default => 'id',
            };
        }

        // 3. Handle primary key collision checking during restore
        if (isset($row[$primaryKeyCol])) {
            $exists = DB::table($table)->where($primaryKeyCol, $row[$primaryKeyCol])->exists();
            if ($exists) {
                unset($row[$primaryKeyCol]);
            }
        }

        // 4. If the old ID is available and free, insert preserving the original ID
        if (isset($row[$primaryKeyCol])) {
            DB::table($table)->insert($row);
            return (int)$row[$primaryKeyCol];
        }

        // 5. Otherwise, let MySQL auto-increment a new ID and return it
        return (int)DB::table($table)->insertGetId($row);
    }

    protected function generateNextCode(string $prefix, int $padLength, string $table, string $pkCol, string $codeCol): string
    {
        $maxId = DB::table($table)->max($pkCol) ?? 0;
        $nextNum = $maxId + 1;
        return $prefix . str_pad((string)$nextNum, $padLength, '0', STR_PAD_LEFT);
    }

    /* ---------------- Snapshots ---------------- */

    public function snapTask(int $taskId): array
    {
        return [
            'task' => (array)DB::table('tasks')->where('tsk_id', $taskId)->first(),
            'task_assignees' => DB::table('task_assignees')->where('tsa_tsk_id', $taskId)->get()->map(fn($r) => (array)$r)->toArray(),
            'sub_tasks' => DB::table('sub_tasks')->where('stk_tsk_id', $taskId)->get()->map(fn($r) => (array)$r)->toArray(),
            'task_comments' => DB::table('task_comments')->where('tsc_tsk_id', $taskId)->get()->map(fn($r) => (array)$r)->toArray(),
            'attachments' => DB::table('attachments')->where('atc_tsk_id', $taskId)->get()->map(fn($r) => (array)$r)->toArray(),
        ];
    }

    public function removeTaskRows(int $taskId): void
    {
        DB::table('task_assignees')->where('tsa_tsk_id', $taskId)->delete();
        DB::table('sub_tasks')->where('stk_tsk_id', $taskId)->delete();
        DB::table('task_comments')->where('tsc_tsk_id', $taskId)->delete();
        DB::table('attachments')->where('atc_tsk_id', $taskId)->delete();
        DB::table('tasks')->where('tsk_id', $taskId)->delete();
    }

    public function extractFiles(string $module, array $snap): array
    {
        $atts = [];
        if ($module === 'attachment' && !empty($snap['attachment'])) {
            $atts[] = $snap['attachment'];
        }
        if ($module === 'task') {
            $atts = $snap['attachments'] ?? [];
        }
        if ($module === 'project') {
            foreach ($snap['tasks'] ?? [] as $ts) {
                $atts = array_merge($atts, $ts['attachments'] ?? []);
            }
            $atts = array_merge($atts, $snap['attachments'] ?? []);
        }
        return array_values(array_filter($atts));
    }

    public function putInBin(User $user, string $module, string $origId, string $label, array $snap, string $reason = ''): int
    {
        $files = $this->extractFiles($module, $snap);
        $drive = array_values(array_filter(array_map(function ($a) {
            return ($a['atc_storage'] ?? 'local') === 'drive' ? ($a['atc_drive_file_id'] ?? null) : null;
        }, $files)));

        $bin = RecycleBin::create([
            'rbs_module' => $module,
            'rbs_original_id' => (string)$origId,
            'rbs_label' => Str::limit($label, 255, ''),
            'rbs_snapshot' => $snap,
            'rbs_file_count' => count($files),
            'rbs_drive_file_ids' => $drive ?: null,
            'rbs_deleted_by_id' => $user->usr_id ?? (function_exists('get_logged_in_user_id') ? get_logged_in_user_id() : null),
            'rbs_deleted_by_name' => $user->usr_name ?? (function_exists('get_logged_in_user_name') ? get_logged_in_user_name() : 'System'),
            'rbs_deleted_at' => now(),
            'rbs_deletion_reason' => Str::limit($reason, 255, ''),
            'rbs_permanent_delete_at' => now()->addDays(self::RECYCLE_DAYS),
            'rbs_restore_status' => 'deleted',
            'rbs_purge_attempts' => 0,
        ]);

        return $bin->rbs_id;
    }

    /* ---------------- Move to Bin ---------------- */

    public function recycleTask(User $user, array $task, string $reason = ''): int
    {
        return DB::transaction(function () use ($user, $task, $reason) {
            $taskId = (int)($task['tsk_id'] ?? $task['id']);
            $taskCode = $task['tsk_code'] ?? $task['code'] ?? ('TK-' . $taskId);
            $taskTitle = $task['tsk_title'] ?? $task['tsk_name'] ?? $task['title'] ?? 'Untitled Task';

            $snap = $this->snapTask($taskId);
            $binId = $this->putInBin($user, 'task', $taskCode, $taskCode . ' - ' . $taskTitle, $snap, $reason);
            $this->removeTaskRows($taskId);
            return $binId;
        });
    }

    public function recycleProject(User $user, array $p, string $reason = ''): int
    {
        return DB::transaction(function () use ($user, $p, $reason) {
            $pid = (int)($p['pro_id'] ?? $p['id']);
            $proCode = $p['pro_code'] ?? $p['code'] ?? ('P-' . $pid);
            $proName = $p['pro_name'] ?? $p['name'] ?? 'Untitled Project';

            $taskIds = DB::table('tasks')->where('tsk_pro_id', $pid)->pluck('tsk_id')->map(fn($id) => (int)$id)->toArray();

            $snap = [
                'project' => $p,
                'members' => DB::table('project_members')->where('pmb_pro_id', $pid)->get()->map(fn($r) => (array)$r)->toArray(),
                'tasks' => []
            ];

            foreach ($taskIds as $tid) {
                $snap['tasks'][] = $this->snapTask($tid);
            }

            $snap['attachments'] = DB::table('attachments as a')
                ->leftJoin('tasks as t', 't.tsk_id', '=', 'a.atc_tsk_id')
                ->where('a.atc_pro_id', $pid)
                ->whereNull('t.tsk_id')
                ->select('a.*')
                ->get()
                ->map(fn($r) => (array)$r)
                ->toArray();

            $binId = $this->putInBin($user, 'project', $proCode, $proCode . ' - ' . $proName . ' (' . count($taskIds) . ' tasks)', $snap, $reason);

            foreach ($taskIds as $tid) {
                $this->removeTaskRows($tid);
            }

            foreach ($snap['attachments'] as $a) {
                DB::table('attachments')->where('atc_id', $a['atc_id'])->delete();
            }

            DB::table('project_members')->where('pmb_pro_id', $pid)->delete();
            DB::table('projects')->where('pro_id', $pid)->delete();

            return $binId;
        });
    }

    public function recycleUser(User $authUser, array $x, string $reason = ''): int
    {
        return DB::transaction(function () use ($authUser, $x, $reason) {
            $row = $x;
            $userId = (int)($x['usr_id'] ?? $x['id']);
            $userName = $x['usr_name'] ?? $x['name'] ?? '';
            $userEmail = $x['usr_email'] ?? $x['email'] ?? '';

            foreach (['usr_pass_hash', 'usr_password', 'usr_remember_token', 'usr_token', 'usr_token_at', 'usr_reset_hash', 'usr_reset_expires', 'usr_reset_used', 'usr_fail_count', 'usr_locked_until'] as $k) {
                unset($row[$k]);
            }

            $permissions = Schema::hasTable('user_module_permissions')
                ? DB::table('user_module_permissions')->where('ump_usr_id', $userId)->get()->map(fn($r) => (array)$r)->toArray()
                : [];

            $snap = [
                'user' => $row,
                'members' => DB::table('project_members')->where('pmb_usr_id', $userId)->get()->map(fn($r) => (array)$r)->toArray(),
                'permissions' => $permissions
            ];

            $binId = $this->putInBin($authUser, 'user', (string)$userId, $userName . ' (' . $userEmail . ')', $snap, $reason);

            DB::table('project_members')->where('pmb_usr_id', $userId)->delete();
            if (Schema::hasTable('user_module_permissions')) {
                DB::table('user_module_permissions')->where('ump_usr_id', $userId)->delete();
            }
            DB::table('users')->where('usr_id', $userId)->delete();

            return $binId;
        });
    }

    public function recycleSalesPipeline(User $user, array $ssp, string $reason = ''): int
    {
        return DB::transaction(function () use ($user, $ssp, $reason) {
            $sspId = (int)($ssp['ssp_id'] ?? $ssp['id']);
            $sspCode = $ssp['ssp_code'] ?? $ssp['code'] ?? ('SSP-' . $sspId);
            $sspName = $ssp['ssp_name'] ?? $ssp['name'] ?? 'Untitled Deal';

            $binId = $this->putInBin($user, 'sales_pipeline', $sspCode, $sspCode . ' - ' . $sspName, ['sales_pipeline' => $ssp], $reason);
            DB::table('sales_pipelines')->where('ssp_id', $sspId)->delete();
            return $binId;
        });
    }

    public function recycleDeal(User $user, array $d, string $reason = ''): int
    {
        return $this->recycleSalesPipeline($user, $d, $reason);
    }

    public function recycleAttachment(User $user, array $a, string $reason = ''): int
    {
        return DB::transaction(function () use ($user, $a, $reason) {
            $row = $a;
            $atcId = (int)($a['atc_id'] ?? $a['id']);
            $fileName = $a['atc_file_name'] ?? $a['file_name'] ?? 'File';
            $label = $fileName;

            $binId = $this->putInBin($user, 'attachment', (string)$atcId, $label, ['attachment' => $row], $reason);
            DB::table('attachments')->where('atc_id', $atcId)->delete();
            return $binId;
        });
    }

    /* ---------------- Restore ---------------- */

    protected function restoreTask(array $ts, $bin_id = null, $projectId = 'keep'): array
    {
        $t = $ts['task'];
        $oldId = (int)($t['tsk_id'] ?? $t['id']);

        if ($projectId !== 'keep') {
            $t['tsk_pro_id'] = $projectId;
        }

        if (!empty($t['tsk_pro_id']) && !DB::table('projects')->where('pro_id', $t['tsk_pro_id'])->exists()) {
            $t['tsk_pro_id'] = null;
        }

        if (DB::table('tasks')->where('tsk_code', $t['tsk_code'])->exists()) {
            $t['tsk_code'] = $this->generateNextCode('TK-', 0, 'tasks', 'tsk_id', 'tsk_code');
        }

        $newId = $this->insertRow('tasks', $t, 'tsk_id');

        // 1. Task Assignees
        foreach ($ts['task_assignees'] ?? [] as $r) {
            $r['tsa_tsk_id'] = $newId;
            $this->insertRow('task_assignees', $r, 'tsa_id');
        }

        // 2. Sub Tasks
        foreach ($ts['sub_tasks'] ?? [] as $r) {
            $r['stk_tsk_id'] = $newId;
            $this->insertRow('sub_tasks', $r, 'stk_id');
        }

        // 3. Task Comments
        foreach ($ts['task_comments'] ?? [] as $r) {
            $r['tsc_tsk_id'] = $newId;
            $this->insertRow('task_comments', $r, 'tsc_id');
        }

        // 4. Attachments
        foreach ($ts['attachments'] ?? [] as $a) {
            $a['atc_tsk_id'] = $newId;
            $a['atc_pro_id'] = $t['tsk_pro_id'] ?? null;
            $this->insertRow('attachments', $a, 'atc_id');
        }

        if (!empty($bin_id)) {
            app(AuditService::class)->log(
                action: 'restore',
                module: 'task',
                record: $t['tsk_code'],
                oldValues: [],
                newValues: [
                    'bin_id' => $bin_id,
                ],
                description: 'Restored ' . $t['tsk_code'] . ' - ' . $t['tsk_name'] . 'from the Recycle Bin.',
            );
        }

        return [$newId, $t['tsk_code']];
    }

    public function restore(RecycleBin $bin): string
    {
        $snap = $bin->rbs_snapshot ?? $bin->snapshot;
        if (!is_array($snap)) {
            throw new RuntimeException('This item cannot be restored (its saved copy is missing).');
        }

        $module = $bin->rbs_module ?? $bin->module;

        return DB::transaction(function () use ($bin, $snap, $module) {
            switch ($module) {
                case 'task':
                    [$id, $code] = $this->restoreTask(ts: $snap, bin_id: $bin->rbs_id);
                    return "Task {$code} restored.";

                case 'project':
                    $p = $snap['project'];
                    if (DB::table('projects')->where('pro_code', $p['pro_code'])->exists()) {
                        $p['pro_code'] = $this->generateNextCode('P-', 0, 'projects', 'pro_id', 'pro_code');
                    }
                    $pid = $this->insertRow('projects', $p, 'pro_id');

                    foreach ($snap['members'] ?? [] as $m) {
                        if (DB::table('project_members')->where('pmb_pro_id', $pid)->where('pmb_usr_id', $m['pmb_usr_id'])->exists()) {
                            continue;
                        }
                        $m['pmb_pro_id'] = $pid;
                        DB::table('project_members')->insert(array_intersect_key($m, array_flip($this->getTableColumns('project_members'))));
                    }

                    foreach ($snap['tasks'] ?? [] as $ts) {
                        $this->restoreTask(ts: $ts, projectId: $pid);
                    }

                    foreach ($snap['attachments'] ?? [] as $a) {
                        if (!empty($a['atc_tsk_id']) && !DB::table('tasks')->where('tsk_id', $a['atc_tsk_id'])->exists()) {
                            $a['atc_tsk_id'] = null;
                        }
                        $a['atc_pro_id'] = $pid;
                        $this->insertRow('attachments', $a, 'atc_id');
                    }

                    $taskCount = count($snap['tasks'] ?? []);

                    app(AuditService::class)->log(
                        action: 'restore',
                        module: 'project',
                        record: $p['pro_code'],
                        oldValues: [],
                        newValues: [
                            'bin_id' => $bin->rbs_id,
                        ],
                        description: 'Restored ' . $p['pro_code'] . ' - ' . $p['pro_name'] . '(' . $taskCount . 'tasks) from the Recycle Bin.'
                    );
                    return "Project {$p['pro_code']} restored with {$taskCount} tasks.";

                case 'user':
                    $x = $snap['user'];
                    if (DB::table('users')->whereRaw('LOWER(usr_email) = LOWER(?)', [$x['usr_email']])->exists()) {
                        throw new RuntimeException("Another user now uses the email {$x['usr_email']}.");
                    }
                    if (DB::table('users')->where('usr_name', $x['usr_name'])->exists()) {
                        throw new RuntimeException("Another user now has the name {$x['usr_name']}.");
                    }

                    $x['usr_password'] = bcrypt(Str::random(16));
                    $x['usr_must_change'] = 1;
                    $x['usr_token'] = null;
                    $x['usr_token_at'] = null;
                    $x['usr_online'] = 0;
                    $x['usr_fail_count'] = 0;
                    $x['usr_locked_until'] = null;
                    $x['usr_reset_hash'] = null;
                    $x['usr_reset_used'] = 0;

                    $uid = $this->insertRow('users', $x, 'usr_id');

                    foreach ($snap['members'] ?? [] as $m) {
                        if (!DB::table('projects')->where('pro_id', $m['pmb_pro_id'])->exists()) {
                            continue;
                        }
                        if (DB::table('project_members')->where('pmb_pro_id', $m['pmb_pro_id'])->where('pmb_usr_id', $uid)->exists()) {
                            continue;
                        }
                        $m['pmb_usr_id'] = $uid;
                        DB::table('project_members')->insert(array_intersect_key($m, array_flip($this->getTableColumns('project_members'))));
                    }

                    if (Schema::hasTable('user_module_permissions')) {
                        foreach ($snap['permissions'] ?? [] as $pm) {
                            $pm['ump_usr_id'] = $uid;
                            unset($pm['ump_id']);
                            $this->insertRow('user_module_permissions', $pm, 'ump_id');
                        }
                    }

                    app(AuditService::class)->log(
                        action: 'restore',
                        module: 'user',
                        record: $uid,
                        oldValues: [],
                        newValues: [
                            'bin_id' => $bin->rbs_id,
                        ],
                        description: 'Restored ' . $x['usr_name'] . '(' . $x['usr_email'] . ') from the Recycle Bin.'
                    );

                    return "User {$x['usr_name']} restored. Set a new temporary password for them (Members > Edit) before they sign in.";

                case 'sales_pipeline':
                case 'deal':
                    $d = $snap['sales_pipeline'] ?? $snap['deal'];
                    if (DB::table('sales_pipelines')->where('ssp_code', $d['ssp_code'])->exists()) {
                        $d['ssp_code'] = $this->generateNextCode('D-', 0, 'sales_pipelines', 'ssp_id', 'ssp_code');
                    }
                    $this->insertRow('sales_pipelines', $d, 'ssp_id');

                    app(AuditService::class)->log(
                        action: 'restore',
                        module: 'deal',
                        record: $d['ssp_code'],
                        oldValues: [],
                        newValues: [
                            'bin_id' => $bin->rbs_id,
                        ],
                        description: 'Restored ' . $d['ssp_code'] . ' - ' . $d['ssp_name'] . ' from the Recycle Bin.'
                    );

                    return "Sales Pipeline {$d['ssp_code']} restored.";

                case 'attachment':
                    $a = $snap['attachment'];
                    $t = !empty($a['atc_tsk_id']) ? DB::table('tasks')->where('tsk_id', $a['atc_tsk_id'])->first() : null;
                    if (!$t) {
                        throw new RuntimeException('The task this file belonged to no longer exists. Restore the task first.');
                    }
                    $a['atc_pro_id'] = $t->tsk_pro_id;
                    $this->insertRow('attachments', $a, 'atc_id');

                    app(AuditService::class)->log(
                        action: 'restore',
                        module: 'attachment',
                        record: $a['atc_file_name'],
                        oldValues: [],
                        newValues: [
                            'bin_id' => $bin->rbs_id,
                        ],
                        description: 'Restored ' . $a['atc_file_name'] . ' (' . $a['task']['tsk_code'] . ') from the Recycle Bin.'
                    );
                    return "File {$a['atc_file_name']} restored.";
            }

            throw new RuntimeException('Unknown item type.');
        });
    }

    /* ---------------- Permanent Purge ---------------- */

    public function purge(RecycleBin $bin, string $byId): void
    {
        $snap = $bin->rbs_snapshot ?? $bin->snapshot ?? [];
        $module = $bin->rbs_module ?? $bin->module;

        foreach ($this->extractFiles($module, $snap) as $a) {
            if (($a['atc_storage'] ?? 'local') === 'drive' && !empty($a['atc_drive_file_id'])) {
                if (DB::table('attachments')->where('atc_drive_file_id', $a['atc_drive_file_id'])->exists()) {
                    continue;
                }
                // Call Google Drive Service integration here if applicable:
                // Storage::disk('google')->delete($a['atc_drive_file_id']);
            } elseif (!empty($a['atc_stored_as'])) {
                if (DB::table('attachments')->where('atc_stored_as', $a['atc_stored_as'])->exists()) {
                    continue;
                }
                if (Storage::disk('private')->exists($a['atc_stored_as'])) {
                    Storage::disk('private')->delete($a['atc_stored_as']);
                }
            }
        }

        $audit_data = $this->purgeAuditOldData($module, $snap);
        app(AuditService::class)->log(
            action: 'permanent_delete',
            module: $module,
            record: $bin->rbs_original_id,
            oldValues: [
                'label' => $audit_data['label'],
                'files' => $audit_data['files']
            ],
            newValues: [],
            description: $audit_data['main_label']
        );

        $bin->update([
            'rbs_restore_status' => 'purged',
            'rbs_snapshot' => null,
            'rbs_purged_at' => now(),
            'rbs_purged_by' => $byId,
            'rbs_purge_error' => null,
            'rbs_purge_attempts' => ($bin->rbs_purge_attempts + 1),
        ]);
    }

    public function purgeAuditOldData($module, $snap)
    {
        $ret_val = [];
        switch ($module) {
            case 'task':
                $ret_val = [
                    'main_label' => 'Permanently deleted ' . $snap['task']['tsk_code'] . ' - ' . $snap['task']['tsk_name'],
                    'label' => $snap['task']['tsk_code'] . ' - ' . $snap['task']['tsk_name'],
                    'files' => !empty($snap['task']['attachments']) ? count($snap['task']['attachments']) : 0,
                ];
                break;

            case 'user':
                $ret_val = [
                    'main_label' => 'Permanently deleted ' . $snap['user']['usr_name'] . ' (' . $snap['user']['usr_email'] . ')',
                    'label' => $snap['user']['usr_name'] . ' (' . $snap['user']['usr_email'] . ')',
                    'files' => 0,
                ];
                break;
            case 'project':
                $ret_val = [
                    'main_label' => 'Permanently deleted ' . $snap['project']['pro_code'] . ' (' . $snap['project']['pro_name'] . ')',
                    'label' => $snap['project']['pro_code'] . ' - ' . $snap['project']['pro_name'],
                    'files' => !empty($snap['project']['attachments']) ? count($snap['project']['attachments']) : 0,
                ];
                break;
            case 'sales_pipeline':
            case 'deal':
                $ret_val = [
                    'main_label' => 'Permanently deleted ' . $snap['sales_pipeline']['ssp_code'] . ' (' . $snap['sales_pipeline']['ssp_name'] . ')',
                    'label' => $snap['sales_pipeline']['ssp_code'] . ' - ' . $snap['sales_pipeline']['ssp_name'],
                    'files' => !empty($snap['sales_pipeline']['attachments']) ? count($snap['sales_pipeline']['attachments']) : 0,
                ];
                break;
            case 'attachment':
                $ret_val = [
                    'main_label' => 'Permanently deleted ' . $snap['attachment']['atc_file_name'] . ' (' . $snap['attachment']['task']['tsk_code'] . ')',
                    'label' => $snap['attachment']['atc_file_name'] . ' (' . $snap['attachment']['task']['tsk_code'] . ')',
                    'files' => 0,
                ];
                break;
        }
        return $ret_val;
    }
}
