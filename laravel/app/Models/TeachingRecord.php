<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TeachingRecord extends Model
{
    protected $table = 'records';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'weekly_submission_id', 'tambon', 'week', 'date', 'level', 'semester', 'year',
        'subject', 'subject_code', 'class_group_code', 'class_name', 'total', 'present',
        'result', 'problem', 'suggestion', 'supervisor_comment', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'week' => 'integer',
            'date' => 'date',
            'total' => 'integer',
            'present' => 'integer',
        ];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isDistrict() ? $query : $query->where('tambon', $user->tambon);
    }

    public function images(): HasMany
    {
        return $this->hasMany(RecordImage::class, 'record_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(RecordAttendance::class, 'record_id');
    }

    public function documentLayout(): HasOne
    {
        return $this->hasOne(RecordDocumentLayout::class, 'record_id');
    }

    public function tambonRelation(): BelongsTo
    {
        return $this->belongsTo(Tambon::class, 'tambon');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(WeeklySubmission::class, 'weekly_submission_id');
    }
}
