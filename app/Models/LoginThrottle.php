<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginThrottle extends Model
{
    public $timestamps = false;
    protected $table = 'login_throttles';

    protected $primaryKey = null;
}
