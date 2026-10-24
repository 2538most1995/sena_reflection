<?php

namespace Tests\Feature;

use App\Models\RecordAttendance;
use App\Models\RecordImage;
use App\Models\Tambon;
use App\Models\TeachingDraft;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\RecordRevisionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyRecordKeyMySqlTest extends TestCase
{
    private ?string $databaseName = null;

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $socket = getenv('SENA_MYSQL_TEST_SOCKET') ?: '';
        if (! $socket) {
            $this->markTestSkipped('Set SENA_MYSQL_TEST_SOCKET to an isolated sena-save-mysql-* fixture socket.');
        }
        if (! is_file($socket) && ! file_exists($socket)) {
            $this->fail('The isolated MySQL test socket is missing.');
        }
        $this->assertStringContainsString('/sena-save-mysql-', $socket);
        $this->originalConnection = config('database.default');
        $connection = [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 13379, 'unix_socket' => $socket,
            'database' => '', 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ];
        config(['database.connections.key_fixture_admin' => $connection]);
        $this->databaseName = 'sena_key_test_'.bin2hex(random_bytes(6));
        DB::connection('key_fixture_admin')->statement('CREATE DATABASE `'.$this->databaseName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.key_fixture' => [...$connection, 'database' => $this->databaseName], 'database.default' => 'key_fixture']);
        $this->artisan('migrate', ['--database' => 'key_fixture', '--force' => true])->assertSuccessful();
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        if ($this->databaseName !== null) {
            config(['database.default' => $this->originalConnection]);
            DB::purge('key_fixture');
            DB::connection('key_fixture_admin')->statement('DROP DATABASE `'.$this->databaseName.'`');
            DB::purge('key_fixture_admin');
        }
        parent::tearDown();
    }

    private function removeAutoIncrement(): void
    {
        foreach (['record_images', 'record_attendance'] as $table) {
            DB::statement('ALTER TABLE `'.$table.'` MODIFY `id` BIGINT UNSIGNED NOT NULL');
        }
    }

    private function repairKeys(): void
    {
        $migration = require database_path('migrations/2026_10_24_142034_restore_auto_increment_on_legacy_record_keys.php');
        $migration->up();
    }

    private function teacher(string $role = 'tambon'): User
    {
        Tambon::firstOrCreate(['id' => 'key-tambon'], ['name' => 'ตำบลทดสอบรหัส']);

        return User::create(['id' => 'key-'.$role, 'username' => 'key-'.$role, 'name' => 'ผู้ทดสอบ', 'password' => Hash::make('fixture-only'), 'role' => $role, 'tambon' => $role === 'tambon' ? 'key-tambon' : null]);
    }

    public function test_health_reports_a_broken_numeric_key_schema_until_repaired(): void
    {
        $this->removeAutoIncrement();
        $this->artisan('sena:health')->expectsOutputToContain('requires AUTO_INCREMENT')->assertFailed();
        $this->repairKeys();
        $this->artisan('sena:health')->assertSuccessful();
    }

    public function test_mysql_numeric_key_repair_preserves_ids_and_does_not_guess_strings_after_1364(): void
    {
        $this->removeAutoIncrement();
        DB::table('record_images')->insert([
            ['id' => 0, 'record_id' => 'fixture', 'file_path' => 'storage/zero.jpg'],
            ['id' => 17, 'record_id' => 'fixture', 'file_path' => 'storage/seventeen.jpg'],
        ]);
        DB::table('record_attendance')->insert(['id' => 50, 'record_id' => 'fixture', 'student_id' => '0001', 'full_name' => 'ผู้เรียน', 'present' => false]);
        foreach ([[], ['id' => 'i16836481151e470e915']] as $index => $identity) {
            try {
                RecordImage::createWithAdaptiveKey([...$identity, 'record_id' => 'fixture', 'file_path' => 'storage/rejected.jpg']);
                $this->fail('The malformed numeric schema must fail before its repair.');
            } catch (QueryException $exception) {
                $this->assertSame($index === 0 ? 1364 : 1366, $exception->errorInfo[1]);
            }
        }
        $beforeMode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        $this->repairKeys();
        $this->repairKeys();
        $this->assertSame($beforeMode, DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode);
        $this->assertSame([0, 17], DB::table('record_images')->orderBy('id')->pluck('id')->all());
        $this->assertSame([50], DB::table('record_attendance')->pluck('id')->all());
        $image = RecordImage::createWithAdaptiveKey(['record_id' => 'fixture', 'file_path' => 'storage/new.jpg']);
        $this->assertSame('18', (string) $image->id);
        RecordAttendance::insertAdaptively([['record_id' => 'fixture', 'student_id' => '0002', 'full_name' => 'ผู้เรียนสอง', 'present' => false]]);
        $this->assertDatabaseHas('record_attendance', ['id' => 51, 'student_id' => '0002']);
        foreach (['record_images', 'record_attendance'] as $table) {
            $this->assertTrue(collect(Schema::getColumns($table))->firstWhere('name', 'id')['auto_increment']);
        }
    }

    public function test_mysql_unindexed_duplicate_zero_ids_keep_every_legacy_row_and_generate_new_ids(): void
    {
        $this->removeAutoIncrement();
        DB::statement('ALTER TABLE record_images DROP PRIMARY KEY, MODIFY id INT NOT NULL');
        $rows = [];
        foreach (range(1, 1932) as $index) {
            $rows[] = ['id' => $index <= 1890 ? 0 : $index - 1890, 'record_id' => 'legacy-'.($index % 631), 'file_path' => 'uploads/legacy-'.$index.'.jpg', 'created_at' => '2026-10-01 12:00:00'];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('record_images')->insert($chunk);
        }
        $before = DB::table('record_images')->orderBy('file_path')->get()->toArray();
        $mode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        $indexes = Schema::getIndexes('record_images');
        $this->repairKeys();
        $this->repairKeys();
        $this->assertEquals($before, DB::table('record_images')->orderBy('file_path')->get()->toArray());
        $this->assertSame(1890, DB::table('record_images')->where('id', 0)->count());
        $this->assertSame($mode, DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode);
        $afterIndexes = Schema::getIndexes('record_images');
        foreach ($indexes as $index) {
            $this->assertContains($index, $afterIndexes);
        }
        $this->assertCount(count($indexes) + 1, $afterIndexes);
        $this->assertFalse(collect($afterIndexes)->firstWhere('name', 'record_images_generated_id_index')['unique']);
        $this->assertSame('int', collect(Schema::getColumns('record_images'))->firstWhere('name', 'id')['type_name']);
        $image = RecordImage::createWithAdaptiveKey(['record_id' => 'new-record', 'file_path' => 'storage/new.jpg']);
        $this->assertSame('43', (string) $image->id);
        RecordAttendance::insertAdaptively([['record_id' => 'new-record', 'student_id' => '0001', 'full_name' => 'ผู้เรียน', 'present' => false]]);
        $this->assertDatabaseCount('record_images', 1933);
        $this->assertDatabaseCount('record_attendance', 1);
    }

    public function test_mysql_repair_reuses_leading_id_indexes_and_preserves_other_primary_keys(): void
    {
        foreach (['INDEX legacy_id (id)', 'UNIQUE INDEX legacy_id (id)', 'PRIMARY KEY (id, record_id)', 'PRIMARY KEY (file_path), INDEX legacy_id (id, record_id)'] as $indexDefinition) {
            DB::statement('DROP TABLE record_images');
            $nullable = str_contains($indexDefinition, 'PRIMARY KEY (id,') ? 'NOT NULL' : 'NULL';
            DB::statement('CREATE TABLE record_images (id INT '.$nullable.', record_id VARCHAR(20) NOT NULL, file_path VARCHAR(255) NOT NULL, created_at DATETIME NULL, '.$indexDefinition.') ENGINE=InnoDB');
            DB::table('record_images')->insert(['id' => 17, 'record_id' => 'fixture', 'file_path' => 'uploads/old.jpg']);
            $beforeIndexes = Schema::getIndexes('record_images');
            $this->repairKeys();
            $this->repairKeys();
            $this->assertEquals($beforeIndexes, Schema::getIndexes('record_images'));
            $this->assertDatabaseHas('record_images', ['id' => 17, 'file_path' => 'uploads/old.jpg']);
            $image = RecordImage::createWithAdaptiveKey(['record_id' => 'fixture', 'file_path' => 'storage/new.jpg']);
            $this->assertSame('18', (string) $image->id);
        }
    }

    public function test_mysql_repair_adds_an_index_without_replacing_a_different_primary_key(): void
    {
        DB::statement('DROP TABLE record_images');
        DB::statement('CREATE TABLE record_images (id INT NOT NULL, record_id VARCHAR(20), file_path VARCHAR(255) PRIMARY KEY, created_at DATETIME NULL, INDEX record_images_generated_id_index (record_id)) ENGINE=InnoDB');
        DB::table('record_images')->insert(['id' => 0, 'record_id' => 'fixture', 'file_path' => 'uploads/old.jpg']);
        $beforeIndexes = Schema::getIndexes('record_images');
        $this->repairKeys();
        foreach ($beforeIndexes as $index) {
            $this->assertContains($index, Schema::getIndexes('record_images'));
        }
        $this->assertDatabaseHas('record_images', ['id' => 0, 'file_path' => 'uploads/old.jpg']);
        $this->assertNotNull(collect(Schema::getIndexes('record_images'))->firstWhere('name', 'record_images_generated_id_index_1'));
        $this->assertSame('1', (string) RecordImage::createWithAdaptiveKey(['record_id' => 'fixture', 'file_path' => 'storage/new.jpg'])->id);
    }

    public function test_mysql_repair_rejects_null_identities_before_altering_any_table(): void
    {
        $this->removeAutoIncrement();
        DB::statement('ALTER TABLE record_attendance DROP PRIMARY KEY, MODIFY id INT NULL');
        DB::table('record_attendance')->insert(['id' => null, 'record_id' => 'fixture', 'student_id' => '0001', 'full_name' => 'ผู้เรียน', 'present' => false]);
        $beforeIndexes = Schema::getIndexes('record_images');
        $mode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        try {
            $this->repairKeys();
            $this->fail('Null identities require explicit repair before changing the schema.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('record_attendance.id generation: null_rows=1', $exception->getMessage());
        }
        $this->assertFalse(collect(Schema::getColumns('record_images'))->firstWhere('name', 'id')['auto_increment']);
        $this->assertEquals($beforeIndexes, Schema::getIndexes('record_images'));
        $this->assertSame($mode, DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode);
        $this->assertSame(1, DB::table('record_attendance')->whereNull('id')->count());
    }

    public function test_mysql_legacy_text_keys_are_preserved_and_never_converted_to_numeric_keys(): void
    {
        foreach (['record_images', 'record_attendance'] as $table) {
            DB::statement('ALTER TABLE `'.$table.'` MODIFY `id` VARCHAR(20) NOT NULL');
        }
        DB::table('record_images')->insert(['id' => 'original-photo-0001', 'record_id' => 'fixture', 'file_path' => 'storage/original.jpg']);
        $this->repairKeys();
        $this->assertDatabaseHas('record_images', ['id' => 'original-photo-0001']);
        $image = RecordImage::createWithAdaptiveKey(['record_id' => 'fixture', 'file_path' => 'storage/new.jpg']);
        $this->assertStringStartsWith('i', $image->id);
        $this->assertSame(20, strlen($image->id));
        RecordAttendance::insertAdaptively([['record_id' => 'fixture', 'student_id' => '0002', 'full_name' => 'ผู้เรียนสอง', 'present' => false]]);
        $this->assertStringStartsWith('a', (string) RecordAttendance::firstOrFail()->id);
    }

    public function test_mysql_existing_record_with_26_students_and_four_photos_rolls_back_then_retries_without_duplicates(): void
    {
        $teacher = $this->teacher();
        $district = $this->teacher('district');
        $source = TeachingRecord::create(['id' => 'legacy-edit', 'tambon' => 'key-tambon', 'week' => 16, 'date' => '2026-09-30', 'semester' => '1', 'year' => '2569', 'level' => 'มัธยมศึกษาตอนปลาย', 'class_group_code' => '230009', 'class_name' => 'กลุ่มทดสอบ', 'subject' => 'อาชญากรรมบนโลกออนไลน์', 'total' => 26, 'present' => 7, 'result' => 'ผลการสอนเดิม', 'created_by' => $teacher->id]);
        for ($index = 1; $index <= 26; $index++) {
            RecordAttendance::create(['record_id' => $source->id, 'student_id' => str_pad((string) $index, 10, '0', STR_PAD_LEFT), 'full_name' => ' ผู้เรียน '.$index.' ', 'group_code' => ' 230009 ', 'present' => $index <= 7, 'learning_method' => $index <= 7 ? ' ' : null]);
        }
        $this->removeAutoIncrement();
        DB::statement('ALTER TABLE record_images DROP PRIMARY KEY, MODIFY id INT NOT NULL');
        DB::table('record_images')->insert(['id' => 0, 'record_id' => 'other-record', 'file_path' => 'uploads/other-record.jpg']);
        foreach (range(1, 4) as $index) {
            $path = UploadedFile::fake()->image('original-'.$index.'.jpg')->store('records/original', 'public');
            DB::table('record_images')->insert(['id' => 0, 'record_id' => $source->id, 'file_path' => 'storage/'.$path]);
        }
        $originalImages = $source->images()->get()->toArray();
        $this->repairKeys();
        $created = $this->actingAs($district)->postJson(route('teaching-drafts.store'), ['source_record_id' => $source->id])->assertCreated();
        $draft = $created->json('data.draft');
        $created->assertJsonCount(26, 'data.draft.payload.levels.3.attendance')->assertJsonCount(4, 'data.draft.images');
        $request = ['lock_version' => $draft['lock_version'], 'idempotency_key' => (string) Str::uuid(), 'confirmed' => true];
        $this->mock(RecordRevisionService::class)->shouldReceive('capture')->once()->andThrow(new \RuntimeException('fixture private SQL diagnostic'));
        $failed = $this->postJson(route('teaching-drafts.finalize', $draft['id']), $request)->assertInternalServerError();
        $this->assertStringNotContainsString('SQL diagnostic', $failed->getContent());
        $this->assertSame($originalImages, $source->images()->get()->toArray());
        $this->assertCount(4, Storage::disk('public')->allFiles());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('weekly_submissions', 0);
        $this->assertDatabaseHas('teaching_drafts', ['id' => $draft['id'], 'status' => 'active', 'lock_version' => $draft['lock_version']]);
        $this->app->forgetInstance(RecordRevisionService::class);
        $saved = $this->postJson(route('teaching-drafts.finalize', $draft['id']), $request)->assertOk();
        $saved->assertJsonPath('data.record_ids', [$source->id]);
        $this->assertSame(26, $source->fresh()->total);
        $this->assertSame(7, $source->fresh()->present);
        $this->assertSame(4, $source->images()->count());
        $this->assertSame(4, $source->images()->where('id', '>', 0)->distinct()->count('id'));
        $this->assertDatabaseHas('record_images', ['id' => 0, 'record_id' => 'other-record', 'file_path' => 'uploads/other-record.jpg']);
        $this->assertSame(7, $source->attendance()->where('present', true)->count());
        $this->postJson(route('teaching-drafts.finalize', $draft['id']), $request)->assertOk();
        $this->assertDatabaseCount('records', 1);
        $this->assertDatabaseCount('record_revisions', 1);
    }

    public function test_mysql_new_week_20_with_26_students_four_present_and_two_photos_can_be_confirmed(): void
    {
        $teacher = $this->teacher();
        $this->removeAutoIncrement();
        $this->repairKeys();
        $attendance = [];
        foreach (range(1, 26) as $index) {
            $attendance[] = ['student_id' => str_pad((string) $index, 10, '0', STR_PAD_LEFT), 'full_name' => 'ผู้เรียน '.$index, 'group_code' => '230009', 'present' => $index <= 4, 'learning_method' => $index <= 4 ? 'พบกลุ่ม' : null];
        }
        $payload = ['schema_version' => 1, 'scope' => ['tambon' => 'key-tambon', 'week' => 20, 'date' => '2026-10-24', 'semester' => '1', 'year' => '2569'], 'enabled_level_codes' => ['3'], 'active_level_code' => '3', 'levels' => ['3' => ['subject' => 'คุณธรรมจริยธรรมในการใช้สื่อสังคมออนไลน์', 'class_group_code' => '230009', 'class_name' => 'กลุ่มทดสอบ', 'result' => 'ผู้เรียนฝึกใช้งานสื่ออย่างเหมาะสม', 'attendance_mode' => 'roster', 'attendance_reviewed' => false, 'attendance' => $attendance, 'image_ids' => []]]];
        $draft = $this->actingAs($teacher)->postJson(route('teaching-drafts.store'), ['payload' => $payload])->assertCreated()->json('data.draft');
        foreach ([1, 2] as $index) {
            $uploaded = $this->post(route('teaching-drafts.images.store', $draft['id']), ['lock_version' => $draft['lock_version'], 'level_code' => '3', 'image' => UploadedFile::fake()->image('photo-'.$index.'.jpg', 300, 300)])->assertCreated();
            $draft['lock_version'] = $uploaded->json('data.lock_version');
        }
        $saved = $this->postJson(route('teaching-drafts.finalize', $draft['id']), ['lock_version' => $draft['lock_version'], 'idempotency_key' => (string) Str::uuid(), 'confirmed' => true])->assertOk();
        $record = TeachingRecord::findOrFail($saved->json('data.record_ids.0'));
        $this->assertSame(26, $record->total);
        $this->assertSame(4, $record->present);
        $this->assertCount(2, $record->images);
        $this->assertDatabaseCount('record_attendance', 26);
        $this->assertSame('finalized', TeachingDraft::findOrFail($draft['id'])->status);
    }
}
