<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalSchedule extends Model
{
    protected $connection = "external_db";
    protected $table = "external_employee_schedules";

    protected $fillable = [
        'external_employee_id',
        'dtr_date',
        'is_shifting',
        'first_in',
        'first_out',
        'second_in',
        'second_out',
    ];

    public function externalEmployee()
    {
        return $this->belongsTo(ExternalEmployee::class, 'external_employee_id');
    }
}
