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
        'is_hrbliz',
        'receiver_by_default',
        'fp_version',
        'last_seen_at',
        'last_cleared_at',
    ];

    /**
     * Get the device's fingerprint algorithm version ('v10' or 'v9').
     */
    public function getFingerprintAlgorithm(): string
    {
        $val = trim((string)($this->fp_version ?? ''));
        if ($val === '9' || $val === '9.0' || $val === 'v9') {
            return 'v9';
        }
        if ($val === '10' || $val === '10.0' || $val === 'v10') {
            return 'v10';
        }

        // Check persistent cache fallback
        if (!empty($this->serial_number)) {
            $cached = cache()->get("device_algo_{$this->serial_number}");
            if ($cached === 'v9' || $cached === 'v10') {
                return $cached;
            }
        }

        return 'v10';
    }
    
    protected $casts = [
        'last_seen_at' => 'datetime',
        'last_cleared_at' => 'datetime',
        'is_active' => 'boolean',
        'is_registration' => 'boolean',
        'for_attendance' => 'boolean',
        'is_hrbliz' => 'boolean',
    ];

    /**
     * Accessor for receiver_by_default attribute.
     */
    public function getReceiverByDefaultAttribute($value): bool
    {
        return $this->canReceiveSync();
    }

    /**
     * Mutator for receiver_by_default attribute.
     */
    public function setReceiverByDefaultAttribute($value): void
    {
        $this->attributes['receiver_by_default'] = $value === null ? null : (bool)$value;
    }

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

    /**
     * Scope for HRBLIZ devices
     */
    public function scopeHrbliz($query)
    {
        return $query->where('is_hrbliz', 1);
    }

    /**
     * Scope for standard non-HRBLIZ devices
     */
     public function scopeStandard($query)
     {
         return $query->where(function ($q) {
             $q->whereNull('is_hrbliz')->orWhere('is_hrbliz', 0);
         });
     }

    /**
     * Check if this device is eligible to receive biometric sync updates.
     * HRBLIZ terminals (is_hrbliz = 1) strictly only receive sync if receiver_by_default is explicitly 1.
     * Standard terminals receive sync unless receiver_by_default is explicitly 0.
     */
    public function canReceiveSync(): bool
    {
        $raw = $this->attributes['receiver_by_default'] ?? null;

        if ($this->is_hrbliz) {
            return $raw === true || $raw === 1 || $raw === '1';
        }

        return $raw !== false && $raw !== 0 && $raw !== '0';
    }

    /**
     * Scope for devices eligible to receive biometric sync updates.
     * HRBLIZ devices (is_hrbliz = 1) strictly require receiver_by_default = 1.
     * Standard non-HRBLIZ devices receive unless receiver_by_default is 0.
     */
    public function scopeCanReceiveSync($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($sub) {
                // Standard non-HRBLIZ devices: eligible unless receiver_by_default is 0
                $sub->where(function ($h) {
                    $h->whereNull('is_hrbliz')->orWhere('is_hrbliz', 0);
                })->where(function ($r) {
                    $r->whereNull('receiver_by_default')->orWhere('receiver_by_default', 1);
                });
            })->orWhere(function ($sub) {
                // HRBLIZ devices: strictly only eligible if receiver_by_default is explicitly 1
                $sub->where('is_hrbliz', 1)
                    ->where('receiver_by_default', 1);
            });
        });
    }
}

