<?php

namespace App\Services;

use App\Exceptions\DraftVersionConflictException;
use App\Models\DraftImage;
use App\Models\Tambon;
use App\Models\TeachingDraft;
use App\Models\TeachingRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DraftService
{
    public static function reviewCacheKey(string $id, int $version, string $levelCode): string
    {
        return "draft-pdf-review:{$id}:{$version}:{$levelCode}";
    }

    public function __construct(
        private readonly EducationLevelService $levels,
        private readonly RecordDocumentOptions $documentOptions,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): TeachingDraft
    {
        $storedPaths = [];
        try {
            return DB::transaction(function () use ($user, $data, &$storedPaths): TeachingDraft {
                return $this->createInTransaction($user, $data, $storedPaths);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data @param list<string> $storedPaths */
    private function createInTransaction(User $user, array $data, array &$storedPaths): TeachingDraft
    {
        $sourceRecordId = trim((string) ($data['source_record_id'] ?? ''));
        if ($sourceRecordId !== '') {
            $existing = TeachingDraft::query()
                ->where('user_id', $user->id)
                ->where('source_record_id', $sourceRecordId)
                ->where('status', 'active')
                ->latest('updated_at')
                ->orderByDesc('id')
                ->first();
            if ($existing && empty($data['refresh_source'])) {
                return $existing->load('images');
            }

            $source = TeachingRecord::query()->visibleTo($user)->whereKey($sourceRecordId)->lockForUpdate()->first();
            if (! $source) {
                throw (new ModelNotFoundException)->setModel(TeachingRecord::class, [$sourceRecordId]);
            }

            $payload = $this->payloadFromRecord($source);
            $payload['source_fingerprint'] = $this->sourceFingerprint($source);
        } else {
            $source = null;
            $payload = $this->normalizePayload($data['payload'] ?? [], $user);
        }

        $scope = $payload['scope'];
        $draft = TeachingDraft::create([
            'user_id' => $user->id,
            'tambon' => $scope['tambon'] ?: null,
            'week' => $scope['week'] ?: null,
            'semester' => $scope['semester'] ?: null,
            'year' => $scope['year'] ?: null,
            'schema_version' => 1,
            'payload' => $payload,
            'lock_version' => 1,
            'status' => 'active',
            'last_saved_at' => now(),
            'source_record_id' => $source?->id,
            'source_revision' => $source?->updated_at?->timestamp,
        ]);

        if ($source) {
            $this->importSourceImages($draft, $source, $storedPaths);
        }

        return $draft->load('images');
    }

    /** @param array<string, mixed> $payload */
    public function update(User $user, TeachingDraft|string $draft, int $lockVersion, array $payload): TeachingDraft
    {
        $owned = $this->owned($user, $draft);
        if ($owned->status !== 'active') {
            throw ValidationException::withMessages(['draft' => 'ร่างนี้ยืนยันแล้วและไม่สามารถแก้ไขได้']);
        }

        $normalized = $this->normalizePayload($payload, $user);
        if (isset($owned->payload['source_fingerprint'])) {
            $normalized['source_fingerprint'] = $owned->payload['source_fingerprint'];
        }
        $scope = $normalized['scope'];
        $updated = TeachingDraft::query()
            ->whereKey($owned->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('lock_version', $lockVersion)
            ->update([
                'payload' => json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'tambon' => $scope['tambon'] ?: null,
                'week' => $scope['week'] ?: null,
                'semester' => $scope['semester'] ?: null,
                'year' => $scope['year'] ?: null,
                'lock_version' => DB::raw('lock_version + 1'),
                'last_saved_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            $this->throwConflict($user, $owned->id);
        }

        return $this->owned($user, $owned->id)->load('images');
    }

    public function uploadImage(User $user, TeachingDraft|string $draft, int $lockVersion, string $levelCode, UploadedFile $file): DraftImage
    {
        $storedPath = null;
        try {
            return DB::transaction(function () use ($user, $draft, $lockVersion, $levelCode, $file, &$storedPath): DraftImage {
                $owned = $this->lockOwned($user, $draft, $lockVersion);
                if ($owned->images()->where('level_code', $levelCode)->count() >= 4) {
                    throw ValidationException::withMessages(['image' => 'แต่ละระดับเก็บรูปในร่างได้ไม่เกิน 4 รูป']);
                }

                $extension = strtolower($file->guessExtension() ?: $file->extension() ?: 'jpg');
                $storedPath = $file->storeAs(
                    "teaching-drafts/{$user->id}/{$owned->id}",
                    (string) Str::uuid().'.'.$extension,
                    'local',
                );
                $dimensions = @getimagesize($file->getRealPath()) ?: [0, 0];
                $image = $owned->images()->create([
                    'level_code' => $levelCode,
                    'storage_disk' => 'local',
                    'storage_path' => $storedPath,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime_type' => (string) $file->getMimeType(),
                    'width' => (int) $dimensions[0],
                    'height' => (int) $dimensions[1],
                    'size_bytes' => (int) $file->getSize(),
                    'sort_order' => (int) $owned->images()->where('level_code', $levelCode)->max('sort_order') + 1,
                ]);
                $payload = $owned->payload;
                $payload['levels'][$levelCode] ??= [];
                $payload['levels'][$levelCode]['image_ids'] = array_values(array_unique([
                    ...($payload['levels'][$levelCode]['image_ids'] ?? []),
                    $image->id,
                ]));
                $owned->payload = $payload;
                $this->touchLockedDraft($owned);

                return $image;
            }, 3);
        } catch (\Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }
    }

    public function deleteImage(User $user, TeachingDraft|string $draft, DraftImage|string $image, int $lockVersion): TeachingDraft
    {
        $path = null;
        $disk = null;
        $result = DB::transaction(function () use ($user, $draft, $image, $lockVersion, &$path, &$disk): TeachingDraft {
            $owned = $this->lockOwned($user, $draft, $lockVersion);
            $ownedImage = $owned->images()->whereKey($image instanceof DraftImage ? $image->id : $image)->firstOrFail();
            $path = $ownedImage->storage_path;
            $disk = $ownedImage->storage_disk;
            $levelCode = $ownedImage->level_code;
            $ownedImage->delete();
            $this->normalizeImageOrder($owned, $levelCode);
            $payload = $owned->payload;
            $payload['levels'][$levelCode]['image_ids'] = $owned->images()
                ->where('level_code', $levelCode)->orderBy('sort_order')->pluck('id')->all();
            $owned->payload = $payload;
            $this->touchLockedDraft($owned);

            return $owned->fresh('images');
        }, 3);

        if ($path && $disk) {
            Storage::disk($disk)->delete($path);
        }

        return $result;
    }

    /** @param list<string> $imageIds */
    public function reorderImages(User $user, TeachingDraft|string $draft, int $lockVersion, string $levelCode, array $imageIds): TeachingDraft
    {
        return DB::transaction(function () use ($user, $draft, $lockVersion, $levelCode, $imageIds): TeachingDraft {
            $owned = $this->lockOwned($user, $draft, $lockVersion);
            $storedIds = $owned->images()->where('level_code', $levelCode)->pluck('id')->sort()->values()->all();
            $requestedIds = collect($imageIds)->sort()->values()->all();
            if ($storedIds !== $requestedIds) {
                throw ValidationException::withMessages(['image_ids' => 'รายการรูปไม่ตรงกับรูปของระดับชั้นนี้']);
            }

            foreach ($imageIds as $position => $imageId) {
                $owned->images()->whereKey($imageId)->update(['sort_order' => $position]);
            }
            $payload = $owned->payload;
            $payload['levels'][$levelCode]['image_ids'] = array_values($imageIds);
            $owned->payload = $payload;
            $this->touchLockedDraft($owned);

            return $owned->fresh('images');
        }, 3);
    }

    public function owned(User $user, TeachingDraft|string $draft): TeachingDraft
    {
        $id = $draft instanceof TeachingDraft ? $draft->id : $draft;
        $owned = TeachingDraft::query()->whereKey($id)->where('user_id', $user->id)->first();
        if (! $owned) {
            throw (new ModelNotFoundException)->setModel(TeachingDraft::class, [$id]);
        }

        return $owned;
    }

    /** @return list<array<string, mixed>> */
    public function activeSummaries(User $user, int $limit = 20): array
    {
        return TeachingDraft::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->with('images:id,teaching_draft_id,level_code')
            ->latest('last_saved_at')
            ->limit($limit)
            ->get()
            ->map(function (TeachingDraft $draft): array {
                $payload = $draft->payload;
                $enabled = is_array($payload['enabled_level_codes'] ?? null)
                    ? array_values(array_intersect(['1', '2', '3'], $payload['enabled_level_codes']))
                    : ['1', '2', '3'];
                $totalLevels = count($enabled) ?: 3;
                $levels = collect($payload['levels'] ?? [])->only($enabled);
                $completed = $levels->filter(fn ($entry, $levelCode): bool => is_array($entry)
                    && trim((string) ($entry['subject'] ?? '')) !== ''
                    && (trim((string) ($entry['class_group_code'] ?? '')) !== '' || trim((string) ($entry['class_name'] ?? '')) !== '')
                    && trim((string) ($entry['result'] ?? '')) !== ''
                    && $this->attendanceReady($entry)
                    && in_array($draft->images->where('level_code', $levelCode)->count(), [0, 2, 4], true))
                    ->count();

                return [
                    'id' => $draft->id,
                    'lock_version' => $draft->lock_version,
                    'last_saved_at' => $draft->last_saved_at?->toISOString(),
                    'scope' => [...($payload['scope'] ?? []), 'tambon' => $draft->tambon],
                    'progress' => ['completed_levels' => $completed, 'total_levels' => $totalLevels],
                    'active_level_code' => $payload['active_level_code'] ?? '1',
                    'source_record_id' => $draft->source_record_id,
                    'images_count' => $draft->images->count(),
                ];
            })->values()->all();
    }

    private function attendanceReady(array $entry): bool
    {
        if (($entry['attendance_mode'] ?? 'counts') !== 'roster') {
            return Validator::make(['total' => $entry['total'] ?? 0, 'present' => $entry['present'] ?? 0], [
                'total' => ['required', 'integer', 'between:0,10000'],
                'present' => ['required', 'integer', 'between:0,10000', 'lte:total'],
            ])->passes();
        }
        $valid = Validator::make($entry, [
            'attendance' => ['required', 'array', 'min:1', 'max:2000'],
            'attendance.*.student_id' => ['required', 'string', 'max:50', 'distinct'],
            'attendance.*.full_name' => ['required', 'string', 'max:255'],
            'attendance.*.present' => ['required', 'boolean'],
            'attendance.*.learning_method' => ['nullable', 'in:พบกลุ่ม,ออนไลน์'],
        ])->passes();

        return $valid && collect($entry['attendance'])->every(static fn (array $row): bool => empty($row['group_code']) || empty($entry['class_group_code']) || $row['group_code'] === $entry['class_group_code']);
    }

    public function assertVersion(User $user, TeachingDraft|string $draft, int $lockVersion): TeachingDraft
    {
        $owned = $this->owned($user, $draft)->load('images');
        if ($owned->lock_version !== $lockVersion) {
            throw new DraftVersionConflictException($owned);
        }

        return $owned;
    }

    /** @return array<string, mixed> */
    public function resource(TeachingDraft $draft): array
    {
        $draft->loadMissing('images');

        return [
            'id' => $draft->id,
            'lock_version' => $draft->lock_version,
            'schema_version' => $draft->schema_version,
            'status' => $draft->status,
            'scope' => [
                'tambon' => $draft->tambon,
                'week' => $draft->week,
                'semester' => $draft->semester,
                'year' => $draft->year,
            ],
            'payload' => $draft->payload,
            'images' => $draft->images->map(fn (DraftImage $image): array => $this->imageResource($image))->values()->all(),
            'last_saved_at' => $draft->last_saved_at?->toISOString(),
            'source_record_id' => $draft->source_record_id,
            'source_revision' => $draft->source_revision,
            'source_level_codes' => $this->sourceLevelCodes($draft),
            'finalized_submission_id' => $draft->finalized_submission_id,
            'finalize_key' => $draft->finalize_key,
            'finalized_at' => $draft->finalized_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    public function imageResource(DraftImage $image): array
    {
        return [
            'id' => $image->id,
            'level_code' => $image->level_code,
            'url' => route('teaching-drafts.images.show', [$image->teaching_draft_id, $image]),
            'original_name' => $image->original_name,
            'mime_type' => $image->mime_type,
            'width' => $image->width,
            'height' => $image->height,
            'size_bytes' => $image->size_bytes,
            'sort_order' => $image->sort_order,
        ];
    }

    /** @param array<string, mixed> $document */
    public function snapshotForPreview(User $user, TeachingDraft|string $draft, string $levelCode, array $document = [], ?int $lockVersion = null): array
    {
        return DB::transaction(function () use ($user, $draft, $levelCode, $document, $lockVersion): array {
            $owned = $lockVersion === null
                ? $this->owned($user, $draft)->load('images')
                : $this->lockOwned($user, $draft, $lockVersion)->load('images');

            return $this->buildPreviewSnapshot($user, $owned, $levelCode, $document);
        });
    }

    /** @param array<string, mixed> $document @return array<string, mixed> */
    private function buildPreviewSnapshot(User $user, TeachingDraft $owned, string $levelCode, array $document): array
    {
        if (! in_array($levelCode, EducationLevelService::enabledCodes($owned->payload), true)) {
            throw ValidationException::withMessages(['level_code' => 'ระดับชั้นนี้ไม่ได้เลือกสอนในสัปดาห์นี้']);
        }
        $entry = $owned->payload['levels'][$levelCode] ?? null;
        if (! is_array($entry)) {
            throw ValidationException::withMessages(['level_code' => 'ไม่พบข้อมูลระดับชั้นในร่าง']);
        }
        $scope = $owned->payload['scope'];
        $record = new TeachingRecord([
            ...$entry,
            'id' => 'draft-'.$owned->id,
            'tambon' => $scope['tambon'],
            'week' => $scope['week'],
            'date' => $scope['date'],
            'semester' => $scope['semester'],
            'year' => $scope['year'],
            'level' => EducationLevelService::NAMES[$levelCode],
            'created_by' => $user->id,
        ]);
        $attendance = ($entry['attendance_mode'] ?? 'counts') === 'roster'
            ? array_values($entry['attendance'] ?? []) : [];
        if (($entry['attendance_mode'] ?? 'counts') === 'roster') {
            $record->total = count($attendance);
            $record->present = collect($attendance)->where('present', true)->count();
        }
        $images = $owned->images->where('level_code', $levelCode)->sortBy('sort_order')->values();
        $record->setRelation('images', $images);
        $documentPayload = array_replace_recursive(
            is_array($entry['document'] ?? null) ? $entry['document'] : [],
            $document,
        );
        $content = $this->documentOptions->content($record, []);
        $settings = $this->documentOptions->validate($record, [
            'settings' => $this->documentOptions->settings(
                is_array($documentPayload['settings'] ?? null) ? $documentPayload['settings'] : [],
                $images->pluck('id')->all(),
                $scope['tambon'],
            ),
            'content' => $content,
        ])['settings'];
        $teacherName = $user->name;
        if ($owned->source_record_id) {
            $source = TeachingRecord::query()->visibleTo($user)->find($owned->source_record_id);
            $sourceLevel = $source?->weekly_submission_id
                ? TeachingRecord::query()->where('weekly_submission_id', $source->weekly_submission_id)
                    ->where('level', EducationLevelService::NAMES[$levelCode])->first()
                : $source;
            if ($sourceLevel && $sourceLevel->level === EducationLevelService::NAMES[$levelCode]) {
                $teacherName = User::query()->whereKey($sourceLevel->created_by)->value('name') ?? $teacherName;
            }
        }

        $snapshot = [
            'schema_version' => 1,
            'record' => [...$record->attributesToArray(), 'tambon_name' => Tambon::query()->whereKey($scope['tambon'])->value('name') ?? '', 'teacher_name' => $teacherName],
            'images' => $images->map(fn (DraftImage $image) => [
                'id' => $image->id,
                'file_path' => Storage::disk($image->storage_disk)->path($image->storage_path),
            ])->values()->all(),
            'attendance' => $attendance,
            'settings' => array_merge($this->documentOptions->legacySettings($scope['tambon'], $user), $settings),
            'content' => $content,
        ];

        return app(BoundDocumentService::class)->attachDraftSnapshot($snapshot, $owned->id, $levelCode);
    }

    /** @param array<string, mixed> $payload */
    public function normalizePayload(array $payload, User $user): array
    {
        $scope = is_array($payload['scope'] ?? null) ? $payload['scope'] : [];
        $tambon = $user->isDistrict() ? trim((string) ($scope['tambon'] ?? '')) : (string) $user->tambon;
        $normalizedLevels = [];
        foreach ((array) ($payload['levels'] ?? []) as $key => $entry) {
            if (! is_array($entry) || ! ($code = $this->levels->code($key))) {
                continue;
            }
            $normalizedLevels[$code] = collect($entry)->only([
                'subject', 'subject_code', 'class_group_code', 'class_name', 'total', 'present',
                'result', 'problem', 'suggestion', 'supervisor_comment', 'attendance_reviewed', 'attendance_mode',
                'attendance', 'image_ids', 'document',
            ])->all();
            $normalizedLevels[$code]['attendance'] = collect($entry['attendance'] ?? [])
                ->filter(fn ($row): bool => is_array($row))
                ->map(fn (array $row): array => [
                    'student_id' => trim((string) ($row['student_id'] ?? '')),
                    'full_name' => trim((string) ($row['full_name'] ?? '')),
                    'group_code' => trim((string) ($row['group_code'] ?? '')),
                    'class_name' => trim((string) ($row['class_name'] ?? '')),
                    'present' => (bool) ($row['present'] ?? false),
                    'learning_method' => ! empty($row['present']) ? ($row['learning_method'] ?? 'พบกลุ่ม') : null,
                ])->values()->all();
            $normalizedLevels[$code]['attendance_reviewed'] = (bool) ($entry['attendance_reviewed'] ?? false);
            $normalizedLevels[$code]['document'] = [
                'settings' => collect($entry['document']['settings'] ?? [])->only(RecordDocumentOptions::SETTING_KEYS)->all(),
            ];
        }

        $enabledLevelCodes = EducationLevelService::enabledCodes($payload);

        return [
            'schema_version' => 1,
            'scope' => [
                'tambon' => $tambon,
                'week' => isset($scope['week']) ? (int) $scope['week'] : null,
                'date' => trim((string) ($scope['date'] ?? '')) ?: null,
                'semester' => trim((string) ($scope['semester'] ?? '')) ?: null,
                'year' => trim((string) ($scope['year'] ?? '')) ?: null,
            ],
            'levels' => collect($normalizedLevels)->sortKeys()->all(),
            'enabled_level_codes' => $enabledLevelCodes,
            'active_level_code' => in_array($this->levels->code($payload['active_level_code'] ?? null), $enabledLevelCodes, true)
                ? $this->levels->code($payload['active_level_code']) : $enabledLevelCodes[0],
        ];
    }

    private function lockOwned(User $user, TeachingDraft|string $draft, int $lockVersion): TeachingDraft
    {
        $id = $draft instanceof TeachingDraft ? $draft->id : $draft;
        $owned = TeachingDraft::query()->whereKey($id)->where('user_id', $user->id)->lockForUpdate()->first();
        if (! $owned) {
            throw (new ModelNotFoundException)->setModel(TeachingDraft::class, [$id]);
        }
        if ($owned->status !== 'active') {
            throw ValidationException::withMessages(['draft' => 'ร่างนี้ยืนยันแล้วและไม่สามารถแก้ไขได้']);
        }
        if ($owned->lock_version !== $lockVersion) {
            throw new DraftVersionConflictException($owned->load('images'));
        }

        return $owned;
    }

    /** @return list<string> */
    private function sourceLevelCodes(TeachingDraft $draft): array
    {
        if (! $draft->source_record_id) {
            return [];
        }
        $source = TeachingRecord::query()->find($draft->source_record_id);
        if (! $source) {
            return [];
        }
        $names = $source->weekly_submission_id
            ? TeachingRecord::query()->where('weekly_submission_id', $source->weekly_submission_id)->pluck('level')->all()
            : [$source->level];

        return array_values(array_filter(array_map(fn ($name) => $this->levels->code($name), $names)));
    }

    private function touchLockedDraft(TeachingDraft $draft): void
    {
        $draft->forceFill(['lock_version' => $draft->lock_version + 1, 'last_saved_at' => now()])->save();
    }

    private function throwConflict(User $user, string $draftId): never
    {
        throw new DraftVersionConflictException($this->owned($user, $draftId)->load('images'));
    }

    private function normalizeImageOrder(TeachingDraft $draft, string $levelCode): void
    {
        foreach ($draft->images()->where('level_code', $levelCode)->orderBy('sort_order')->get() as $position => $image) {
            $image->update(['sort_order' => $position]);
        }
    }

    private function payloadFromRecord(TeachingRecord $source): array
    {
        $records = $source->weekly_submission_id
            ? TeachingRecord::query()->where('weekly_submission_id', $source->weekly_submission_id)->orderBy('id')->lockForUpdate()->with(['attendance', 'documentLayout'])->get()
            : collect([$source->load(['attendance', 'documentLayout'])]);
        $levels = [];
        foreach ($records as $record) {
            $code = $this->levels->code($record->level);
            if (! $code) {
                continue;
            }
            $levels[$code] = [
                ...$record->only(['subject', 'subject_code', 'class_group_code', 'class_name', 'total', 'present', 'result', 'problem', 'suggestion', 'supervisor_comment']),
                'attendance_reviewed' => true,
                'attendance_mode' => $record->attendance->isNotEmpty() ? 'roster' : 'counts',
                'attendance' => $record->attendance->map(fn ($row) => $row->only(['student_id', 'full_name', 'group_code', 'class_name', 'present', 'learning_method']))->all(),
                'image_ids' => [],
                'document' => ['settings' => $record->documentLayout?->settings ?? [], 'content' => []],
            ];
        }

        $enabledLevelCodes = array_values(array_unique(array_filter(array_map(fn ($r) => $this->levels->code($r->level), $records->all()))));
        if (empty($enabledLevelCodes)) {
            $enabledLevelCodes = ['1', '2', '3'];
        }
        foreach (['1', '2', '3'] as $c) {
            if (! isset($levels[$c])) {
                $levels[$c] = [
                    'subject' => '', 'subject_code' => '', 'class_group_code' => '', 'class_name' => '',
                    'total' => 0, 'present' => 0, 'result' => '', 'problem' => '', 'suggestion' => '',
                    'supervisor_comment' => '', 'attendance_reviewed' => false, 'attendance_mode' => 'roster',
                    'attendance' => [], 'image_ids' => [], 'document' => ['settings' => [], 'content' => []],
                ];
            }
        }

        return [
            'schema_version' => 1,
            'scope' => [
                'tambon' => $source->tambon,
                'week' => $source->week,
                'date' => $source->date?->format('Y-m-d'),
                'semester' => $source->semester,
                'year' => $source->year,
            ],
            'levels' => collect($levels)->sortKeys()->all(),
            'enabled_level_codes' => $enabledLevelCodes,
            'active_level_code' => $this->levels->code($source->level) ?? $enabledLevelCodes[0] ?? '1',
        ];
    }

    public function sourceFingerprint(TeachingRecord $source): string
    {
        $records = TeachingRecord::query()
            ->when($source->weekly_submission_id,
                fn ($query) => $query->where('weekly_submission_id', $source->weekly_submission_id),
                fn ($query) => $query->whereKey($source->id))
            ->with(['attendance', 'images', 'documentLayout'])->orderBy('id')->get();
        $snapshot = $records->map(fn (TeachingRecord $record): array => [
            'record' => $record->attributesToArray(),
            'attendance' => $record->attendance->sortBy('student_id')->values()->toArray(),
            'images' => $record->images->sortBy('id')->values()->toArray(),
            'document' => $record->documentLayout?->toArray(),
        ])->all();

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $storedPaths */
    private function importSourceImages(TeachingDraft $draft, TeachingRecord $source, array &$storedPaths): void
    {
        $records = $source->weekly_submission_id
            ? TeachingRecord::query()->where('weekly_submission_id', $source->weekly_submission_id)->with('images')->get()
            : collect([$source->load('images')]);
        $payload = $draft->payload;
        foreach ($records as $record) {
            $code = $this->levels->code($record->level);
            if (! $code) {
                continue;
            }
            foreach ($record->images->take(4) as $position => $sourceImage) {
                $sourcePath = $this->resolveRecordImagePath($sourceImage->file_path);
                if (! $sourcePath || ! is_file($sourcePath)) {
                    throw ValidationException::withMessages(['source_record_id' => 'ไม่พบไฟล์รูปภาพเดิมบางรายการ กรุณาตรวจสอบไฟล์ก่อนแก้ไขบันทึก']);
                }
                $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'jpg';
                $storagePath = "teaching-drafts/{$draft->user_id}/{$draft->id}/".Str::uuid().'.'.$extension;
                if (! Storage::disk('local')->put($storagePath, (string) file_get_contents($sourcePath))) {
                    throw ValidationException::withMessages(['source_record_id' => 'คัดลอกรูปเข้าร่างไม่สำเร็จ กรุณาลองใหม่']);
                }
                $storedPaths[] = $storagePath;
                $dimensions = @getimagesize($sourcePath) ?: [0, 0];
                $image = $draft->images()->create([
                    'level_code' => $code,
                    'storage_disk' => 'local',
                    'storage_path' => $storagePath,
                    'original_name' => basename($sourcePath),
                    'mime_type' => mime_content_type($sourcePath) ?: 'image/jpeg',
                    'width' => (int) $dimensions[0],
                    'height' => (int) $dimensions[1],
                    'size_bytes' => filesize($sourcePath) ?: 0,
                    'sort_order' => $position,
                ]);
                $payload['levels'][$code]['image_ids'][] = $image->id;
                $settings = $payload['levels'][$code]['document']['settings'] ?? [];
                $layouts = json_decode((string) ($settings['image_layout_by_id'] ?? '{}'), true) ?: [];
                if (isset($layouts[(string) $sourceImage->id])) {
                    $layouts[$image->id] = $layouts[(string) $sourceImage->id];
                    unset($layouts[(string) $sourceImage->id]);
                    $payload['levels'][$code]['document']['settings']['image_layout_by_id'] = json_encode($layouts, JSON_THROW_ON_ERROR);
                }
            }
        }
        $draft->update(['payload' => $payload]);
    }

    private function resolveRecordImagePath(string $storedPath): ?string
    {
        if (str_starts_with($storedPath, 'storage/')) {
            return Storage::disk('public')->path(substr($storedPath, 8));
        }

        $legacy = dirname(base_path()).'/'.ltrim($storedPath, '/');
        $public = public_path(ltrim($storedPath, '/'));

        return is_file($legacy) ? $legacy : (is_file($public) ? $public : null);
    }
}
