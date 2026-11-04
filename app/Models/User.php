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
        'usr_city',
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

                // Include only if the user has view permission for this module
                if ($perm && $perm->ump_can_view) {
                    $permittedModules[$slug] = [
                        'label' => $info['label'] ?? ucfirst($slug),
                        'icon' => $info['icon'] ?? '',
                        'in_sidebar' => $info['in_sidebar'] ?? true,
                        'can_view' => (bool)$perm->ump_can_view,
                        'can_add' => (bool)$perm->ump_can_add,
                        'can_edit' => (bool)$perm->ump_can_edit,
                        'can_delete' => (bool)$perm->ump_can_delete,
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
