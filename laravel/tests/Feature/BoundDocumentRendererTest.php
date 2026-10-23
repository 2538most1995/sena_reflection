<?php

namespace Tests\Feature;

use App\Exceptions\DocumentRuntimeUnavailableException;
use App\Models\Document;
use App\Models\DocumentAsset;
use App\Models\User;
use App\Services\BoundDocumentRenderer;
use App\Services\DocumentSchema;
use App\Services\LegacyPrintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BoundDocumentRendererTest extends TestCase
{
    use RefreshDatabase;

    private string $buildDirectory;

    private string $applicationPublicPath;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->applicationPublicPath = public_path();
        $this->buildDirectory = sys_get_temp_dir().'/sena-snapshot-test-'.Str::uuid();
        File::ensureDirectoryExists($this->buildDirectory.'/build/assets');
        app()->usePublicPath($this->buildDirectory);
        File::put($this->buildDirectory.'/build/assets/snapshot.js', 'document.body.dataset.documentPrintReady="true"');
        File::put($this->buildDirectory.'/build/assets/canvas.js', 'export {};');
        File::put($this->buildDirectory.'/build/assets/Thai font.ttf', 'local-font-fixture');
        File::put($this->buildDirectory.'/build/assets/canvas.css', '.document-page{background:white}');
        File::put($this->buildDirectory.'/build/assets/fonts.css', '@font-face{font-family:Sarabun;src:url(./Thai%20font.ttf)}');
        File::put($this->buildDirectory.'/build/fonts-manifest.json', json_encode(['style' => ['file' => 'assets/fonts.css']], JSON_THROW_ON_ERROR));
        File::put($this->buildDirectory.'/build/assets/snapshot.css', '@font-face{font-family:THSarabunNew;src:url(/sena_reflection/build/assets/Thai%20font.ttf)}');
        File::put($this->buildDirectory.'/build/manifest.json', json_encode([
            'resources/js/document-snapshot.tsx' => ['file' => 'assets/snapshot.js', 'imports' => ['_canvas.js'], 'css' => ['assets/snapshot.css']],
            '_canvas.js' => ['file' => 'assets/canvas.js', 'css' => ['assets/canvas.css']],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->buildDirectory);
        parent::tearDown();
    }

    public function test_snapshot_html_uses_built_local_assets_and_escapes_the_json_and_document_title(): void
    {
        $model = $this->model();
        $model['title'] = '</title><script>alert("title")</script>';
        $model['content']['content'][0]['content'][0]['text'] = '</script><script>alert("content")</script> ภาษาไทย';
        $html = app(BoundDocumentRenderer::class)->html($model);

        $this->assertStringContainsString('<meta name="sena-document-snapshot" content="1">', $html);
        $this->assertStringContainsString('data-document-snapshot-mode="local"', $html);
        $this->assertStringContainsString('.document-page{background:white}', $html);
        $this->assertStringContainsString('font-family:Sarabun', $html);
        $this->assertStringContainsString('file://'.realpath($this->buildDirectory).'/build/assets/Thai%20font.ttf', $html);
        $this->assertStringContainsString('src="file://'.realpath($this->buildDirectory).'/build/assets/snapshot.js"', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;/title&gt;', $html);
        $this->assertSame($model['content'], $this->payload($html)['content']);
        $this->assertStringNotContainsString('data-document-print-ready="true"', $html, 'Only the browser may mark a completed live layout as ready.');
    }

    public function test_snapshot_embeds_only_referenced_private_images_in_the_body_textbox_and_object(): void
    {
        $document = Document::factory()->create();
        $first = $this->asset($document, 'first-image');
        $second = $this->asset($document, 'second-image');
        $unused = $this->asset($document, 'unused-image');
        $inline = fn (DocumentAsset $asset): array => ['type' => 'image', 'attrs' => ['assetId' => $asset->id, 'src' => 'https://untrusted.invalid/never-fetch']];
        $model = $this->model($document->id);
        $model['content']['content'][0]['content'][] = $inline($first);
        $model['objects'] = [
            ['id' => 'image-object', 'type' => 'image', 'assetId' => $second->id, 'src' => 'file:///private/never-read'],
            ['id' => 'textbox', 'type' => 'textbox', 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [$inline($first)]]]]],
        ];
        $model['assets'] = [['id' => $unused->id, 'url' => '/private/unused']];
        $html = app(BoundDocumentRenderer::class)->html($model);
        $payload = $this->payload($html);
        $firstUrl = 'data:image/png;base64,'.base64_encode('first-image');
        $this->assertSame($firstUrl, $payload['content']['content'][0]['content'][1]['attrs']['src']);
        $this->assertSame($firstUrl, $payload['objects'][1]['content']['content'][0]['content'][0]['attrs']['src']);
        $this->assertSame('data:image/png;base64,'.base64_encode('second-image'), $payload['objects'][0]['src']);
        $this->assertSame([], $payload['assets']);
        $this->assertStringNotContainsString('unused-image', $html);
        $this->assertStringNotContainsString(base64_encode('unused-image'), $html);
        $this->assertStringNotContainsString('never-fetch', $html);
        $this->assertStringNotContainsString('never-read', $html);
        $this->assertSame('file:///private/never-read', $model['objects'][0]['src'], 'Rendering must not change the immutable snapshot.');
    }

    public function test_foreign_asset_substitution_and_missing_files_fail_without_reading_input_urls(): void
    {
        $document = Document::factory()->create();
        $foreign = $this->asset(Document::factory()->create(), 'foreign-private-image');
        $model = $this->model($document->id);
        $model['objects'] = [['type' => 'image', 'assetId' => $foreign->id, 'src' => 'file:///etc/passwd']];
        try {
            app(BoundDocumentRenderer::class)->html($model);
            $this->fail('A foreign asset must not be rendered.');
        } catch (DocumentRuntimeUnavailableException $exception) {
            $this->assertStringContainsString('unavailable', $exception->getPrevious()->getMessage());
        }
        $own = $this->asset($document, 'own-private-image');
        Storage::disk('local')->delete($own->storage_path);
        $model['objects'][0]['assetId'] = $own->id;
        $this->expectException(DocumentRuntimeUnavailableException::class);
        app(BoundDocumentRenderer::class)->html($model);
    }

    public function test_browser_print_keeps_the_mounted_build_urls_and_uses_one_canonical_toolbar(): void
    {
        URL::forceRootUrl('https://school.test/sena_reflection');
        URL::forceScheme('https');
        $model = $this->model();
        $html = app(LegacyPrintService::class)->browserSnapshotHtml(['advanced_document' => $model], new User(['id' => 'test-user']), 'https://school.test/records/one');

        $this->assertStringContainsString('src="https://school.test/sena_reflection/build/assets/snapshot.js"', $html);
        $this->assertStringContainsString('data-document-snapshot-mode="browser"', $html);
        $this->assertStringContainsString('url("https://school.test/sena_reflection/build/assets/Thai%20font.ttf")', $html);
        $this->assertStringNotContainsString('file://', $html);
        $this->assertStringNotContainsString('browser-print-toolbar', $html);
        $this->assertSame(1, substr_count($html, 'id="document-snapshot-root"'));
    }

    public function test_missing_snapshot_build_and_manifest_traversal_fail_explicitly(): void
    {
        File::put($this->buildDirectory.'/secret.js', 'private-server-content');
        File::put($this->buildDirectory.'/build/manifest.json', json_encode(['resources/js/document-snapshot.tsx' => ['file' => '../secret.js']], JSON_THROW_ON_ERROR));
        try {
            app(BoundDocumentRenderer::class)->html($this->model());
            $this->fail('A build entry outside the build root must fail.');
        } catch (DocumentRuntimeUnavailableException $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
        File::delete($this->buildDirectory.'/build/manifest.json');
        $this->expectException(DocumentRuntimeUnavailableException::class);
        app(BoundDocumentRenderer::class)->html($this->model());
    }

    public function test_advanced_pdf_rejects_a_real_chrome_pdf_if_the_layout_never_reports_ready(): void
    {
        $chrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
        if (! is_executable($chrome)) {
            $this->markTestSkipped('The optional real Chrome renderer is not installed.');
        }
        config()->set('services.documents.chrome_binary', $chrome);
        $print = $this->partialMock(LegacyPrintService::class);
        $print->shouldReceive('snapshotHtml')->once()->andReturn('<!doctype html><html><head><meta name="sena-document-snapshot" content="1"></head><body><p>Incomplete layout</p></body></html>');
        $this->expectException(DocumentRuntimeUnavailableException::class);
        $print->snapshotPdf(['record' => []], new User(['id' => 'test-user']));
    }

    public function test_built_canvas_renders_a_private_image_and_editable_floating_text_in_real_chrome(): void
    {
        $manifest = $this->applicationPublicPath.'/build/manifest.json';
        $chrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
        if (! is_executable($chrome) || ! is_file($manifest) || ! isset(json_decode(file_get_contents($manifest), true)['resources/js/document-snapshot.tsx'])) {
            $this->markTestSkipped('The optional real Chrome and built document snapshot entry are required.');
        }
        app()->usePublicPath($this->applicationPublicPath);
        config()->set('services.documents.chrome_binary', $chrome);
        $document = Document::factory()->create();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jhx0AAAAASUVORK5CYII=');
        $asset = $this->asset($document, $png);
        $model = $this->model($document->id);
        $model['pageSettings']['fontFamily'] = 'THSarabunNew';
        $model['pageSettings']['template'] = 'weekly-record';
        $geometry = ['page' => 1, 'anchor' => ['kind' => 'page', 'page' => 1], 'x' => 125, 'y' => 30, 'width' => 50, 'height' => 20, 'rotation' => 0, 'zIndex' => 1, 'opacity' => 1, 'wrap' => 'inline', 'borderWidth' => 0, 'padding' => 0];
        $model['objects'] = [
            ['id' => 'private-picture', 'type' => 'image', 'assetId' => $asset->id, 'src' => '/must-not-fetch', ...$geometry],
            ['id' => 'floating-text', 'type' => 'textbox', ...$geometry, 'y' => 70, 'height' => 30, 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ข้อความลอยใน PDF']]]]]],
        ];
        if (getenv('SENA_RENDERER_EVIDENCE_DIR')) {
            File::ensureDirectoryExists(getenv('SENA_RENDERER_EVIDENCE_DIR'));
            File::put(getenv('SENA_RENDERER_EVIDENCE_DIR').'/canonical-server-objects.html', app(BoundDocumentRenderer::class)->html($model));
        }
        try {
            $pdf = app(LegacyPrintService::class)->snapshotPdf(['advanced_document' => $model], new User(['id' => 'test-user']));
        } catch (DocumentRuntimeUnavailableException $exception) {
            $this->fail($exception->getPrevious()?->getMessage() ?? $exception->getMessage());
        }

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));
        $this->assertMatchesRegularExpression('~/Subtype\s*/Image~', $pdf);
        if (getenv('SENA_RENDERER_EVIDENCE_DIR')) {
            $directory = getenv('SENA_RENDERER_EVIDENCE_DIR');
            File::ensureDirectoryExists($directory);
            File::put($directory.'/canonical-server-objects.pdf', $pdf);
        }
    }

    public function test_real_chrome_keeps_every_long_document_marker_once_and_matches_the_rendered_page_count(): void
    {
        $chrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
        $extractor = getenv('SENA_PDFTOTEXT_BIN') ?: (new ExecutableFinder)->find('pdftotext');
        $manifest = $this->applicationPublicPath.'/build/manifest.json';
        if (! is_executable($chrome) || ! $extractor || ! is_executable($extractor) || ! is_file($manifest) || ! isset(json_decode(file_get_contents($manifest), true)['resources/js/document-snapshot.tsx'])) {
            $this->markTestSkipped('Optional Chrome, pdftotext and a built snapshot entry are required for PDF text regression coverage.');
        }
        app()->usePublicPath($this->applicationPublicPath);
        config()->set('services.documents.chrome_binary', $chrome);
        $model = $this->model();
        $model['content']['content'] = [];
        $model['pageSettings']['fontFamily'] = 'THSarabunNew';
        $model['pageSettings']['header'] = 'HEADER-SNAPSHOT';
        $model['pageSettings']['footer'] = 'FOOTER-SNAPSHOT';
        for ($index = 1; $index <= 80; $index++) {
            $marker = 'SENAQA'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $model['content']['content'][] = ['type' => 'paragraph', 'attrs' => ['id' => 'long-paragraph-'.$index], 'content' => [['type' => 'text', 'text' => $marker.' '.str_repeat('ทดสอบการจัดหน้าเอกสารภาษาไทยอย่างถูกต้อง ', 5)]]];
        }
        $rows = [];
        for ($index = 1; $index <= 50; $index++) {
            $values = ['TABLEROW'.str_pad((string) $index, 3, '0', STR_PAD_LEFT), 'รายชื่อผู้เรียนภาษาไทย ลำดับ '.$index, 'เข้าเรียน'];
            $rows[] = ['type' => 'tableRow', 'content' => array_map(fn (string $value): array => ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $value]]]]], $values)];
        }
        $model['content']['content'][] = ['type' => 'table', 'attrs' => ['columnSize' => '25,45,30'], 'content' => $rows];
        $html = app(BoundDocumentRenderer::class)->html($model);
        $htmlPath = $this->buildDirectory.'/long-document.html';
        $pdfPath = $this->buildDirectory.'/long-document.pdf';
        File::put($htmlPath, $html);
        $dump = new Process([$chrome, '--headless=new', '--disable-gpu', '--no-first-run', '--allow-file-access-from-files', '--run-all-compositor-stages-before-draw', '--virtual-time-budget=5000', '--user-data-dir='.$this->buildDirectory.'/long-chrome-profile', '--dump-dom', 'file://'.$htmlPath]);
        $dump->setTimeout(30)->mustRun();
        $this->assertMatchesRegularExpression('/<body\b[^>]*data-document-print-ready="true"/', $dump->getOutput());
        preg_match('/data-document-print-page-count="(\d+)"/', $dump->getOutput(), $pageCount);
        $this->assertNotEmpty($pageCount, 'The snapshot must expose its completed physical page count.');
        File::put($pdfPath, app(LegacyPrintService::class)->snapshotPdf(['advanced_document' => $model], new User(['id' => 'test-user'])));
        $text = (new Process([$extractor, '-layout', $pdfPath, '-']))->setTimeout(30)->mustRun()->getOutput();
        for ($index = 1; $index <= 80; $index++) {
            $marker = 'SENAQA'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $this->assertSame(1, substr_count($text, $marker), $marker.' must be searchable exactly once across all PDF pages.');
        }
        for ($index = 1; $index <= 50; $index++) {
            $marker = 'TABLEROW'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $this->assertSame(1, substr_count($text, $marker), $marker.' must not be dropped or duplicated when the table crosses pages.');
        }
        $this->assertGreaterThan(1, (int) $pageCount[1]);
        $this->assertSame((int) $pageCount[1], substr_count($text, "\f"));
        $this->assertSame((int) $pageCount[1], substr_count($text, 'HEADER-SNAPSHOT'));
        $this->assertSame((int) $pageCount[1], substr_count($text, 'FOOTER-SNAPSHOT'));
        $bodyText = preg_replace('/[\s\d]+/u', '', str_replace(['HEADER-SNAPSHOT', 'FOOTER-SNAPSHOT'], '', $text));
        $this->assertSame(400, substr_count($bodyText, 'ทดสอบการจัดหน้าเอกสารภาษาไทยอย่างถูกต้อง'), 'All five Thai phrases in every paragraph must survive page boundaries.');
        if (getenv('SENA_RENDERER_EVIDENCE_DIR')) {
            File::put(getenv('SENA_RENDERER_EVIDENCE_DIR').'/canonical-server-markers.pdf', File::get($pdfPath));
        }
    }

    private function asset(Document $document, string $bytes): DocumentAsset
    {
        $id = (string) Str::uuid();
        $path = 'documents/'.$document->id.'/'.$id.'.png';
        Storage::disk('local')->put($path, $bytes);

        return DocumentAsset::create(['id' => $id, 'document_id' => $document->id, 'name' => 'ภาพ.png', 'storage_path' => $path, 'mime' => 'image/png', 'size' => strlen($bytes), 'width' => 10, 'height' => 10]);
    }

    private function model(string $id = 'snapshot-without-images'): array
    {
        return ['id' => $id, 'title' => 'บันทึกภาษาไทย', 'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'เนื้อหาในเอกสาร']]]]], 'pageSettings' => DocumentSchema::defaultPageSettings(), 'objects' => [], 'assets' => [], 'revision' => 1, 'schemaVersion' => 1];
    }

    private function payload(string $html): array
    {
        preg_match('~<script id="document-snapshot-data" type="application/json">(.*?)</script>~s', $html, $match);

        return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    }
}
