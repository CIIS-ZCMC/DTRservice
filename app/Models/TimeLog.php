<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimeLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'device_id',
        'log_time',
        'log_type', // 'check_in', 'check_out', 'break_start', 'break_end'
        'synced_at',
    ];

    protected $casts = [
        'log_time' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
