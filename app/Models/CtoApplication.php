<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CtoApplication extends Model
{
    protected $table = 'cto_applications';

    protected $fillable = [
        'employee_profile_id',
        'status',
        'date',
    ];
}
