<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskActivity extends Model {
    public $timestamps = false;

    protected $table = 'task_activities';
    protected $primaryKey = 'tct_id';

    protected $guarded = [];

    public function activist (): BelongsTo {
        return $this->belongsTo(User::class, 'tct_who', 'usr_id');
    }
}
