<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DraftImage extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'teaching_draft_id', 'level_code', 'storage_disk', 'storage_path', 'original_name',
        'mime_type', 'width', 'height', 'size_bytes', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(TeachingDraft::class, 'teaching_draft_id');
    }
}
