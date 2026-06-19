<?php

namespace App\Contracts;

interface DeviceRepositoryInterface
{
    /**
     * Find device by ID
     */
    public function findById(int $id): ?\App\Models\Devices;

    /**
     * Find device by device_id
     */
    public function findByDeviceId(string $deviceId): ?\App\Models\Devices;

    /**
     * Get all devices
     */
    public function getAll();

    
    public function findByIP(string $ip): ?\App\Models\Devices;

    public function markAsConnected(string $ip): void;
}


