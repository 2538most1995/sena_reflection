<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentApiConnection extends Model
{
    protected $guarded = [];

    protected $hidden = ['token'];
}
