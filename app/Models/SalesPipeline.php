<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesPipeline extends Model {
    use SoftDeletes;

    const CREATED_AT = 'ssp_created_on';
    const UPDATED_AT = 'ssp_updated_on';
    const DELETED_AT = 'ssp_deleted_on';

    protected $primaryKey = 'ssp_id';

    protected $fillable = [
        'ssp_code',
        'ssp_name',
        'ssp_account',
        'ssp_owner_id',
        'ssp_owner',
        'ssp_stage',
        'ssp_value',
        'ssp_probability',
        'ssp_close_date',
        'ssp_created_by',
        'ssp_updated_by',
        'ssp_created_on',
        'ssp_updated_on',
        'ssp_deleted_on',
    ];

    public function dealOwner(): BelongsTo {
        return $this->belongsTo(User::class, 'ssp_owner_id', 'usr_id');
    }
}
