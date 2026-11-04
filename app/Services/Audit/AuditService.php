<?php

namespace App\Services\Audit;

use App\Events\AuditLogCreated;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditService {
    public function __construct (
        private readonly AuditSanitizer $sanitizer
    ) {}

    public function log (
        string                $action,
        string                $module,
        Model|string|int|null $record = null,
        ?array                $oldValues = null,
        ?array                $newValues = null,
        ?string               $description = null,
        ?array                $actorSnapshot = null
    ): AuditLog {
        $actor = Auth::user();

        $recordId = $record instanceof Model
            ? $record->getKey()
            : $record;

        $entry = AuditLog::create([
            'alg_usr_id' => $actor?->getAuthIdentifier(),
            'alg_actor_name' => $actor?->usr_name ?? 'System',
            'alg_actor_email' => $actor?->usr_email ?? '',
            'alg_actor_role' => $actor?->usr_role ?? '',

            'alg_action' => mb_substr($action, 0, 40),
            'alg_module' => mb_substr($module, 0, 30),
            'alg_record_id' => $recordId === null
                ? ''
                : mb_substr((string)$recordId, 0, 40),

            'alg_old_values' => $this->encode(
                $this->sanitizer->sanitize($oldValues)
            ),
            'alg_new_values' => $this->encode(
                $this->sanitizer->sanitize($newValues)
            ),

            'alg_ip_address' => (string)(Request::ip() ?? ''),
            'alg_user_agent' => mb_substr((string)Request::userAgent(), 0, 255),
            'alg_description' => mb_substr((string)$description, 0, 500),
            'alg_updated_by' => setCreatedUpdatedBy(),
            'alg_updated_on' => now(),
        ]);

        AuditLogCreated::dispatch($entry);
        return $entry;
    }

    private function encode (?array $values): ?string {
        return $values === null ? null : json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
