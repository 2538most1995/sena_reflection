<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAsset extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['document_id', 'name', 'storage_path', 'mime', 'size', 'width', 'height'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
