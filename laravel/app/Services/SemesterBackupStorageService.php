<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Only release original images that remain recoverable from a verified semester ZIP. */
class SemesterBackupStorageService
{
    private const REFERENCES = [
        'record_images' => ['id', ['file_path'], []],
        'draft_images' => ['id', ['storage_path'], []],
        'document_assets' => ['id', ['id', 'storage_path'], []],
        'documents' => ['id', [], ['content_json', 'page_settings_json', 'objects_json', 'binding_snapshot']],
        'document_versions' => ['id', [], ['snapshot_json']],
        'record_revisions' => ['id', [], ['snapshot']],
        'record_document_layouts' => ['record_id', [], ['settings', 'content']],
        'teaching_drafts' => ['id', [], ['payload']],
        'settings' => ['setting_key', ['setting_value'], []],
        'users' => ['id', ['avatar_path'], []],
    ];

    /**
     * The caller supplies only ZIP-verified files and a path resolver with disk/root/symlink checks.
     * Optional asset_ids/urls are derived from the archived tables, never from request input.
     *
     * @param  list<array{disk: string, path: string, size: int, sha256: string, asset_ids?: list<string>, urls?: list<string>}>  $files
     * @param  callable(string, string): string  $resolvePath
     */
    public function inspect(array $files, callable $resolvePath): array
    {
        return $this->summarize($this->plan($files, $resolvePath, false));
    }

    /** The caller holds the shared restore/cleanup flock and the archived backup row lock. */
    public function release(array $files, callable $resolvePath): array
    {
        if (DB::connection()->transactionLevel() < 1) {
            $this->fail('ต้องล็อกข้อมูลชุดสำรองก่อนคืนพื้นที่รูปภาพ');
        }
        $this->requireRangeLocks();
        $entries = $this->plan($files, $resolvePath, true);
        // A second current locking scan covers references committed while the first scan was starting.
        $shared = $this->references($files, true);
        $deleted = 0;
        $bytes = 0;
        foreach ($entries as &$entry) {
            if ($entry['status'] !== 'releasable') {
                continue;
            }
            if (isset($shared[$entry['key']])) {
                $entry['status'] = 'shared';

                continue;
            }
            $file = $entry['file'];
            try {
                $path = $resolvePath($file['disk'], $file['path']);
            } catch (Throwable) {
                $entry['status'] = 'changed';

                continue;
            }
            $entry['status'] = $this->fileState($path, $file);
            if ($entry['status'] !== 'releasable') {
                continue;
            }
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                $entry['status'] = 'failed';

                continue;
            }
            try {
                if (! flock($handle, LOCK_EX | LOCK_NB)) {
                    $entry['status'] = 'failed';

                    continue;
                }
                $stat = fstat($handle);
                $hash = hash_init('sha256');
                hash_update_stream($hash, $handle);
                clearstatcache(true, $path);
                $current = @lstat($path);
                if (! $stat || ! $current || is_link($path) || $stat['ino'] !== $current['ino'] || $stat['dev'] !== $current['dev']
                    || $stat['size'] !== $file['size'] || ! hash_equals($file['sha256'], hash_final($hash))) {
                    $entry['status'] = 'changed';

                    continue;
                }
                if (! @unlink($path)) {
                    $entry['status'] = 'failed';

                    continue;
                }
                $entry['status'] = 'deleted';
                $deleted++;
                $bytes += $file['size'];
            } finally {
                fclose($handle);
            }
        }
        unset($entry);

        return [...$this->summarize($entries), 'deleted_count' => $deleted, 'deleted_bytes' => $bytes];
    }

    private function plan(array $files, callable $resolvePath, bool $lock): array
    {
        $shared = $this->references($files, $lock);
        $entries = [];
        foreach ($files as $file) {
            $key = $this->key($file);
            if (isset($entries[$key])) {
                continue;
            }
            $path = $resolvePath($file['disk'], $file['path']);
            $state = $this->fileState($path, $file);
            if ($state === 'releasable' && (isset($shared[$key]) || ($file['disk'] === 'local' && empty($file['asset_ids']) && empty($file['urls'])))) {
                $state = 'shared';
            }
            $entries[$key] = ['key' => $key, 'file' => $file, 'status' => $state];
        }

        return $entries;
    }

    private function fileState(string $path, array $file): string
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            return 'changed';
        }
        if (! file_exists($path)) {
            return 'missing';
        }
        if (! is_file($path) || ! is_readable($path) || @filesize($path) !== $file['size']) {
            return 'changed';
        }
        $hash = @hash_file('sha256', $path);

        return is_string($hash) && hash_equals($file['sha256'], $hash) ? 'releasable' : 'changed';
    }

    /** Lock complete indexed ranges, including empty ranges, to protect against new references. */
    private function references(array $files, bool $lock): array
    {
        $aliases = [];
        foreach ($files as $file) {
            $key = $this->key($file);
            $values = [$file['path'], basename($file['path']), ...($file['asset_ids'] ?? []), ...($file['urls'] ?? [])];
            if ($file['disk'] === 'public') {
                $values[] = 'storage/'.$file['path'];
            }
            foreach ($values as $value) {
                $aliases[$this->normalize($value)][$key] = true;
            }
        }
        $shared = [];
        $scan = function (mixed $value) use (&$scan, &$shared, $aliases): void {
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $scan((string) $key);
                    $scan($item);
                }

                return;
            }
            if (! is_string($value) || $value === '') {
                return;
            }
            $text = html_entity_decode(rawurldecode(str_replace('\\/', '/', $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $tokens = [$text];
            preg_match_all('~[\pL\pN_.%+-]+(?:/[\pL\pN_.%+-]+)+|[a-f0-9]{8}-[a-f0-9-]{27,}|[a-f0-9]{64}|[\pL\pN_.-]+\.(?:jpe?g|png|webp|gif)~iu', $text, $matches);
            if (preg_last_error() !== PREG_NO_ERROR) {
                foreach ($aliases as $keys) {
                    $shared += $keys;
                }

                return;
            }
            array_push($tokens, ...$matches[0]);
            foreach ($tokens as $token) {
                $shared += $aliases[$this->normalize($token)] ?? [];
                $shared += $aliases[basename($token)] ?? [];
            }
        };
        foreach (self::REFERENCES as $table => [$order, $columns, $jsonColumns]) {
            if (! Schema::hasTable($table) || array_diff([...$columns, ...$jsonColumns], Schema::getColumnListing($table)) !== []) {
                $this->fail('โครงสร้างข้อมูลอ้างอิงรูปภาพยังไม่ครบ จึงยังคืนพื้นที่อย่างปลอดภัยไม่ได้ กรุณาตรวจ migrations');
            }
            $query = DB::table($table)->select(array_unique([$order, ...$columns, ...$jsonColumns]))->orderBy($order);
            if ($table === 'record_images') {
                // Some legacy imports have duplicate zero IDs; identical paths are the only remaining ties.
                $query->orderBy('file_path');
            }
            if ($lock) {
                $query->lockForUpdate();
            }
            foreach ($query->lazy(100) as $row) {
                foreach ($columns as $column) {
                    $scan($row->$column);
                }
                foreach ($jsonColumns as $column) {
                    if ($row->$column === null || $row->$column === '') {
                        continue;
                    }
                    try {
                        $scan(json_decode($row->$column, true, 512, JSON_THROW_ON_ERROR));
                    } catch (Throwable) {
                        foreach ($aliases as $keys) {
                            $shared += $keys;
                        }
                    }
                }
            }
        }

        return $shared;
    }

    private function normalize(string $value): string
    {
        $value = preg_replace('~[?#].*$~', '', trim(rawurldecode($value))) ?? '';
        $value = str_replace('\\', '/', $value);
        if (preg_match('~(?:^|/)((?:api/)?documents/.*|uploads/.*|storage/.*|records/.*)$~D', $value, $match)) {
            return $match[1];
        }

        return ltrim($value, '/');
    }

    private function key(array $file): string
    {
        return $file['disk'].':'.$file['path'];
    }

    private function summarize(array $entries): array
    {
        $result = ['releasable_count' => 0, 'releasable_bytes' => 0, 'shared_count' => 0, 'changed_count' => 0, 'missing_count' => 0, 'failed_count' => 0, 'files' => []];
        foreach ($entries as $entry) {
            $status = $entry['status'];
            if ($status !== 'deleted') {
                $result[$status.'_count']++;
            }
            if ($status === 'releasable') {
                $result['releasable_bytes'] += $entry['file']['size'];
            }
            $result['files'][] = ['disk' => $entry['file']['disk'], 'path' => $entry['file']['path'], 'status' => $status];
        }

        return $result;
    }

    private function requireRangeLocks(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        try {
            $isolation = (string) DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level;
        } catch (Throwable) {
            $isolation = (string) DB::selectOne('SELECT @@tx_isolation AS isolation_level')->isolation_level;
        }
        if (! in_array(strtoupper(str_replace(' ', '-', $isolation)), ['REPEATABLE-READ', 'SERIALIZABLE'], true)) {
            $this->fail('ต้องใช้ transaction isolation แบบ REPEATABLE READ หรือ SERIALIZABLE ก่อนคืนพื้นที่ เพื่อป้องกันรูปที่ถูกใช้งานร่วมกัน');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['archive' => $message]);
    }
}
