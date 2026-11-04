<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubTask extends Model {
    use HasFactory, SoftDeletes;
    const DELETED_AT = 'stk_deleted_on';
    const UPDATED_AT  = 'stk_updated_on';
    public $primaryKey = 'stk_id';

    public $timestamps = false;
    protected $fillable = [
        'stk_title',
        'stk_tsk_id',
        'stk_done',
        'stk_sort_order',
        'stk_completed_at',
        'stk_updated_by',
        'stk_updated_on',
        'stk_deleted_on',
    ];

    public function parentTask(): BelongsTo {
        return $this->belongsTo(Task::class, 'stk_tsk_id', 'tsk_id');
    }
}
