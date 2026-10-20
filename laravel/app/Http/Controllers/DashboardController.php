<?php

namespace App\Http\Controllers;

use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\TeachingDraft;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filters = $request->validate([
            'year' => ['nullable', 'string', 'max:10'],
            'semester' => ['nullable', 'in:1,2,3'],
            'week' => ['nullable', 'integer', 'between:1,60'],
            'tambon' => ['nullable', 'string', 'max:20'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

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

        $records = (clone $base)
            ->select(['id', 'weekly_submission_id', 'tambon', 'week', 'date', 'level', 'semester', 'year', 'subject', 'subject_code', 'total', 'present', 'created_by', 'created_at'])
            ->with(['tambonRelation:id,name'])
            ->withCount('images')
            ->orderByDesc('date')
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString()
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
        ];

        return Inertia::render('dashboard', [
            'records' => $records,
            'summary' => $summaryPayload,
            'availableWeeks' => $availableWeeks,
            'tambonOptions' => $tambonOptions,
            'filters' => $filters,
            'drafts' => TeachingDraft::query()->where('user_id', $user->id)->where('status', 'active')
                ->latest('last_saved_at')->limit(6)->get()->map(fn (TeachingDraft $draft) => [
                    'id' => $draft->id, 'last_saved_at' => $draft->last_saved_at?->toIso8601String(),
                    'payload' => $draft->payload,
                ]),
            'weeklyDefaults' => [
                'year' => (string) ((int) date('Y') + 543),
                'semester' => '1',
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
