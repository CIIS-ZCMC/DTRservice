<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Devices extends Model
{
    protected $table = "devices";
    
    protected $fillable = [
        'device_name',
        'device_id',
        'serial_number',
        'ip_address',
        'com_key',
        'soap_port',
        'udp_port',
        'is_active',
        'is_registration',
        'for_attendance',
        'last_seen_at',
    ];
    
    protected $casts = [
        'last_seen_at' => 'datetime',
        'is_active' => 'boolean',
        'is_registration' => 'boolean',
        'for_attendance' => 'boolean',
    ];

    /**
     * Check if device is currently online (seen in the last 2 minutes)
     */
    public function isOnline(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->diffInMinutes(now()) <= 2;
    }

    /**
     * Scope for active devices
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }
}

