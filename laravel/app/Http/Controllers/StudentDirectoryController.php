<?php

namespace App\Http\Controllers;

use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\EducationLevelService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class StudentDirectoryController extends Controller
{
    public function __construct(private readonly EducationLevelService $levels) {}

    public function legacyPayload(Request $request, User $user, ?string $scopeTambon = null, bool $allPages = false): array
    {
        $allowed = ['page', 'limit', 'q', 'search', 'student_id', 'id', 'level', 'class_group', 'group', 'status', 'view', 'district_id', 'district_filter', 'include_source'];
        $query = collect($request->only($allowed))->filter(fn ($value) => is_scalar($value) && $value !== '')->map(fn ($value) => (string) $value)->all();
        $query['limit'] ??= '1000';
        $query['status'] ??= 'active';
        $legacy = is_file(base_path('../config/student_api.php')) ? require base_path('../config/student_api.php') : [];
        $primaryUrl = trim((string) env('SENA_STUDENT_API_URL', $legacy['url'] ?? ''));
        $key = trim((string) env('SENA_STUDENT_API_KEY', $legacy['key'] ?? ''));

        if ($primaryUrl !== '' && $key !== '') {
            try {
                [$items, $meta] = $this->requestAllRows($primaryUrl, $key, $query, $allPages);
                $items = $this->filterRawForUser($items, $user, $scopeTambon)
                    ->map(fn ($row) => $this->normalizeExternalRow($this->removeSensitiveKeys($row), $query['level'] ?? null))
                    ->filter()
                    ->when(isset($query['level']) && $query['level'] !== 'all', fn ($items) => $items->where('level_code', $query['level']))
                    ->values();

                return [
                    'items' => $items->all(),
                    'meta' => [...$meta, 'source' => 'student_api', 'fetched_at' => now()->toISOString(), 'stale' => false],
                    'error' => null,
                    'error_code' => null,
                ];
            } catch (\Throwable $exception) {
                Log::warning('Legacy Student API compatibility request failed', ['exception' => $exception::class]);
            }
        }

        $filters = ['q' => $query['q'] ?? $query['search'] ?? null, 'level' => $query['level'] ?? 'all'];
        $rows = $this->localAttendanceRows($user, $filters, $scopeTambon);
        if (($query['view'] ?? '') === 'groups') {
            $rows = $rows->groupBy(fn ($row) => implode('|', [$row['group_code'], $row['class_name'], $row['level']]))
                ->map(fn ($members) => [...$members->first(), 'student_count' => $members->count()])->values();
        } elseif (! empty($query['class_group'])) {
            $needle = $this->normalize($query['class_group']);
            $rows = $rows->filter(fn ($row) => $this->normalize($row['group_code']) === $needle || $this->normalize($row['class_name']) === $needle)->values();
        }

        return $rows->isNotEmpty()
            ? ['items' => $rows->all(), 'meta' => ['source' => 'local_attendance', 'total' => $rows->count(), 'fetched_at' => now()->toISOString(), 'stale' => true], 'error' => null, 'error_code' => null]
            : ['items' => [], 'meta' => ['source' => 'unavailable', 'fetched_at' => now()->toISOString(), 'stale' => true], 'error' => 'ไม่สามารถดึงข้อมูลนักศึกษาได้ในขณะนี้ และยังไม่มีข้อมูลสำรองในระบบ', 'error_code' => 'student_source_unavailable'];
    }

    public function __invoke(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'level' => ['nullable', 'in:all,1,2,3'],
        ]);
        $user = $request->user();
        $error = null;
        $notice = null;
        $source = 'student_api';

        try {
            $rows = $this->fetchApiRows($filters);
            $rows = $this->filterForUser($rows, $user);

            if ($rows->isEmpty() && ! $user->isDistrict()) {
                $fallback = $this->localAttendanceRows($user, $filters);
                if ($fallback->isNotEmpty()) {
                    $rows = $fallback;
                    $source = 'local_attendance';
                    $notice = 'ไม่พบข้อมูลที่ตรงกับบัญชีจาก Student API จึงแสดงข้อมูลสำรองจากบันทึกการเข้าเรียน';
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('Student directory API unavailable', ['exception' => $exception::class]);
            $rows = $this->localAttendanceRows($user, $filters);
            $source = 'local_attendance';
            if ($rows->isNotEmpty()) {
                $notice = 'Student API ไม่ตอบสนอง ขณะนี้กำลังแสดงข้อมูลสำรองจากบันทึกการเข้าเรียน';
            } else {
                $error = 'ไม่สามารถดึงข้อมูลนักศึกษาได้ในขณะนี้ และยังไม่มีข้อมูลสำรองในระบบ';
            }
        }

        $items = $this->paginate($rows, $request);

        return Inertia::render('students/index', compact('items', 'filters', 'error', 'notice', 'source'));
    }

    private function fetchApiRows(array $filters): Collection
    {
        $legacy = is_file(base_path('../config/student_api.php')) ? require base_path('../config/student_api.php') : [];
        $primaryUrl = trim((string) env('SENA_STUDENT_API_URL', $legacy['url'] ?? ''));
        $key = trim((string) env('SENA_STUDENT_API_KEY', $legacy['key'] ?? ''));
        $extraUrls = array_filter(array_map('trim', explode(',', (string) env('SENA_STUDENT_API_FALLBACK_URLS', ''))));
        $urls = array_values(array_unique(array_filter([$primaryUrl, ...$extraUrls])));

        if ($urls === [] || $key === '') {
            throw new RuntimeException('Student API is not configured');
        }

        $params = ['limit' => 1000, 'status' => 'active'];
        if (! empty($filters['q'])) {
            $params['q'] = $filters['q'];
        }
        if (! empty($filters['level']) && $filters['level'] !== 'all') {
            $params['level'] = $filters['level'];
        }

        $cacheKey = 'students:v3:'.hash('sha256', json_encode([$urls, $params]));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return collect($cached);
        }

        $lastException = null;
        foreach ($urls as $url) {
            try {
                [$rawRows] = $this->requestAllRows($url, $key, $params);
                $rows = $rawRows->map(fn (array $row) => $this->safeRow($row))->values();
                Cache::put($cacheKey, $rows->all(), 300);

                return $rows;
            } catch (\Throwable $exception) {
                $lastException = $exception;
            }
        }

        throw $lastException ?? new RuntimeException('Student API unavailable');
    }

    /** @param array<string, mixed> $params @return array{Collection, array<string, mixed>} */
    private function requestAllRows(string $url, string $key, array $params, bool $allPages = true): array
    {
        $rows = collect();
        $page = $allPages ? 1 : max(1, (int) ($params['page'] ?? 1));
        $lastPage = $page;
        $pagesFetched = 0;
        $meta = [];
        do {
            $response = Http::acceptJson()->withHeader('X-API-Key', $key)
                ->connectTimeout(3)->timeout(12)
                ->retry(2, 250, fn ($exception) => $exception instanceof ConnectionException)
                ->get($url, [...$params, 'page' => $page, 'api_key' => $key]);
            $payload = $response->json();
            if (! $response->successful() || ! is_array($payload)
                || (array_key_exists('success', $payload) && ! $payload['success'])) {
                throw new RuntimeException('Student API returned an invalid response');
            }
            $pageRows = $this->extractRows($payload);
            $pageMeta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
            $dataMeta = is_array($payload['data'] ?? null) && ! array_is_list($payload['data']) ? $payload['data'] : [];
            $pagination = is_array($pageMeta['pagination'] ?? null) ? $pageMeta['pagination']
                : (is_array($payload['pagination'] ?? null) ? $payload['pagination'] : [...$dataMeta, ...$pageMeta]);
            if ($pagesFetched === 0) {
                $meta = $pageMeta;
            }
            if ($allPages && $pagesFetched === 0) {
                $total = (int) ($pagination['total'] ?? $payload['total'] ?? 0);
                $perPage = max(1, (int) ($pagination['per_page'] ?? $pagination['limit'] ?? $params['limit'] ?? 1000));
                $lastPage = max(1, (int) ($pagination['last_page'] ?? $pagination['total_pages'] ?? ceil($total / $perPage)));
                if ($lastPage > 50) {
                    throw new RuntimeException('Student API exceeded supported page count');
                }
            }
            if ($allPages && $pageRows->isEmpty() && $lastPage > 1) {
                throw new RuntimeException('Student API returned an incomplete page');
            }
            $rows = $rows->concat($pageRows);
            $pagesFetched++;
            $page++;
        } while ($page <= $lastPage);

        return [$rows->values(), [...$meta, 'pages_fetched' => $pagesFetched]];
    }

    private function extractRows(array $payload): Collection
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $candidates = [
            $payload['data'] ?? null,
            $payload['items'] ?? null,
            $payload['students'] ?? null,
            $payload['result'] ?? null,
            $data['items'] ?? null,
            $data['students'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && array_is_list($candidate)) {
                return collect($candidate)->filter(fn ($row) => is_array($row))->values();
            }
        }

        throw new RuntimeException('Student API response does not contain a list');
    }

    private function safeRow(array $row): array
    {
        $fullName = trim((string) ($row['full_name'] ?? ''));
        if ($fullName === '') {
            $fullName = trim(implode(' ', array_filter([
                $row['prename'] ?? null,
                $row['first_name'] ?? null,
                $row['last_name'] ?? null,
            ])));
        }

        $level = $this->levels->fields($row['level_code'] ?? $row['level'] ?? $row['level_name'] ?? null);

        return [
            'student_id' => (string) ($row['student_id'] ?? $row['id'] ?? ''),
            'full_name' => $fullName,
            'group_code' => (string) ($row['group_code'] ?? ''),
            'class_name' => (string) ($row['class_name'] ?? ''),
            'level' => $level['level'] ?? '',
            'level_code' => $level['level_code'] ?? '',
            'level_name' => $level['level_name'] ?? '',
            'teacher_name' => (string) ($row['teacher_name'] ?? ''),
            'tambon' => (string) ($row['tambon'] ?? $row['tambon_id'] ?? $row['tambon_code'] ?? $row['subdistrict_id'] ?? ''),
            'teachers' => collect(is_array($row['teachers'] ?? null) ? $row['teachers'] : [])->filter(fn ($teacher) => is_array($teacher))->map(fn ($teacher) => [
                'username' => (string) ($teacher['username'] ?? ''),
                'name' => (string) ($teacher['name'] ?? ''),
            ])->values()->all(),
            'student_count' => (int) ($row['student_count'] ?? (is_array($row['students'] ?? null) ? count($row['students']) : 0)),
        ];
    }

    private function filterForUser(Collection $rows, User $user): Collection
    {
        return $this->filterRawForUser($rows, $user)->values();
    }

    private function filterRawForUser(Collection $rows, User $user, ?string $scopeTambon = null): Collection
    {
        if ($user->isDistrict() && ! $scopeTambon) {
            return $rows;
        }
        $targetTambon = $user->isDistrict() ? $scopeTambon : $user->tambon;
        $scopeUsers = User::query()->where('tambon', $targetTambon)->get(['username', 'name']);
        $usernames = $scopeUsers->pluck('username')->push($user->isDistrict() ? '' : $user->username)->map(fn ($value) => $this->normalize((string) $value))->filter()->all();
        $names = $scopeUsers->pluck('name')->push($user->isDistrict() ? '' : $user->name)->map(fn ($value) => $this->normalize((string) $value))->filter()->all();
        $tambonName = $this->normalize((string) Tambon::query()->whereKey($targetTambon)->value('name'));

        return $rows->filter(function (array $row) use ($targetTambon, $usernames, $names, $tambonName) {
            foreach (['tambon', 'tambon_id', 'tambon_code', 'subdistrict_id'] as $key) {
                if (! empty($row[$key])) {
                    return (string) $row[$key] === (string) $targetTambon;
                }
            }
            $teachers = is_array($row['teachers'] ?? null) ? $row['teachers'] : [];
            foreach ($teachers as $teacher) {
                if (is_array($teacher) && (in_array($this->normalize((string) ($teacher['username'] ?? '')), $usernames, true)
                    || in_array($this->normalize((string) ($teacher['name'] ?? '')), $names, true))) {
                    return true;
                }
            }
            if (in_array($this->normalize((string) ($row['teacher_name'] ?? '')), $names, true)) {
                return true;
            }

            return $tambonName !== '' && str_contains($this->normalize((string) ($row['class_name'] ?? '')), $tambonName);
        });
    }

    private function removeSensitiveKeys(array $row): array
    {
        foreach (array_keys($row) as $key) {
            if (preg_match('/password|passwd|secret|api[_-]?key|access[_-]?token/i', (string) $key)) {
                unset($row[$key]);
            } elseif (is_array($row[$key])) {
                $row[$key] = array_is_list($row[$key])
                    ? array_map(fn ($value) => is_array($value) ? $this->removeSensitiveKeys($value) : $value, $row[$key])
                    : $this->removeSensitiveKeys($row[$key]);
            }
        }

        return $row;
    }

    private function localAttendanceRows(User $user, array $filters, ?string $scopeTambon = null): Collection
    {
        return TeachingRecord::query()->visibleTo($user)
            ->when($user->isDistrict() && $scopeTambon, fn ($query) => $query->where('records.tambon', $scopeTambon))
            ->join('record_attendance as attendance', 'attendance.record_id', '=', 'records.id')
            ->when(! empty($filters['q']), function ($query) use ($filters) {
                $search = addcslashes($filters['q'], '%_\\');
                $query->where(fn ($inner) => $inner->where('attendance.full_name', 'like', "%{$search}%")
                    ->orWhere('attendance.student_id', 'like', "{$search}%")
                    ->orWhere('records.class_name', 'like', "%{$search}%"));
            })
            ->when(! empty($filters['level']) && $filters['level'] !== 'all', function ($query) use ($filters) {
                $level = EducationLevelService::NAMES[$filters['level']];
                $query->where('records.level', $level);
            })
            ->select(['attendance.student_id', 'attendance.full_name', 'attendance.group_code', 'records.class_name', 'records.level'])
            ->orderBy('attendance.full_name')
            ->get()
            ->unique(fn ($row) => $row->student_id.'|'.$row->group_code)
            ->map(function ($row): array {
                $level = $this->levels->fields($row->level);

                return [
                    'student_id' => $row->student_id,
                    'full_name' => $row->full_name,
                    'group_code' => $row->group_code ?? '',
                    'class_name' => $row->class_name ?? '',
                    'level' => $level['level'] ?? '',
                    'level_code' => $level['level_code'] ?? '',
                    'level_name' => $level['level_name'] ?? '',
                    'teacher_name' => '', 'teachers' => [], 'student_count' => 0,
                ];
            })->values();
    }

    /** @return array<string, mixed>|null */
    private function normalizeExternalRow(array $row, mixed $fallbackLevel = null): ?array
    {
        $level = $this->levels->fields($row['level_code'] ?? $row['level'] ?? $row['level_name'] ?? $fallbackLevel);
        if ($level === null) {
            return $row;
        }

        return [...$row, ...$level];
    }

    private function paginate(Collection $rows, Request $request): LengthAwarePaginator
    {
        $perPage = 50;
        $page = max(1, (int) $request->query('page', 1));

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    }

    private function normalize(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[\s\p{P}\p{S}]+/u', '', trim($value)));
    }
}
