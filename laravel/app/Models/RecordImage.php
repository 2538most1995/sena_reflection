<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecordImage extends Model
{
    public $timestamps = false;

    protected $table = 'record_images';

    protected $fillable = ['record_id', 'file_path', 'created_at'];
}
