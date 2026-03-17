<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = [
        'device_user_id',
        'name',
        'card_number',
        'device_ip',
    ];
}
