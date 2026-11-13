<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PasswordResetRequest extends Model
{
    public $timestamps = false;

    public $table = 'password_reset_requests';
    protected $guarded = [];

    protected $primaryKey = 'prr_id';

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prr_opened_by', 'usr_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prr_completed_by', 'usr_id');
    }
}
