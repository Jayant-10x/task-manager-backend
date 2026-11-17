<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Audit\AuditService;

class UserObserver {
    private const FIELD_MAP = [
        'usr_name' => 'name',
        'usr_email' => 'email',
        'usr_phone' => 'phone',
        'usr_role' => 'role',
        'usr_designation' => 'designation',
        'usr_dept' => 'department',
        'usr_custom_dept' => 'custom_department',
        'usr_city' => 'city',
        'usr_status' => 'status',
        'usr_is_super_admin' => 'is_super_admin',
    ];

    public function created(User $user): void {
        $user->assignDefaultModulePermissions();

        $values = [];
        foreach (self::FIELD_MAP as $col => $label) {
            $val = $user->getAttribute($col);
            if ($col === 'usr_status') {
                $val = (int)$val === 1 ? 'Active' : 'Inactive';
            } elseif ($col === 'usr_is_super_admin') {
                $val = (bool)$val;
            }
            if ($val !== null && $val !== '') {
                $values[$label] = $val;
            }
        }

        app(AuditService::class)->log(
            action: 'create',
            module: 'user',
            record: (string) $user->usr_id,
            newValues: $values,
            description: 'Created user: ' . ($user->usr_name ?: 'User #' . $user->usr_id)
        );
    }

    public function updated(User $user): void {
        $trackedCols = array_keys(self::FIELD_MAP);
        $changes = array_intersect_key(
            $user->getChanges(),
            array_flip($trackedCols)
        );

        if ($changes === []) {
            return;
        }

        $old = [];
        $new = [];

        foreach (array_keys($changes) as $col) {
            $label = self::FIELD_MAP[$col] ?? $col;
            $oldVal = $user->getOriginal($col);
            $newVal = $user->getAttribute($col);

            if ($col === 'usr_status') {
                $oldVal = (int)$oldVal === 1 ? 'Active' : 'Inactive';
                $newVal = (int)$newVal === 1 ? 'Active' : 'Inactive';
            } elseif ($col === 'usr_is_super_admin') {
                $oldVal = (bool)$oldVal;
                $newVal = (bool)$newVal;
            }

            $old[$label] = $oldVal;
            $new[$label] = $newVal;
        }

        app(AuditService::class)->log(
            action: 'update',
            module: 'user',
            record: (string) $user->usr_id,
            oldValues: $old,
            newValues: $new,
            description: 'Updated user: ' . ($user->usr_name ?: 'User #' . $user->usr_id)
        );
    }

    public function deleted(User $user): void {
        $values = [];
        foreach (self::FIELD_MAP as $col => $label) {
            $values[$label] = $user->getAttribute($col);
        }

        app(AuditService::class)->log(
            action: 'delete',
            module: 'user',
            record: (string) $user->usr_id,
            oldValues: $values,
            description: 'Deleted user: ' . ($user->usr_name ?: 'User #' . $user->usr_id)
        );
    }
}
