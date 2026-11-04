<?php

namespace App\Listeners;

use App\Events\AuditLogCreated;
use Illuminate\Support\Facades\Log;

class HandleAuditLogCreated {
    public function handle(AuditLogCreated $event): void {
        Log::info('Audit entry created', [
            'audit_log_id' => $event->auditLog->getKey(),
            'action' => $event->auditLog->action,
            'module' => $event->auditLog->module,
            'record_id' => $event->auditLog->record_id,
            'actor_user_id' => $event->auditLog->user_id,
        ]);
    }
}
