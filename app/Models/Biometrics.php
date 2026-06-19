<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Biometrics extends Model
{
   protected $table = "biometrics";

   public function employeeProfile()
    {
        return $this->hasOne(EmployeeProfile::class, 'biometric_id', 'biometric_id');
    }
}
