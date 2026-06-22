<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeProfile extends Model
{
     protected $table = "employee_profiles";

      public function name()
        {
            $personal_information = $this->personalInformation;
            $fullName = $personal_information['first_name'] . ' ' . $personal_information['last_name'];

            return $fullName;
        }

        
    public function personalInformation()
    {
        return $this->belongsTo(PersonalInformation::class);
    }

    public function employeeSchedules()
    {
        return $this->hasMany(EmployeeSchedule::class, 'employee_profile_id');
    }
}
