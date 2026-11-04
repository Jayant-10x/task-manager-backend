<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attachment extends Model
{
    use softDeletes;

    protected $table = 'attachments';
    protected $primaryKey = 'atc_id';
    public $timestamps = false;

    const string DELETED_AT = 'atc_deleted_on';

    protected $fillable = [
        'atc_tsk_id',
        'atc_pro_id',
        'atc_file_name',
        'atc_stored_as',
        'atc_mime',
        'atc_size_kb',
        'atc_uploaded_by',
        'atc_created_at',
        'atc_storage',
        'atc_drive_file_id',
        'atc_drive_url',
        'atc_deleted_on',
    ];

    public function task()
    {
        return $this->belongsTo(Task::class, 'atc_tsk_id', 'tsk_id');
    }
}
