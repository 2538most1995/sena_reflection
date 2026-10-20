<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecordRevision extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'revision' => 'integer', 'created_at' => 'datetime'];
    }
}
