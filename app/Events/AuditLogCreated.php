<?php

namespace App\Events;

use App\Models\AuditLog;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AuditLogCreated implements ShouldDispatchAfterCommit {
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly AuditLog $auditLog
    ) {}
}
