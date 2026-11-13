<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Audit\AuditService;

class UserObserver {
    private const TRACKED_FIELDS = ['name', 'email', 'phone', 'role', 'designation', 'dept', 'usr_custom_dept', 'city', 'usr_avatar', 'status', 'is_super_admin',];

    public function created(User $user): void {
        $user->assignDefaultModulePermissions();

        app(AuditService::class)->log(
            action: 'create',
            module: 'user',
            record: $user,
            newValues: $user->only(self::TRACKED_FIELDS),
            description: 'Created user: ' . $user->name
        );
    }

    public function updated(User $user): void {
        $changes = array_intersect_key(
            $user->getChanges(),
            array_flip(self::TRACKED_FIELDS)
        );

        if ($changes === []) {
            return;
        }

        $old = [];
        $new = [];

        foreach (array_keys($changes) as $field) {
            $old[$field] = $user->getOriginal($field);
            $new[$field] = $user->getAttribute($field);
        }

        app(AuditService::class)->log(
            action: 'update',
            module: 'user',
            record: $user,
            oldValues: $old,
            newValues: $new,
            description: 'Updated user: ' . $user->name
        );
    }

    public function deleted(User $user): void {
        app(AuditService::class)->log(
            action: 'delete',
            module: 'user',
            record: (string)$user->getKey(),
            oldValues: $user->only(self::TRACKED_FIELDS),
            description: 'Deleted user: ' . $user->name
        );
    }
}
