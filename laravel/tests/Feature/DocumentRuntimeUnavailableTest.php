<?php

namespace Tests\Feature;

use App\Exceptions\DocumentRuntimeUnavailableException;
use App\Models\User;
use App\Services\LegacyPrintService;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class DocumentRuntimeUnavailableTest extends TestCase
{
    public function test_json_runtime_failure_returns_503_without_process_details_even_in_debug_mode(): void
    {
        config()->set('app.debug', true);
        Route::get('/_document-runtime', function (): never {
            throw new DocumentRuntimeUnavailableException(new RuntimeException('/private/server/libnspr4.so not found; password=internal-only'));
        });

        $response = $this->getJson('/_document-runtime')->assertServiceUnavailable()
            ->assertHeader('Retry-After', '60')
            ->assertExactJson(['code' => 'document_renderer_unavailable', 'message' => DocumentRuntimeUnavailableException::PUBLIC_MESSAGE])
            ->assertDontSee('libnspr4.so')->assertDontSee('internal-only');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_html_runtime_failure_is_accessible_and_links_to_the_mounted_dashboard(): void
    {
        Route::get('/_document-runtime', function (): never {
            throw new DocumentRuntimeUnavailableException(new RuntimeException('/private/server/chrome stderr diagnostic'));
        });

        $response = $this->withServerVariables([
            'SCRIPT_NAME' => '/sena_reflection/index.php',
            'PHP_SELF' => '/sena_reflection/index.php',
            'SCRIPT_FILENAME' => '/var/www/vhosts/krumost.com/httpdocs/sena_reflection/index.php',
            'HTTPS' => 'on', 'SERVER_PORT' => '443',
        ])->get('https://krumost.com/sena_reflection/_document-runtime')
            ->assertServiceUnavailable()->assertHeader('Retry-After', '60')
            ->assertSee('lang="th"', false)->assertSee('role="status"', false)
            ->assertSee('href="https://krumost.com/sena_reflection"', false)
            ->assertSee(DocumentRuntimeUnavailableException::PUBLIC_MESSAGE)
            ->assertDontSee('stderr diagnostic')->assertDontSee('/private/server/chrome');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_unrelated_runtime_exception_is_not_masked_as_renderer_unavailability(): void
    {
        config()->set('app.debug', false);
        Route::get('/_unrelated-error', function (): never {
            throw new RuntimeException('An unrelated storage or application failure');
        });

        $this->getJson('/_unrelated-error')->assertInternalServerError()
            ->assertJsonMissingPath('code');
    }

    public function test_validation_errors_keep_their_original_status_and_field_errors(): void
    {
        Route::get('/_document-validation', function (): never {
            throw ValidationException::withMessages(['subject' => 'กรุณาระบุรายวิชา']);
        });

        $this->getJson('/_document-validation')->assertUnprocessable()
            ->assertJsonValidationErrors('subject')->assertJsonMissingPath('code');
    }

    public function test_failed_chrome_process_is_reported_as_unavailable_without_using_another_renderer(): void
    {
        config()->set('services.documents.chrome_binary', PHP_BINARY);
        $print = $this->partialMock(LegacyPrintService::class);
        $print->shouldReceive('snapshotHtml')->once()->andReturn('<!doctype html><html lang="th"><body>PDF</body></html>');
        Route::get('/_failed-chrome', fn () => response($print->snapshotPdf(['record' => []], new User(['id' => 'u1']))));

        $this->getJson('/_failed-chrome')->assertServiceUnavailable()
            ->assertJsonPath('code', 'document_renderer_unavailable')
            ->assertDontSee(PHP_BINARY)->assertDontSee('Chrome could not produce PDF');
    }
}
