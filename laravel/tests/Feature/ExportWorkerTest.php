<?php

namespace Tests\Feature;

use App\Console\Commands\WorkRecordExports;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ExportWorkerTest extends TestCase
{
    private function runWorker(BufferedOutput $output): int
    {
        $command = app(WorkRecordExports::class);
        $command->setLaravel($this->app);

        return $command->run(new ArrayInput([]), $output);
    }

    public function test_worker_runs_only_the_export_connection_with_a_bounded_budget(): void
    {
        Cache::store()->flush();
        Artisan::shouldReceive('call')->once()->with('queue:work', Mockery::on(fn (array $options): bool => $options['connection'] === 'exports' && $options['--queue'] === 'exports'
            && $options['--stop-when-empty'] === true && $options['--max-time'] === 50
            && $options['--tries'] === 1 && $options['--timeout'] === 1800
        ), Mockery::any())->andReturn(0);
        $this->assertSame(0, $this->runWorker(new BufferedOutput));
        $lock = Cache::lock('sena:export-cron-worker', 1860);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_second_cron_run_exits_without_starting_another_worker(): void
    {
        Cache::store()->flush();
        $lock = Cache::lock('sena:export-cron-worker', 1860);
        $this->assertTrue($lock->get());
        Artisan::shouldReceive('call')->with('queue:work', Mockery::any(), Mockery::any())->never();
        $output = new BufferedOutput;
        $this->assertSame(0, $this->runWorker($output));
        $this->assertStringContainsString('An export cron worker is already running.', $output->fetch());
        $lock->release();
    }
}
