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


}
