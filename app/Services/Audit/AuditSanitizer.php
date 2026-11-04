<?php


namespace App\Services\Audit;

class AuditSanitizer {
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'oldpassword',
        'newpassword',
        'pass_hash',
        'password_hash',
        'token',
        'access_token',
        'refresh_token',
        'reset_hash',
        'remember_token',
        'secret',
        'client_secret',
        'authorization',
        'cookie',
        'session',
    ];

    public function sanitize(?array $values): ?array {
        if ($values === null) {
            return null;
        }

        foreach ($values as $key => $value) {
            if (in_array(
                strtolower((string)$key),
                self::SENSITIVE_KEYS,
                true
            )) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->sanitize($value);
            }
        }

        return $values;
    }
}
