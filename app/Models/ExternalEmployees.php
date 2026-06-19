<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalEmployees extends Model
{
     protected $table = "external_employees";

    public function getFullNameAttribute()
    {
        return $this->first_name . ' ' . $this->middle_name . ' ' . $this->last_name;
    }
}
