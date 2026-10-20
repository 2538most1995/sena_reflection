<?php

namespace App\Console\Commands;

use App\Models\TeachingRecord;
use App\Models\WeeklySubmission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillWeeklySubmissionsCommand extends Command
{
    protected $signature = 'sena:backfill-weekly {--apply : Write weekly parents and link legacy records}';

    protected $description = 'Backfill weekly submission parents from legacy records without changing record content';

    public function handle(): int
    {
        $groups = TeachingRecord::query()->whereNull('weekly_submission_id')
            ->select(['tambon', 'year', 'semester', 'week'])->distinct()->get();
        $complete = 0;
        $incomplete = 0;
        $linked = 0;
        foreach ($groups as $group) {
            $query = TeachingRecord::query()->whereNull('weekly_submission_id')->where('tambon', $group->tambon)->where('year', $group->year)->where('semester', $group->semester)->where('week', $group->week);
            $levels = (clone $query)->distinct()->pluck('level')->filter();
            $status = collect(['ประถมศึกษา', 'มัธยมศึกษาตอนต้น', 'มัธยมศึกษาตอนปลาย'])->diff($levels)->isEmpty() ? 'complete' : 'incomplete';
            $status === 'complete' ? $complete++ : $incomplete++;
            $count = (clone $query)->count();
            $linked += $count;
            if ($this->option('apply')) {
                DB::transaction(function () use ($group, $query, $status) {
                    $first = (clone $query)->oldest('created_at')->first();
                    $submission = WeeklySubmission::query()->firstOrCreate(['tambon' => $group->tambon, 'year' => $group->year, 'semester' => $group->semester, 'week' => $group->week], ['submitted_by' => $first?->created_by ?? 'legacy', 'status' => $status]);
                    (clone $query)->update(['weekly_submission_id' => $submission->id]);
                });
            }
        }
        $this->table(['Groups', 'Complete', 'Incomplete', 'Records'], [[$groups->count(), $complete, $incomplete, $linked]]);
        $this->info($this->option('apply') ? 'Backfill applied.' : 'Preview only; run with --apply to write changes.');

        return self::SUCCESS;
    }
}
