<?php

namespace App\Http\Controllers;

use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Services\AcademicTermService;
use App\Services\ActiveAcademicTermService;
use App\Services\DraftService;
use App\Services\EducationLevelService;
use App\Services\StudentApiService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RecordCreateController extends Controller
{
    public function create(Request $request, StudentDirectoryController $students, EducationLevelService $levels, DraftService $drafts): Response|RedirectResponse
    {
        $draft = null;
        if ($request->filled('draft')) {
            $owned = $drafts->owned($request->user(), (string) $request->query('draft'));
            if ($owned->status === 'paused') {
                return redirect()->route('teaching-drafts.manage')->with('error', 'ร่างนี้พักไว้ กรุณานำร่างกลับมาแก้ไขจากหน้าจัดการร่าง');
            }
            $draft = $drafts->resource($owned->load('images'));
        }

        $optionsRequest = $draft ? $request->duplicate([...$request->query(), ...$draft['scope']]) : $request;
        $options = $this->options($optionsRequest, $students, $levels);
        $data = $options['data'];
        $terms = app(AcademicTermService::class);
        $defaultTerm = $terms->fromFilters($draft['scope'] ?? $request->query())
            ?? (app(StudentApiService::class)->isSdl() ? app(ActiveAcademicTermService::class)->resolve()['term'] : $terms->normalize($data['latest_term'] ?? null))
            ?? app(ActiveAcademicTermService::class)->resolve()['term'];

        return Inertia::render('records/create', [
            'groups' => $data['groups'],
            'subjects' => $data['subjects'],
            'subjectSource' => $data['subject_source'],
            'subjectError' => $data['subject_error'],
            'subjectTerm' => $data['subject_term'],
            'tambonOptions' => $request->user()->isDistrict() ? Tambon::query()->orderBy('name')->get(['id', 'name']) : [],
            'studentSource' => $data['source'],
            'studentError' => $data['error_code'] ? $options['message'] : null,
            'defaults' => [...$terms->defaults($defaultTerm), 'date' => now()->format('Y-m-d')],
            'workspace' => [
                'mode' => $draft && $draft['source_record_id'] ? 'edit' : 'create',
                'draft' => $draft,
                'submission' => null,
                'activeRecordId' => $draft['source_record_id'] ?? null,
            ],
        ]);
    }

    /** @return array{status: string, message: string, data: array<string, mixed>} */
    public function options(Request $request, StudentDirectoryController $students, EducationLevelService $levels): array
    {
        $filters = $request->validate([
            'year' => ['nullable', 'required_with:semester', 'regex:/^25\d{2}$/'],
            'semester' => ['nullable', 'required_with:year', 'in:1,2,3'],
            'term' => ['nullable', 'regex:/^[123]\/25\d{2}$/'],
            'refresh' => ['nullable', 'boolean'],
        ]);
        $term = app(AcademicTermService::class)->fromFilters($filters)
            ?? (app(StudentApiService::class)->isSdl() ? app(StudentApiService::class)->latestKnownTerm() : null);
        $user = $request->user();
        $targetTambon = $user->isDistrict() ? trim((string) $request->query('tambon', '')) : (string) $user->tambon;
        if ($targetTambon !== '' && ! Tambon::query()->whereKey($targetTambon)->exists()) {
            throw ValidationException::withMessages(['tambon' => 'ไม่พบตำบลที่เลือก']);
        }

        $payload = $this->rememberSuccessful(
            'record-options:groups:v4:'.hash('sha256', implode('|', [app(StudentApiService::class)->fingerprint(), $user->id, $targetTambon, $term ?? 'latest'])),
            fn () => $students->legacyPayload(
                Request::create('/record-options/groups', 'GET', ['view' => 'groups', 'limit' => 1000, 'status' => 'active', 'term' => $term, 'refresh' => $request->boolean('refresh')]),
                $user,
                $targetTambon ?: null,
                true,
            ),
            $request->boolean('refresh'),
        );
        $groups = collect($payload['items'] ?? [])->map(function (array $row) use ($levels): ?array {
            $level = $levels->fields($row['level_code'] ?? $row['level'] ?? $row['level_name'] ?? null);
            if ($level === null) {
                return null;
            }

            return [
                'group_code' => trim((string) ($row['group_code'] ?? '')),
                'class_name' => trim((string) ($row['class_name'] ?? '')),
                ...$level,
                'student_count' => isset($row['student_count']) ? (int) $row['student_count'] : null,
            ];
        })->filter(fn (?array $row) => $row !== null && ($row['group_code'] !== '' || $row['class_name'] !== ''))
            ->unique(fn (array $row) => implode('|', [$row['group_code'], $row['class_name'], $row['level_code']]))
            ->sortBy(fn (array $row) => $row['level_code'].'|'.$row['class_name'])->values();

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $api = app(StudentApiService::class);
        $subjectTerm = $term ?? app(AcademicTermService::class)->normalize($meta['latest_term'] ?? null);
        if ($api->isSdl() && $subjectTerm === null) {
            $subjectTerm = app(AcademicTermService::class)->normalize($api->status()['latest_term']);
        }
        $subjects = TeachingRecord::query()->visibleTo($user)
            ->when($user->isDistrict() && $targetTambon !== '', fn ($query) => $query->where('tambon', $targetTambon))
            ->when($api->isSdl(), function ($query) use ($subjectTerm): void {
                if ($subjectTerm === null) {
                    $query->whereIn('id', []);

                    return;
                }
                [$semester, $year] = explode('/', $subjectTerm);
                $query->where('semester', $semester)->where('year', $year);
            })
            ->whereNotNull('subject')->where('subject', '!=', '')
            ->select(['subject', 'subject_code', 'level'])->distinct()->orderBy('subject')->limit(200)->get()
            ->map(function (TeachingRecord $record) use ($levels): ?array {
                $level = $levels->fields($record->level);
                if ($level === null) {
                    return null;
                }

                return [
                    'subject' => trim((string) $record->subject),
                    'subject_code' => trim((string) $record->subject_code),
                    ...$level,
                    'source' => 'history',
                ];
            })->filter()->unique(fn (array $row) => $row['level_code'].'|'.$row['subject_code'].'|'.$row['subject'])->values();
        $subjectSource = $subjects->isNotEmpty() ? 'history' : 'unavailable';
        $subjectError = null;
        if ($api->isSdl()) {
            try {
                $catalogue = $api->subjects($subjectTerm ?? '', $request->boolean('refresh'));
                $catalogueKeys = $catalogue->map(fn (array $row): string => mb_strtolower($row['subject_code']).'|'.$row['level'])->all();
                $subjects = $catalogue->concat($subjects->reject(fn (array $row): bool => in_array(mb_strtolower($row['subject_code']).'|'.$row['level_code'], $catalogueKeys, true)))->values();
                $subjectSource = 'sdl';
                if ($catalogue->contains(fn (array $row): bool => $row['level'] === '')) {
                    $subjectError = 'SDL School ยังไม่ระบุระดับชั้นของรายวิชาบางรายการ จึงไม่แสดงวิชาที่ตรวจระดับไม่ได้ กรุณาอัปเดต API รายวิชา หรือเลือกประวัติของระดับชั้นนี้/กรอกรายวิชาเอง';
                }
            } catch (\Throwable $exception) {
                Log::warning('SDL School subject catalogue unavailable', ['exception' => $exception::class, 'term' => $subjectTerm]);
                $subjectError = 'โหลดรายวิชาจาก SDL School ไม่สำเร็จ'.($subjectTerm ? ' สำหรับภาคเรียน '.$subjectTerm : '').' สามารถเลือกจากประวัติภาคเรียนนี้หรือเพิ่มรายวิชาเองได้';
            }
        }
        $error = $payload['error'] ?? null;

        return [
            'status' => $error ? 'error' : 'success',
            'message' => $error ?: 'โหลดตัวเลือกสำหรับบันทึกแล้ว',
            'data' => [
                'groups' => $groups,
                'subjects' => $subjects,
                'subject_source' => $subjectSource,
                'subject_error' => $subjectError,
                'subject_term' => $subjectTerm,
                'source' => (string) (($meta['source'] ?? null) ?: 'student_api'),
                'fetched_at' => (string) (($meta['fetched_at'] ?? null) ?: now()->toISOString()),
                'stale' => (bool) ($meta['stale'] ?? false),
                'error_code' => $payload['error_code'] ?? null,
                'latest_term' => $meta['latest_term'] ?? null,
                'selected_term' => $term,
            ],
        ];
    }

    public function students(Request $request, StudentDirectoryController $directory): JsonResponse
    {
        $filters = $request->validate([
            'group_code' => ['required', 'string', 'max:50'],
            'level' => ['required', 'in:1,2,3'],
            'tambon' => ['nullable', 'string', 'max:20'],
            'year' => ['nullable', 'required_with:semester', 'regex:/^25\d{2}$/'],
            'semester' => ['nullable', 'required_with:year', 'in:1,2,3'],
            'term' => ['nullable', 'regex:/^[123]\/25\d{2}$/'],
            'refresh' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();
        $targetTambon = $user->isDistrict() ? trim((string) ($filters['tambon'] ?? '')) : (string) $user->tambon;
        if ($targetTambon !== '' && ! Tambon::query()->whereKey($targetTambon)->exists()) {
            throw ValidationException::withMessages(['tambon' => 'ไม่พบตำบลที่เลือก']);
        }
        $term = app(AcademicTermService::class)->fromFilters($filters);
        $cacheKey = 'record-create:students:v4:'.hash('sha256', implode('|', [app(StudentApiService::class)->fingerprint(), $user->id, $targetTambon, $filters['group_code'], $filters['level'], $term ?? 'latest']));
        $payload = $this->rememberSuccessful($cacheKey, fn () => $directory->legacyPayload(
            Request::create('/record-options/students', 'GET', ['class_group' => $filters['group_code'], 'level' => $filters['level'], 'limit' => 1000, 'status' => 'active', 'term' => $term, 'refresh' => $request->boolean('refresh')]),
            $user,
            $targetTambon ?: null,
            true,
        ), $request->boolean('refresh'));
        $items = collect($payload['items'] ?? [])->map(function (array $row): array {
            $fullName = trim((string) ($row['full_name'] ?? ''));
            if ($fullName === '') {
                $fullName = trim(implode(' ', array_filter([$row['prename'] ?? null, $row['first_name'] ?? null, $row['last_name'] ?? null])));
            }

            return [
                'student_id' => trim((string) ($row['student_id'] ?? $row['id'] ?? '')),
                'full_name' => $fullName,
                'group_code' => trim((string) ($row['group_code'] ?? '')),
                'class_name' => trim((string) ($row['class_name'] ?? '')),
            ];
        })->filter(fn (array $row) => $row['student_id'] !== '' && $row['full_name'] !== '')
            ->filter(fn (array $row) => $row['group_code'] === '' || $row['group_code'] === $filters['group_code'] || $row['class_name'] === $filters['group_code'])
            ->unique('student_id')->sortBy('full_name')->values();
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        return response()->json([
            'status' => $payload['error'] ? 'error' : 'success',
            'message' => $payload['error'] ?? 'โหลดรายชื่อนักศึกษาแล้ว',
            'data' => [
                'items' => $items,
                'source' => (string) (($meta['source'] ?? null) ?: 'student_api'),
                'fetched_at' => (string) (($meta['fetched_at'] ?? null) ?: now()->toISOString()),
                'stale' => (bool) ($meta['stale'] ?? false),
                'error_code' => $payload['error_code'] ?? null,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function rememberSuccessful(string $key, Closure $callback, bool $refresh = false): array
    {
        $cached = Cache::get($key);
        if (! $refresh && is_array($cached) && empty($cached['error']) && empty($cached['meta']['stale'])) {
            return $cached;
        }
        $payload = $callback();
        if (is_array($payload) && empty($payload['error']) && empty($payload['meta']['stale'])) {
            Cache::put($key, $payload, 300);
        }

        return is_array($payload) ? $payload : [
            'items' => [],
            'meta' => ['source' => 'unavailable', 'fetched_at' => now()->toISOString(), 'stale' => true],
            'error' => 'โหลดข้อมูลไม่สำเร็จ',
            'error_code' => 'student_source_unavailable',
        ];
    }
}
