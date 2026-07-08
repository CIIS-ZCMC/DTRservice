<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfficialBusinessApplication extends Model
{
    protected $table = 'official_business_applications';

    protected $fillable = [
        'employee_profile_id',
        'status',
        'date_from',
        'date_to',
    ];
}
