<?php

namespace Tests\Feature;

use App\Models\StudentApiConnection;
use App\Services\StudentApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StudentSourcePerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        StudentApiConnection::create(['id' => 1, 'token' => Crypt::encryptString('fixture-performance-token'), 'latest_term' => '1/2569']);
    }

    public function test_repeated_source_metadata_checks_read_configuration_only_once_per_request(): void
    {
        $samples = [];
        for ($sample = 0; $sample < 7; $sample++) {
            $this->app->forgetScopedInstances();
            $this->app->instance('request', Request::create('/performance-fixture'));
            $api = app(StudentApiService::class);
            DB::flushQueryLog();
            DB::enableQueryLog();
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
            $memory = memory_get_usage();
            $started = hrtime(true);
            for ($check = 0; $check < 10; $check++) {
                $this->assertSame('sdl', $api->status()['driver']);
                $this->assertSame('1/2569', $api->latestKnownTerm());
                $this->assertNotSame('', $api->fingerprint());
            }
            $samples[] = ['queries' => count(DB::getQueryLog()), 'db_ms' => round(array_sum(array_column(DB::getQueryLog(), 'time')), 3), 'service_ms' => round((hrtime(true) - $started) / 1e6, 3), 'peak_mb' => round((memory_get_peak_usage() - $memory) / 1048576, 3)];
            DB::disableQueryLog();
        }
        if (getenv('SENA_PERF_REPORT')) {
            fwrite(STDOUT, json_encode(['student_source_configuration' => ['samples' => 7, 'checks_per_sample' => 30, 'connection_rows' => 1, 'measurements' => $samples]], JSON_THROW_ON_ERROR).PHP_EOL);
        }
        $this->assertLessThanOrEqual(2, max(array_column($samples, 'queries')));
        Http::assertNothingSent();
    }

    public function test_new_service_resolutions_and_new_requests_read_rotated_credentials_and_term(): void
    {
        $this->app->instance('request', Request::create('/first-request'));
        $first = app(StudentApiService::class);
        $fingerprint = $first->fingerprint();
        StudentApiConnection::whereKey(1)->update(['token' => Crypt::encryptString('fixture-rotated-token'), 'latest_term' => '2/2569']);
        $second = app(StudentApiService::class);
        $this->assertNotSame($first, $second);
        $this->assertNotSame($fingerprint, $second->fingerprint());
        $this->assertSame('2/2569', $second->status()['latest_term']);
        $this->app->instance('request', Request::create('/second-request'));
        $this->assertNotSame($fingerprint, $first->fingerprint());
        Http::assertNothingSent();
    }

    public function test_newly_verified_term_is_visible_to_later_metadata_reads_in_the_same_request(): void
    {
        Http::fake(fn () => Http::response(['data' => [], 'meta' => ['latest_term' => '2/2569', 'pagination' => ['last_page' => 1]]]));
        $api = app(StudentApiService::class);
        $this->assertSame('1/2569', $api->status()['latest_term']);
        $api->fetch(['term' => '2/2569'], false, true);
        $this->assertSame('2/2569', $api->status()['latest_term']);
    }
}
