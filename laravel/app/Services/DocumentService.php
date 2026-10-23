<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentAsset;
use App\Models\DocumentVersion;
use App\Models\RecordRevision;
use App\Models\TeachingRecord;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DocumentService
{
    public function __construct(private readonly DocumentSchema $schema) {}

    public function create(User $user, array $payload = []): Document
    {
        $data = $this->schema->validate($payload);

        return DB::transaction(function () use ($user, $data): Document {
            $document = Document::create(['user_id' => $user->id, ...$this->attributes($data), 'revision' => 1]);
            $this->capture($document, $user);

            return $document;
        }, 3);
    }

    public function update(Document $document, User $user, int $revision, array $payload): Document
    {
        return DB::transaction(function () use ($document, $user, $revision, $payload): Document {
            $current = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($current->revision !== $revision) {
                $this->conflict($current);
            }
            $data = $this->schema->validate($payload, $current);
            $attributes = [...$this->attributes($data), 'revision' => $revision + 1, 'updated_at' => now()];
            $current->forceFill($attributes);
            $serialized = array_intersect_key($current->getAttributes(), $attributes);
            if (Document::query()->whereKey($current->id)->where('revision', $revision)->update($serialized) !== 1) {
                $this->conflict($current->fresh());
            }
            $current->refresh();
            $this->capture($current, $user);

            return $current;
        }, 3);
    }

    public function restore(Document $document, DocumentVersion $version, User $user, int $revision): Document
    {
        abort_unless($version->document_id === $document->id, 404);

        return $this->update($document, $user, $revision, $version->snapshot_json);
    }

    public function duplicate(Document $source, User $user, array $payload): Document
    {
        $data = $this->schema->validate($payload, $source);

        return DB::transaction(function () use ($source, $user, $data): Document {
            $document = Document::create(['user_id' => $user->id, ...$this->attributes(['title' => $data['title'], 'content' => DocumentSchema::blankContent(), 'pageSettings' => $data['pageSettings'], 'objects' => [], 'schemaVersion' => DocumentSchema::VERSION]), 'revision' => 1]);
            try {
                $references = [];
                foreach ($source->assets as $asset) {
                    $path = $this->assetPath($source, $asset);
                    $copy = $this->storeAsset($document, file_get_contents($path), $asset->name, [0 => $asset->width, 1 => $asset->height, 'mime' => $asset->mime]);
                    $sourceUrl = $this->assetResource($asset)['url'];
                    $newUrl = $this->assetResource($copy)['url'];
                    $references[$asset->id] = $copy->id;
                    $references[$sourceUrl] = $newUrl;
                    $references[parse_url($sourceUrl, PHP_URL_PATH)] = $newUrl;
                }
                $rewritten = $this->rewriteAssetReferences($data, $references);
                $validated = $this->schema->validate($rewritten, $document);
                $document->fill($this->attributes($validated))->save();
                $this->capture($document, $user);
            } catch (\Throwable $exception) {
                Storage::disk('local')->deleteDirectory('documents/'.$document->id);
                throw $exception;
            }

            return $document;
        }, 3);
    }

    public function fromRecord(User $user, TeachingRecord $record, RecordDocumentOptions $options, RecordImageAssets $images): Document
    {
        return DB::transaction(function () use ($user, $record, $options, $images): Document {
            TeachingRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $existing = Document::query()->where('user_id', $user->id)->where('source_record_id', $record->id)->first();
            if ($existing) {
                return $existing;
            }
            $record->loadMissing(['documentLayout', 'images', 'tambonRelation']);
            $frozen = RecordRevision::query()->where('record_id', $record->id)->orderByDesc('revision')->value('snapshot');
            $settings = $options->settings(array_merge($frozen['settings'] ?? [], $record->documentLayout?->settings ?? []), [], $record->tambon);
            $paragraph = static fn (string $text, array $attrs = []): array => ['type' => 'paragraph', 'attrs' => ['id' => (string) Str::uuid(), ...$attrs], ...($text === '' ? [] : ['content' => [['type' => 'text', 'text' => $text]]])];
            $heading = static fn (string $text, int $level = 2): array => ['type' => 'heading', 'attrs' => ['id' => (string) Str::uuid(), 'level' => $level], 'content' => [['type' => 'text', 'text' => $text]]];
            $parts = [
                $heading('บันทึกหลังการจัดการเรียนรู้', 1),
                $paragraph('รายวิชา '.$record->subject.'   รหัสวิชา '.($record->subject_code ?? '')),
                $paragraph('กลุ่มเรียน '.($record->class_name ?: $record->class_group_code).'   ระดับ '.$record->level),
                $paragraph('สัปดาห์ที่ '.$record->week.'   วันที่ '.$this->sourceDateLabel($record).'   ภาคเรียน '.$record->semester.'/'.$record->year),
                $paragraph('ตำบล '.($record->tambonRelation?->name ?? $record->tambon).'   ผู้เรียนทั้งหมด '.$record->total.' คน   มาเรียน '.$record->present.' คน'),
            ];
            foreach ([['record_section1_title', 'result'], ['record_section2_title', 'problem'], ['record_section3_title', 'suggestion']] as [$titleKey, $field]) {
                $parts[] = $heading($settings[$titleKey]);
                $this->appendPlainParagraphs($parts, (string) ($record->{$field} ?? ''), $paragraph);
            }
            $teacher = $settings['sig1_name'] !== '' ? $settings['sig1_name'] : (User::query()->whereKey($record->created_by)->value('name') ?? $user->name);
            $parts[] = $paragraph('( '.$teacher.' )', ['textAlign' => 'right']);
            $parts[] = $paragraph($settings['sig1_pos'], ['textAlign' => 'right']);
            foreach ([2, 3] as $index) {
                $parts[] = $heading($settings["sig{$index}_title"]);
                if ($index === 2) {
                    $this->appendPlainParagraphs($parts, (string) ($record->supervisor_comment ?? ''), $paragraph);
                }
                $parts[] = $paragraph('( '.($settings["sig{$index}_name"] ?: '................................').' )', ['textAlign' => 'right']);
                $parts[] = $paragraph($settings["sig{$index}_pos"], ['textAlign' => 'right']);
            }
            $pageSettings = self::sourcePageSettings($settings);
            $data = $this->schema->validate(['title' => $record->subject.' — สัปดาห์ที่ '.$record->week, 'content' => ['type' => 'doc', 'content' => $parts], 'pageSettings' => $pageSettings]);
            $document = Document::create(['user_id' => $user->id, 'source_record_id' => $record->id, ...$this->attributes($data), 'revision' => 1]);
            try {
                $objects = [];
                $imageHeading = $heading('ภาพกิจกรรมการจัดการเรียนรู้');
                $sourceImages = $settings['show_image_page'] === '1' ? $record->images : collect();
                foreach ($sourceImages as $index => $source) {
                    $path = $images->resolve((string) $source->file_path);
                    if ($path === null || ! $this->allowedSourceImage($path)) {
                        continue;
                    }
                    $dimensions = @getimagesize($path);
                    if (! $dimensions || ! in_array($dimensions['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true) || filesize($path) > 5 * 1024 * 1024) {
                        continue;
                    }
                    $asset = $this->storeAsset($document, file_get_contents($path), basename($path), $dimensions);
                    $objects[] = ['id' => (string) Str::uuid(), 'type' => 'image', 'page' => 2, 'x' => 20 + ($index % 2) * 85, 'y' => 30 + intdiv($index, 2) * 75, 'width' => 80, 'height' => 60, 'rotation' => 0, 'zIndex' => $index + 1, 'opacity' => 1, 'wrap' => 'square', 'anchor' => ['kind' => 'paragraph', 'id' => $imageHeading['attrs']['id'], 'offsetY' => 20 + intdiv($index, 2) * 75], 'assetId' => $asset->id, 'src' => $this->assetResource($asset)['url'], 'lockAspectRatio' => true];
                }
                if ($objects !== []) {
                    $parts = [...$parts, ['type' => 'pageBreak'], $imageHeading];
                }
                $this->appendAttendance($parts, $record, $settings, $paragraph, $heading);
                $validated = $this->schema->validate(['content' => ['type' => 'doc', 'content' => $parts], 'objects' => $objects], $document);
                $document->fill($this->attributes($validated))->save();
                $this->capture($document, $user);
            } catch (\Throwable $exception) {
                Storage::disk('local')->deleteDirectory('documents/'.$document->id);
                throw $exception;
            }

            return $document;
        }, 3);
    }

    public function upload(Document $document, UploadedFile $file): DocumentAsset
    {
        $dimensions = @getimagesize($file->getRealPath());
        if (! $dimensions || ! in_array($dimensions['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true) || $dimensions[0] * $dimensions[1] > 40_000_000) {
            throw ValidationException::withMessages(['file' => 'กรุณาเลือกรูป JPG, PNG หรือ WebP ที่มีขนาดภาพเหมาะสม']);
        }

        return $this->storeAsset($document, $file->get(), $file->getClientOriginalName(), $dimensions);
    }

    public function resource(Document $document): array
    {
        $document->loadMissing('assets');

        return [
            'id' => $document->id, 'title' => $document->title, 'content' => $document->content_json,
            'pageSettings' => $document->page_settings_json, 'objects' => $document->objects_json,
            'revision' => $document->revision, 'schemaVersion' => $document->schema_version,
            'updatedAt' => $document->updated_at->toIso8601String(), 'sourceRecordId' => $document->source_record_id,
            'assets' => $document->assets->map(fn (DocumentAsset $asset): array => $this->assetResource($asset))->all(),
            ...($document->binding_key ? ['editorContext' => app(BoundDocumentService::class)->context($document)] : []),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function importSnapshotAssets(Document $document, array $snapshot): array
    {
        $copied = [];
        $resolver = app(RecordImageAssets::class);
        foreach ($snapshot['images'] ?? [] as $image) {
            $stored = (string) ($image['file_path'] ?? '');
            $path = DraftImagePath::resolve($stored, $snapshot['images'] ?? []);
            if ($path === null) {
                $path = $resolver->resolve($stored);
                if ($path === null || ! $this->allowedSourceImage($path)) {
                    continue;
                }
            }
            $dimensions = @getimagesize($path);
            if (! $dimensions || ! in_array($dimensions['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true) || filesize($path) > 5 * 1024 * 1024) {
                continue;
            }
            $asset = $this->storeAsset($document, file_get_contents($path), basename($path), $dimensions);
            $resource = $this->assetResource($asset);
            $copied[] = ['id' => (string) ($image['id'] ?? ''), 'source_id' => (string) ($image['id'] ?? ''), 'asset_id' => $asset->id, 'assetId' => $asset->id, 'url' => $resource['url'], 'file_path' => $resource['url'], 'width' => $asset->width, 'height' => $asset->height];
        }

        return $copied;
    }

    public function assetResource(DocumentAsset $asset): array
    {
        return ['id' => $asset->id, 'url' => route('documents.assets.show', ['document' => $asset->document_id, 'asset' => $asset]), 'mime' => $asset->mime, 'name' => $asset->name, 'width' => $asset->width, 'height' => $asset->height];
    }

    public function assetPath(Document $document, DocumentAsset $asset): string
    {
        abort_unless($asset->document_id === $document->id && str_starts_with($asset->storage_path, 'documents/'.$document->id.'/'), 404);
        $root = realpath(Storage::disk('local')->path('documents/'.$document->id));
        $path = realpath(Storage::disk('local')->path($asset->storage_path));
        abort_unless($root && $path && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && is_file($path), 404);

        return $path;
    }

    private function storeAsset(Document $document, string $bytes, string $name, array $dimensions): DocumentAsset
    {
        $mime = $dimensions['mime'];
        $extension = match ($mime) {
            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
        };
        $path = 'documents/'.$document->id.'/'.Str::uuid().'.'.$extension;
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new RuntimeException('ไม่สามารถบันทึกไฟล์รูปภาพได้');
        }
        try {
            return DocumentAsset::create(['document_id' => $document->id, 'name' => mb_substr(basename($name), 0, 255), 'storage_path' => $path, 'mime' => $mime, 'size' => strlen($bytes), 'width' => $dimensions[0], 'height' => $dimensions[1]]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    private function attributes(array $data): array
    {
        return ['title' => $data['title'], 'content_json' => $data['content'], 'page_settings_json' => $data['pageSettings'], 'objects_json' => $data['objects'], 'schema_version' => $data['schemaVersion']];
    }

    public function capture(Document $document, User $user): void
    {
        DocumentVersion::create(['document_id' => $document->id, 'user_id' => $user->id, 'revision' => $document->revision, 'snapshot_json' => [
            'title' => $document->title, 'content' => $document->content_json, 'pageSettings' => $document->page_settings_json,
            'objects' => $document->objects_json, 'schemaVersion' => $document->schema_version,
        ]]);
    }

    private function rewriteAssetReferences(array $data, array $references): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->rewriteAssetReferences($value, $references);
            } elseif (in_array($key, ['src', 'assetId'], true) && is_string($value) && isset($references[$value])) {
                $data[$key] = $references[$value];
            }
        }

        return $data;
    }

    private function conflict(Document $document): never
    {
        throw new HttpResponseException(response()->json(['message' => 'เอกสารถูกแก้ไขจากอีกหน้าต่าง กรุณาโหลดฉบับล่าสุดก่อนบันทึก', 'code' => 'document_version_conflict', 'document' => $this->resource($document)], 409));
    }

    private function allowedSourceImage(string $path): bool
    {
        foreach ([dirname(base_path()).'/uploads', public_path('uploads'), Storage::disk('public')->path('')] as $candidate) {
            $root = realpath($candidate);
            if ($root && str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    private function appendPlainParagraphs(array &$parts, string $text, \Closure $paragraph): void
    {
        $segments = preg_split('/(\[break\]|\[page-break\]|\*\*\*)/i', str_replace(["\r\n", "\r"], "\n", $text), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [''];
        foreach ($segments as $segment) {
            if (preg_match('/^(\[break\]|\[page-break\]|\*\*\*)$/i', $segment) === 1) {
                $parts[] = ['type' => 'pageBreak'];

                continue;
            }
            foreach (explode("\n", $segment) as $line) {
                $parts[] = $paragraph($line);
            }
        }
    }

    private function appendAttendance(array &$parts, TeachingRecord $record, array $settings, \Closure $paragraph, \Closure $heading): void
    {
        if ($settings['show_attendance_page'] !== '1') {
            return;
        }
        $students = $record->attendance()->where('present', 1)->orderBy('id')->get();
        if ($students->isEmpty()) {
            return;
        }
        $cell = static fn (string $text, bool $header = false): array => ['type' => $header ? 'tableHeader' : 'tableCell', 'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null], 'content' => [$paragraph($text)]];
        $rows = [['type' => 'tableRow', 'content' => array_map(static fn (string $label): array => $cell($label, true), ['ลำดับที่', 'รหัสนักศึกษา', 'ชื่อ - ชื่อสกุล', 'ลายมือชื่อ', 'หมายเหตุ'])]];
        foreach ($students as $index => $student) {
            $method = $student->learning_method === 'ออนไลน์' ? 'ออนไลน์' : 'พบกลุ่ม';
            $rows[] = ['type' => 'tableRow', 'content' => array_map($cell, [(string) ($index + 1), (string) $student->student_id, (string) $student->full_name, '', $method])];
        }
        $parts[] = ['type' => 'pageBreak'];
        $parts[] = $heading('บัญชีรายชื่อผู้เรียน');
        $parts[] = $paragraph('ครั้งที่ '.$record->week.'   วันที่ '.$this->sourceDateLabel($record).'   ภาคเรียน '.$record->semester.'/'.$record->year);
        $parts[] = ['type' => 'table', 'attrs' => ['columnSize' => '8,20,32,22,18'], 'content' => $rows];
    }

    private function sourceDateLabel(TeachingRecord $record): string
    {
        return $record->date ? $record->date->format('d/m/').((int) $record->date->format('Y') + 543) : '';
    }

    private static function sourcePageSettings(array $settings): array
    {
        $page = DocumentSchema::defaultPageSettings();
        $page['orientation'] = $settings['paper_orientation'] ?? 'portrait';
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $page['margins'][$side] = round(((float) ($settings['pdf_margin_'.$side.'_px'] ?? 57)) * 25.4 / 96, 2);
        }
        $page['fontSize'] = (int) ($settings['pdf_font_size_pt'] ?? 16);

        return $page;
    }
}
