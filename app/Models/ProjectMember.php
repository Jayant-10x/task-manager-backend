<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectMember extends Model {
    use SoftDeletes;

    const DELETED_AT = 'pmb_deleted_on';
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = null;

    protected $fillable = [
        'pmb_pro_id',
        'pmb_usr_id',
    ];

    public function member(): BelongsTo {
        return $this->belongsTo(User::class, 'pmb_usr_id', 'usr_id');
    }

    public function project(): BelongsTo {
        return $this->belongsTo(Project::class, 'pmb_pro_id', 'pro_id');
    }
}
