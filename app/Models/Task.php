<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model {
    use HasFactory, SoftDeletes;

    const DELETED_AT = 'tsk_deleted_on';
    public $timestamps = false;

    public $primaryKey = 'tsk_id';
    protected $fillable = [
        'tsk_pro_id', 'tsk_code', 'tsk_title', 'tsk_description', 'tsk_status', 'tsk_category',
        'tsk_priority', 'tsk_assigned_by', 'tsk_start_date', 'tsk_due_date',
        'tsk_est_hours', 'tsk_spent_hours', 'tsk_tags', 'tsk_progress', 'tsk_completed_at', 'tsk_created_by', 'tsk_created_on', 'tsk_updated_by', 'tsk_updated_on', 'tsk_deleted_on',
    ];

    public function project (): BelongsTo {
        return $this->belongsTo(Project::class, 'tsk_pro_id', 'pro_id');
    }

    public function assignedBy (): BelongsTo {
        return $this->belongsTo(User::class, 'tsk_assigned_by', 'usr_id');
    }

    public function assignees () {
        return $this->hasMany(TaskAssignee::class, 'tsa_tsk_id', 'tsk_id');
    }

    public function subtasks () {
        return $this->hasMany(SubTask::class, 'stk_tsk_id', 'tsk_id');
    }

    public function comments () {
        return $this->hasMany(TaskComment::class, 'tsc_tsk_id', 'tsk_id');
    }

    public function attachments () {
        return $this->hasMany(Attachment::class, 'atc_tsk_id', 'tsk_id');
    }

    public function activities () {
        return $this->hasMany(TaskActivity::class, 'tct_tsk_id', 'tsk_id')->orderByDesc('tct_id')->limit(10);
    }
    /*public function dependencies() {
        return $this->hasMany(TaskDep::class, 'task_id');
    }*/
}
