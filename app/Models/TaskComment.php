<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskComment extends Model {
    use SoftDeletes;

    const UPDATED_AT = 'tsc_updated_on';
    const DELETED_AT = 'tsc_deleted_on';
    public $timestamps = false;
    public $primaryKey = 'tsc_id';

    protected $fillable = [
        'tsc_tsk_id',
        'tsc_author',
        'tsc_comment',
        'tsc_mentions',
        'tsc_updated_by',
        'tsc_updated_on',
    ];

    protected $casts = [
        'tsc_mentions' => 'array',
    ];

    public function commentedTask(): BelongsTo {
        return $this->belongsTo(Task::class, 'tsc_tsk_id', 'tsk_id');
    }

    public function commentAuthor(): BelongsTo {
        return $this->belongsTo(User::class, 'tsc_author', 'usr_id');
    }

}
