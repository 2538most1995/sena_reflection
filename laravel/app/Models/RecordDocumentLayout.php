<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordDocumentLayout extends Model
{
    protected $primaryKey = 'record_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['record_id', 'settings', 'content', 'updated_by'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'content' => 'array',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(TeachingRecord::class, 'record_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
