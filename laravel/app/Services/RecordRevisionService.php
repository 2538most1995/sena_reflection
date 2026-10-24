<?php

namespace App\Services;

use App\Models\RecordRevision;
use App\Models\TeachingRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecordRevisionService
{
    public function capture(TeachingRecord $record, User $user, ?array $rendererSettings = null, bool $resetSettings = false): RecordRevision
    {
        return DB::transaction(function () use ($record, $user, $rendererSettings, $resetSettings): RecordRevision {
            $record = TeachingRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $record->load(['images', 'attendance', 'documentLayout', 'tambonRelation']);
            $options = app(RecordDocumentOptions::class);
            $data = $record->attributesToArray();
            $data['date'] = $record->date?->format('Y-m-d');
            $data['tambon_name'] = $record->tambonRelation?->name ?? '';
            $data['teacher_name'] = User::query()->whereKey($record->created_by)->value('name') ?? $user->name;
            $latest = RecordRevision::query()->where('record_id', $record->id)->orderByDesc('revision')->first();
            $frozenSettings = $resetSettings ? [] : ($latest?->snapshot['settings'] ?? []);
            $frozenSettings = array_merge($frozenSettings, $rendererSettings ?? []);
            $settings = array_merge(
                $frozenSettings ?: $options->legacySettings($record->tambon, $user),
                $options->settings(array_merge($frozenSettings, $record->documentLayout?->settings ?? []), $record->images->pluck('id')->map(fn ($id) => (string) $id)->all(), $record->tambon),
            );
            $snapshot = [
                'schema_version' => 1, 'record' => $data,
                'images' => $record->images->map(fn ($image) => $image->only(['id', 'file_path']))->all(),
                'attendance' => $record->attendance->toArray(), 'settings' => $settings,
                'content' => $options->content($record),
            ];
            $legacyContent = $record->documentLayout?->content ?? [];
            $advanced = app(BoundDocumentService::class)->snapshotForRecord($record);
            if ($advanced) {
                $snapshot['advanced_document'] = $advanced;
            }
            if ($legacyContent !== []) {
                $snapshot['legacy_document_content'] = $legacyContent;
            }
            $hash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            if ($latest && hash_equals($latest->content_hash, $hash)) {
                return $latest;
            }

            return RecordRevision::create([
                'id' => (string) Str::uuid(), 'record_id' => $record->id,
                'revision' => ($latest?->revision ?? 0) + 1, 'content_hash' => $hash,
                'snapshot' => $snapshot, 'created_by' => $user->id, 'created_at' => now(),
            ]);
        }, 3);
    }
}
