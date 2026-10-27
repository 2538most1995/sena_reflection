<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** A resumable private staging file; never extracts ZIP members or changes teaching records. */
class SemesterBackupUploadService
{
    private const DIRECTORY = 'semester-backup-uploads';

    private const MIN_CHUNK_BYTES = 64 * 1024;

    private const MAX_CHUNK_BYTES = 8 * 1024 * 1024;

    public function start(User $user, string $name, int $size): array
    {
        $this->district($user);
        if ($size < 22 || $size > SemesterBackupService::MAX_BYTES || mb_strlen($name) > 255
            || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0") || ! preg_match('/\.zip$/iD', $name)) {
            $this->fail('กรุณาเลือกไฟล์ ZIP สำรองที่มีขนาดอยู่ในขีดจำกัดของระบบ');
        }
        $this->pruneExpired($user);
        $active = 0;
        foreach ($this->sessionDirectories() as $directory) {
            $metadata = $this->metadata($directory, false);
            if ($metadata && $metadata['owner'] === (string) $user->id && $metadata['status'] === 'uploading') {
                $active++;
            }
        }
        if ($active >= 3) {
            $this->fail('มีไฟล์นำเข้าที่ค้างอยู่ 3 ชุด กรุณาทำต่อหรือยกเลิกชุดเดิมก่อน');
        }
        $chunkSize = min(self::MAX_CHUNK_BYTES, UploadedFile::getMaxFilesize() - self::MIN_CHUNK_BYTES);
        $chunkSize = (int) (floor($chunkSize / 1024) * 1024);
        if ($chunkSize < self::MIN_CHUNK_BYTES) {
            $this->fail('ขีดจำกัดอัปโหลด PHP ต่ำเกินไปสำหรับการนำเข้าแบบแบ่งส่วน');
        }
        $id = (string) Str::uuid();
        $directory = $this->root().DIRECTORY_SEPARATOR.$id;
        if (! @mkdir($directory, 0700)) {
            $this->fail('สร้างพื้นที่นำเข้าไฟล์ไม่สำเร็จ กรุณาตรวจพื้นที่ว่างของเซิร์ฟเวอร์');
        }
        try {
            $metadata = ['id' => $id, 'owner' => (string) $user->id, 'name' => $name, 'size' => $size,
                'chunk_size' => $chunkSize, 'offset' => 0, 'hashes' => [], 'status' => 'uploading', 'expires_at' => now()->addDay()->timestamp];
            $stream = @fopen($directory.'/archive.zip', 'x+b');
            if ($stream === false) {
                $this->fail('สร้างไฟล์นำเข้าชั่วคราวไม่สำเร็จ');
            }
            fclose($stream);
            @chmod($directory.'/archive.zip', 0600);
            $this->saveMetadata($directory, $metadata);

            return $this->resource($metadata);
        } catch (Throwable $exception) {
            $this->removeDirectory($directory);
            throw $exception;
        }
    }

    /** List only this owner's unfinished uploads so abandoned sessions can be cancelled. */
    public function list(User $user): array
    {
        $this->district($user);
        $this->pruneExpired($user);
        $sessions = [];
        foreach ($this->sessionDirectories() as $directory) {
            $metadata = $this->metadata($directory, false);
            if ($metadata && $metadata['owner'] === (string) $user->id && $metadata['status'] === 'uploading') {
                $sessions[] = [...$this->resource($metadata), 'name' => $metadata['name'], 'size' => $metadata['size']];
            }
        }
        usort($sessions, static fn (array $left, array $right): int => strcmp($right['expires_at'], $left['expires_at']));

        return $sessions;
    }

    public function part(User $user, string $id, int $part, UploadedFile $chunk): array
    {
        return $this->locked($user, $id, function (array $metadata, string $directory) use ($part, $chunk): array {
            if ($metadata['status'] !== 'uploading' || ! $chunk->isValid() || $part < 0 || $part >= $this->parts($metadata)) {
                $this->fail('ส่วนไฟล์นำเข้าไม่ถูกต้องหรือชุดนี้นำเข้าสำเร็จแล้ว');
            }
            $expected = min($metadata['chunk_size'], $metadata['size'] - $part * $metadata['chunk_size']);
            if ($chunk->getSize() !== $expected) {
                $this->fail('ขนาดส่วนไฟล์ไม่ตรงกับที่กำหนด กรุณาลองส่งส่วนเดิมอีกครั้ง');
            }
            $hash = hash_file('sha256', $chunk->getRealPath());
            if (isset($metadata['hashes'][$part])) {
                if (! hash_equals($metadata['hashes'][$part], $hash)) {
                    $this->fail('ส่วนไฟล์ที่ส่งซ้ำมีเนื้อหาต่างจากเดิม กรุณาเริ่มนำเข้าไฟล์ใหม่');
                }
                $this->verifyCommittedPart($directory, $metadata, $part, $expected);

                return $this->resource($metadata);
            }
            if ($part !== count($metadata['hashes'])) {
                $this->fail('ต้องอัปโหลดส่วนไฟล์ตามลำดับ กรุณาส่งต่อจากส่วนล่าสุด');
            }
            $path = $this->archivePath($directory);
            $output = @fopen($path, 'c+b');
            $input = @fopen($chunk->getRealPath(), 'rb');
            if ($output === false || $input === false) {
                if (is_resource($output)) {
                    fclose($output);
                }
                if (is_resource($input)) {
                    fclose($input);
                }
                $this->fail('เปิดไฟล์นำเข้าไม่ได้ กรุณาตรวจพื้นที่ว่างและลองใหม่');
            }
            try {
                $stat = fstat($output);
                if (! $stat || $stat['size'] < $metadata['offset'] || ! ftruncate($output, $metadata['offset']) || fseek($output, $metadata['offset']) !== 0) {
                    $this->fail('ไฟล์นำเข้าชั่วคราวไม่ครบ กรุณาเริ่มนำเข้าใหม่');
                }
                $copied = stream_copy_to_stream($input, $output, $expected);
                if ($copied !== $expected || ! fflush($output)) {
                    ftruncate($output, $metadata['offset']);
                    $this->fail('เขียนไฟล์นำเข้าไม่สำเร็จ กรุณาตรวจพื้นที่ว่างแล้วลองส่วนเดิมอีกครั้ง');
                }
                $updated = $metadata;
                $updated['hashes'][] = $hash;
                $updated['offset'] += $copied;
                try {
                    $this->saveMetadata($directory, $updated);
                } catch (Throwable $exception) {
                    ftruncate($output, $metadata['offset']);
                    throw $exception;
                }

                return $this->resource($updated);
            } finally {
                fclose($input);
                fclose($output);
            }
        });
    }

    public function finish(User $user, string $id): array
    {
        return $this->locked($user, $id, function (array $metadata, string $directory) use ($user): array {
            if ($metadata['status'] === 'completed') {
                return ['backup' => $metadata['backup']];
            }
            if ($metadata['offset'] !== $metadata['size'] || count($metadata['hashes']) !== $this->parts($metadata)) {
                $this->fail('อัปโหลดไฟล์ยังไม่ครบทุกส่วน กรุณาอัปโหลดต่อก่อนตรวจ ZIP');
            }
            $path = $this->archivePath($directory);
            clearstatcache(true, $path);
            if (! is_file($path) || filesize($path) !== $metadata['size']) {
                $this->fail('ไฟล์นำเข้าชั่วคราวมีขนาดไม่ครบ กรุณาเริ่มนำเข้าใหม่');
            }
            $input = @fopen($path, 'rb');
            if ($input === false) {
                $this->fail('เปิดไฟล์ ZIP ที่อัปโหลดไม่สำเร็จ');
            }
            try {
                $header = fread($input, 4);
                if (! in_array($header, ["PK\x03\x04", "PK\x05\x06"], true)) {
                    $this->fail('ไฟล์ที่อัปโหลดไม่ใช่ ZIP สำรองที่รองรับ');
                }
                rewind($input);
                foreach ($metadata['hashes'] as $index => $expectedHash) {
                    $bytes = min($metadata['chunk_size'], $metadata['size'] - $index * $metadata['chunk_size']);
                    $hash = hash_init('sha256');
                    if (hash_update_stream($hash, $input, $bytes) !== $bytes || ! hash_equals($expectedHash, hash_final($hash))) {
                        $this->fail('ไฟล์นำเข้าชั่วคราวเสียหาย กรุณาเริ่มอัปโหลดใหม่');
                    }
                }
            } finally {
                fclose($input);
            }
            $backup = app(SemesterBackupService::class)->importStaged($user, $path);
            $metadata['status'] = 'completed';
            $metadata['backup'] = $backup;
            $this->saveMetadata($directory, $metadata);

            return ['backup' => $backup];
        });
    }

    public function cancel(User $user, string $id): array
    {
        return $this->locked($user, $id, function (array $metadata, string $directory): array {
            $this->removeDirectory($directory);

            return ['cancelled' => true];
        }, true);
    }

    public function pruneExpired(User $user): int
    {
        $this->district($user);
        $removed = 0;
        foreach ($this->sessionDirectories() as $directory) {
            $metadata = $this->metadata($directory, false);
            if (! $metadata || $metadata['owner'] !== (string) $user->id || $metadata['expires_at'] > now()->timestamp) {
                continue;
            }
            if (is_link($directory.'/.lock')) {
                continue;
            }
            $lock = @fopen($directory.'/.lock', 'c+b');
            if ($lock === false) {
                continue;
            }
            try {
                if (! flock($lock, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                $current = $this->metadata($directory, false);
                if ($current && $current['owner'] === (string) $user->id && $current['expires_at'] <= now()->timestamp) {
                    $this->removeDirectory($directory);
                    $removed++;
                }
            } finally {
                fclose($lock);
            }
        }

        return $removed;
    }

    private function locked(User $user, string $id, callable $operation, bool $allowExpired = false): array
    {
        $this->district($user);
        abort_unless(Str::isUuid($id), 404);
        $directory = $this->root().'/'.$id;
        abort_unless(is_dir($directory) && ! is_link($directory), 404);
        if (is_link($directory.'/.lock')) {
            $this->fail('พื้นที่นำเข้าไฟล์ไม่ปลอดภัย');
        }
        $lock = @fopen($directory.'/.lock', 'c+b');
        if ($lock === false) {
            $this->fail('ล็อกไฟล์นำเข้าไม่สำเร็จ');
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                $this->fail('มีการส่งไฟล์ชุดนี้อยู่ กรุณารอสักครู่แล้วลองส่วนเดิมอีกครั้ง');
            }
            $metadata = $this->metadata($directory);
            abort_unless($metadata['owner'] === (string) $user->id, 404);
            abort_if(! $allowExpired && $metadata['expires_at'] <= now()->timestamp, 410, 'ชุดนำเข้าหมดอายุแล้ว กรุณาเริ่มอัปโหลดใหม่');

            return $operation($metadata, $directory);
        } finally {
            fclose($lock);
        }
    }

    private function verifyCommittedPart(string $directory, array $metadata, int $part, int $expected): void
    {
        $stream = @fopen($this->archivePath($directory), 'c+b');
        if ($stream === false) {
            $this->fail('เปิดส่วนไฟล์ที่อัปโหลดไว้ไม่สำเร็จ');
        }
        try {
            $stat = fstat($stream);
            if (! $stat || $stat['size'] < $metadata['offset'] || ! ftruncate($stream, $metadata['offset'])
                || fseek($stream, $part * $metadata['chunk_size']) !== 0) {
                $this->fail('ส่วนไฟล์ที่อัปโหลดไว้ไม่ครบ กรุณาเริ่มอัปโหลดใหม่');
            }
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $stream, $expected) !== $expected || ! hash_equals($metadata['hashes'][$part], hash_final($hash))) {
                $this->fail('ส่วนไฟล์ที่อัปโหลดไว้เสียหาย กรุณาเริ่มอัปโหลดใหม่');
            }
        } finally {
            fclose($stream);
        }
    }

    private function metadata(string $directory, bool $required = true): ?array
    {
        $path = $directory.'/metadata.json';
        if (! is_file($path) || is_link($path) || filesize($path) > 4 * 1024 * 1024) {
            if ($required) {
                $this->fail('ข้อมูลชุดนำเข้าเสียหาย กรุณาเริ่มนำเข้าใหม่');
            }

            return null;
        }
        try {
            $metadata = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($metadata) || ! is_string($metadata['id'] ?? null) || basename($directory) !== $metadata['id']
                || ! is_string($metadata['name'] ?? null) || mb_strlen($metadata['name']) > 255
                || ! is_string($metadata['owner'] ?? null) || ! is_int($metadata['size'] ?? null) || $metadata['size'] < 22 || $metadata['size'] > SemesterBackupService::MAX_BYTES
                || ! is_int($metadata['chunk_size'] ?? null) || $metadata['chunk_size'] < self::MIN_CHUNK_BYTES || $metadata['chunk_size'] > self::MAX_CHUNK_BYTES
                || ! is_array($metadata['hashes'] ?? null) || ! array_is_list($metadata['hashes']) || count($metadata['hashes']) > $this->parts($metadata)
                || ! is_int($metadata['offset'] ?? null) || $metadata['offset'] !== min($metadata['size'], count($metadata['hashes']) * $metadata['chunk_size'])
                || ! is_int($metadata['expires_at'] ?? null) || ! in_array($metadata['status'] ?? '', ['uploading', 'completed'], true)
                || (($metadata['status'] ?? '') === 'completed' && (! is_array($metadata['backup'] ?? null) || ! is_string($metadata['backup']['id'] ?? null)))) {
                throw new \RuntimeException('Invalid staging metadata');
            }
            foreach ($metadata['hashes'] as $hash) {
                if (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                    throw new \RuntimeException('Invalid chunk hash');
                }
            }

            return $metadata;
        } catch (Throwable) {
            if ($required) {
                $this->fail('ข้อมูลชุดนำเข้าเสียหาย กรุณาเริ่มนำเข้าใหม่');
            }

            return null;
        }
    }

    private function saveMetadata(string $directory, array $metadata): void
    {
        $bytes = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_link($directory.'/.metadata.tmp') || is_link($directory.'/metadata.json')
            || file_put_contents($directory.'/.metadata.tmp', $bytes, LOCK_EX) !== strlen($bytes) || ! rename($directory.'/.metadata.tmp', $directory.'/metadata.json')) {
            $this->fail('บันทึกความคืบหน้านำเข้าไม่สำเร็จ กรุณาตรวจพื้นที่ว่างและลองใหม่');
        }
        @chmod($directory.'/metadata.json', 0600);
    }

    private function root(): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIRECTORY);
        $root = $disk->path(self::DIRECTORY);
        if (! is_dir($root) || is_link($root)) {
            $this->fail('พื้นที่นำเข้าไฟล์ไม่ปลอดภัย');
        }

        return $root;
    }

    private function sessionDirectories(): array
    {
        $directories = [];
        $root = $this->root();
        foreach (scandir($root) ?: [] as $id) {
            if (Str::isUuid($id) && is_dir($root.'/'.$id) && ! is_link($root.'/'.$id)) {
                $directories[] = $root.'/'.$id;
            }
        }

        return $directories;
    }

    private function archivePath(string $directory): string
    {
        $path = $directory.'/archive.zip';
        if (is_link($path)) {
            $this->fail('ไฟล์นำเข้าชั่วคราวไม่ปลอดภัย');
        }

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (['archive.zip', '.metadata.tmp', 'metadata.json', '.lock'] as $name) {
            if ((is_file($directory.'/'.$name) || is_link($directory.'/'.$name)) && ! @unlink($directory.'/'.$name)) {
                $this->fail('ล้างไฟล์นำเข้าชั่วคราวไม่สำเร็จ');
            }
        }
        if (! @rmdir($directory)) {
            $this->fail('ล้างโฟลเดอร์นำเข้าชั่วคราวไม่สำเร็จ');
        }
    }

    private function resource(array $metadata): array
    {
        return ['id' => $metadata['id'], 'chunk_size' => $metadata['chunk_size'], 'parts' => $this->parts($metadata),
            'uploaded_parts' => count($metadata['hashes']), 'uploaded_bytes' => $metadata['offset'], 'total_bytes' => $metadata['size'],
            'expires_at' => date(DATE_ATOM, $metadata['expires_at'])];
    }

    private function parts(array $metadata): int
    {
        return (int) ceil($metadata['size'] / $metadata['chunk_size']);
    }

    private function district(User $user): void
    {
        abort_unless($user->isDistrict(), 403);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['archive' => $message]);
    }
}
