<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecordAttendance extends Model
{
    public $timestamps = false;

    protected $table = 'record_attendance';

    protected $guarded = [];
}
