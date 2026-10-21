<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecordImage extends Model
{
    public $timestamps = false;

    protected $table = 'record_images';

    // Imported installations can retain string image IDs. Keep automatic
    // increments for new rows while preserving the exact stored identity.
    protected $keyType = 'string';

    protected $fillable = ['record_id', 'file_path', 'created_at'];
}
