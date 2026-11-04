<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model {
    use SoftDeletes;

    const CREATED_AT = 'pro_created_on';
    const UPDATED_AT = 'pro_updated_on';

    const DELETED_AT = 'pro_deleted_on';

    public $timestamps = true;
    protected $table = 'projects';
    protected $primaryKey = 'pro_id';
    protected $fillable = [
        'pro_id',
        'pro_code',
        'pro_name',
        'pro_description',
        'pro_manager',
        'pro_status',
        'pro_progress',
        'pro_start_date',
        'pro_due_date',
        'pro_budget',
        'pro_updated_by',
        'pro_created_by',
    ];

    public function projectManager(): BelongsTo {
        return $this->belongsTo(User::class, 'pro_manager', 'usr_id');
    }

    public function tasks(): HasMany {
        return $this->hasMany(Task::class, 'tsk_pro_id', 'pro_id');
    }

    public function projectMembers(): HasMany {
        return $this->hasMany(ProjectMember::class, 'pmb_pro_id', 'pro_id');
    }
}
