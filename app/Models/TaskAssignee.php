<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskAssignee extends Model {
    use HasFactory, SoftDeletes;

    const string DELETED_AT = 'tsa_deleted_on';
    public $timestamps = false ;
    protected $table = 'task_assignees';
    protected $fillable = [
        'tsa_tsk_id',
        'tsa_usr_id',
    ];

    public function whichAssignedTask(): BelongsTo {
        return $this->belongsTo(Task::class, 'tsa_tsk_id', 'tsk_id');
    }

    public function assignedUser(): BelongsTo {
        return $this->belongsTo(User::class, 'tsa_usr_id', 'usr_id');
    }
}
