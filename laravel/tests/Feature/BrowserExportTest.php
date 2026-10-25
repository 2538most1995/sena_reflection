<?php

namespace Tests\Feature;

use App\Models\ExportJob;
use App\Models\RecordRevision;
use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\LegacyPrintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BrowserExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_export_applies_every_filter_without_crossing_tambon_scope(): void
    {
        Queue::fake();
        $teacher = $this->teacher();
        $chosen = $this->record($teacher, 'chosen');
        $this->record($teacher, 'other-week', ['week' => 9]);
        $this->record($teacher, 'other-year', ['year' => '2568']);
        $this->record($teacher, 'other-semester', ['semester' => '2']);
        $this->record($teacher, 'other-subject', ['subject' => 'คณิตศาสตร์', 'subject_code' => 'MA101']);
        $this->record($this->teacher('other', 't2'), 'foreign');

        $response = $this->page($teacher, ['scope' => 'week', 'year' => '2569', 'semester' => '1', 'week' => 8, 'search' => 'TH', 'tambon' => 't2']);
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $records = $response->viewData('page')['props']['records'];
        $this->assertCount(1, $records);
        $this->assertSame($chosen->id, $records[0]['record_id']);
        $this->assertArrayNotHasKey('tambon', $response->viewData('page')['props']['filters']);
        $this->assertSame('exports/browser-print', $response->viewData('page')['component']);
        $this->assertDatabaseCount('record_revisions', 1);
        $this->assertDatabaseCount('export_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_district_export_honors_optional_period_tambon_and_subject_filters(): void
    {
        $teacher = $this->teacher();
        $this->record($teacher, 'one');
        $this->record($teacher, 'two', ['week' => 9]);
        $other = $this->teacher('other', 't2');
        $this->record($other, 'foreign');
        $this->record($teacher, 'previous', ['year' => '2568']);
        $district = User::create(['id' => 'district', 'username' => 'district', 'name' => 'ผู้ดูแล', 'role' => 'district', 'password' => 'not-used']);

        $response = $this->page($district, ['scope' => 'all', 'year' => '2569', 'semester' => '1', 'tambon' => 't1', 'search' => 'ภาษา']);
        $response->assertOk();
        $this->assertSame(['one', 'two'], array_column($response->viewData('page')['props']['records'], 'record_id'));
        $all = $this->page($district, ['scope' => 'all', 'year' => '2569']);
        $this->assertCount(3, $all->viewData('page')['props']['records']);
    }

    public function test_signed_print_uses_the_frozen_snapshot_without_chrome_or_queue_jobs(): void
    {
        Queue::fake();
        $teacher = $this->teacher();
        $record = $this->record($teacher, 'frozen');
        $entry = $this->page($teacher)->assertOk()->viewData('page')['props']['records'][0];
        $repeat = $this->page($teacher)->assertOk()->viewData('page')['props']['records'][0];
        $this->assertSame($entry['revision_id'], $repeat['revision_id']);
        $record->update(['result' => 'ข้อความแก้ไขหลังเปิดหน้าพิมพ์']);
        $this->mock(LegacyPrintService::class)->shouldReceive('browserSnapshotHtml')->once()
            ->withArgs(fn (array $snapshot, User $user, string $recordUrl): bool => $snapshot['record']['result'] === 'ข้อความฉบับที่ตรวจแล้ว'
                && $user->is($teacher) && $recordUrl === route('records.show', $record))
            ->andReturn('<!doctype html><html lang="th"><body>frozen snapshot</body></html>');

        $this->get($entry['print_url'])->assertOk()->assertSee('frozen snapshot')
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $next = $this->page($teacher)->assertOk()->viewData('page')['props']['records'][0];
        $this->assertNotSame($entry['revision_id'], $next['revision_id']);
        $this->assertDatabaseCount('record_revisions', 2);
        $this->assertDatabaseCount('export_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_signed_revision_is_private_expires_and_rechecks_current_record_scope(): void
    {
        $teacher = $this->teacher();
        $record = $this->record($teacher);
        $entry = $this->page($teacher)->assertOk()->viewData('page')['props']['records'][0];
        $this->mock(LegacyPrintService::class)->shouldNotReceive('browserSnapshotHtml');

        $this->get(route('exports.browser-print.revision', ['revision' => $entry['revision_id'], 'user' => $teacher->id]))->assertForbidden();
        $this->get($entry['print_url'].'&unexpected=1')->assertForbidden();
        $this->actingAs($this->teacher('other'))->get($entry['print_url'])->assertForbidden();
        $this->actingAs($teacher);
        $record->update(['tambon' => 't2']);
        $this->get($entry['print_url'])->assertForbidden();
        $record->update(['tambon' => 't1']);
        $this->travel(61)->minutes();
        $this->get($entry['print_url'])->assertForbidden();
    }

    public function test_more_than_twelve_records_can_render_without_exhausting_preview_throttle(): void
    {
        $teacher = $this->teacher();
        foreach (range(1, 13) as $index) {
            $this->record($teacher, 'record-'.$index);
        }
        $entries = $this->page($teacher)->assertOk()->viewData('page')['props']['records'];
        $this->mock(LegacyPrintService::class)->shouldReceive('browserSnapshotHtml')->times(13)->andReturn('<!doctype html><html>snapshot</html>');
        foreach ($entries as $entry) {
            $this->get($entry['print_url'])->assertOk();
        }
    }

    public function test_mismatched_frozen_record_is_rejected_before_rendering(): void
    {
        $teacher = $this->teacher();
        $this->record($teacher);
        $entry = $this->page($teacher)->assertOk()->viewData('page')['props']['records'][0];
        $revision = RecordRevision::findOrFail($entry['revision_id']);
        $snapshot = $revision->snapshot;
        $snapshot['record']['id'] = 'different-record';
        $revision->update(['snapshot' => $snapshot]);
        $this->mock(LegacyPrintService::class)->shouldNotReceive('browserSnapshotHtml');

        $this->get($entry['print_url'])->assertConflict();
    }

    public function test_browser_export_requires_login_validates_filters_and_handles_empty_results(): void
    {
        $this->get(route('exports.browser-print', ['scope' => 'all']))->assertRedirect(route('login'));
        $teacher = $this->teacher();
        $this->actingAs($teacher)->getJson(route('exports.browser-print', ['scope' => 'week']))->assertUnprocessable();
        $this->getJson(route('exports.browser-print', ['scope' => 'all', 'year' => '2026x']))->assertUnprocessable();
        $this->assertSame([], $this->page($teacher)->assertOk()->viewData('page')['props']['records']);
        $this->assertDatabaseCount('record_revisions', 0);
    }

    public function test_signed_links_preserve_the_hosting_subdirectory(): void
    {
        $teacher = $this->teacher();
        $this->record($teacher);
        URL::forceRootUrl('https://school.test/sena_reflection');
        URL::forceScheme('https');
        try {
            $this->withServerVariables([
                'SCRIPT_NAME' => '/sena_reflection/index.php', 'PHP_SELF' => '/sena_reflection/index.php',
                'SCRIPT_FILENAME' => '/var/www/vhosts/school.test/httpdocs/sena_reflection/index.php',
                'HTTPS' => 'on', 'SERVER_PORT' => '443',
            ]);
            $response = $this->page($teacher)->assertOk();
            $entry = $response->viewData('page')['props']['records'][0];
            $this->assertStringStartsWith('https://school.test/sena_reflection/exports/revisions/', $entry['print_url']);
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
        }
    }

    public function test_queued_export_prints_its_original_manifest_without_recapture_or_status_changes(): void
    {
        Queue::fake();
        $teacher = $this->teacher();
        $record = $this->record($teacher);
        $entry = $this->page($teacher)->assertOk()->viewData('page')['props']['records'][0];
        $manifest = [['record_id' => $record->id, 'revision_id' => $entry['revision_id']]];
        $export = $this->export($teacher, $manifest);
        $record->update(['subject' => 'วิชาที่แก้ภายหลัง']);

        $response = $this->get(route('exports.browser-print.existing', $export))->assertOk();
        $entries = $response->viewData('page')['props']['records'];
        $this->assertCount(1, $entries);
        $this->assertSame($entry['revision_id'], $entries[0]['revision_id']);
        $this->assertSame('ภาษาไทย', $entries[0]['subject']);
        $this->assertSame('queued', $export->fresh()->status);
        $this->assertSame(0, $export->fresh()->completed);
        $this->assertSame($manifest, $export->fresh()->manifest);
        $this->assertDatabaseCount('record_revisions', 1);
        $this->assertDatabaseCount('export_jobs', 1);
        Queue::assertNothingPushed();
    }

    public function test_existing_export_preserves_owner_expiry_scope_and_revision_pairing(): void
    {
        $teacher = $this->teacher();
        $record = $this->record($teacher);
        $entry = $this->page($teacher)->assertOk()->viewData('page')['props']['records'][0];
        $export = $this->export($teacher, [['record_id' => $record->id, 'revision_id' => $entry['revision_id']]]);
        $url = route('exports.browser-print.existing', $export);
        $this->actingAs($this->teacher('other'))->get($url)->assertNotFound();
        $this->actingAs($teacher);
        $record->update(['tambon' => 't2']);
        $this->get($url)->assertForbidden();
        $record->update(['tambon' => 't1']);
        $second = $this->record($teacher, 'second');
        $export->update(['manifest' => [['record_id' => $second->id, 'revision_id' => $entry['revision_id']]]]);
        $this->get($url)->assertConflict();
        $export->update(['expires_at' => now()->subSecond()]);
        $this->get($url)->assertGone();
    }

    public function test_existing_export_signatures_cannot_outlive_the_original_export(): void
    {
        $teacher = $this->teacher();
        $record = $this->record($teacher);
        $entry = $this->page($teacher)->assertOk()->viewData('page')['props']['records'][0];
        $export = $this->export($teacher, [['record_id' => $record->id, 'revision_id' => $entry['revision_id']]]);
        $export->update(['expires_at' => now()->addMinute()]);
        $response = $this->get(route('exports.browser-print.existing', $export))->assertOk();
        $printUrl = $response->viewData('page')['props']['records'][0]['print_url'];
        $this->travel(2)->minutes();
        $this->get($printUrl)->assertForbidden();
    }

    /** @param list<array{record_id: string, revision_id: string}> $manifest */
    private function export(User $user, array $manifest): ExportJob
    {
        return ExportJob::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'status' => 'queued', 'completed' => 0,
            'filters' => ['scope' => 'all'], 'manifest' => $manifest, 'total' => count($manifest), 'expires_at' => now()->addDay(),
        ]);
    }

    /** @param array<string, int|string> $filters */
    private function page(User $user, array $filters = ['scope' => 'all']): TestResponse
    {
        return $this->actingAs($user)->get(route('exports.browser-print', $filters));
    }

    private function teacher(string $id = 'browser-teacher', string $tambon = 't1'): User
    {
        Tambon::firstOrCreate(['id' => $tambon], ['name' => 'ตำบล '.$tambon]);

        return User::create(['id' => $id, 'username' => $id, 'name' => 'ครูทดสอบ', 'role' => 'tambon', 'tambon' => $tambon, 'password' => 'not-used']);
    }

    /** @param array<string, int|string> $overrides */
    private function record(User $user, string $id = 'browser-record', array $overrides = []): TeachingRecord
    {
        return TeachingRecord::create(array_merge([
            'id' => $id, 'tambon' => $user->tambon, 'created_by' => $user->id,
            'year' => '2569', 'semester' => '1', 'week' => 8, 'date' => '2026-10-16',
            'level' => 'ประถมศึกษา', 'subject' => 'ภาษาไทย', 'subject_code' => 'TH101',
            'result' => 'ข้อความฉบับที่ตรวจแล้ว', 'total' => 3, 'present' => 2,
        ], $overrides));
    }
}
