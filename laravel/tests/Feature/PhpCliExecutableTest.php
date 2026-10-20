<?php

namespace Tests\Feature;

use App\Exceptions\DocumentRuntimeUnavailableException;
use App\Services\PhpCliExecutable;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PhpCliExecutableTest extends TestCase
{
    public function test_uses_the_sapi_aware_php_finder_and_verifies_the_cli_runtime(): void
    {
        config()->set('services.documents.php_cli_binary', null);
        $this->mock(PhpExecutableFinder::class)->shouldReceive('find')->once()->with(false)->andReturn(PHP_BINARY);

        $this->assertSame(PHP_BINARY, app(PhpCliExecutable::class)->resolve());
    }

    public function test_uses_the_configured_cli_path_instead_of_the_web_server_binary(): void
    {
        config()->set('services.documents.php_cli_binary', PHP_BINARY);
        $this->mock(PhpExecutableFinder::class)->shouldNotReceive('find');

        $this->assertSame(PHP_BINARY, app(PhpCliExecutable::class)->resolve());
    }

    public function test_failed_filesystem_finder_falls_back_to_php_bindir(): void
    {
        config()->set('services.documents.php_cli_binary', null);
        $this->mock(PhpExecutableFinder::class)->shouldReceive('find')->once()->with(false)->andReturn(false);

        $this->assertSame(PHP_BINDIR.DIRECTORY_SEPARATOR.'php', app(PhpCliExecutable::class)->resolve());
    }

    public function test_invalid_explicit_cli_configuration_does_not_silently_fall_back(): void
    {
        config()->set('services.documents.php_cli_binary', base_path('missing-php-cli'));
        $this->mock(PhpExecutableFinder::class)->shouldNotReceive('find');
        $this->expectException(DocumentRuntimeUnavailableException::class);
        $this->expectExceptionMessage(DocumentRuntimeUnavailableException::PUBLIC_MESSAGE);

        app(PhpCliExecutable::class)->resolve();
    }

    public function test_executable_that_is_not_php_cli_is_rejected(): void
    {
        if (! is_executable('/bin/sh')) {
            $this->markTestSkipped('A POSIX shell is required for this invalid executable fixture.');
        }
        config()->set('services.documents.php_cli_binary', '/bin/sh');
        $this->expectException(DocumentRuntimeUnavailableException::class);
        $this->expectExceptionMessage(DocumentRuntimeUnavailableException::PUBLIC_MESSAGE);

        app(PhpCliExecutable::class)->resolve();
    }

    public function test_configured_cli_is_validated_when_open_basedir_blocks_file_metadata(): void
    {
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (@is_file(PHP_BINARY)) {
    fwrite(STDERR, 'The fixture must deny PHP binary metadata reads.');
    exit(1);
}
config()->set('services.documents.php_cli_binary', PHP_BINARY);
echo app(App\Services\PhpCliExecutable::class)->resolve();
PHP;
        $process = new Process([PHP_BINARY, '-d', 'open_basedir='.base_path(), '-r', $script], base_path());

        $process->setTimeout(15)->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame(PHP_BINARY, trim($process->getOutput()));
    }
}
