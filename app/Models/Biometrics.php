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

    public function externalProfile(){
          return $this->hasOne(ExternalEmployee::class, 'biometric_id', 'biometric_id');
    }

    public function getSchedules($date){
        $externalEmployee = $this->externalProfile;
      
        if (!$externalEmployee) {
            return null;
        }
        return ExternalSchedule::where('external_employee_id', $externalEmployee->id)
            ->where('dtr_date', $date)
            ->first();
    }
}
