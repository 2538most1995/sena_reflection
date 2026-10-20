<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportJob extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['filters' => 'array', 'manifest' => 'array', 'expires_at' => 'datetime', 'completed' => 'integer', 'total' => 'integer'];
    }
}
