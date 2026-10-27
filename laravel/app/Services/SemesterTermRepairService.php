<?php

namespace App\Services;

use App\Models\Document;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Models\WeeklySubmission;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SemesterTermRepairService
{
    public function preview(User $user, string $sourceYear, string $sourceSemester, string $targetYear, string $targetSemester): array
    {
        $this->validate($user, $sourceYear, $sourceSemester, $targetYear, $targetSemester);

        return $this->review($sourceYear, $sourceSemester, $targetYear, $targetSemester);
    }

    /** The verified original backup is retained even when a concurrent change cancels repair. */
    public function repair(User $user, string $sourceYear, string $sourceSemester, string $targetYear, string $targetSemester, string $fingerprint, callable $createBackup): array
    {
        $preview = $this->preview($user, $sourceYear, $sourceSemester, $targetYear, $targetSemester);
        $this->assertReady($preview, $fingerprint);
        $backup = $createBackup();
        if (! is_array($backup) || empty($backup['id']) || ($backup['year'] ?? null) !== $sourceYear || ($backup['semester'] ?? null) !== $sourceSemester) {
            $this->fail('ชุดสำรองก่อนแก้ไขไม่ตรงกับภาคเรียนต้นทาง จึงยังไม่แก้ข้อมูล');
        }

        return DB::transaction(function () use ($user, $sourceYear, $sourceSemester, $targetYear, $targetSemester, $fingerprint, $backup): array {
            $review = $this->review($sourceYear, $sourceSemester, $targetYear, $targetSemester, true);
            $this->assertReady($review, $fingerprint);
            $records = TeachingRecord::query()->where('year', $sourceYear)->where('semester', $sourceSemester)->orderBy('id')->lockForUpdate()->get();
            $oldParents = $records->pluck('weekly_submission_id')->filter()->unique()->all();
            $revisions = app(RecordRevisionService::class);
            foreach ($records as $record) {
                $revisions->capture($record, $user);
                $parent = WeeklySubmission::query()->where('year', $targetYear)->where('semester', $targetSemester)
                    ->where('tambon', $record->tambon)->where('week', $record->week)->lockForUpdate()->first();
                $parent ??= WeeklySubmission::query()->create([
                    'tambon' => $record->tambon, 'year' => $targetYear, 'semester' => $targetSemester,
                    'week' => $record->week, 'submitted_by' => $user->id, 'status' => 'incomplete',
                ]);
                $record->update(['year' => $targetYear, 'semester' => $targetSemester, 'weekly_submission_id' => $parent->id]);
                // Rebase only an existing bound editor; never seed a replacement template.
                if (Document::query()->where('binding_key', 'record:'.$record->id)->exists()) {
                    app(BoundDocumentService::class)->forRecord($user, $record->fresh());
                }
                $revisions->capture($record->fresh(), $user);
                $levels = $parent->records()->pluck('level')->map(fn ($level) => app(EducationLevelService::class)->code($level) ?? (string) $level)->unique();
                $parent->update(['status' => $levels->count() === 3 ? 'complete' : 'incomplete']);
            }
            // Only empty parents from the backed-up source period may be removed.
            WeeklySubmission::query()->whereIn('id', $oldParents)->where('year', $sourceYear)->where('semester', $sourceSemester)
                ->whereDoesntHave('records')->delete();

            return ['repaired' => $records->count(), 'record_ids' => $records->pluck('id')->all(), 'backup' => $backup,
                'source' => ['year' => $sourceYear, 'semester' => $sourceSemester], 'target' => ['year' => $targetYear, 'semester' => $targetSemester]];
        }, 3);
    }

    private function validate(User $user, string $sourceYear, string $sourceSemester, string $targetYear, string $targetSemester): void
    {
        abort_unless($user->isDistrict(), 403);
        Validator::make(compact('sourceYear', 'sourceSemester', 'targetYear', 'targetSemester'), [
            'sourceYear' => ['required', 'string', 'max:10', 'regex:~^[0-9/]+$~D'],
            'sourceSemester' => ['required', 'string', 'max:10', 'regex:~^[0-9/]+$~D'],
            'targetYear' => ['required', 'string', 'regex:/^25[0-9]{2}$/D'],
            'targetSemester' => ['required', 'string', 'in:1,2'],
        ], ['targetYear.regex' => 'กรุณาระบุปี พ.ศ. 4 หลัก เช่น 2569 โดยไม่ใส่เลขภาคเรียนในช่องปี'])->validate();
        if ($sourceYear === $targetYear && $sourceSemester === $targetSemester) {
            $this->fail('ภาคเรียนต้นทางและปลายทางเป็นรายการเดียวกัน');
        }
    }

    private function review(string $sourceYear, string $sourceSemester, string $targetYear, string $targetSemester, bool $lock = false): array
    {
        $source = $this->rows(DB::table('records')->where('year', $sourceYear)->where('semester', $sourceSemester), $lock);
        $groups = [];
        foreach ($source as $row) {
            $groups[$row['tambon'].'|'.$row['week']] = [(string) $row['tambon'], (int) $row['week']];
        }
        $affectedGroups = function (Builder $query) use ($groups): void {
            if ($groups === []) {
                $query->whereRaw('1 = 0');
            }
            foreach ($groups as [$tambon, $week]) {
                $query->orWhere(fn (Builder $part): Builder => $part->where('tambon', $tambon)->where('week', $week));
            }
        };
        // A malformed period may contain one record. Do not load or lock all text,
        // photos, attendance and documents of an unrelated 700-record destination.
        $targets = $this->rows(DB::table('records')->where('year', $targetYear)->where('semester', $targetSemester)->where($affectedGroups), $lock);
        $rows = [...$source, ...$targets];
        $ids = array_column($rows, 'id');
        $sourceIds = array_column($source, 'id');
        $parentIds = array_values(array_filter(array_column($rows, 'weekly_submission_id')));
        $parents = $this->rows(DB::table('weekly_submissions')->where(fn (Builder $query): Builder => $query
            ->where(fn (Builder $part): Builder => $part->where('year', $sourceYear)->where('semester', $sourceSemester))
            ->orWhere(fn (Builder $part): Builder => $part->where('year', $targetYear)->where('semester', $targetSemester)->where($affectedGroups))
            ->orWhereIn('id', $parentIds)), $lock);
        $drafts = $this->rows(DB::table('teaching_drafts')->where('status', 'active')->where(fn (Builder $query): Builder => $query
            ->where(fn (Builder $part): Builder => $part->where('year', $sourceYear)->where('semester', $sourceSemester))->orWhereIn('source_record_id', $ids)), $lock);
        $documents = $this->rows(DB::table('documents')->whereIn('binding_key', array_map(fn ($id): string => 'record:'.$id, $sourceIds)), $lock);
        $facts = ['records' => $rows, 'weekly_submissions' => $parents, 'drafts' => $drafts, 'documents' => $documents];
        foreach (['record_images', 'record_attendance', 'record_document_layouts', 'record_revisions'] as $table) {
            $facts[$table] = $this->rows(DB::table($table)->whereIn('record_id', $sourceIds), $lock);
        }
        foreach (['document_assets', 'document_versions'] as $table) {
            $facts[$table] = $this->rows(DB::table($table)->whereIn('document_id', array_column($documents, 'id')), $lock);
        }
        $exports = $this->rows(DB::table('export_jobs')->where(fn (Builder $query): Builder => $query->where('status', 'processing')
            ->orWhere(fn (Builder $part): Builder => $part->where('status', 'queued')->where('expires_at', '>', now()))), $lock);
        $facts['exports'] = array_values(array_filter($exports, fn (array $job): bool => array_intersect($ids, array_column(json_decode($job['manifest'], true) ?? [], 'record_id')) !== []));
        $blockers = [];
        if ($source === []) {
            $blockers[] = 'ไม่พบบันทึกในภาคเรียนต้นทาง อาจแก้ไขไปแล้ว กรุณาโหลดรายการใหม่';
        }
        $levels = app(EducationLevelService::class);
        $keys = [];
        foreach ($rows as $row) {
            $key = json_encode([(string) $row['tambon'], (int) $row['week'], $levels->code($row['level']) ?? trim((string) $row['level'])], JSON_THROW_ON_ERROR);
            $keys[$key][] = (string) $row['id'];
        }
        foreach ($source as $row) {
            $key = json_encode([(string) $row['tambon'], (int) $row['week'], $levels->code($row['level']) ?? trim((string) $row['level'])], JSON_THROW_ON_ERROR);
            if (count($keys[$key]) > 1) {
                $blockers[] = 'มีบันทึกตำบล สัปดาห์ และระดับชั้นเดียวกันในปลายทางหรือซ้ำในต้นทาง จึงย้ายทับไม่ได้';
            }
        }
        foreach ($drafts as $draft) {
            if (((string) $draft['year'] === $sourceYear && (string) $draft['semester'] === $sourceSemester) || in_array($draft['source_record_id'], $sourceIds, true)) {
                $blockers[] = 'ยังมีฉบับร่างต้นทางหรือร่างที่กำลังแก้บันทึกนี้ ให้เจ้าของจัดการร่างก่อนแก้ภาคเรียน';
            }
        }
        foreach ($facts['exports'] as $job) {
            if (array_intersect($sourceIds, array_column(json_decode($job['manifest'], true) ?? [], 'record_id')) !== []) {
                $blockers[] = 'มีงานส่งออกที่กำลังใช้บันทึกต้นทาง กรุณารอให้งานเสร็จก่อนแก้ภาคเรียน';
            }
        }
        $sourceParentIds = array_values(array_filter(array_column($source, 'weekly_submission_id')));
        foreach ($parents as $parent) {
            if (in_array($parent['id'], $sourceParentIds, true) && ((string) $parent['year'] !== $sourceYear || (string) $parent['semester'] !== $sourceSemester)) {
                $blockers[] = 'ชุดส่งงานต้นทางไม่ตรงกับภาคเรียนของบันทึก ต้องตรวจความสัมพันธ์ก่อนสำรองและแก้ไข';
            }
        }
        if (DB::table('records')->whereIn('weekly_submission_id', $sourceParentIds)->whereNotIn('id', $sourceIds)->exists()) {
            $blockers[] = 'ชุดส่งงานต้นทางยังมีบันทึกนอกภาคเรียนที่เลือก จึงยังแก้เป็นชุดไม่ได้';
        }
        $fingerprint = hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $blockers = array_values(array_unique($blockers));

        return ['source' => ['year' => $sourceYear, 'semester' => $sourceSemester], 'target' => ['year' => $targetYear, 'semester' => $targetSemester],
            'records' => count($source), 'record_ids' => $sourceIds, 'blockers' => $blockers, 'can_repair' => $blockers === [], 'fingerprint' => $fingerprint,
            'expected_confirmation' => 'MOVE '.$sourceSemester.'/'.$sourceYear.' -> '.$targetSemester.'/'.$targetYear];
    }

    private function rows(Builder $query, bool $lock): array
    {
        $rows = ($lock ? $query->lockForUpdate() : $query)->get()->map(static fn ($row): array => (array) $row)->all();
        usort($rows, static fn (array $left, array $right): int => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));

        return $rows;
    }

    private function assertReady(array $review, string $fingerprint): void
    {
        if (! hash_equals($review['fingerprint'], $fingerprint)) {
            $this->fail('ข้อมูลหรือเอกสารเปลี่ยนหลังตรวจ กรุณาตรวจรายการใหม่ก่อนแก้ภาคเรียน');
        }
        if (! $review['can_repair']) {
            $this->fail(implode(' ', $review['blockers']));
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['term_repair' => $message]);
    }
}
