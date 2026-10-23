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

    protected $fillable = ['user_id', 'source_record_id', 'title', 'content_json', 'page_settings_json', 'objects_json', 'schema_version', 'revision', 'binding_key', 'binding_snapshot'];

    protected function casts(): array
    {
        return ['content_json' => 'array', 'page_settings_json' => 'array', 'objects_json' => 'array', 'schema_version' => 'integer', 'revision' => 'integer', 'binding_snapshot' => 'array'];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $visible) use ($user): void {
            $visible->where(function (Builder $standalone) use ($user): void {
                $standalone->whereNull('binding_key');
                if (! $user->isDistrict()) {
                    $standalone->where('user_id', $user->id);
                }
            })->orWhere(function (Builder $records) use ($user): void {
                $records->whereNotNull('binding_key')->where('binding_snapshot->kind', 'record')
                    ->whereIn('binding_snapshot->record_id', TeachingRecord::query()->visibleTo($user)->select('id'));
            })->orWhere(function (Builder $drafts) use ($user): void {
                $drafts->whereNotNull('binding_key')->where('binding_snapshot->kind', 'draft')->where('user_id', $user->id)
                    ->whereIn('binding_snapshot->draft_id', TeachingDraft::query()->where('user_id', $user->id)->select('id'));
            });
        });
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
