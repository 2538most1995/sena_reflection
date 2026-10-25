<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

class SemesterBackupService
{
    public const MAX_BYTES = 512 * 1024 * 1024;

    private const MAX_DATA_BYTES = 64 * 1024 * 1024;

    private const TABLES = ['weekly_submissions', 'records', 'record_images', 'record_attendance', 'record_document_layouts', 'documents', 'document_assets', 'document_versions', 'record_revisions'];

    private const TABLE_LABELS = [
        'weekly_submissions' => 'บันทึกประจำสัปดาห์', 'records' => 'บันทึกหลังสอน',
        'record_images' => 'รูปกิจกรรม', 'record_attendance' => 'รายชื่อเข้าเรียน',
        'record_document_layouts' => 'รูปแบบเอกสาร', 'documents' => 'เอกสารจัดหน้า',
        'document_assets' => 'ไฟล์ประกอบเอกสาร', 'document_versions' => 'ประวัติเอกสาร',
        'record_revisions' => 'ฉบับย้อนหลังของบันทึก',
    ];

    private const JSON_COLUMNS = ['settings', 'content', 'content_json', 'page_settings_json', 'objects_json', 'binding_snapshot', 'snapshot_json', 'snapshot'];

    public function index(int $page = 1): array
    {
        $periods = DB::table('records as period_records')->select('year', 'semester')->selectRaw('COUNT(*) AS records')->groupBy('year', 'semester');
        foreach (['record_attendance' => 'attendance', 'record_images' => 'images'] as $table => $alias) {
            $periods->selectSub(DB::table($table.' as child')->join('records as source', 'source.id', '=', 'child.record_id')->selectRaw('COUNT(*)')
                ->whereColumn('source.year', 'period_records.year')->whereColumn('source.semester', 'period_records.semester'), $alias);
        }
        $periods->selectSub(DB::table('teaching_drafts as draft')->selectRaw('COUNT(*)')->where('draft.status', 'active')->where(function (Builder $query): void {
            $query->where(fn (Builder $scope): Builder => $scope->whereColumn('draft.year', 'period_records.year')->whereColumn('draft.semester', 'period_records.semester'))
                ->orWhereExists(fn (Builder $source): Builder => $source->selectRaw('1')->from('records as source')->whereColumn('source.id', 'draft.source_record_id')->whereColumn('source.year', 'period_records.year')->whereColumn('source.semester', 'period_records.semester'));
        }), 'active_drafts');
        $semesters = $periods->orderByDesc('year')->orderByDesc('semester')->get()->map(static fn ($period): array => ['year' => (string) $period->year, 'semester' => (string) $period->semester,
            'records' => (int) $period->records, 'attendance' => (int) $period->attendance, 'images' => (int) $period->images, 'active_drafts' => (int) $period->active_drafts])->all();

        $uploadLimit = min(self::MAX_BYTES, UploadedFile::getMaxFilesize());
        $backups = DB::table('semester_backups')->orderByDesc('created_at')->orderByDesc('id')->paginate(30, ['*'], 'backup_page', $page);

        return ['semesters' => $semesters, 'backups' => $backups->getCollection()->map(fn ($backup): array => $this->resource($backup))->all(),
            'backupPagination' => ['page' => $backups->currentPage(), 'last_page' => $backups->lastPage(), 'total' => $backups->total(), 'per_page' => $backups->perPage()],
            'limits' => ['max_upload_mb' => round($uploadLimit / 1024 / 1024, 2)]];
    }

    public function create(User $user, string $year, string $semester): array
    {
        if (! class_exists(ZipArchive::class)) {
            $this->fail('เซิร์ฟเวอร์ยังไม่ได้เปิด PHP ZIP กรุณาเปิดส่วนขยายนี้ก่อนสำรองข้อมูล');
        }
        $id = (string) Str::uuid();
        $relative = 'semester-backups/'.$id.'.zip';
        $disk = Storage::disk('local');
        $disk->makeDirectory('semester-backups');
        try {
            $manifest = DB::transaction(function () use ($year, $semester, $relative): array {
                $tables = $this->capture($year, $semester, true);
                if ($tables['records'] === []) {
                    $this->fail('ไม่พบบันทึกในภาคเรียนที่เลือก');
                }
                $files = $this->files($tables);
                $data = $this->json($tables);
                if (strlen($data) > self::MAX_DATA_BYTES) {
                    $this->fail('ข้อมูลภาคเรียนมีขนาดเกิน 64 MB กรุณาแบ่งชุดข้อมูลก่อนสำรอง');
                }
                $manifest = ['format' => 'sena-semester-backup', 'version' => 1, 'created_at' => now()->toIso8601String(),
                    'scope' => ['year' => $year, 'semester' => $semester], 'counts' => $this->counts($tables),
                    'data_sha256' => hash('sha256', $data), 'fingerprint' => $this->fingerprint($tables),
                    'files' => $files, 'identities' => $this->identities($tables)];
                $manifest['signature'] = $this->signature($manifest);
                $zip = new ZipArchive;
                if ($zip->open(Storage::disk('local')->path($relative), ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                    $this->fail('สร้างไฟล์สำรองไม่สำเร็จ');
                }
                try {
                    $zip->addFromString('manifest.json', $this->json($manifest));
                    $zip->addFromString('data.json', $data);
                    foreach ($files as $file) {
                        if (! $zip->addFile($this->physicalPath($file['disk'], $file['path']), $file['entry'])) {
                            $this->fail('อ่านรูปภาพเพื่อสำรองไม่สำเร็จ');
                        }
                    }
                } finally {
                    if (! $zip->close()) {
                        $this->fail('บันทึกไฟล์ ZIP ไม่สำเร็จ');
                    }
                }

                return $manifest;
            });
            $this->read($disk->path($relative));
            $this->catalog($id, $user, $relative, $manifest, 'ready');

            return $this->resource($this->find($id));
        } catch (Throwable $exception) {
            $disk->delete($relative);
            throw $exception;
        }
    }

    public function import(User $user, UploadedFile $upload): array
    {
        $manifest = $this->read($upload->getRealPath())['manifest'];
        $id = (string) Str::uuid();
        $relative = 'semester-backups/'.$id.'.zip';
        if (! Storage::disk('local')->putFileAs('semester-backups', $upload, $id.'.zip')) {
            $this->fail('เก็บไฟล์ที่นำเข้าไม่สำเร็จ');
        }
        try {
            $this->catalog($id, $user, $relative, $manifest, 'imported');

            return $this->resource($this->find($id));
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($relative);
            throw $exception;
        }
    }

    public function inspect(string $id): array
    {
        $backup = $this->find($id);
        $bundle = $this->verified($backup);
        $blockers = $this->archiveBlockers($backup, $bundle['tables'], $bundle['manifest']['files']);
        $conflicts = $this->restoreConflicts($bundle['tables'], $bundle['manifest']);

        return ['backup' => $this->resource($backup), 'can_archive' => $blockers === [], 'can_restore' => $conflicts === [], 'blockers' => $blockers, 'conflicts' => $conflicts];
    }

    public function archive(string $id): array
    {
        DB::transaction(function () use ($id): void {
            $backup = $this->find($id, true);
            $bundle = $this->verified($backup);
            $tables = $bundle['tables'];
            $current = $this->capture($backup->year, $backup->semester, true);
            $this->activeDrafts($backup->year, $backup->semester, array_column($tables['records'], 'id'))->lockForUpdate()->get(['id']);
            $blockers = $this->archiveBlockers($backup, $tables, $bundle['manifest']['files'], $current);
            if ($blockers !== []) {
                $this->fail(implode(' ', $blockers));
            }
            foreach (array_reverse(self::TABLES) as $table) {
                $key = $table === 'record_document_layouts' ? 'record_id' : 'id';
                foreach (array_chunk(array_column($tables[$table], $key), 500) as $ids) {
                    if ($table === 'weekly_submissions' && DB::table('records')->whereIn('weekly_submission_id', $ids)->exists()) {
                        $this->fail('มีบันทึกใหม่อ้างถึงชุดส่งงานนี้ระหว่างเก็บภาคเรียน กรุณาสำรองใหม่');
                    }
                    $query = DB::table($table)->whereIn($key, $ids);
                    if (str_starts_with($table, 'record_')) {
                        $query->whereIn('record_id', array_column($tables['records'], 'id'));
                    }
                    $query->delete();
                }
            }
            DB::table('semester_backups')->where('id', $id)->update(['status' => 'archived', 'archived_at' => now(), 'updated_at' => now()]);
        }, 3);

        return $this->resource($this->find($id));
    }

    public function restore(string $id): array
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('semester-backups');
        $lock = @fopen($disk->path('semester-backups/.restore.lock'), 'c+b');
        if ($lock === false) {
            $this->fail('เปิดพื้นที่ล็อกการนำกลับไม่สำเร็จ กรุณาตรวจสิทธิ์ private storage');
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $this->fail('มีการนำข้อมูลกลับที่กำลังทำงานอยู่ กรุณารอให้เสร็จก่อนลองใหม่');
        }
        try {
            return $this->restoreLocked($id);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function restoreLocked(string $id): array
    {
        $written = [];
        try {
            DB::transaction(function () use ($id, &$written): void {
                $backup = $this->find($id, true);
                $bundle = $this->verified($backup);
                $conflicts = $this->restoreConflicts($bundle['tables'], $bundle['manifest']);
                if ($conflicts !== []) {
                    $this->fail(implode(' ', $conflicts));
                }
                $zip = new ZipArchive;
                $zip->open(Storage::disk('local')->path($backup->storage_path));
                try {
                    foreach ($bundle['manifest']['files'] as $file) {
                        $target = $this->physicalPath($file['disk'], $file['path']);
                        if (is_file($target)) {
                            if (! hash_equals($file['sha256'], hash_file('sha256', $target))) {
                                $this->fail('มีไฟล์เดิมชื่อเดียวกันแต่เนื้อหาต่างกัน จึงนำเข้าทับไม่ได้');
                            }

                            continue;
                        }
                        $directory = dirname($target);
                        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
                            $this->fail('สร้างโฟลเดอร์รูปภาพไม่สำเร็จ');
                        }
                        $stream = $zip->getStream($file['entry']);
                        $output = @fopen($target, 'x+b');
                        if ($stream === false || $output === false) {
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                            $this->fail('สร้างไฟล์รูปภาพไม่สำเร็จ');
                        }
                        $written[] = $target;
                        try {
                            $copied = stream_copy_to_stream($stream, $output);
                        } finally {
                            fclose($stream);
                            fclose($output);
                        }
                        if ($copied !== $file['size'] || ! hash_equals($file['sha256'], hash_file('sha256', $target))) {
                            $this->fail('ตรวจสอบรูปภาพที่กู้คืนไม่ผ่าน');
                        }
                    }
                } finally {
                    $zip->close();
                }
                $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
                $mode = $mysql ? (string) DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode : null;
                try {
                    if ($mysql) {
                        DB::statement('SET SESSION sql_mode = ?', [implode(',', array_unique([...array_filter(explode(',', $mode)), 'NO_AUTO_VALUE_ON_ZERO']))]);
                    }
                    foreach (self::TABLES as $table) {
                        foreach (array_chunk($bundle['tables'][$table], 100) as $rows) {
                            DB::table($table)->insert($rows);
                        }
                    }
                } finally {
                    if ($mysql) {
                        DB::statement('SET SESSION sql_mode = ?', [$mode]);
                    }
                }
                DB::table('semester_backups')->where('id', $id)->update(['status' => 'restored', 'restored_at' => now(), 'updated_at' => now()]);
            });
        } catch (Throwable $exception) {
            foreach ($written as $path) {
                @unlink($path);
            }
            if ($exception instanceof QueryException && in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                $this->fail('มีข้อมูลที่ขัดแย้งเกิดขึ้นระหว่างนำกลับ ระบบยกเลิกการนำกลับทั้งหมดโดยไม่เขียนทับข้อมูลเดิม กรุณาตรวจสอบอีกครั้ง');
            }
            throw $exception;
        }

        return $this->resource($this->find($id));
    }

    public function downloadPath(string $id): string
    {
        $backup = $this->find($id);
        $this->verified($backup);
        DB::table('semester_backups')->where('id', $id)->update(['downloaded_at' => now(), 'updated_at' => now()]);

        return Storage::disk('local')->path($backup->storage_path);
    }

    public function find(string $id, bool $lock = false): object
    {
        $query = DB::table('semester_backups')->where('id', $id);
        $backup = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($backup, 404);

        return $backup;
    }

    private function capture(string $year, string $semester, bool $lock = false): array
    {
        $fetch = function (Builder $query, string $key = 'id') use ($lock): array {
            $query->orderBy($key);

            $rows = ($lock ? $query->lockForUpdate() : $query)->get()->map(static fn ($row): array => (array) $row)->all();
            usort($rows, fn (array $left, array $right): int => strcmp($this->json($left), $this->json($right)));

            return $rows;
        };
        $tables = [];
        $tables['weekly_submissions'] = $fetch(DB::table('weekly_submissions')->where('year', $year)->where('semester', $semester));
        $tables['records'] = $fetch(DB::table('records')->where('year', $year)->where('semester', $semester));
        $ids = array_column($tables['records'], 'id');
        foreach (['record_images', 'record_attendance', 'record_document_layouts', 'record_revisions'] as $table) {
            $tables[$table] = $fetch(DB::table($table)->whereIn('record_id', $ids), $table === 'record_document_layouts' ? 'record_id' : 'id');
        }
        $documentIds = [];
        foreach ($tables['record_revisions'] as $revision) {
            $snapshot = json_decode($revision['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            if (isset($snapshot['advanced_document']['id'])) {
                $documentIds[] = $snapshot['advanced_document']['id'];
            }
        }
        $tables['documents'] = $fetch(DB::table('documents')->where(function (Builder $query) use ($ids, $documentIds): void {
            $query->whereIn('binding_key', array_map(static fn ($id): string => 'record:'.$id, $ids))->orWhereIn('id', $documentIds);
        }));
        foreach ($tables['documents'] as $document) {
            $binding = json_decode($document['binding_snapshot'] ?? 'null', true);
            if (($binding['kind'] ?? '') !== 'record' || ! in_array($binding['record_id'] ?? '', $ids, true)) {
                $this->fail('ประวัติเอกสารอ้างถึงเอกสารส่วนตัวหรือข้อมูลนอกภาคเรียน กรุณาตรวจสอบก่อนสำรอง');
            }
        }
        $documentIds = array_column($tables['documents'], 'id');
        foreach (['document_assets', 'document_versions'] as $table) {
            $tables[$table] = $fetch(DB::table($table)->whereIn('document_id', $documentIds));
        }
        $submissionIds = array_column($tables['weekly_submissions'], 'id');
        if (DB::table('records')->whereIn('weekly_submission_id', $submissionIds)->whereNotIn('id', $ids)->exists()) {
            $this->fail('การส่งบันทึกบางชุดอ้างถึงข้อมูลนอกภาคเรียน ต้องแก้ข้อมูลให้สอดคล้องกันก่อนสำรอง');
        }
        $this->validateRelations($tables);

        return array_replace(array_fill_keys(self::TABLES, []), $tables);
    }

    private function files(array $tables): array
    {
        $paths = [];
        $addImage = function (string $stored) use (&$paths): void {
            $path = RecordImageAssets::normalizeStoredPath($stored);
            if ($path === null || (! str_starts_with($path, 'storage/') && ! str_starts_with($path, 'uploads/'))) {
                $this->fail('พบที่อยู่รูปภาพที่ไม่รองรับในชุดข้อมูลสำรอง');
            }
            $disk = str_starts_with($path, 'storage/') ? 'public' : 'legacy';
            $relative = $disk === 'public' ? substr($path, 8) : $path;
            $paths[$disk.':'.$relative] = ['disk' => $disk, 'path' => $relative];
        };
        foreach ($tables['record_images'] as $image) {
            $addImage($image['file_path']);
        }
        foreach ($tables['record_revisions'] as $revision) {
            $snapshot = json_decode($revision['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            foreach ($snapshot['images'] ?? [] as $image) {
                $addImage($image['file_path']);
            }
        }
        foreach ($tables['document_assets'] as $asset) {
            if (! str_starts_with($asset['storage_path'], 'documents/'.$asset['document_id'].'/')) {
                $this->fail('ที่อยู่ไฟล์เอกสารไม่ตรงกับเจ้าของ');
            }
            $paths['local:'.$asset['storage_path']] = ['disk' => 'local', 'path' => $asset['storage_path']];
        }
        ksort($paths);
        $files = [];
        $bytes = 0;
        foreach ($paths as $file) {
            $path = $this->physicalPath($file['disk'], $file['path']);
            if (! is_file($path) || ! is_readable($path)) {
                $this->fail('ไม่พบรูปภาพบางไฟล์ จึงยังสร้างสำเนาที่ครบถ้วนไม่ได้');
            }
            $size = filesize($path);
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if ($size > 20 * 1024 * 1024 || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                $this->fail('รูปภาพไม่รองรับหรือมีขนาดเกิน 20 MB');
            }
            $bytes += $size;
            if ($bytes > self::MAX_BYTES - self::MAX_DATA_BYTES || count($files) >= 20000) {
                $this->fail('ชุดสำรองมีขนาดเกินขีดจำกัด 512 MB');
            }
            $files[] = [...$file, 'entry' => 'files/'.count($files), 'size' => $size, 'sha256' => hash_file('sha256', $path), 'mime' => $mime];
        }

        return $files;
    }

    /** No ZIP extraction: member names are never used as filesystem destinations. */
    private function read(string $path): array
    {
        if (! class_exists(ZipArchive::class) || ! is_file($path) || filesize($path) > self::MAX_BYTES) {
            $this->fail('ไม่รองรับไฟล์สำรองนี้ หรือไฟล์ใหญ่เกิน 512 MB');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $this->fail('เปิดไฟล์ ZIP สำรองไม่ได้');
        }
        try {
            if ($zip->numFiles > 20002) {
                $this->fail('จำนวนไฟล์ในชุดสำรองมากเกินกำหนด');
            }
            $seen = [];
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = $stat['name'];
                if (isset($seen[$name]) || ! preg_match('~^(manifest\.json|data\.json|files/[0-9]+)$~D', $name)
                    || $stat['size'] > self::MAX_DATA_BYTES || ($name === 'manifest.json' && $stat['size'] > 4 * 1024 * 1024)
                    || ($stat['encryption_method'] ?? 0) !== 0) {
                    $this->fail('รายการไฟล์ใน ZIP ไม่ถูกต้อง');
                }
                $seen[$name] = true;
                $total += $stat['size'];
                if ($total > self::MAX_BYTES) {
                    $this->fail('ขนาดข้อมูลเมื่อคลาย ZIP เกิน 512 MB');
                }
            }
            $manifestBytes = $zip->getFromName('manifest.json');
            $data = $zip->getFromName('data.json');
            if ($manifestBytes === false || strlen($manifestBytes) > 4 * 1024 * 1024 || $data === false || strlen($data) > self::MAX_DATA_BYTES) {
                $this->fail('ไฟล์สำรองไม่มี manifest หรือข้อมูลที่สมบูรณ์');
            }
            $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || array_is_list($manifest)) {
                $this->fail('โครงสร้าง manifest ของไฟล์สำรองไม่ถูกต้อง');
            }
            $signature = $manifest['signature'] ?? '';
            unset($manifest['signature']);
            if (($manifest['format'] ?? '') !== 'sena-semester-backup' || ($manifest['version'] ?? 0) !== 1
                || ! is_string($signature) || ! hash_equals($this->signature($manifest), $signature)) {
                $this->fail('ไฟล์สำรองไม่ใช่ของระบบนี้ ลายเซ็นไม่ถูกต้อง หรือ APP_KEY ต่างจากระบบที่สร้างไฟล์');
            }
            $manifest['signature'] = $signature;
            if (! hash_equals($manifest['data_sha256'] ?? '', hash('sha256', $data))) {
                $this->fail('ข้อมูลสำรองเสียหาย: checksum ไม่ตรง');
            }
            $tables = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            $this->validateTables($tables, $manifest);
            if (count($seen) !== count($manifest['files']) + 2) {
                $this->fail('จำนวนไฟล์สำรองไม่ตรงกับ manifest');
            }
            $targets = [];
            foreach ($manifest['files'] as $file) {
                $this->physicalPath($file['disk'], $file['path']);
                $target = $file['disk'].':'.$file['path'];
                if (isset($targets[$target]) || ! isset($seen[$file['entry']]) || ! preg_match('~^files/[0-9]+$~D', $file['entry'])) {
                    $this->fail('ไฟล์รูปภาพในชุดสำรองซ้ำหรือขาดหาย');
                }
                $targets[$target] = true;
                $stream = $zip->getStream($file['entry']);
                if ($stream === false) {
                    $this->fail('อ่านไฟล์รูปภาพใน ZIP ไม่ได้');
                }
                $hash = hash_init('sha256');
                $size = hash_update_stream($hash, $stream);
                fclose($stream);
                if ($size !== $file['size'] || ! hash_equals($file['sha256'], hash_final($hash))) {
                    $this->fail('รูปภาพสำรองเสียหาย: checksum ไม่ตรง');
                }
            }

            return compact('manifest', 'tables');
        } catch (\JsonException|\TypeError $exception) {
            $this->fail('โครงสร้างไฟล์สำรองไม่ถูกต้อง');
        } finally {
            $zip->close();
        }
    }

    private function validateTables(array $tables, array $manifest): void
    {
        if (array_diff(self::TABLES, array_keys($tables)) !== [] || array_diff(array_keys($tables), self::TABLES) !== []
            || ! preg_match('/^[0-9]{4}$/D', $manifest['scope']['year'] ?? '') || ! in_array($manifest['scope']['semester'] ?? '', ['1', '2'], true)) {
            $this->fail('ตารางหรือภาคเรียนในไฟล์สำรองไม่ถูกต้อง');
        }
        foreach (self::TABLES as $table) {
            $columns = Schema::getColumnListing($table);
            foreach ($tables[$table] as $row) {
                if (! is_array($row) || array_diff(array_keys($row), $columns) !== [] || array_diff($columns, array_keys($row)) !== []) {
                    $this->fail('โครงสร้างฐานข้อมูลไม่ตรงกับไฟล์สำรอง กรุณาอัปเดตระบบให้เป็นรุ่นเดียวกัน');
                }
                foreach ($row as $key => $value) {
                    if (! is_scalar($value) && $value !== null) {
                        $this->fail('ค่าข้อมูลในตารางไม่ถูกต้อง');
                    }
                    if (in_array($key, self::JSON_COLUMNS, true) && $value !== null) {
                        json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                    }
                }
            }
        }
        $recordIds = array_column($tables['records'], 'id');
        $documentIds = array_column($tables['documents'], 'id');
        foreach (['records', 'weekly_submissions'] as $table) {
            foreach ($tables[$table] as $row) {
                if ((string) $row['year'] !== $manifest['scope']['year'] || (string) $row['semester'] !== $manifest['scope']['semester']) {
                    $this->fail('ข้อมูลมีรายการนอกภาคเรียนที่ระบุ');
                }
            }
        }
        foreach (['record_images', 'record_attendance', 'record_document_layouts', 'record_revisions'] as $table) {
            foreach ($tables[$table] as $row) {
                if (! in_array($row['record_id'], $recordIds, true)) {
                    $this->fail('ข้อมูลลูกอ้างถึงบันทึกนอกชุดสำรอง');
                }
            }
        }
        foreach (['document_assets', 'document_versions'] as $table) {
            foreach ($tables[$table] as $row) {
                if (! in_array($row['document_id'], $documentIds, true)) {
                    $this->fail('ไฟล์หรือประวัติเอกสารอ้างถึงเอกสารนอกชุดสำรอง');
                }
            }
        }
        foreach ($tables['documents'] as $document) {
            $binding = json_decode($document['binding_snapshot'] ?? 'null', true);
            if (($binding['kind'] ?? '') !== 'record' || ! in_array($binding['record_id'] ?? '', $recordIds, true)
                || ($document['binding_key'] !== null && $document['binding_key'] !== 'record:'.$binding['record_id'])) {
                $this->fail('เอกสารในชุดสำรองไม่ได้ผูกกับบันทึกในภาคเรียน');
            }
        }
        $this->validateRelations($tables);
        if (! hash_equals($manifest['fingerprint'], $this->fingerprint($tables)) || $manifest['counts'] !== $this->counts($tables)) {
            $this->fail('จำนวนหรือรหัสตรวจสอบข้อมูลไม่ตรงกับ manifest');
        }
    }

    private function validateRelations(array $tables): void
    {
        $submissionIds = array_column($tables['weekly_submissions'], 'id');
        foreach ($tables['records'] as $record) {
            if ($record['weekly_submission_id'] !== null && ! in_array($record['weekly_submission_id'], $submissionIds, true)) {
                $this->fail('ข้อมูลบันทึกอ้างถึงชุดส่งงานนอกภาคเรียน');
            }
        }
        $documentIds = array_column($tables['documents'], 'id');
        foreach ($tables['record_revisions'] as $revision) {
            $snapshot = json_decode($revision['snapshot'], true, 512, JSON_THROW_ON_ERROR);
            if (isset($snapshot['advanced_document']['id']) && ! in_array($snapshot['advanced_document']['id'], $documentIds, true)) {
                $this->fail('ไม่พบเอกสารต้นฉบับที่ประวัติการพิมพ์อ้างถึง จึงยังสำรองให้ครบไม่ได้');
            }
        }
    }

    private function verified(object $backup): array
    {
        $path = Storage::disk('local')->path($backup->storage_path);
        if (! is_file($path) || ! hash_equals($backup->sha256, hash_file('sha256', $path))) {
            $this->fail('ไฟล์สำรองบนเซิร์ฟเวอร์สูญหายหรือเสียหาย กรุณานำเข้าจากสำเนาที่ดาวน์โหลดไว้');
        }

        return $this->read($path);
    }

    private function archiveBlockers(object $backup, array $tables, array $files, ?array $current = null): array
    {
        $blockers = [];
        if ($backup->downloaded_at === null) {
            $blockers[] = 'กรุณาดาวน์โหลดและเก็บไฟล์สำรองไว้นอกระบบก่อนเก็บภาคเรียน';
        }
        $current ??= $this->capture($backup->year, $backup->semester);
        if (! hash_equals($backup->fingerprint, $this->fingerprint($current))) {
            $blockers[] = 'ข้อมูลเปลี่ยนหลังสำรอง กรุณาสร้างชุดสำรองใหม่';
        } elseif ($files !== $this->files($current)) {
            $blockers[] = 'รูปภาพหรือไฟล์เอกสารเปลี่ยนหลังสำรอง กรุณาสร้างชุดสำรองใหม่';
        }
        $ids = array_column($tables['records'], 'id');
        if ($this->activeDrafts($backup->year, $backup->semester, $ids)->exists()) {
            $blockers[] = 'ยังมีฉบับร่างที่กำลังใช้งานในภาคเรียนนี้หรือผูกกับบันทึกนี้ ให้เจ้าของบันทึกให้เสร็จก่อน';
        }
        $activeExports = DB::table('export_jobs')->where(function (Builder $query): void {
            $query->where('status', 'processing')->orWhere(fn (Builder $queued): Builder => $queued->where('status', 'queued')->where('expires_at', '>', now()));
        })->get(['manifest']);
        foreach ($activeExports as $job) {
            if (array_intersect(array_column(json_decode($job->manifest, true) ?? [], 'record_id'), $ids) !== []) {
                $blockers[] = 'มีงานส่งออกที่กำลังใช้บันทึกภาคเรียนนี้ กรุณารอให้งานเสร็จ';
                break;
            }
        }

        return $blockers;
    }

    private function restoreConflicts(array $tables, array $manifest): array
    {
        $conflicts = [];
        foreach (self::TABLES as $table) {
            $key = $table === 'record_document_layouts' ? 'record_id' : 'id';
            if (in_array($table, ['record_images', 'record_attendance'], true)) {
                if (DB::table($table)->whereIn('record_id', array_column($tables['records'], 'id'))->exists()) {
                    $conflicts[] = 'มีข้อมูลลูกของบันทึกเดิมใน '.$table.' แล้ว';
                }
                $uniqueId = collect(Schema::getIndexes($table))->contains(static fn (array $index): bool => ($index['primary'] || $index['unique']) && $index['columns'] === ['id']);
                if (! $uniqueId) {
                    continue;
                }
            }
            foreach (array_chunk(array_column($tables[$table], $key), 500) as $ids) {
                if (DB::table($table)->whereIn($key, $ids)->exists()) {
                    $conflicts[] = 'มี'.self::TABLE_LABELS[$table].'ที่ใช้รหัสเดียวกันแล้ว ระบบจะไม่นำเข้าทับ';
                    break;
                }
            }
        }
        foreach ($tables['weekly_submissions'] as $row) {
            if (DB::table('weekly_submissions')->where('tambon', $row['tambon'])->where('year', $row['year'])->where('semester', $row['semester'])->where('week', $row['week'])->exists()) {
                $conflicts[] = 'มีการส่งบันทึกในสัปดาห์และตำบลเดียวกันแล้ว';
                break;
            }
        }
        foreach ($tables['documents'] as $row) {
            if ($row['binding_key'] !== null && DB::table('documents')->where('binding_key', $row['binding_key'])->exists()) {
                $conflicts[] = 'มีเอกสารผูกกับบันทึกเดียวกันแล้ว';
                break;
            }
            if ($row['source_record_id'] !== null && DB::table('documents')->where('user_id', $row['user_id'])->where('source_record_id', $row['source_record_id'])->exists()) {
                $conflicts[] = 'มีเอกสารของผู้สร้างและบันทึกต้นทางเดียวกันแล้ว ระบบจะไม่นำเข้าทับ';
                break;
            }
        }
        foreach ($manifest['identities']['users'] as $identity) {
            if (! DB::table('users')->where('id', $identity['id'])->where('username', $identity['username'])->exists()) {
                $conflicts[] = 'บัญชีผู้สร้างข้อมูลเดิมไม่อยู่ในระบบหรือรหัสบัญชีถูกใช้ใหม่ กรุณากู้คืนบัญชีเดิมก่อน';
                break;
            }
        }
        foreach ($manifest['identities']['tambons'] as $tambon) {
            if (! DB::table('tambons')->where('id', $tambon)->exists()) {
                $conflicts[] = 'ไม่พบตำบลเดิมในระบบ กรุณาสร้างตำบลรหัสเดิมก่อน';
                break;
            }
        }

        return array_values(array_unique($conflicts));
    }

    private function activeDrafts(string $year, string $semester, array $ids): Builder
    {
        return DB::table('teaching_drafts')->where('status', 'active')->where(function (Builder $query) use ($year, $semester, $ids): void {
            $query->where(fn (Builder $scope): Builder => $scope->where('year', $year)->where('semester', $semester))->orWhereIn('source_record_id', $ids);
        });
    }

    /** Only allow image locations already used by the application, never arbitrary paths. */
    private function physicalPath(string $disk, string $path): string
    {
        $prefix = match ($disk) {
            'local' => 'documents/', 'public' => 'records/', 'legacy' => 'uploads/', default => ''
        };
        if ($prefix === '' || ! str_starts_with($path, $prefix) || str_contains($path, '\\') || str_contains($path, "\0")
            || in_array('..', explode('/', $path), true) || ! preg_match('~\.(jpe?g|png|webp|gif)$~iD', $path)) {
            $this->fail('ที่อยู่ไฟล์รูปภาพไม่ปลอดภัยหรือไม่รองรับ');
        }
        $root = $disk === 'legacy' ? dirname(base_path()) : Storage::disk($disk)->path('');
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        $target = $root.DIRECTORY_SEPARATOR.$path;
        $ancestor = $target;
        while (! file_exists($ancestor) && ! is_link($ancestor) && $ancestor !== dirname($ancestor)) {
            $ancestor = dirname($ancestor);
        }
        $realRoot = realpath($root);
        $realAncestor = realpath($ancestor);
        if ($realRoot === false || $realAncestor === false || ($realAncestor !== $realRoot && ! str_starts_with($realAncestor, $realRoot.DIRECTORY_SEPARATOR))) {
            $this->fail('ที่อยู่ไฟล์อยู่นอกพื้นที่เก็บข้อมูล');
        }
        $relative = '';
        foreach (explode('/', $path) as $part) {
            $relative .= DIRECTORY_SEPARATOR.$part;
            if (is_link($root.$relative)) {
                $this->fail('ไม่รองรับไฟล์หรือโฟลเดอร์รูปภาพที่เป็น symbolic link');
            }
        }

        return $target;
    }

    private function identities(array $tables): array
    {
        $userIds = [];
        foreach ($tables as $rows) {
            foreach ($rows as $row) {
                foreach (['created_by', 'updated_by', 'user_id', 'submitted_by'] as $key) {
                    if (! empty($row[$key])) {
                        $userIds[] = $row[$key];
                    }
                }
            }
        }

        return ['users' => DB::table('users')->whereIn('id', array_unique($userIds))->orderBy('id')->get(['id', 'username'])->map(static fn ($row): array => (array) $row)->all(),
            'tambons' => array_values(array_unique(array_column($tables['records'], 'tambon')))];
    }

    private function counts(array $tables): array
    {
        return ['records' => count($tables['records']), 'attendance' => count($tables['record_attendance']), 'images' => count($tables['record_images']), 'documents' => count($tables['documents']), 'revisions' => count($tables['record_revisions'])];
    }

    private function fingerprint(array $tables): string
    {
        return hash('sha256', $this->json($tables));
    }

    private function signature(array $manifest): string
    {
        if (trim((string) config('app.key')) === '') {
            $this->fail('ระบบยังไม่มี APP_KEY ที่ใช้ลงลายเซ็นสำรองข้อมูล');
        }

        return hash_hmac('sha256', $this->json($manifest), (string) config('app.key'));
    }

    private function catalog(string $id, User $user, string $relative, array $manifest, string $status): void
    {
        $path = Storage::disk('local')->path($relative);
        DB::table('semester_backups')->insert(['id' => $id, 'year' => $manifest['scope']['year'], 'semester' => $manifest['scope']['semester'], 'status' => $status,
            'created_by' => $user->id, 'storage_path' => $relative, 'sha256' => hash_file('sha256', $path), 'fingerprint' => $manifest['fingerprint'],
            'counts' => $this->json($manifest['counts']), 'size_bytes' => filesize($path), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function resource(object $backup): array
    {
        return ['id' => $backup->id, 'year' => $backup->year, 'semester' => $backup->semester, 'status' => $backup->status,
            'counts' => json_decode($backup->counts, true), 'size_bytes' => (int) $backup->size_bytes, 'created_at' => $backup->created_at,
            'archived_at' => $backup->archived_at, 'restored_at' => $backup->restored_at, 'downloaded_at' => $backup->downloaded_at,
            'download_url' => route('semester-backups.download', $backup->id, false),
            'archive_confirmation' => 'ARCHIVE '.$backup->semester.'/'.$backup->year, 'restore_confirmation' => 'RESTORE '.$backup->semester.'/'.$backup->year];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['archive' => $message]);
    }
}
