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
        'last_cleared_at',
    ];
    
    protected $casts = [
        'last_seen_at' => 'datetime',
        'last_cleared_at' => 'datetime',
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
     * Check if device requires an attendance log clear (e.g. not cleared in 7+ days)
     */
    public function needsLogClear(int $days = 7): bool
    {
        if (!$this->last_cleared_at) {
            return true;
        }

        return $this->last_cleared_at->diffInDays(now()) >= $days;
    }

    /**
     * Scope for active devices
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }

    /**
     * Scope for active devices that need log clearance
     */
    public function scopeNeedingLogClear($query, int $days = 7)
    {
        $cutoff = now()->subDays($days);
        return $query->where('is_active', 1)
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('last_cleared_at')
                    ->orWhere('last_cleared_at', '<=', $cutoff);
            });
    }
}

