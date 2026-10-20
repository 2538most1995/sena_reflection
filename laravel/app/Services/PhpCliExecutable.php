<?php

namespace App\Services;

use App\Exceptions\DocumentRuntimeUnavailableException;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class PhpCliExecutable
{
    private ?string $resolvedPath = null;

    public function __construct(private readonly PhpExecutableFinder $finder) {}

    public function resolve(): string
    {
        if ($this->resolvedPath !== null) {
            return $this->resolvedPath;
        }
        if (! function_exists('proc_open')) {
            throw new DocumentRuntimeUnavailableException(new RuntimeException('Document runtime requires proc_open.'));
        }

        $configured = trim((string) config('services.documents.php_cli_binary', ''));
        $candidate = $configured !== '' ? $configured : (@$this->finder->find(false) ?: PHP_BINDIR.DIRECTORY_SEPARATOR.'php');
        if ($candidate === '' || str_contains($candidate, "\0")
            || ! (str_starts_with($candidate, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $candidate))
            || preg_match('/(?:fpm|cgi|phpdbg)/i', basename($candidate))) {
            throw new DocumentRuntimeUnavailableException(new RuntimeException('A valid absolute PHP_CLI_BINARY CLI path is required.'));
        }

        try {
            // Plesk can allow proc_open while open_basedir denies metadata reads
            // for /opt/plesk/php. Probe only this server-configured executable;
            // the operating system still enforces the process user's permissions.
            $probe = new Process([$candidate, '-r', 'echo PHP_SAPI, ":", PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;']);
            $probe->setTimeout(5)->mustRun();
            $expected = 'cli:'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
            if (trim($probe->getOutput()) !== $expected) {
                throw new RuntimeException('PHP CLI must match the web PHP version.');
            }
        } catch (\Throwable $exception) {
            throw new DocumentRuntimeUnavailableException($exception);
        }

        return $this->resolvedPath = $candidate;
    }
}
