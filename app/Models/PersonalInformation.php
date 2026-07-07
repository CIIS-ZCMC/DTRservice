<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonalInformation extends Model
{
     protected $table = "personal_informations";

       public function employeeName()
    {
        $nameExtension = $this->name_extension === NULL || $this->name_extension === "" ? ' ' : " " . $this->name_extension;
        $nameTitle = $this->name_title === NULL || $this->name_title === "" ? ' ' : ', ' . $this->name_title;
        $middleName = $this->middle_name === NULL || $this->middle_name === '' ? '' : $this->middle_name[0] . '. ';


        $name = $this->first_name . ' ' . $middleName . $this->last_name . $nameExtension . $nameTitle;

        return $name;
    }
     
     public function contact()
    {
        return $this->hasOne(Contact::class);
    }
}
