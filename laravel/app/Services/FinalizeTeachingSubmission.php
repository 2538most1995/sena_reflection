<?php

namespace App\Services;

use App\Exceptions\DraftVersionConflictException;
use App\Models\RecordAttendance;
use App\Models\RecordDocumentLayout;
use App\Models\RecordImage;
use App\Models\Tambon;
use App\Models\TeachingDraft;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Models\WeeklySubmission;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinalizeTeachingSubmission
{
    public function __construct(
        private readonly DraftService $drafts,
        private readonly RecordDocumentOptions $documentOptions,
        private readonly RecordRevisionService $revisions,
    ) {}

    /** @return array{submission_id: string, record_ids: list<string>, redirect_url: string} */
    public function finalize(User $user, TeachingDraft $draft, int $lockVersion, string $idempotencyKey, ?array $reviewedLevelCodes = null, bool $confirmed = false): array
    {
        $owned = $this->drafts->owned($user, $draft)->load('images');
        if ($owned->status === 'finalized') {
            if (hash_equals((string) $owned->finalize_key, $idempotencyKey)) {
                return $this->result($owned);
            }
            throw ValidationException::withMessages(['draft' => 'ร่างนี้ยืนยันแล้ว']);
        }
        if ($owned->lock_version !== $lockVersion) {
            throw new DraftVersionConflictException($owned);
        }

        $copiedPaths = [];
        try {
            return DB::transaction(function () use ($user, $owned, $lockVersion, $idempotencyKey, $reviewedLevelCodes, $confirmed, &$copiedPaths): array {
                $lockedDraft = TeachingDraft::query()->whereKey($owned->id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
                if ($lockedDraft->status === 'finalized' && hash_equals((string) $lockedDraft->finalize_key, $idempotencyKey)) {
                    return $this->result($lockedDraft);
                }
                if ($lockedDraft->lock_version !== $lockVersion) {
                    throw new DraftVersionConflictException($lockedDraft->load('images'));
                }
                if ($lockedDraft->status !== 'active') {
                    throw ValidationException::withMessages(['draft' => 'ร่างนี้ยืนยันแล้ว']);
                }
                $payload = $lockedDraft->payload;
                $scope = $payload['scope'] ?? [];
                Validator::make(['payload' => ['scope' => $scope]], [
                    'payload.scope.week' => ['required', 'integer', 'between:1,20'],
                    'payload.scope.year' => AcademicTermService::yearRules(),
                    'payload.scope.semester' => ['required', 'in:1,2,3'],
                ])->validate();
                $entries = $this->validatedEntries($lockedDraft->load('images'), $payload, $confirmed);
                $tambon = $user->isDistrict() ? (string) ($scope['tambon'] ?? '') : (string) $user->tambon;
                if ($tambon === '' || ! Tambon::query()->whereKey($tambon)->exists()) {
                    throw ValidationException::withMessages(['payload.scope.tambon' => 'กรุณาเลือกตำบลที่ถูกต้อง']);
                }
                if ($tambon !== (string) ($scope['tambon'] ?? '')) {
                    throw ValidationException::withMessages(['payload.scope.tambon' => 'ตำบลของบัญชีเปลี่ยนแปลง กรุณาตรวจข้อมูลในร่างอีกครั้ง']);
                }
                foreach (['week', 'date', 'semester', 'year'] as $key) {
                    if (empty($scope[$key])) {
                        throw ValidationException::withMessages(["payload.scope.{$key}" => 'กรุณากรอกข้อมูลสัปดาห์ให้ครบถ้วน']);
                    }
                }

                $targetSubmission = WeeklySubmission::query()->where([
                    'tambon' => $tambon,
                    'week' => (int) $scope['week'],
                    'semester' => (string) $scope['semester'],
                    'year' => (string) $scope['year'],
                ])->lockForUpdate()->first();
                $sourceRecord = $lockedDraft->source_record_id
                    ? TeachingRecord::query()->visibleTo($user)->whereKey($lockedDraft->source_record_id)->lockForUpdate()->first()
                    : null;
                $sourceSubmission = $sourceRecord?->weekly_submission_id
                    ? WeeklySubmission::query()->whereKey($sourceRecord->weekly_submission_id)->lockForUpdate()->first()
                    : null;
                if ($targetSubmission && $sourceSubmission && $targetSubmission->id !== $sourceSubmission->id) {
                    throw ValidationException::withMessages(['payload.scope.week' => 'สัปดาห์นี้มีการยืนยันเรียบร้อยแล้ว']);
                }
                if ($sourceSubmission) {
                    $sourceSubmission->records()->lockForUpdate()->get();
                }
                if ($lockedDraft->source_record_id && (! $sourceRecord || ! hash_equals(
                    (string) ($payload['source_fingerprint'] ?? ''),
                    $this->drafts->sourceFingerprint($sourceRecord),
                ))) {
                    throw new DraftVersionConflictException(
                        $lockedDraft,
                        'บันทึกต้นฉบับถูกแก้ไขหลังเปิดร่างนี้ กรุณาเปิดฉบับล่าสุดก่อนยืนยันอีกครั้ง',
                        'source_record_conflict',
                    );
                }
                $submission = $sourceSubmission ?? $targetSubmission;
                if ($submission) {
                    $submission->update([
                        'tambon' => $tambon,
                        'week' => (int) $scope['week'],
                        'semester' => (string) $scope['semester'],
                        'year' => (string) $scope['year'],
                        'submitted_by' => $user->id,
                        'status' => 'incomplete',
                    ]);
                } else {
                    $submission = WeeklySubmission::create([
                        'tambon' => $tambon,
                        'week' => (int) $scope['week'],
                        'semester' => (string) $scope['semester'],
                        'year' => (string) $scope['year'],
                        'submitted_by' => $user->id,
                        'status' => 'incomplete',
                    ]);
                }

                $enabledCodes = EducationLevelService::enabledCodes($payload);
                if ($reviewedLevelCodes !== null && array_values(array_intersect(['1', '2', '3'], $reviewedLevelCodes)) !== $enabledCodes) {
                    throw ValidationException::withMessages(['reviewed_level_codes' => 'กรุณาตรวจ PDF ให้ครบระดับชั้นที่เลือก']);
                }

                $enabledNames = collect($enabledCodes)->map(fn ($c) => EducationLevelService::NAMES[$c])->all();
                if ($sourceSubmission && $submission->records()->whereNotIn('level', $enabledNames)->exists()) {
                    throw ValidationException::withMessages(['payload.enabled_level_codes' => 'ไม่สามารถปิดระดับชั้นที่มีบันทึกเดิมได้ ข้อมูลเดิมจะไม่ถูกลบ']);
                }
                if (! $sourceRecord && $submission->records()->whereIn('level', $enabledNames)->exists()) {
                    throw ValidationException::withMessages(['payload.scope.week' => 'ระดับชั้นที่เลือกมีบันทึกในสัปดาห์นี้แล้ว กรุณาเปิดรายการเดิมเพื่อแก้ไข']);
                }

                $recordIds = [];
                foreach ($enabledCodes as $levelCode) {
                    $levelName = EducationLevelService::NAMES[$levelCode];
                    $entry = $entries[$levelCode];
                    $reviewedSettings = $confirmed && $reviewedLevelCodes === null
                        ? null : Cache::get(DraftService::reviewCacheKey($lockedDraft->id, $lockVersion, $levelCode));
                    if ($reviewedLevelCodes !== null && ! is_array($reviewedSettings)) {
                        throw ValidationException::withMessages(['reviewed_level_codes' => 'ตัวอย่าง PDF หมดอายุหรือร่างเปลี่ยนแปลง กรุณาสร้างตัวอย่างใหม่ก่อนยืนยัน']);
                    }
                    $documentSnapshot = $this->drafts->snapshotForPreview($user, $lockedDraft, $levelCode, is_array($reviewedSettings) ? ['settings' => $reviewedSettings] : []);
                    $documentSettings = is_array($reviewedSettings) ? $reviewedSettings : $documentSnapshot['settings'];
                    $attendance = collect($entry['attendance'] ?? [])->unique('student_id')->values();
                    $isRoster = ($entry['attendance_mode'] ?? 'counts') === 'roster';
                    $recordData = [
                        'weekly_submission_id' => $submission->id,
                        'tambon' => $tambon,
                        'week' => (int) $scope['week'],
                        'date' => $scope['date'],
                        'level' => $levelName,
                        'semester' => (string) $scope['semester'],
                        'year' => (string) $scope['year'],
                        'subject' => trim((string) $entry['subject']),
                        'subject_code' => trim((string) ($entry['subject_code'] ?? '')),
                        'class_group_code' => trim((string) ($entry['class_group_code'] ?? '')),
                        'class_name' => trim((string) ($entry['class_name'] ?? '')),
                        'total' => $isRoster ? $attendance->count() : (int) ($entry['total'] ?? 0),
                        'present' => $isRoster ? $attendance->where('present', true)->count() : (int) ($entry['present'] ?? 0),
                        'result' => (string) ($entry['result'] ?? ''),
                        'problem' => (string) ($entry['problem'] ?? ''),
                        'suggestion' => (string) ($entry['suggestion'] ?? ''),
                        'supervisor_comment' => (string) ($entry['supervisor_comment'] ?? ''),
                        'created_by' => $user->id,
                    ];
                    $record = $submission->records()->where('level', $levelName)->oldest('created_at')->first();
                    if (! $record && $sourceRecord && ! $sourceRecord->weekly_submission_id && $sourceRecord->level === $levelName) {
                        $record = $sourceRecord;
                    }
                    if ($record) {
                        $recordData['created_by'] = $record->created_by;
                        $record->update($recordData);
                    } else {
                        $record = TeachingRecord::create([
                            ...$recordData,
                            'id' => 'r'.substr(str_replace('-', '', (string) Str::uuid()), 0, 19),
                        ]);
                    }

                    $record->attendance()->delete();
                    if ($isRoster && $attendance->isNotEmpty()) {
                        $attendanceRows = $attendance->map(fn (array $row): array => [
                            'record_id' => $record->id,
                            'student_id' => $row['student_id'],
                            'full_name' => $row['full_name'],
                            'group_code' => $row['group_code'] ?? null,
                            'class_name' => $row['class_name'] ?? null,
                            'present' => (bool) $row['present'],
                            'learning_method' => $row['present'] ? ($row['learning_method'] ?? 'พบกลุ่ม') : null,
                            'created_at' => now(),
                        ])->all();

                        RecordAttendance::insertAdaptively($attendanceRows);
                    }

                    $record->images()->delete();
                    $imageIdMap = [];
                    foreach ($lockedDraft->images()->where('level_code', $levelCode)->orderBy('sort_order')->get() as $image) {
                        $extension = pathinfo($image->storage_path, PATHINFO_EXTENSION) ?: 'jpg';
                        $targetPath = "records/{$scope['year']}/{$scope['week']}/".Str::uuid().'.'.$extension;
                        if (! Storage::disk('public')->put($targetPath, Storage::disk($image->storage_disk)->get($image->storage_path))) {
                            throw ValidationException::withMessages(["payload.levels.{$levelCode}.image_ids" => 'บันทึกไฟล์รูปภาพไม่สำเร็จ']);
                        }
                        $copiedPaths[] = $targetPath;
                        $recordImage = RecordImage::createWithAdaptiveKey([
                            'record_id' => $record->id,
                            'file_path' => 'storage/'.$targetPath,
                            'created_at' => now(),
                        ]);
                        $imageIdMap[$image->id] = (string) $recordImage->id;
                    }

                    $layouts = json_decode((string) ($documentSettings['image_layout_by_id'] ?? '{}'), true) ?: [];
                    $remapped = [];
                    foreach ($imageIdMap as $draftImageId => $recordImageId) {
                        if (isset($layouts[$draftImageId])) {
                            $remapped[$recordImageId] = $layouts[$draftImageId];
                        }
                    }
                    $documentSettings['image_layout_by_id'] = json_encode($remapped, JSON_THROW_ON_ERROR);
                    $record->unsetRelation('images');
                    $canonicalContent = $this->documentOptions->content($record);
                    $validatedDocument = $this->documentOptions->validate($record, [
                        'settings' => $this->documentOptions->settings($documentSettings, array_values($imageIdMap), $tambon),
                        'content' => $canonicalContent,
                    ]);
                    RecordDocumentLayout::query()->updateOrCreate(
                        ['record_id' => $record->id],
                        ['settings' => $validatedDocument['settings'], 'content' => [], 'updated_by' => $user->id],
                    );

                    app(BoundDocumentService::class)->transferDraftToRecord($user, $lockedDraft, $levelCode, $record);
                    $this->revisions->capture($record, $user, $documentSettings);
                    $recordIds[] = $record->id;
                }

                $submission->update(['status' => 'complete', 'submitted_by' => $user->id]);
                $lockedDraft->update([
                    'status' => 'finalized',
                    'finalize_key' => $idempotencyKey,
                    'finalized_submission_id' => $submission->id,
                    'finalized_at' => now(),
                    'lock_version' => $lockedDraft->lock_version + 1,
                    'last_saved_at' => now(),
                ]);

                return [
                    'submission_id' => $submission->id,
                    'record_ids' => $recordIds,
                    'redirect_url' => route('dashboard', [
                        'year' => $scope['year'],
                        'semester' => $scope['semester'],
                        'week' => $scope['week'],
                    ]),
                ];
            });
        } catch (QueryException $exception) {
            Storage::disk('public')->delete($copiedPaths);
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages(['payload.scope.week' => 'สัปดาห์นี้มีการยืนยันเรียบร้อยแล้ว']);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($copiedPaths);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $payload @return array<string, array<string, mixed>> */
    private function validatedEntries(TeachingDraft $draft, array $payload, bool $confirmed): array
    {
        $entries = is_array($payload['levels'] ?? null) ? $payload['levels'] : [];
        $enabledCodes = EducationLevelService::enabledCodes($payload);
        foreach ($enabledCodes as $levelCode) {
            $levelName = EducationLevelService::NAMES[$levelCode];
            if (! isset($entries[$levelCode]) || ! is_array($entries[$levelCode])) {
                throw ValidationException::withMessages(['payload.levels' => "กรุณากรอกข้อมูลระดับ{$levelName}"]);
            }
            $entry = $entries[$levelCode];
            if (is_array($entry['attendance'] ?? null)) {
                $entry['attendance'] = $this->drafts->normalizeAttendanceRows($entry['attendance']);
            }
            foreach (['class_group_code', 'class_name'] as $field) {
                $entry[$field] = trim((string) ($entry[$field] ?? ''));
            }
            if (trim((string) ($entry['subject'] ?? '')) === '') {
                throw ValidationException::withMessages(["payload.levels.{$levelCode}.subject" => 'กรุณาระบุรายวิชา']);
            }
            if (trim((string) ($entry['class_group_code'] ?? '')) === '' && trim((string) ($entry['class_name'] ?? '')) === '') {
                throw ValidationException::withMessages(["payload.levels.{$levelCode}.class_name" => 'กรุณาเลือกกลุ่มเรียน']);
            }
            if (trim((string) ($entry['result'] ?? '')) === '') {
                throw ValidationException::withMessages(["payload.levels.{$levelCode}.result" => 'กรุณาบันทึกผลการจัดการเรียนการสอน']);
            }
            $mode = $entry['attendance_mode'] ?? 'counts';
            if ($mode === 'roster' && ((! $confirmed && empty($entry['attendance_reviewed'])) || empty($entry['attendance']))) {
                throw ValidationException::withMessages(["payload.levels.{$levelCode}.attendance" => 'กรุณาตรวจรับรายชื่อผู้เรียนก่อนยืนยัน']);
            }
            if ($mode === 'counts') {
                Validator::make(['payload' => ['levels' => [$levelCode => ['total' => $entry['total'] ?? 0, 'present' => $entry['present'] ?? 0]]]], [
                    "payload.levels.{$levelCode}.total" => ['required', 'integer', 'between:0,10000'],
                    "payload.levels.{$levelCode}.present" => ['required', 'integer', 'between:0,10000', "lte:payload.levels.{$levelCode}.total"],
                ])->validate();
            }
            if ($mode === 'roster') {
                Validator::make(['payload' => ['levels' => [$levelCode => $entry]]], [
                    "payload.levels.{$levelCode}.attendance" => ['required', 'array', 'min:1', 'max:2000'],
                    "payload.levels.{$levelCode}.attendance.*.student_id" => ['required', 'string', 'max:50'],
                    "payload.levels.{$levelCode}.attendance.*.full_name" => ['required', 'string', 'max:255'],
                    "payload.levels.{$levelCode}.attendance.*.present" => ['required', 'boolean'],
                    "payload.levels.{$levelCode}.attendance.*.learning_method" => ['nullable', 'in:พบกลุ่ม,ออนไลน์'],
                ])->validate();
                $studentIds = [];
                foreach ($entry['attendance'] as $row) {
                    if (in_array($row['student_id'], $studentIds, true)) {
                        throw ValidationException::withMessages(["payload.levels.{$levelCode}.attendance" => 'มีรหัสนักศึกษาซ้ำในระดับชั้นเดียวกัน']);
                    }
                    $studentIds[] = $row['student_id'];
                    if (! empty($row['group_code']) && ! empty($entry['class_group_code'])
                        && $row['group_code'] !== $entry['class_group_code']) {
                        throw ValidationException::withMessages(["payload.levels.{$levelCode}.attendance" => 'รายชื่อผู้เรียนไม่ตรงกับกลุ่มที่เลือก กรุณาโหลดรายชื่อใหม่']);
                    }
                }
            }
            $images = $draft->images->where('level_code', $levelCode)->sortBy('sort_order')->values();
            if (! in_array($images->count(), [0, 2, 4], true)) {
                throw ValidationException::withMessages(["payload.levels.{$levelCode}.image_ids" => 'ภาพประกอบต้องมี 0, 2 หรือ 4 รูป']);
            }
            $payloadIds = array_values($entry['image_ids'] ?? []);
            if ($payloadIds !== $images->pluck('id')->all()) {
                throw ValidationException::withMessages(["payload.levels.{$levelCode}.image_ids" => 'รายการรูปในร่างไม่ตรงกับรูปที่บันทึกไว้']);
            }
            $entries[$levelCode] = [...$entry, 'attendance_mode' => $mode];
        }

        return $entries;
    }

    /** @return array{submission_id: string, record_ids: list<string>, redirect_url: string} */
    private function result(TeachingDraft $draft): array
    {
        $submission = WeeklySubmission::query()->whereKey($draft->finalized_submission_id)->firstOrFail();

        return [
            'submission_id' => $submission->id,
            'record_ids' => $submission->records()->whereIn('level', array_map(fn ($code) => EducationLevelService::NAMES[$code], EducationLevelService::enabledCodes($draft->payload)))->orderBy('level')->pluck('id')->all(),
            'redirect_url' => route('dashboard', [
                'year' => $submission->year,
                'semester' => $submission->semester,
                'week' => $submission->week,
            ]),
        ];
    }
}
