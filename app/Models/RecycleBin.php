<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecycleBin extends Model
{
    protected $table = 'recycle_bins';
    public $timestamps = false;
    protected $primaryKey = 'rbs_id';

    protected $fillable = [
        'rbs_module',
        'rbs_original_id',
        'rbs_label',
        'rbs_snapshot',
        'rbs_file_count',
        'rbs_drive_file_ids',
        'rbs_deleted_by_id',
        'rbs_deleted_by_name',
        'rbs_deleted_at',
        'rbs_deletion_reason',
        'rbs_permanent_delete_at',
        'rbs_restore_status',
        'rbs_restored_at',
        'rbs_restored_by',
        'rbs_purged_at',
        'rbs_purged_by',
        'rbs_purge_error',
        'rbs_purge_attempts',
    ];

    protected $casts = [
        'rbs_snapshot' => 'array',
        'rbs_drive_file_ids' => 'array',
        'rbs_deleted_at' => 'datetime',
        'rbs_permanent_delete_at' => 'datetime',
        'rbs_restored_at' => 'datetime',
        'rbs_purged_at' => 'datetime',
        'rbs_file_count' => 'integer',
        'rbs_purge_attempts' => 'integer',
    ];

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rbs_deleted_by_id', 'usr_id');
    }
}
