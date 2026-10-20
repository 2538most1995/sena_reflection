<?php

namespace App\Http\Controllers;

use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Services\DraftService;
use App\Services\EducationLevelService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RecordCreateController extends Controller
{
    public function create(Request $request, StudentDirectoryController $students, EducationLevelService $levels, DraftService $drafts): Response
    {
        $options = $this->options($request, $students, $levels);
        $data = $options['data'];
        $draft = null;
        if ($request->filled('draft')) {
            $draft = $drafts->resource($drafts->owned($request->user(), (string) $request->query('draft'))->load('images'));
        }

        return Inertia::render('records/create', [
            'groups' => $data['groups'],
            'subjects' => $data['subjects'],
            'tambonOptions' => $request->user()->isDistrict() ? Tambon::query()->orderBy('name')->get(['id', 'name']) : [],
            'studentSource' => $data['source'],
            'studentError' => $data['error_code'] ? $options['message'] : null,
            'defaults' => ['year' => (string) ((int) date('Y') + 543), 'semester' => '1', 'date' => now()->format('Y-m-d')],
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
        $user = $request->user();
        $targetTambon = $user->isDistrict() ? trim((string) $request->query('tambon', '')) : (string) $user->tambon;
        if ($targetTambon !== '' && ! Tambon::query()->whereKey($targetTambon)->exists()) {
            throw ValidationException::withMessages(['tambon' => 'ไม่พบตำบลที่เลือก']);
        }

        $payload = $this->rememberSuccessful(
            'record-options:groups:v3:'.hash('sha256', implode('|', [$user->id, $targetTambon])),
            fn () => $students->legacyPayload(
                Request::create('/record-options/groups', 'GET', ['view' => 'groups', 'limit' => 1000, 'status' => 'active']),
                $user,
                $targetTambon ?: null,
                true,
            ),
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

        $subjects = TeachingRecord::query()->visibleTo($user)
            ->when($user->isDistrict() && $targetTambon !== '', fn ($query) => $query->where('tambon', $targetTambon))
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
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $error = $payload['error'] ?? null;

        return [
            'status' => $error ? 'error' : 'success',
            'message' => $error ?: 'โหลดตัวเลือกสำหรับบันทึกแล้ว',
            'data' => [
                'groups' => $groups,
                'subjects' => $subjects,
                'source' => (string) (($meta['source'] ?? null) ?: 'student_api'),
                'fetched_at' => (string) (($meta['fetched_at'] ?? null) ?: now()->toISOString()),
                'stale' => (bool) ($meta['stale'] ?? false),
                'error_code' => $payload['error_code'] ?? null,
            ],
        ];
    }

    public function students(Request $request, StudentDirectoryController $directory): JsonResponse
    {
        $filters = $request->validate([
            'group_code' => ['required', 'string', 'max:50'],
            'level' => ['required', 'in:1,2,3'],
            'tambon' => ['nullable', 'string', 'max:20'],
        ]);
        $user = $request->user();
        $targetTambon = $user->isDistrict() ? trim((string) ($filters['tambon'] ?? '')) : (string) $user->tambon;
        if ($targetTambon !== '' && ! Tambon::query()->whereKey($targetTambon)->exists()) {
            throw ValidationException::withMessages(['tambon' => 'ไม่พบตำบลที่เลือก']);
        }
        $cacheKey = 'record-create:students:v3:'.hash('sha256', implode('|', [$user->id, $targetTambon, $filters['group_code'], $filters['level']]));
        $payload = $this->rememberSuccessful($cacheKey, fn () => $directory->legacyPayload(
            Request::create('/record-options/students', 'GET', ['class_group' => $filters['group_code'], 'level' => $filters['level'], 'limit' => 1000, 'status' => 'active']),
            $user,
            $targetTambon ?: null,
            true,
        ));
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
    private function rememberSuccessful(string $key, Closure $callback): array
    {
        $cached = Cache::get($key);
        if (is_array($cached) && empty($cached['error']) && empty($cached['meta']['stale'])) {
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
