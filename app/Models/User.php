<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable {
    use HasApiTokens, HasFactory, Notifiable, softDeletes;

    const string DELETED_AT = 'usr_deleted_on';
    // Explicit primary key
    public $timestamps = false;
    protected $primaryKey = 'usr_id';

    // Allow fillable fields matching your schema
    protected $fillable = [
        'usr_name',
        'usr_email',
        'usr_phone',
        'usr_role',
        'usr_designation',
        'usr_dept',
        'usr_custom_dept',
        'usr_city',
        'usr_avatar',
        'usr_status',
        'usr_online',
        'usr_last_seen',
        'usr_joined',
        'usr_password',
        'usr_must_change',
        'usr_is_super_admin',
        'usr_created_by',
        'usr_created_on',
        'usr_updated_by',
        'usr_updated_on',
    ];

    // Hide sensitive attributes from JSON output
    protected $hidden = [
        'usr_password',
        'usr_token',
        'usr_reset_hash',
    ];

    // Cast attributes

    /**
     * Override default password column name for Laravel Auth.
     */

    public function getAuthPasswordName (): string {
        return 'usr_password';
    }

    public function getAuthPassword () {
        return $this->usr_password;
    }

    public function modulePermissions () {
        return $this->hasMany(UserModulePermission::class, 'ump_usr_id', 'usr_id');
    }

    /**
     * Check single permission (e.g., $user->hasModuleAccess('projects', 'edit'))
     */
    public function hasModuleAccess (string $module, string $action): bool {
        // Global admin bypass if role is used
        if ($this->role === 'admin') {
            return true;
        }

        $column = 'can_' . $action; // 'can_view', 'can_add', 'can_edit', 'can_delete'
        $permission = $this->modulePermissions->firstWhere('module_slug', $module);

        return $permission ? (bool)$permission->{$column} : false;
    }

    /**
     * Returns user permissions grouped by category (workspace, team, etc.)
     * including only modules where 'can_view' is true.
     */
    public function getFlattenedPermissions (): array {
        $nestedConfig = config('app_modules', []);
        $isSuper = (bool)$this->usr_is_super_admin;

        if (!$isSuper && $this->modulePermissions->isEmpty()) {
            $this->assignDefaultModulePermissions();
            $this->unsetRelation('modulePermissions');
        }

        $userPermissions = $this->modulePermissions->keyBy('ump_module_slug');
        $groupedPermissions = [];

        foreach ($nestedConfig as $groupKey => $group) {
            $parentLabel = $group['parent_label'] ?? strtoupper($groupKey);
            $permittedModules = [];

            foreach ($group as $slug => $info) {
                // Skip non-array keys like 'parent_label'
                if (!is_array($info)) {
                    continue;
                }

                $perm = $userPermissions->get($slug);
                $canView = $isSuper || ($perm && $perm->ump_can_view);

                // Include only if the user has view permission for this module
                if ($canView) {
                    $permittedModules[$slug] = [
                        'label' => $info['label'] ?? ucfirst($slug),
                        'icon' => $info['icon'] ?? '',
                        'in_sidebar' => $info['in_sidebar'] ?? true,
                        'can_view' => true,
                        'can_add' => $isSuper || (bool)($perm?->ump_can_add),
                        'can_edit' => $isSuper || (bool)($perm?->ump_can_edit),
                        'can_delete' => $isSuper || (bool)($perm?->ump_can_delete),
                        'route' => $info['route'] ?? '',
                        'description' => $info['description'] ?? ''
                    ];
                }
            }

            // Only add the group to response if the user has at least one permitted module in it
            if (!empty($permittedModules)) {
                $groupedPermissions[$groupKey] = array_merge(
                    ['parent_label' => $parentLabel],
                    $permittedModules
                );
            }
        }

        return $groupedPermissions;
    }

    /**
     * Assigns role-based default module permissions to this user in user_module_permissions.
     */
    public function assignDefaultModulePermissions(): void {
        $role = strtolower($this->usr_role ?? 'employee');
        $isSuper = (bool)$this->usr_is_super_admin;
        $modules = config('app_modules', []);

        $records = [];
        $now = now();

        foreach ($modules as $groupKey => $group) {
            foreach ($group as $slug => $info) {
                if (!is_array($info)) continue;

                $canView = false;
                $canAdd = false;
                $canEdit = false;
                $canDelete = false;

                if ($isSuper || $role === 'admin') {
                    $canView = true;
                    $canAdd = true;
                    $canEdit = true;
                    $canDelete = true;
                } elseif ($role === 'manager') {
                    if (!in_array($slug, ['password_reset_request', 'audit_log', 'recycle_bin', 'settings'])) {
                        $canView = true;
                        $canAdd = true;
                        $canEdit = true;
                        $canDelete = !in_array($slug, ['members', 'projects']);
                    }
                } else { // employee
                    if (in_array($slug, ['dashboard', 'my_tasks', 'calendar', 'notifications', 'task_board', 'projects', 'sales_pipeline', 'all_tasks', 'team_tasks'])) {
                        $canView = true;
                        $canAdd = in_array($slug, ['my_tasks']);
                        $canEdit = in_array($slug, ['my_tasks']);
                        $canDelete = false;
                    }
                }

                $records[] = [
                    'ump_usr_id' => $this->usr_id,
                    'ump_module_slug' => $slug,
                    'ump_can_view' => $canView ? 1 : 0,
                    'ump_can_add' => $canAdd ? 1 : 0,
                    'ump_can_edit' => $canEdit ? 1 : 0,
                    'ump_can_delete' => $canDelete ? 1 : 0,
                    'ump_created_on' => $now,
                    'ump_updated_on' => $now,
                ];
            }
        }

        UserModulePermission::upsert(
            $records,
            ['ump_usr_id', 'ump_module_slug'],
            ['ump_can_view', 'ump_can_add', 'ump_can_edit', 'ump_can_delete', 'ump_updated_on']
        );
    }

    public function userTasks (): HasMany {
        return $this->hasMany(TaskAssignee::class, 'tsa_usr_id', 'usr_id');
    }

    public function userOpenTasks (): HasMany {
        return $this->hasMany(TaskAssignee::class, 'tsa_usr_id', 'usr_id')
            ->whereHas('whichAssignedTask', function ($query) {
                $query->whereNotIn('tsk_status', ['completed', 'cancelled']);
            });
    }

    public function taskAssignees (): HasMany {
        return $this->hasMany(TaskAssignee::class, 'tsa_usr_id', 'usr_id');
    }

    protected function casts (): array {
        return [
            'usr_password' => 'hashed',
            'usr_status' => 'boolean',
            'usr_online' => 'boolean',
            'usr_must_change' => 'boolean',
            'usr_is_super_admin' => 'boolean',
            'usr_last_seen' => 'datetime',
            'usr_joined' => 'date',
        ];
    }
}
