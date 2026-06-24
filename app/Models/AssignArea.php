<?php

namespace App\Models;

use App\Http\Resources\AssignAreaDepartmentResource;
use App\Http\Resources\AssignAreaDivisionResource;
use App\Http\Resources\AssignAreaSectionResource;
use App\Http\Resources\AssignAreaUnitResource;
use Illuminate\Database\Eloquent\Model;

class AssignArea extends Model
{
   protected $table = 'assigned_areas';

     public function division()
    {
        return $this->belongsTo(Division::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }


    public function findDetails()
    {
        if ($this->unit_id !== null) {
            if ($this->unit) {
                return [
                    'details' => new AssignAreaUnitResource($this->unit),
                    'sector' => 'Unit'
                ];
            }
        }

        if ($this->section_id !== null) {
            if ($this->section) {
                return [
                    'details' => new AssignAreaSectionResource($this->section),
                    'sector' => 'Section'
                ];
            }
        }

        if ($this->department_id !== null) {
            if ($this->department) {
                return [
                    'details' => new AssignAreaDepartmentResource($this->department),
                    'sector' => 'Department'
                ];
            }
        }

        if ($this->division) {
            return [
                'details' => new AssignAreaDivisionResource($this->division),
                'sector' => 'Division'
            ];
        }

        return [
            'details' => null,
            'sector' => null
        ];
    }
}
