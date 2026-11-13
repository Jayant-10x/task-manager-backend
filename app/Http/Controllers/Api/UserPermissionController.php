<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserModulePermission;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserPermissionController extends Controller {
    /**
     * GET /api/permissions
     * Returns all modules permissions and users list for the permissions matrix in Admin.jsx.
     */
    public function index(Request $request) {
        if (!$request->user() || (!$request->user()->usr_is_super_admin && $request->user()->usr_role !== 'admin')) {
            return response()->json(['message' => 'Access denied. Only administrators can view and manage permissions.'], 403);
        }

        $flatModules = $this->getFlatModules();
        $perms = [];
        $actions = ['view' => 'View', 'add' => 'Add', 'edit' => 'Edit', 'delete' => 'Delete'];

        foreach ($flatModules as $slug => $mod) {
            foreach ($actions as $actKey => $actLabel) {
                $perms[] = [
                    'key' => "{$slug}.{$actKey}",
                    'module' => $mod['label'] ?? ucfirst($slug),
                    'label' => $actLabel,
                    'action' => $actKey,
                    'slug' => $slug,
                ];
            }
        }

        $users = User::query()->whereNull('usr_deleted_on')->get();
        $allUserPerms = UserModulePermission::all()->groupBy('ump_usr_id');

        $userList = $users->map(function ($u) use ($allUserPerms) {
            $userP = $allUserPerms->get($u->usr_id, collect());
            $effective = [];
            $granted = [];
            $revoked = [];

            foreach ($userP as $ump) {
                if ($ump->ump_can_view) { $effective[] = "{$ump->ump_module_slug}.view"; $granted[] = "{$ump->ump_module_slug}.view"; }
                if ($ump->ump_can_add) { $effective[] = "{$ump->ump_module_slug}.add"; $granted[] = "{$ump->ump_module_slug}.add"; }
                if ($ump->ump_can_edit) {
                    $effective[] = "{$ump->ump_module_slug}.edit";
                    $granted[] = "{$ump->ump_module_slug}.edit";
                    $effective[] = "{$ump->ump_module_slug}.update";
                    $granted[] = "{$ump->ump_module_slug}.update";
                }
                if ($ump->ump_can_delete) { $effective[] = "{$ump->ump_module_slug}.delete"; $granted[] = "{$ump->ump_module_slug}.delete"; }
            }

            return [
                'id' => $u->usr_id,
                'name' => $u->usr_name,
                'email' => $u->usr_email,
                'role' => ucfirst($u->usr_role),
                'status' => $u->usr_status ? 'Active' : 'Inactive',
                'designation' => $u->usr_designation ?? '',
                'superAdmin' => (bool)$u->usr_is_super_admin,
                'effective' => $effective,
                'granted' => $granted,
                'revoked' => $revoked,
                'editable' => true,
            ];
        });

        $rolePerms = [
            'Admin' => collect($perms)->pluck('key')->all(),
            'Manager' => collect($perms)->filter(function ($p) {
                return !in_array($p['slug'], ['password_reset_request', 'audit_log', 'recycle_bin', 'settings'])
                    && !in_array($p['key'], ['members.delete', 'projects.delete']);
            })->pluck('key')->values()->all(),
            'Employee' => collect($perms)->filter(function ($p) {
                return in_array($p['slug'], ['dashboard', 'my_tasks', 'calendar', 'notifications'])
                    || in_array($p['key'], ['task_board.view', 'projects.view', 'sales_pipeline.view', 'all_tasks.view', 'team_tasks.view']);
            })->pluck('key')->values()->all(),
        ];

        $currentUser = $request->user();
        $isSuper = (bool)($currentUser?->usr_is_super_admin);
        $mine = $currentUser ? ($allUserPerms->get($currentUser->usr_id, collect())->map(function ($ump) {
            $keys = [];
            if ($ump->ump_can_view) $keys[] = "{$ump->ump_module_slug}.view";
            if ($ump->ump_can_add) $keys[] = "{$ump->ump_module_slug}.add";
            if ($ump->ump_can_edit) $keys[] = "{$ump->ump_module_slug}.edit";
            if ($ump->ump_can_delete) $keys[] = "{$ump->ump_module_slug}.delete";
            return $keys;
        })->flatten()->all()) : [];

        return response()->json([
            'ok' => true,
            'perms' => $perms,
            'users' => $userList,
            'rolePerms' => $rolePerms,
            'mine' => $isSuper ? collect($perms)->pluck('key')->all() : $mine,
            'isSuper' => $isSuper,
            'ready' => true,
        ]);
    }

    /**
     * GET /api/users/{user}/permissions
     * Returns grouped or flattened module matrix for management UI.
     */
    public function show(Request $request, User $user) {
        if (!$request->user() || (!$request->user()->usr_is_super_admin && $request->user()->usr_role !== 'admin')) {
            return response()->json(['message' => 'Access denied. Only administrators can view and manage permissions.'], 403);
        }

        $nestedModules = config('app_modules', []);
        $userPermissions = $user->modulePermissions()->get()->keyBy('ump_module_slug');

        $groupedResult = [];

        foreach ($nestedModules as $groupKey => $group) {
            $parentLabel = $group['parent_label'] ?? strtoupper($groupKey);
            $moduleList = [];

            foreach ($group as $itemKey => $item) {
                if (!is_array($item)) {
                    continue;
                }

                $record = $userPermissions->get($itemKey);

                $moduleList[] = [
                    'module_slug' => $itemKey,
                    'label' => $item['label'] ?? ucfirst($itemKey),
                    'icon' => $item['icon'] ?? '',
                    'in_sidebar' => $item['in_sidebar'] ?? true,
                    'can_view' => $record ? (bool)$record->ump_can_view : false,
                    'can_add' => $record ? (bool)$record->ump_can_add : false,
                    'can_edit' => $record ? (bool)$record->ump_can_edit : false,
                    'can_delete' => $record ? (bool)$record->ump_can_delete : false,
                ];
            }

            $groupedResult[] = [
                'group_key' => $groupKey,
                'parent_label' => $parentLabel,
                'modules' => $moduleList,
            ];
        }

        return response()->json([
            'user' => [
                'usr_id' => $user->usr_id,
                'usr_name' => $user->usr_name,
            ],
            'groups' => $groupedResult,
        ]);
    }

    public function store(Request $request, User $user) {
        if (!$request->user() || (!$request->user()->usr_is_super_admin && $request->user()->usr_role !== 'admin')) {
            return response()->json(['message' => 'Access denied. Only administrators can view and manage permissions.'], 403);
        }

        $flatModules = $this->getFlatModules();
        $validSlugs = array_keys($flatModules);

        $validated = $request->validate([
            '*.module_slug' => [
                'required',
                'string',
                'distinct',
                Rule::in($validSlugs),
            ],
            '*.can_view' => ['required', 'boolean'],
            '*.can_add' => ['required', 'boolean'],
            '*.can_edit' => ['required', 'boolean'],
            '*.can_delete' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($validated, $user) {
            foreach ($validated as $permissionData) {
                $existing = UserModulePermission::query()
                    ->where('ump_usr_id', $user->usr_id)
                    ->where('ump_module_slug', $permissionData['module_slug'])
                    ->exists();

                $values = [
                    'ump_can_view' => $permissionData['can_view'],
                    'ump_can_add' => $permissionData['can_add'],
                    'ump_can_edit' => $permissionData['can_edit'],
                    'ump_can_delete' => $permissionData['can_delete'],
                    'ump_updated_by' => setCreatedUpdatedBy(),
                    'ump_updated_on' => now(),
                ];

                if ($existing) {
                    DB::table('user_module_permissions')
                        ->where('ump_usr_id', $user->usr_id)
                        ->where('ump_module_slug', $permissionData['module_slug'])
                        ->update($values);
                } else {
                    $values['ump_usr_id'] = $user->usr_id;
                    $values['ump_module_slug'] = $permissionData['module_slug'];
                    $values['ump_created_by'] = setCreatedUpdatedBy();
                    $values['ump_created_on'] = now();

                    DB::table('user_module_permissions')->insert($values);
                }
            }
        });

        return response()->json([
            'message' => 'Module permissions added successfully.',
            'user_permissions' => $user->fresh('modulePermissions')
                ->getFlattenedPermissions(),
        ], 200);
    }

    private function getFlatModules(): array {
        $nestedConfig = config('app_modules', []);
        $flat = [];

        foreach ($nestedConfig as $groupKey => $group) {
            $parentLabel = $group['parent_label'] ?? strtoupper($groupKey);

            foreach ($group as $itemKey => $item) {
                if (!is_array($item)) {
                    continue;
                }

                $flat[$itemKey] = array_merge($item, [
                    'group_key' => $groupKey,
                    'parent_label' => $parentLabel,
                ]);
            }
        }

        return $flat;
    }

    /**
     * PUT /api/users/{user}/permissions
     * Syncs user permissions (deletes omitted modules, inserts/updates passed ones).
     */
    public function update(Request $request, User $user) {
        if (!$request->user() || (!$request->user()->usr_is_super_admin && $request->user()->usr_role !== 'admin')) {
            return response()->json(['message' => 'Access denied. Only administrators can view and manage permissions.'], 403);
        }

        $flatModules = $this->getFlatModules();
        $validSlugs = implode(',', array_keys($flatModules));

        $validated = $request->validate([
            'permissions' => 'present|array',
            'permissions.*.module_slug' => "required|string|in:{$validSlugs}",
            'permissions.*.can_view' => 'required|boolean',
            'permissions.*.can_add' => 'required|boolean',
            'permissions.*.can_edit' => 'required|boolean',
            'permissions.*.can_delete' => 'required|boolean',
        ]);

        $submittedPermissions = $validated['permissions'];

        DB::transaction(function () use ($user, $submittedPermissions) {
            // 1. Delete ALL current permissions for this user (forceDelete to prevent unique constraint conflict on re-insert)
            UserModulePermission::where('ump_usr_id', $user->usr_id)->forceDelete();

            // 2. Perform a single batch insert for submitted permissions
            if (!empty($submittedPermissions)) {
                $now = now();
                $creator = setCreatedUpdatedBy();

                $records = array_map(function ($item) use ($user, $now, $creator) {
                    return [
                        'ump_usr_id' => $user->usr_id,
                        'ump_module_slug' => $item['module_slug'],
                        'ump_can_view' => $item['can_view'],
                        'ump_can_add' => $item['can_add'],
                        'ump_can_edit' => $item['can_edit'],
                        'ump_can_delete' => $item['can_delete'],
                        'ump_created_by' => $creator,
                        'ump_created_on' => $now,
                        'ump_updated_on' => $now,
                    ];
                }, $submittedPermissions);

                UserModulePermission::insert($records);
            }
        });

        app(AuditService::class)->log(
            action: 'permissions_changed',
            module: 'security',
            record: !$user ? '-' : 'user:' . $user->usr_id,
            oldValues: [],
            newValues: [
                'from' => 'from permissions',
                'to' => 'to permissions',
            ],
            description: 'Security: Permission changed'
        );

        return response()->json([
            'message' => 'Permissions synced successfully.',
            'permissions' => $user->fresh('modulePermissions')->getFlattenedPermissions(),
        ]);
    }
}
