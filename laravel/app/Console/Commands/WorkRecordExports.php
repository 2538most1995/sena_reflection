<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class WorkRecordExports extends Command
{
    protected $signature = 'sena:work-exports';

    protected $description = 'Drain the PDF export queue for a bounded hosting cron run without overlapping workers';

    public function handle(): int
    {
        $lock = Cache::lock('sena:export-cron-worker', 1860);
        if (! $lock->get()) {
            $this->info('An export cron worker is already running.');

            return self::SUCCESS;
        }
        try {
            $status = Artisan::call('queue:work', [
                'connection' => 'exports', '--queue' => 'exports', '--stop-when-empty' => true,
                '--max-time' => 50, '--max-jobs' => 20, '--tries' => 1, '--timeout' => 1800, '--sleep' => 1,
            ], $this->output);

            return $status;
        } finally {
            $lock->release();
        }
    }
}
