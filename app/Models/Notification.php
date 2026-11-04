<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model {
    // If your table name doesn't match standard pluralization conventions:
    protected $table = 'notifications';
    protected $primaryKey = 'not_id';

    // Disable standard timestamps if you use custom helpers like get_created_updated_by_db_column
    public $timestamps = false;

    protected $fillable = [
        'not_usr_id',
        'not_kind',
        'not_title',
        'not_body',
        'not_meta_info',
        'not_actor',
        'not_is_read',
        'not_created_by', // Update if named differently by your helper
        'not_created_on',
    ];

    protected $casts = [
        'not_is_read' => 'boolean',
        'not_meta_info' => 'array', // Cast JSON to PHP Array
    ];

    /**
     * Get the user who owns the notification.
     */
    public function user (): BelongsTo {
        return $this->belongsTo(User::class, 'not_usr_id', 'usr_id');
    }
}
