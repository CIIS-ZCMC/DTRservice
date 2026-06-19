<?php

namespace App\Repositories;

use App\Contracts\DeviceRepositoryInterface;
use App\Models\Devices;

class DeviceRepository implements DeviceRepositoryInterface
{
    protected Devices $model;

    public function __construct(Devices $device)
    {
        $this->model = $device;
    }

    public function findById(int $id): ?Devices
    {
        return $this->model->find($id);
    }

    public function findByDeviceId(string $deviceId): ?Devices
    {
        return $this->model->where('device_id', $deviceId)->first();
    }

    public function getAll()
    {
        return $this->model->all();
    }

    public function findByIP(string $ip): ?Devices
    {
        return $this->model->where('ip_address', $ip)
        ->where('is_active', true)
        ->first();
    }

    public function markAsConnected(string $ip): void
    {
        $this->model->where('ip_address', $ip)
        ->where('is_active', true)
        ->update(['last_seen_at' => now()]);
    }


}
