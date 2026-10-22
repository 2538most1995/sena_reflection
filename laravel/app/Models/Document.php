<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['user_id', 'source_record_id', 'title', 'content_json', 'page_settings_json', 'objects_json', 'schema_version', 'revision'];

    protected function casts(): array
    {
        return ['content_json' => 'array', 'page_settings_json' => 'array', 'objects_json' => 'array', 'schema_version' => 'integer', 'revision' => 'integer'];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isDistrict() ? $query : $query->where('user_id', $user->id);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(DocumentAsset::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('revision');
    }
}
