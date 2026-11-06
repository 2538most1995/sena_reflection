<?php

namespace App\Http\Controllers;

use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Services\AcademicTermService;
use App\Services\ActiveAcademicTermService;
use App\Services\DraftService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ActiveAcademicTermService $activeTerms, DraftService $draftService): Response
    {
        $filters = $request->validate([
            'year' => ['nullable', 'string', 'max:10'],
            'semester' => ['nullable', 'in:1,2,3'],
            'week' => ['nullable', 'integer', 'between:1,60'],
            'tambon' => ['nullable', 'string', 'max:20'],
            'search' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', 'in:current,selected,all'],
        ]);

        $activeTerm = $activeTerms->resolve();
        if (($filters['scope'] ?? null) === 'all') {
            unset($filters['year'], $filters['semester']);
        } elseif (! $request->filled('year') && ! $request->filled('semester')) {
            $filters = [...$filters, 'year' => $activeTerm['year'], 'semester' => $activeTerm['semester'], 'scope' => 'current'];
        } else {
            $filters['scope'] = ($filters['year'] ?? null) === $activeTerm['year'] && ($filters['semester'] ?? null) === $activeTerm['semester'] && ($filters['scope'] ?? null) === 'current' ? 'current' : 'selected';
        }

        $user = $request->user();
        $base = TeachingRecord::query()->visibleTo($user);
        $this->applyFilters($base, $filters, $user->isDistrict());

        $periods = (clone $base)->select(['year', 'semester', 'week'])
            ->selectRaw('COUNT(*) AS record_count, SUM(total) AS total_students, SUM(present) AS total_present')
            ->groupBy('year', 'semester', 'week');
        $summary = DB::query()->fromSub($periods, 'visible_periods')->selectRaw(
            'COALESCE(SUM(record_count), 0) AS record_count, COUNT(*) AS week_count, COALESCE(SUM(total_students), 0) AS total_students, COALESCE(SUM(total_present), 0) AS total_present'
        )->first();
        $uniquePresentStudents = DB::table('record_attendance')
            ->whereIn('record_id', (clone $base)->select('id'))
            ->where('present', true)
            ->whereRaw("TRIM(student_id) <> ''")
            ->distinct()
            ->count(DB::raw('TRIM(student_id)'));

        $records = (clone $base)
            ->select(['id', 'weekly_submission_id', 'tambon', 'week', 'date', 'level', 'semester', 'year', 'subject', 'subject_code', 'total', 'present', 'created_by', 'created_at'])
            ->with(['tambonRelation:id,name'])
            ->withCount('images')
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->paginate(30, total: (int) $summary->record_count)
            ->appends(array_filter($filters, static fn ($value): bool => $value !== null && $value !== ''))
            ->through(function (TeachingRecord $record): array {
                $payload = $record->toArray();
                $payload['date'] = $record->date?->format('Y-m-d');

                return $payload;
            });
        $records->setCollection($records->getCollection()->values());

        $availableWeeks = TeachingRecord::query()->visibleTo($user)
            ->when($filters['year'] ?? null, fn (Builder $q, $year) => $q->where('year', $year))
            ->when($filters['semester'] ?? null, fn (Builder $q, $semester) => $q->where('semester', $semester))
            ->select('week')->distinct()->orderBy('week')->pluck('week');

        // This list is intentionally not cached. It is small, while the legacy
        // application used the generic "tambons:list" key for a different shape.
        $tambonOptions = $user->isDistrict()
            ? Tambon::query()->orderBy('name')->get(['id', 'name'])
            : collect();

        $summaryPayload = [
            'record_count' => (int) ($summary->record_count ?? 0),
            'week_count' => (int) ($summary->week_count ?? 0),
            'total_students' => (int) ($summary->total_students ?? 0),
            'total_present' => (int) ($summary->total_present ?? 0),
            'unique_present_students' => $uniquePresentStudents,
        ];
        $olderPeriods = TeachingRecord::query()->visibleTo($user)->select('year', 'semester')->selectRaw('COUNT(*) AS records')
            ->groupBy('year', 'semester')->get()->filter(function ($period) use ($activeTerm): bool {
                return app(AcademicTermService::class)->normalize($period->semester.'/'.$period->year) !== null
                    && [(int) $period->year, (int) $period->semester] < [(int) $activeTerm['year'], (int) $activeTerm['semester']];
            });

        $drafts = $draftService->activeSummaries($user, 6, $filters);

        return Inertia::render('dashboard', [
            'records' => $records,
            'summary' => $summaryPayload,
            'availableWeeks' => $availableWeeks,
            'tambonOptions' => $tambonOptions,
            'filters' => $filters,
            'activeTerm' => $activeTerm,
            'olderRecordCount' => (int) $olderPeriods->sum('records'),
            'drafts' => $drafts,
            'weeklyDefaults' => [
                'year' => $activeTerm['year'],
                'semester' => $activeTerm['semester'],
                'date' => now()->format('Y-m-d'),
            ],
        ]);
    }

    private function applyFilters(Builder $query, array $filters, bool $district): void
    {
        $query
            ->when($filters['year'] ?? null, fn (Builder $q, $value) => $q->where('year', $value))
            ->when($filters['semester'] ?? null, fn (Builder $q, $value) => $q->where('semester', $value))
            ->when($filters['week'] ?? null, fn (Builder $q, $value) => $q->where('week', $value))
            ->when($district && ($filters['tambon'] ?? null), fn (Builder $q) => $q->where('tambon', $filters['tambon']))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $escaped = addcslashes($search, '%_\\');
                $q->where(fn (Builder $inner) => $inner
                    ->where('subject', 'like', "%{$escaped}%")
                    ->orWhere('subject_code', 'like', "{$escaped}%"));
            });
    }
}
