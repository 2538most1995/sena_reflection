<?php

namespace App\Services;

use App\Exceptions\DraftVersionConflictException;
use App\Models\TeachingDraft;
use App\Models\TeachingRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DraftLifecycleService
{
    public function __construct(private readonly DraftService $drafts) {}

    /** @return list<array<string, mixed>> */
    public function summaries(User $user): array
    {
        return TeachingDraft::query()->where('user_id', $user->id)->whereIn('status', ['active', 'paused'])
            ->select(['id', 'status', 'lock_version', 'tambon', 'year', 'semester', 'week', 'last_saved_at', 'source_record_id', 'updated_at'])
            ->withCount('images')->latest('last_saved_at')->orderByDesc('id')->get()
            ->map(fn (TeachingDraft $draft): array => $this->summary($draft))->all();
    }

    /** @return array<string, mixed> */
    public function summary(TeachingDraft $draft): array
    {
        if (! array_key_exists('images_count', $draft->getAttributes())) {
            $draft->loadCount('images');
        }

        return ['id' => $draft->id, 'status' => $draft->status, 'lock_version' => $draft->lock_version,
            'scope' => ['year' => $draft->year, 'semester' => $draft->semester, 'week' => $draft->week, 'tambon' => $draft->tambon],
            'source_record_id' => $draft->source_record_id, 'last_saved_at' => $draft->last_saved_at?->toIso8601String(),
            'images_count' => $draft->images_count];
    }

    public function pause(User $user, TeachingDraft|string $draft, int $lockVersion): TeachingDraft
    {
        return $this->change($user, $draft, $lockVersion, 'active', 'paused');
    }

    public function resume(User $user, TeachingDraft|string $draft, int $lockVersion): TeachingDraft
    {
        return $this->change($user, $draft, $lockVersion, 'paused', 'active');
    }

    private function change(User $user, TeachingDraft|string $draft, int $lockVersion, string $from, string $to): TeachingDraft
    {
        return DB::transaction(function () use ($user, $draft, $lockVersion, $from, $to): TeachingDraft {
            $id = $draft instanceof TeachingDraft ? $draft->id : $draft;
            $owned = TeachingDraft::query()->whereKey($id)->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
            if ($owned->lock_version !== $lockVersion) {
                throw new DraftVersionConflictException($owned);
            }
            if ($owned->status !== $from) {
                $this->fail($owned->status === 'finalized' ? 'ร่างนี้ยืนยันเป็นบันทึกแล้ว จึงพักหรือนำกลับไม่ได้' : 'สถานะร่างเปลี่ยนไปแล้ว กรุณาโหลดรายการใหม่');
            }
            if ($to === 'active') {
                $this->assertCanResume($user, $owned);
            }
            $owned->forceFill(['status' => $to, 'lock_version' => $owned->lock_version + 1])->save();

            return $owned;
        }, 3);
    }

    private function assertCanResume(User $user, TeachingDraft $draft): void
    {
        if ($draft->source_record_id) {
            $source = TeachingRecord::query()->visibleTo($user)->whereKey($draft->source_record_id)->lockForUpdate()->first();
            if (! $source) {
                $this->fail('บันทึกต้นฉบับยังไม่พร้อมใช้งาน หากเก็บภาคเรียนไปแล้ว ให้นำชุดสำรองกลับก่อนนำร่างกลับมาแก้ไข');
            }
            if (! hash_equals((string) ($draft->payload['source_fingerprint'] ?? ''), $this->drafts->sourceFingerprint($source))) {
                throw new DraftVersionConflictException($draft, 'ต้นฉบับเปลี่ยนหลังเปิดร่างนี้ ร่างที่พักไว้ยังอยู่ กรุณาเปิดบันทึกฉบับล่าสุดก่อนแก้ไข', 'source_record_conflict');
            }

            return;
        }

        $archived = DB::table('semester_backups')->where('year', $draft->year)->where('semester', $draft->semester)
            ->whereIn('status', ['archived', 'offline'])->whereNotNull('archived_at')->lockForUpdate()->first(['id']);
        if ($archived && ! TeachingRecord::query()->where('year', $draft->year)->where('semester', $draft->semester)->lockForUpdate()->first(['id'])) {
            $this->fail('ภาคเรียนของร่างนี้ถูกเก็บออกจากระบบแล้ว ให้นำชุดสำรองภาคเรียนกลับก่อน เพื่อไม่ให้ข้อมูลเก่าถูกสร้างซ้ำ');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['draft' => $message]);
    }
}
