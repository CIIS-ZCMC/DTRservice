<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Devices extends Model
{
    protected $table = "devices";
    
    protected $fillable = [
        'serial_number',
        'last_seen_at',
    ];
    
    protected $casts = [
        'last_seen_at' => 'datetime',
    ];
}
