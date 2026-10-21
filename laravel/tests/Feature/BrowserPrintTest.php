<?php

namespace Tests\Feature;

use App\Exceptions\DocumentRuntimeUnavailableException;
use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\LegacyPrintService;
use App\Services\RecordPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BrowserPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_navigation_redirects_to_explicit_browser_print_if_server_pdf_is_unavailable(): void
    {
        [$teacher, $record] = $this->record();
        $this->mock(RecordPdfService::class)->shouldReceive('renderOne')->once()->andThrow(new DocumentRuntimeUnavailableException);

        $this->actingAs($teacher)->get(route('records.print', $record), ['Accept' => 'text/html,application/xhtml+xml'])
            ->assertRedirect(route('records.browser-print', $record));
    }

    public function test_explicit_pdf_json_and_api_requests_keep_503_without_a_browser_redirect(): void
    {
        [$teacher, $record] = $this->record();
        $this->mock(RecordPdfService::class)->shouldReceive('renderOne')->times(3)->andThrow(new DocumentRuntimeUnavailableException);

        $this->actingAs($teacher)->get(route('records.print', $record), ['Accept' => 'application/pdf'])
            ->assertServiceUnavailable()->assertHeaderMissing('Location')->assertHeaderMissing('Content-Disposition');
        $this->getJson(route('records.print', $record))->assertServiceUnavailable()
            ->assertJsonPath('code', 'document_renderer_unavailable');
        $this->getJson('/api/download_pdf.php?id='.$record->id)->assertServiceUnavailable()
            ->assertJsonPath('code', 'document_renderer_unavailable');
    }

    public function test_browser_print_uses_same_snapshot_with_embedded_assets_without_editor_mode_and_mounted_links(): void
    {
        [$teacher, $record] = $this->record();
        $snapshot = ['record' => ['id' => $record->id, 'subject' => 'ภาษาไทย'], 'settings' => ['paper_orientation' => 'landscape']];
        $print = $this->partialMock(LegacyPrintService::class);
        $print->shouldReceive('savedSnapshot')->once()->withArgs(fn ($savedRecord, $user) => $savedRecord->is($record) && $user->is($teacher))->andReturn($snapshot);
        $print->shouldReceive('snapshotHtml')->once()->withArgs(fn ($data, $user, $editor, $browserAssets) => $data === $snapshot && $user->is($teacher) && $editor === false && $browserAssets === true)
            ->andReturn('<!doctype html><html lang="th"><head><base href="http://localhost/"><style>@page{size:A4 landscape}@font-face{src:url(data:font/ttf;base64,AAAA)}</style></head><body><section class="page">ภาษาไทย<img src="data:image/png;base64,AAAA"></section></body></html>');

        $this->actingAs($teacher)->withServerVariables([
            'SCRIPT_NAME' => '/sena_reflection/index.php', 'PHP_SELF' => '/sena_reflection/index.php',
            'SCRIPT_FILENAME' => '/var/www/vhosts/krumost.com/httpdocs/sena_reflection/index.php', 'HTTPS' => 'on', 'SERVER_PORT' => '443',
        ])->get('https://krumost.com/sena_reflection/records/'.$record->id.'/browser-print')
            ->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('<base href="https://krumost.com/sena_reflection/">', false)
            ->assertSee('https://krumost.com/sena_reflection/records/'.$record->id)
            ->assertSee('window.print()', false)->assertSee('@media print{.browser-print-toolbar{display:none!important}', false)
            ->assertSee('html.html-preview-direct .page{zoom:1!important;transform:none!important;margin:0!important}', false)
            ->assertSee('html.html-preview-direct body{display:block!important;margin:0!important;padding:0!important}', false)
            ->assertSee('@page{size:A4 landscape}', false)
            ->assertSee('data:image/png;base64', false)->assertSee('data:font/ttf;base64', false)
            ->assertSee('Save as PDF')->assertSee('withTimeout(document.fonts.ready)', false)
            ->assertSee('ตรวจเอกสารก่อนพิมพ์')->assertSee('<details><summary>ตั้งค่าการพิมพ์</summary>', false)
            ->assertSee('id="browser-print-button" disabled', false)->assertSee('image.decode()', false)
            ->assertSee('event.source !== window.parent', false)->assertSee('sena-print-invalidated')
            ->assertSee('เอกสารถูกแก้ไข กรุณาอัปเดตตัวอย่างก่อนพิมพ์')
            ->assertSee('id="browser-print-styles"', false)->assertSee('id="browser-print-script"', false)
            ->assertSee('target="_top"', false)
            ->assertSee("document.querySelectorAll('.missing-image-note')", false)
            ->assertSee('images.length + missing', false)->assertSee("reject(new Error('timeout')), 15000", false)
            ->assertSee("window.addEventListener('DOMContentLoaded', ready", false)
            ->assertDontSee('file://')->assertDontSee('data-layout-kind');
    }

    public function test_browser_print_keeps_guest_and_tambon_scope_and_allows_district(): void
    {
        [$teacher, $record] = $this->record();
        $other = User::create(['id' => 'u2', 'username' => 'other', 'name' => 'ครูอื่น', 'password' => 'not-used', 'role' => 'tambon', 'tambon' => 't2']);
        $district = User::create(['id' => 'd1', 'username' => 'district', 'name' => 'ผู้ดูแล', 'password' => 'not-used', 'role' => 'district']);
        $this->mock(LegacyPrintService::class)->shouldReceive('browserPrintHtml')->twice()->andReturn('<!doctype html><html lang="th">บันทึก</html>');
        $url = route('records.browser-print', $record);

        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($other)->get($url)->assertForbidden();
        $this->actingAs($teacher)->get($url)->assertOk();
        $this->actingAs($district)->get($url)->assertOk();
    }

    public function test_other_runtime_errors_are_not_redirected_to_browser_print(): void
    {
        [$teacher, $record] = $this->record();
        config()->set('app.debug', false);
        $this->mock(RecordPdfService::class)->shouldReceive('renderOne')->once()->andThrow(new RuntimeException('Unrelated application failure'));

        $this->actingAs($teacher)->get(route('records.print', $record), ['Accept' => 'text/html'])
            ->assertInternalServerError()->assertHeaderMissing('Location');
    }

    /** @return array{User, TeachingRecord} */
    private function record(): array
    {
        Tambon::create(['id' => 't1', 'name' => 'เสนา']);
        $teacher = User::create(['id' => 'u1', 'username' => 'teacher', 'name' => 'ครูเสนา', 'password' => 'not-used', 'role' => 'tambon', 'tambon' => 't1']);
        $record = TeachingRecord::create([
            'id' => 'browser-record', 'tambon' => 't1', 'week' => 1, 'date' => '2026-10-20', 'level' => 'ประถมศึกษา',
            'semester' => '1', 'year' => '2569', 'subject' => 'ภาษาไทย', 'total' => 2, 'present' => 1, 'created_by' => $teacher->id,
        ]);

        return [$teacher, $record];
    }
}
