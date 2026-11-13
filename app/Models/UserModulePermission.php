<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserModulePermission extends Model {
    use HasFactory, softDeletes;

    const CREATED_AT = 'ump_created_on';
    const UPDATED_AT = 'ump_updated_on';
    const DELETED_AT = 'ump_deleted_on';
    public $timestamps = true;
    public $incrementing = false;
    protected $table = 'user_module_permissions';
    protected $primaryKey = null;
    protected $fillable = [
        'ump_usr_id',
        'ump_module_slug',
        'ump_can_view',
        'ump_can_add',
        'ump_can_edit',
        'ump_can_delete',
        'ump_created_by',
        'ump_updated_by',
        'ump_created_on',
        'ump_updated_on',
    ];

    protected $casts = [
        'ump_can_view' => 'boolean',
        'ump_can_add' => 'boolean',
        'ump_can_edit' => 'boolean',
        'ump_can_delete' => 'boolean',
    ];

    public function user() {
        return $this->hasMany(User::class, 'usr_id', 'ump_usr_id');
    }
}
