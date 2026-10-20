<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeeklySubmission extends Model
{
    use HasUuids;

    protected $fillable = ['tambon', 'week', 'semester', 'year', 'submitted_by', 'status'];

    protected function casts(): array
    {
        return ['week' => 'integer'];
    }

    public function records(): HasMany
    {
        return $this->hasMany(TeachingRecord::class, 'weekly_submission_id');
    }
}
