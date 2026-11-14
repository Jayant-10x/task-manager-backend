<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model {
    public $timestamps = false;
    protected $table = 'audit_logs';
    protected $fillable = [
        'alg_usr_id',
        'alg_actor_name',
        'alg_actor_email',
        'alg_actor_role',
        'alg_action',
        'alg_module',
        'alg_record_id',
        'alg_old_values',
        'alg_new_values',
        'alg_description',
        'alg_ip_address',
        'alg_user_agent',
        'alg_updated_by',
        'alg_updated_on',
    ];

    protected function casts(): array {
        return [
            'alg_old_values' => 'array',
            'alg_new_values' => 'array',
            'old_values' => 'array',
            'new_values' => 'array',
            'alg_updated_on' => 'datetime',
            'updated_on' => 'datetime',
        ];
    }
}
