<?php

namespace App\Http\Controllers;

use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\AcademicTermService;
use App\Services\EducationLevelService;
use App\Services\StudentApiService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class StudentDirectoryController extends Controller
{
    public function __construct(private readonly EducationLevelService $levels, private readonly StudentApiService $api, private readonly AcademicTermService $terms) {}

    public function legacyPayload(Request $request, User $user, ?string $scopeTambon = null, bool $allPages = false): array
    {
        $allowed = ['page', 'limit', 'q', 'search', 'student_id', 'id', 'level', 'class_group', 'group', 'status', 'view', 'district_id', 'district_filter', 'include_source', 'year', 'semester', 'term'];
        $query = collect($request->only($allowed))->filter(fn ($value) => is_scalar($value) && $value !== '')->map(fn ($value) => (string) $value)->all();
        $query['limit'] ??= '1000';
        $query['status'] ??= 'active';
        try {
            [$items, $meta] = $this->api->fetch($query, $allPages, $request->boolean('refresh'));
            $items = $this->filterRawForUser($items, $user, $scopeTambon)
                ->map(fn ($row) => $this->normalizeExternalRow($this->removeSensitiveKeys($row), $query['level'] ?? null))
                ->filter()
                ->when(isset($query['level']) && $query['level'] !== 'all', fn ($items) => $items->where('level_code', $query['level']))
                ->values();

            $selectedTerm = $this->terms->fromFilters($query);
            if ($selectedTerm !== null && $items->isEmpty() && ($meta['latest_term'] ?? null) !== $selectedTerm) {
                $latestTerm = $meta['latest_term'] ?? null;

                return [
                    'items' => [],
                    'meta' => [...$meta, 'source' => 'student_api', 'fetched_at' => now()->toISOString(), 'stale' => false],
                    'error' => $latestTerm !== null
                        ? 'แหล่งข้อมูลยังไม่มีรายชื่อนักศึกษาภาคเรียน '.$selectedTerm.' (ข้อมูลล่าสุด '.$latestTerm.') กรุณาตรวจสอบการเชื่อมต่อ SDL School'
                        : 'แหล่งข้อมูลไม่ระบุภาคเรียนของรายชื่อ จึงยังใช้กับภาคเรียน '.$selectedTerm.' ไม่ได้ กรุณาตรวจสอบการเชื่อมต่อ SDL School',
                    'error_code' => 'student_term_unavailable',
                ];
            }

            return [
                'items' => $items->all(),
                'meta' => [...$meta, 'source' => 'student_api', 'fetched_at' => now()->toISOString(), 'stale' => false],
                'error' => null,
                'error_code' => null,
            ];
        } catch (\Throwable $exception) {
            Log::warning('Legacy Student API compatibility request failed', ['exception' => $exception::class]);
        }

        $filters = ['q' => $query['q'] ?? $query['search'] ?? null, 'level' => $query['level'] ?? 'all', 'term' => $this->terms->fromFilters($query)];
        $rows = $this->localAttendanceRows($user, $filters, $scopeTambon);
        if (($query['view'] ?? '') === 'groups') {
            $rows = $rows->groupBy(fn ($row) => implode('|', [$row['group_code'], $row['class_name'], $row['level']]))
                ->map(fn ($members) => [...$members->first(), 'student_count' => $members->count()])->values();
        } elseif (! empty($query['class_group'])) {
            $needle = $this->normalize($query['class_group']);
            $rows = $rows->filter(fn ($row) => $this->normalize($row['group_code']) === $needle || $this->normalize($row['class_name']) === $needle)->values();
        }

        return $rows->isNotEmpty()
            ? ['items' => $rows->all(), 'meta' => ['source' => 'local_attendance', 'selected_term' => $filters['term'], 'latest_term' => $rows->pluck('current_term')->sortByDesc(fn (string $term): string => substr($term, 2).$term[0])->first(), 'total' => $rows->count(), 'fetched_at' => now()->toISOString(), 'stale' => true], 'error' => null, 'error_code' => null]
            : ['items' => [], 'meta' => ['source' => 'unavailable', 'fetched_at' => now()->toISOString(), 'stale' => true], 'error' => 'ไม่สามารถดึงข้อมูลนักศึกษาได้ในขณะนี้ และยังไม่มีข้อมูลสำรองในระบบ', 'error_code' => 'student_source_unavailable'];
    }

    public function __invoke(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'level' => ['nullable', 'in:all,1,2,3'],
            'year' => ['nullable', 'regex:/^25\d{2}$/'],
            'semester' => ['nullable', 'in:1,2,3'],
            'term' => ['nullable', 'regex:/^[123]\/25\d{2}$/'],
            'refresh' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();
        $error = null;
        $notice = null;
        $source = 'student_api';
        $studentMeta = [];

        try {
            [$rawRows, $studentMeta] = $this->api->fetch(['limit' => 1000, 'status' => 'active', ...$filters], true, $request->boolean('refresh'));
            $rows = $rawRows->map(fn (array $row) => $this->safeRow($row));
            $rows = $this->filterForUser($rows, $user);

            if ($rows->isEmpty() && $this->terms->fromFilters($filters) !== null) {
                $notice = 'ไม่พบรายชื่อนักศึกษาสำหรับภาคเรียน '.$this->terms->fromFilters($filters).' จากแหล่งข้อมูลที่ตั้งค่าไว้';
            }

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

        if ($source === 'local_attendance' && $rows->isNotEmpty()) {
            $studentMeta = ['driver' => 'local_attendance', 'latest_term' => $rows->first()['current_term'] ?? null];
        }
        $filters['term'] = $this->terms->fromFilters($filters) ?? ($studentMeta['latest_term'] ?? null);
        $items = $this->paginate($rows, $request);

        return Inertia::render('students/index', compact('items', 'filters', 'error', 'notice', 'source', 'studentMeta'));
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
            'current_term' => $this->terms->normalize($row['current_term'] ?? $row['academic_term'] ?? null),
            '_source_driver' => $row['_source_driver'] ?? null,
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
        $strictUsernames = $scopeUsers->pluck('username')->push($user->isDistrict() ? '' : $user->username)
            ->map(fn ($value) => mb_strtolower(trim((string) $value)))->filter()->all();
        $usernames = $scopeUsers->pluck('username')->push($user->isDistrict() ? '' : $user->username)->map(fn ($value) => $this->normalize((string) $value))->filter()->all();
        $names = $scopeUsers->pluck('name')->push($user->isDistrict() ? '' : $user->name)->map(fn ($value) => $this->normalize((string) $value))->filter()->all();
        $tambonName = $this->normalize((string) Tambon::query()->whereKey($targetTambon)->value('name'));

        return $rows->filter(function (array $row) use ($targetTambon, $usernames, $strictUsernames, $names, $tambonName) {
            foreach (['tambon', 'tambon_id', 'tambon_code', 'subdistrict_id'] as $key) {
                if (! empty($row[$key])) {
                    return (string) $row[$key] === (string) $targetTambon;
                }
            }
            $teachers = is_array($row['teachers'] ?? null) ? $row['teachers'] : [];
            foreach ($teachers as $teacher) {
                if (! is_array($teacher)) {
                    continue;
                }
                $usernameMatches = ($row['_source_driver'] ?? null) === 'sdl'
                    ? in_array(mb_strtolower(trim((string) ($teacher['username'] ?? ''))), $strictUsernames, true)
                    : in_array($this->normalize((string) ($teacher['username'] ?? '')), $usernames, true);
                if ($usernameMatches || (($row['_source_driver'] ?? null) !== 'sdl'
                    && in_array($this->normalize((string) ($teacher['name'] ?? '')), $names, true))) {
                    return true;
                }
            }
            if (($row['_source_driver'] ?? null) === 'sdl') {
                return false;
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
        $query = TeachingRecord::query()->visibleTo($user)
            ->when($user->isDistrict() && $scopeTambon, fn ($query) => $query->where('records.tambon', $scopeTambon));
        $term = $this->terms->fromFilters($filters);
        if ($term === null) {
            $latest = (clone $query)->whereHas('attendance')->orderByDesc('year')->orderByDesc('semester')->first(['year', 'semester']);
            $term = $latest ? $this->terms->normalize($latest->semester.'/'.$latest->year) : null;
        }
        if ($term !== null) {
            [$semester, $year] = explode('/', $term);
            $query->where('records.semester', $semester)->where('records.year', $year);
        }

        return $query
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
            ->select(['attendance.student_id', 'attendance.full_name', 'attendance.group_code', 'records.class_name', 'records.level', 'records.year', 'records.semester'])
            ->orderBy('attendance.full_name')
            ->get()
            ->unique(fn ($row) => $row->student_id.'|'.$row->group_code)
            ->map(function ($row): array {
                $level = $this->levels->fields($row->level);

                return [
                    'student_id' => $row->student_id,
                    'current_term' => $row->semester.'/'.$row->year,
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
