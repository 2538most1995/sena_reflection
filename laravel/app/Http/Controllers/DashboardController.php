<?php

namespace App\Http\Controllers;

use App\Models\Tambon;
use App\Models\TeachingDraft;
use App\Models\TeachingRecord;
use App\Services\AcademicTermService;
use App\Services\ActiveAcademicTermService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ActiveAcademicTermService $activeTerms): Response
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

        $summary = (clone $base)->selectRaw(
            'COUNT(*) as record_count, COALESCE(SUM(total), 0) as total_students, COALESCE(SUM(present), 0) as total_present'
        )->first();
        $summary->week_count = DB::query()->fromSub(
            (clone $base)->select(['year', 'semester', 'week'])->distinct(),
            'visible_periods'
        )->count();
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
            ->paginate(30)
            ->appends(array_filter($filters, static fn ($value): bool => $value !== null && $value !== ''))
            ->through(function (TeachingRecord $record): array {
                $payload = $record->toArray();
                $payload['date'] = $record->date?->format('Y-m-d');

                return $payload;
            });

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

        return Inertia::render('dashboard', [
            'records' => $records,
            'summary' => $summaryPayload,
            'availableWeeks' => $availableWeeks,
            'tambonOptions' => $tambonOptions,
            'filters' => $filters,
            'activeTerm' => $activeTerm,
            'olderRecordCount' => (int) $olderPeriods->sum('records'),
            'drafts' => TeachingDraft::query()->where('user_id', $user->id)->where('status', 'active')
                ->when($filters['year'] ?? null, fn ($query, $year) => $query->where('year', $year))
                ->when($filters['semester'] ?? null, fn ($query, $semester) => $query->where('semester', $semester))
                ->withCount('images')
                ->latest('last_saved_at')->limit(6)->get()->map(fn (TeachingDraft $draft) => [
                    'id' => $draft->id, 'last_saved_at' => $draft->last_saved_at?->toIso8601String(),
                    'payload' => $draft->payload, 'images_count' => $draft->images_count,
                ]),
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
