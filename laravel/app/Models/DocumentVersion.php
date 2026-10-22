<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['document_id', 'user_id', 'revision', 'snapshot_json'];

    protected function casts(): array
    {
        return ['snapshot_json' => 'array', 'revision' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
