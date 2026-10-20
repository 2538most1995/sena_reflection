<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeachingDraft extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'tambon', 'week', 'semester', 'year', 'schema_version', 'payload',
        'lock_version', 'status', 'last_saved_at', 'source_record_id', 'source_revision',
        'finalized_submission_id', 'finalize_key', 'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'week' => 'integer',
            'schema_version' => 'integer',
            'lock_version' => 'integer',
            'last_saved_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(DraftImage::class)->orderBy('level_code')->orderBy('sort_order');
    }

    public function sourceRecord(): BelongsTo
    {
        return $this->belongsTo(TeachingRecord::class, 'source_record_id');
    }

    public function finalizedSubmission(): BelongsTo
    {
        return $this->belongsTo(WeeklySubmission::class, 'finalized_submission_id');
    }
}
