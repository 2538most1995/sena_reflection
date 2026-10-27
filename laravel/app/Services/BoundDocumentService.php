<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DraftImage;
use App\Models\RecordDocumentLayout;
use App\Models\TeachingDraft;
use App\Models\TeachingRecord;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BoundDocumentService
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly RecordDocumentOptions $options,
        private readonly RecordRevisionService $revisions,
        private readonly LegacyPrintService $print,
        private readonly DraftService $drafts,
        private readonly RecordEditorTemplate $template,
    ) {}

    public function forRecord(User $user, TeachingRecord $record): Document
    {
        $this->authorizeRecord($user, $record);

        return DB::transaction(function () use ($user, $record): Document {
            $record = TeachingRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $existing = Document::query()->where('binding_key', 'record:'.$record->id)->lockForUpdate()->first();
            $snapshot = $this->sourceSnapshot($this->print->savedSnapshot($record, $user));
            $fingerprint = $this->recordFingerprint($record);
            if ($existing) {
                $revision = $existing->revision;
                $refreshed = $this->rebase($existing, $user, $snapshot, $fingerprint);
                if ($refreshed->revision !== $revision) {
                    $this->revisions->capture($record, $user);
                }

                return $refreshed;
            }

            $seeded = $this->seed($user, 'record:'.$record->id, $snapshot, [
                'kind' => 'record', 'record_id' => $record->id,
                'source_fingerprint' => $fingerprint,
            ]);
            $this->revisions->capture($record, $user);

            return $seeded;
        }, 3);
    }

    public function forDraft(User $user, TeachingDraft $draft, string $levelCode): Document
    {
        return DB::transaction(function () use ($user, $draft, $levelCode): Document {
            $owned = $this->drafts->owned($user, TeachingDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail());
            abort_unless($owned->status === 'active', 409, $owned->status === 'paused' ? 'ร่างนี้พักไว้ กรุณานำกลับจากหน้าจัดการร่างก่อนแก้ไขเอกสาร' : 'ร่างนี้ยืนยันแล้ว กรุณาเปิดจากรายการบันทึก');
            $snapshot = $this->sourceSnapshot($this->drafts->snapshotForPreview($user, $owned, $levelCode, [], $owned->lock_version));
            $existing = Document::query()->where('binding_key', 'draft:'.$owned->id.':'.$levelCode)->lockForUpdate()->first();
            if ($existing) {
                return $this->rebase($existing, $user, $snapshot, $this->snapshotFingerprint($snapshot), ['lock_version' => $owned->lock_version]);
            }

            return $this->seed($user, 'draft:'.$owned->id.':'.$levelCode, $snapshot, [
                'kind' => 'draft', 'draft_id' => $owned->id, 'level_code' => $levelCode,
                'lock_version' => $owned->lock_version, 'source_fingerprint' => $this->snapshotFingerprint($snapshot),
            ]);
        }, 3);
    }

    public function refresh(Document $document, User $user): Document
    {
        $this->authorize($user, $document);
        $binding = $document->binding_snapshot;

        return $binding['kind'] === 'record'
            ? $this->forRecord($user, TeachingRecord::query()->findOrFail($binding['record_id']))
            : $this->forDraft($user, TeachingDraft::query()->findOrFail($binding['draft_id']), $binding['level_code']);
    }

    public function snapshotForPrint(Document $document): array
    {
        return array_intersect_key($this->documents->resource($document), array_flip(['id', 'title', 'content', 'pageSettings', 'objects', 'schemaVersion', 'revision', 'assets']));
    }

    public function snapshotForRecord(TeachingRecord $record): ?array
    {
        if (! Schema::hasTable('documents')) {
            return null;
        }

        $document = Document::query()->where('binding_key', 'record:'.$record->id)->first();

        return $document && ($document->binding_snapshot['source_fingerprint'] ?? '') === $this->recordFingerprint($record)
            ? $this->snapshotForPrint($document) : null;
    }

    public function attachDraftSnapshot(array $snapshot, string $draftId, string $levelCode): array
    {
        if (! Schema::hasTable('documents')) {
            return $snapshot;
        }

        $document = Document::query()->where('binding_key', 'draft:'.$draftId.':'.$levelCode)->first();
        if ($document && ($document->binding_snapshot['source_fingerprint'] ?? '') === $this->snapshotFingerprint($snapshot)) {
            $snapshot['advanced_document'] = $this->snapshotForPrint($document);
        }

        return $snapshot;
    }

    public function transferDraftToRecord(User $user, TeachingDraft $draft, string $levelCode, TeachingRecord $record): void
    {
        $this->drafts->owned($user, $draft);
        if (! Schema::hasTable('documents')) {
            return;
        }
        $document = Document::query()->where('binding_key', 'draft:'.$draft->id.':'.$levelCode)->lockForUpdate()->first();
        if (! $document) {
            return;
        }
        $snapshot = $this->sourceSnapshot($this->drafts->snapshotForPreview($user, $draft, $levelCode));
        $document = $this->rebase($document, $user, $snapshot, $this->snapshotFingerprint($snapshot), ['lock_version' => $draft->lock_version]);
        $key = 'record:'.$record->id;
        $previous = Document::query()->where('binding_key', $key)->whereKeyNot($document->id)->lockForUpdate()->first();
        if ($previous) {
            $previous->update(['binding_key' => null]);
        }
        $binding = $document->binding_snapshot;
        $source = $this->sourceSnapshot($this->print->savedSnapshot($record, $user));
        if (isset($binding['managed']['images'])) {
            $remapped = [];
            foreach ($snapshot['images'] as $index => $image) {
                $entry = $binding['managed']['images'][(string) $image['id']] ?? null;
                $target = $source['images'][$index] ?? null;
                if ($entry && $target) {
                    $remapped[(string) $target['id']] = [...$entry, 'path' => $target['file_path']];
                }
            }
            $binding['managed']['images'] = $remapped;
        }
        $document->update(['binding_key' => $key, 'binding_snapshot' => [
            ...$binding, 'kind' => 'record', 'record_id' => $record->id,
            'draft_origin' => ['draft_id' => $draft->id, 'level_code' => $levelCode],
            'source_snapshot' => $source, 'source_fingerprint' => $this->recordFingerprint($record),
        ]]);
        $this->documents->update($document, $user, $document->revision, []);
    }

    public function authorize(User $user, Document $document): void
    {
        $binding = $document->binding_snapshot ?? [];
        if (($binding['kind'] ?? '') === 'record') {
            $this->authorizeRecord($user, TeachingRecord::query()->findOrFail($binding['record_id'] ?? ''));

            return;
        }
        if (($binding['kind'] ?? '') === 'draft') {
            $this->drafts->owned($user, TeachingDraft::query()->findOrFail($binding['draft_id'] ?? ''));

            return;
        }
        abort(404);
    }

    public function context(Document $document): array
    {
        $binding = $document->binding_snapshot ?? [];
        if (($binding['kind'] ?? '') === 'record') {
            return ['kind' => 'record', 'recordId' => $binding['record_id'], 'returnUrl' => route('records.show', $binding['record_id'], false), 'title' => 'จัดเอกสารก่อนพิมพ์', 'sourceVersion' => $binding['source_fingerprint'] ?? '', 'templateVersion' => $binding['template_version'] ?? 1];
        }
        if (($binding['kind'] ?? '') === 'draft') {
            return ['kind' => 'draft', 'draftId' => $binding['draft_id'], 'levelCode' => $binding['level_code'], 'lockVersion' => $binding['lock_version'], 'returnUrl' => route('records.create', ['draft' => $binding['draft_id'], 'review' => 1], false), 'title' => 'จัดเอกสารฉบับร่างก่อนพิมพ์', 'sourceVersion' => $binding['source_fingerprint'] ?? '', 'templateVersion' => $binding['template_version'] ?? 1];
        }

        return [];
    }

    public function save(Document $document, User $user, int $revision, array $payload): Document
    {
        $this->authorize($user, $document);

        return DB::transaction(function () use ($document, $user, $revision, $payload): Document {
            $binding = $document->binding_snapshot ?? [];
            if (($binding['kind'] ?? '') === 'record') {
                return $this->saveRecord($document, $user, $revision, $payload);
            }

            return $this->saveDraft($document, $user, $revision, $payload);
        }, 3);
    }

    private function seed(User $user, string $key, array $snapshot, array $binding): Document
    {
        $document = Document::create([
            'user_id' => $user->id, 'title' => 'บันทึกหลังการสอน — '.($snapshot['record']['subject'] ?? ''),
            'content_json' => DocumentSchema::blankContent(), 'page_settings_json' => DocumentSchema::defaultPageSettings(),
            'objects_json' => [], 'schema_version' => 1, 'revision' => 1, 'binding_key' => $key,
            'binding_snapshot' => [...$binding, 'template_version' => 1, 'original_snapshot' => $snapshot, 'source_snapshot' => $snapshot],
        ]);
        try {
            $renderSnapshot = [...$snapshot, 'images' => $this->documents->importSnapshotAssets($document, $snapshot)];
            $template = $this->template->build($renderSnapshot);
            $this->stampInfoRows($template['content']);
            $data = app(DocumentSchema::class)->validate($template, $document);
            $metadata = $document->binding_snapshot;
            $metadata['managed'] = $this->managedState($snapshot, $data['content'], $data['objects'], $renderSnapshot['images']);
            $document->fill(['content_json' => $data['content'], 'page_settings_json' => $data['pageSettings'], 'objects_json' => $data['objects'], 'binding_snapshot' => $metadata])->save();
            $this->documents->capture($document, $user);
        } catch (\Throwable $exception) {
            Storage::disk('local')->deleteDirectory('documents/'.$document->id);
            throw $exception;
        }

        return $document;
    }

    private function saveRecord(Document $document, User $user, int $revision, array $payload): Document
    {
        $record = TeachingRecord::query()->whereKey($document->binding_snapshot['record_id'])->lockForUpdate()->firstOrFail();
        $this->authorizeRecord($user, $record);
        $current = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
        $binding = $current->binding_snapshot;
        if (($binding['source_fingerprint'] ?? '') !== $this->recordFingerprint($record)) {
            $this->sourceConflict('บันทึกต้นฉบับถูกแก้ไขจากหน้าอื่น กรุณาเก็บสำเนาแล้วเปิดฉบับล่าสุด');
        }
        $this->revisions->capture($record, $user);
        $updated = $this->documents->update($current, $user, $revision, $payload);
        $projection = $this->projection($updated, $binding['source_snapshot']);
        $validated = $this->options->validate($record, $projection);
        $columns = [];
        foreach ($validated['content'] as $key => $value) {
            $columns[substr($key, 7)] = $value;
        }
        $record->update($columns);
        RecordDocumentLayout::query()->updateOrCreate(['record_id' => $record->id], ['settings' => $validated['settings'], 'content' => [], 'updated_by' => $user->id]);
        $binding['source_snapshot'] = $this->sourceSnapshot($this->print->savedSnapshot($record, $user));
        $binding['source_fingerprint'] = $this->recordFingerprint($record);
        $updated->update(['binding_snapshot' => $binding]);
        $this->revisions->capture($record->fresh(), $user, $validated['settings']);

        return $updated;
    }

    private function saveDraft(Document $document, User $user, int $revision, array $payload): Document
    {
        $current = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
        $binding = $current->binding_snapshot;
        $owned = $this->drafts->owned($user, TeachingDraft::query()->whereKey($binding['draft_id'])->lockForUpdate()->firstOrFail());
        abort_unless($owned->status === 'active', 409, $owned->status === 'paused' ? 'ร่างนี้พักไว้ กรุณานำกลับจากหน้าจัดการร่างก่อนแก้ไขเอกสาร' : 'ร่างนี้ยืนยันแล้ว กรุณาเปิดจากรายการบันทึก');
        $lock = (int) ($binding['lock_version'] ?? 0);
        $snapshot = $this->sourceSnapshot($this->drafts->snapshotForPreview($user, $owned, $binding['level_code'], [], $lock));
        if (($binding['source_fingerprint'] ?? '') !== $this->snapshotFingerprint($snapshot)) {
            $this->sourceConflict('ร่างถูกแก้ไขจากหน้าอื่น กรุณาเปิดฉบับล่าสุด');
        }
        $updated = $this->documents->update($current, $user, $revision, $payload);
        $projection = $this->projection($updated, $snapshot);
        $record = new TeachingRecord($snapshot['record']);
        $record->setRelation('images', DraftImage::hydrate($snapshot['images']));
        $validated = $this->options->validate($record, $projection);
        $draftPayload = $owned->payload;
        foreach ($validated['content'] as $key => $value) {
            $draftPayload['levels'][$binding['level_code']][substr($key, 7)] = $value;
        }
        $draftPayload['levels'][$binding['level_code']]['document']['settings'] = $validated['settings'];
        $saved = $this->drafts->update($user, $owned, $lock, $draftPayload);
        $binding['lock_version'] = $saved->lock_version;
        $binding['source_snapshot'] = $this->sourceSnapshot($this->drafts->snapshotForPreview($user, $saved, $binding['level_code']));
        $binding['source_fingerprint'] = $this->snapshotFingerprint($binding['source_snapshot']);
        $updated->update(['binding_snapshot' => $binding]);

        return $updated;
    }

    private function projection(Document $document, array $snapshot): array
    {
        $settings = array_intersect_key($snapshot['settings'] ?? [], array_flip(RecordDocumentOptions::SETTING_KEYS));
        $content = $snapshot['content'] ?? [];
        $fields = [];
        $richFields = [];
        $this->collectFields($document->content_json, $fields, $settings, $richFields);
        foreach ($fields as $field => $values) {
            $value = implode("\n", $values);
            if (in_array($field, RecordDocumentOptions::CONTENT_KEYS, true)) {
                $content[$field] = $value;
            } elseif (in_array($field, RecordDocumentOptions::SETTING_KEYS, true)) {
                if (preg_match('/^sig[123]_name$/', $field) === 1) {
                    $value = trim($value);
                    if (str_starts_with($value, '(') && str_ends_with($value, ')')) {
                        $value = trim(substr($value, 1, -1));
                    }
                }
                $settings[$field] = $value;
            }
        }
        $richMap = json_decode($settings['document_rich_text'] ?? '{}', true) ?: [];
        foreach ($richFields as $field => $nodes) {
            $plain = $content[$field] ?? $settings[$field] ?? '';
            $html = '';
            foreach ($nodes as $node) {
                if (preg_match('/^sig[123]_name$/', $field) === 1) {
                    $this->removeSignatureParens($node);
                }
                $html .= $this->richHtml($node);
            }
            $richMap[$field] = ['text' => $plain, 'html' => $html];
        }
        try {
            $settings['document_rich_text'] = DocumentRichText::normalizeMap(json_encode((object) $richMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), RecordDocumentOptions::RICH_TEXT_KEYS);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['content' => $exception->getMessage()]);
        }
        $page = $document->page_settings_json;
        $settings['paper_orientation'] = $page['orientation'];
        foreach (['top' => [20, 140], 'right' => [20, 160], 'bottom' => [20, 140], 'left' => [20, 160]] as $side => [$min, $max]) {
            $settings['pdf_margin_'.$side.'_px'] = (string) max($min, min($max, (int) round($page['margins'][$side] * 96 / 25.4)));
        }
        if (is_numeric($page['fontSize'] ?? null)) {
            $settings['pdf_font_size_pt'] = (string) max(10, min(20, (int) round($page['fontSize'])));
        }

        return ['settings' => $settings, 'content' => $content];
    }

    private function collectFields(array $node, array &$fields, array &$settings, array &$richFields): void
    {
        $attrs = $node['attrs'] ?? [];
        $field = $attrs['field'] ?? null;
        if (is_string($field) && (in_array($field, RecordDocumentOptions::CONTENT_KEYS, true) || in_array($field, RecordDocumentOptions::SETTING_KEYS, true))) {
            $fields[$field][] = $this->plain($node);
            if (in_array($field, RecordDocumentOptions::RICH_TEXT_KEYS, true)) {
                $richFields[$field][] = $node;
            }
            if (preg_match('/^sig([123])_(name|pos|title)$/', $field, $match) === 1) {
                foreach (['offsetX' => 'x', 'offsetY' => 'y'] as $attr => $axis) {
                    $key = 'sig'.$match[1].'_'.$match[2].'_offset_'.$axis;
                    if (in_array($key, RecordDocumentOptions::SETTING_KEYS, true) && is_numeric($attrs[$attr] ?? null)) {
                        $settings[$key] = (string) (int) round($attrs[$attr]);
                    }
                }
            }
        }
        if (($node['type'] ?? '') === 'recordBody') {
            foreach (['lineSpacing' => ['pdf_line_spacing', 20, 40], 'firstIndent' => ['record_text_indent_px', 0, 160], 'spaceAfter' => ['record_paragraph_spacing_px', 0, 80]] as $attr => [$key, $min, $max]) {
                if (is_numeric($attrs[$attr] ?? null)) {
                    $settings[$key] = (string) max($min, min($max, (int) round($attrs[$attr])));
                }
            }
            if (in_array($attrs['align'] ?? null, ['left', 'center', 'right', 'justify', 'thai-distributed'], true)) {
                $settings['record_text_align'] = $attrs['align'];
            }
        }
        if (($node['type'] ?? '') === 'recordSignature') {
            $index = (int) ($attrs['index'] ?? 0);
            if (in_array($index, [1, 2, 3], true)) {
                $settings["sig{$index}_align"] = match ($attrs['align'] ?? '') {
                    'left' => 'flex-start', 'center' => 'center', default => 'flex-end'
                };
                foreach (['offsetX' => 'offset', 'offsetY' => 'offset_y', 'marginTop' => 'mt'] as $attr => $part) {
                    if (is_numeric($attrs[$attr] ?? null)) {
                        $settings["sig{$index}_{$part}"] = (string) (int) round($attrs[$attr]);
                    }
                }
            }
        }
        if (($node['type'] ?? '') === 'recordGroup') {
            $index = $this->signatureIndex($node);
            if (in_array($index, [2, 3], true)) {
                $settings["sig{$index}_block_offset_y"] = (string) (int) round(($attrs['spaceBefore'] ?? 0) - ($index === 2 ? 18 : 24));
                $settings["sig{$index}_block_offset_x"] = (string) (int) round($attrs['offsetX'] ?? 0);
            }
        }
        foreach ($node['content'] ?? [] as $child) {
            $this->collectFields($child, $fields, $settings, $richFields);
        }
    }

    private function richHtml(array $node): string
    {
        $type = $node['type'] ?? '';
        if ($type === 'text') {
            $html = htmlspecialchars($node['text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            foreach ($node['marks'] ?? [] as $mark) {
                $tag = match ($mark['type'] ?? '') {
                    'bold' => 'b', 'italic' => 'i', 'underline' => 'u', 'strike' => 's', default => null
                };
                if ($tag) {
                    $html = '<'.$tag.'>'.$html.'</'.$tag.'>';
                }
                if (in_array($mark['type'] ?? '', ['textStyle', 'highlight'], true)) {
                    $styles = [];
                    foreach (['fontFamily' => 'font-family', 'fontSize' => 'font-size', 'color' => 'color', 'backgroundColor' => 'background-color', 'lineHeight' => 'line-height'] as $attr => $property) {
                        if (isset($mark['attrs'][$attr])) {
                            $property = ($mark['type'] ?? '') === 'highlight' && $attr === 'color' ? 'background-color' : $property;
                            $styles[] = $property.':'.$mark['attrs'][$attr];
                        }
                    }
                    if ($styles !== []) {
                        $html = '<span style="'.htmlspecialchars(implode(';', $styles), ENT_QUOTES, 'UTF-8').'">'.$html.'</span>';
                    }
                }
            }

            return $html;
        }
        if ($type === 'hardBreak') {
            return '<br>';
        }
        if ($type === 'pageBreak') {
            return '[break]';
        }
        $html = implode('', array_map(fn (array $child): string => $this->richHtml($child), $node['content'] ?? []));

        $alignment = $node['attrs']['textAlign'] ?? ($type === 'recordBody' && ($node['attrs']['align'] ?? '') === 'thai-distributed' ? 'thai-distributed' : null);
        $style = $alignment === 'thai-distributed'
            ? 'text-align:justify;text-align-last:left;text-justify:inter-character'
            : (in_array($alignment, ['left', 'center', 'right', 'justify'], true) ? 'text-align:'.$alignment.';text-align-last:auto;text-justify:auto' : '');
        if (in_array($type, ['paragraph', 'heading'], true) || ($style !== '' && in_array($type, ['recordInfoCell', 'recordSectionTitle', 'recordBody'], true))) {
            return '<div'.($style !== '' ? ' style="'.$style.'"' : '').'>'.$html.'</div>';
        }

        return $html;
    }

    private function removeSignatureParens(array &$node): void
    {
        $leaves = [];
        $walk = function (array &$item) use (&$walk, &$leaves): void {
            if (($item['type'] ?? '') === 'text') {
                $leaves[] = &$item;
            }
            if (isset($item['content'])) {
                foreach ($item['content'] as &$child) {
                    $walk($child);
                }
            }
        };
        $walk($node);
        if ($leaves !== []) {
            $leaves[0]['text'] = preg_replace('/^\s*\(\s*/u', '', $leaves[0]['text']) ?? $leaves[0]['text'];
            $last = count($leaves) - 1;
            $leaves[$last]['text'] = preg_replace('/\s*\)\s*$/u', '', $leaves[$last]['text']) ?? $leaves[$last]['text'];
        }
    }

    private function signatureIndex(array $node): int
    {
        if (($node['type'] ?? '') === 'recordSignature') {
            return (int) ($node['attrs']['index'] ?? 0);
        }
        foreach ($node['content'] ?? [] as $child) {
            if ($index = $this->signatureIndex($child)) {
                return $index;
            }
        }

        return 0;
    }

    private function plain(array $node): string
    {
        if (($node['type'] ?? '') === 'text') {
            return $node['text'] ?? '';
        }
        if (($node['type'] ?? '') === 'hardBreak') {
            return "\n";
        }
        if (($node['type'] ?? '') === 'pageBreak') {
            return '[break]';
        }
        $parts = [];
        foreach ($node['content'] ?? [] as $child) {
            $parts[] = $this->plain($child);
        }
        $separator = in_array($node['type'] ?? '', ['recordBody', 'doc', 'blockquote', 'listItem', 'bulletList', 'orderedList'], true) ? "\n" : '';

        return DocumentRichText::normalize(implode($separator, $parts));
    }

    private function authorizeRecord(User $user, TeachingRecord $record): void
    {
        abort_unless($user->isDistrict() || (string) $record->tambon === (string) $user->tambon, 403);
    }

    private function rebase(Document $document, User $user, array $snapshot, string $fingerprint, array $metadata = []): Document
    {
        $binding = $document->binding_snapshot;
        if (($binding['source_fingerprint'] ?? '') !== $fingerprint) {
            $oldSnapshot = $binding['source_snapshot'];
            $beforeTemplate = $this->template->build($oldSnapshot);
            $afterTemplate = $this->template->build($snapshot);
            $before = $this->templateFields($beforeTemplate['content']);
            $after = $this->templateFields($afterTemplate['content']);
            $changes = [];
            foreach ($after as $field => $node) {
                if (! isset($before[$field]) || $this->plain($before[$field]) !== $this->plain($node)) {
                    $changes[$field] = $node;
                }
            }
            $content = $document->content_json;
            $this->rebaseFields($content, $changes);
            $managed = $binding['managed'] ?? $this->inferManagedState($document, $content);
            $this->mergeInfoRows($content, $managed, $beforeTemplate['content'], $afterTemplate['content']);
            $objects = $document->objects_json;
            $previousAssets = $document->assets()->pluck('id')->all();
            try {
                if ($this->photoSource($oldSnapshot) !== $this->photoSource($snapshot)) {
                    $this->mergePhotos($document, $content, $objects, $managed, $snapshot, $oldSnapshot);
                }
                if ($this->attendanceSource($oldSnapshot) !== $this->attendanceSource($snapshot)) {
                    $this->mergeAttendance($content, $managed, $afterTemplate['content'], $snapshot);
                }
                $document = $this->documents->update($document, $user, $document->revision, ['content' => $content, 'objects' => $objects]);
            } catch (\Throwable $exception) {
                Storage::disk('local')->delete($document->assets()->whereNotIn('id', $previousAssets)->pluck('storage_path')->all());
                throw $exception;
            }
            $binding['managed'] = $managed;
            $binding['source_snapshot'] = $snapshot;
            $binding['source_fingerprint'] = $fingerprint;
        }
        $document->update(['binding_snapshot' => [...$binding, ...$metadata]]);

        return $document;
    }

    private function templateFields(array $node): array
    {
        $fields = [];
        $field = $node['attrs']['field'] ?? null;
        if (is_string($field) && in_array($field, [...RecordDocumentOptions::CONTENT_KEYS, ...RecordDocumentOptions::RICH_TEXT_KEYS], true)) {
            $fields[$field] = $node;
        }
        foreach ($node['content'] ?? [] as $child) {
            $fields = [...$fields, ...$this->templateFields($child)];
        }

        return $fields;
    }

    private function stampInfoRows(array &$content): void
    {
        foreach ($content['content'] as &$node) {
            if ($this->infoKey($node) !== null) {
                $node['attrs']['id'] ??= (string) Str::uuid();
            }
        }
    }

    private function infoKey(array $node): ?string
    {
        if (($node['type'] ?? '') !== 'recordInfoRow' || ($node['attrs']['compact'] ?? false)) {
            return null;
        }

        return implode('|', array_map(fn (array $cell): string => $this->plain($cell), array_filter($node['content'] ?? [], static fn (array $cell): bool => (bool) ($cell['attrs']['label'] ?? false))));
    }

    private function managedState(array $snapshot, array $content, array $objects, array $images): array
    {
        $state = ['images' => [], 'info_rows' => [], 'photo' => null, 'attendance' => null];
        $imported = array_column($images, null, 'source_id');
        foreach ($snapshot['images'] ?? [] as $image) {
            $id = (string) $image['id'];
            $asset = $imported[$id] ?? null;
            $object = collect($objects)->firstWhere('assetId', $asset['asset_id'] ?? '');
            $state['images'][$id] = ['path' => $image['file_path'], 'asset_id' => $asset['asset_id'] ?? null, 'object_id' => $object['id'] ?? null, 'object' => $object];
        }
        foreach ($content['content'] as $index => $node) {
            if (($key = $this->infoKey($node)) !== null) {
                $state['info_rows'][$key] = $node['attrs']['id'] ?? null;
            }
            if (($node['attrs']['role'] ?? '') !== 'recordTitle') {
                continue;
            }
            if ($this->plain($node) === 'ภาพประกอบการจัดการเรียนการสอน') {
                $state['photo'] = ['heading_id' => $node['attrs']['id'], 'blank_id' => $content['content'][$index + 1]['attrs']['id'] ?? null];
            }
            if ($this->plain($node) === 'บัญชีรายชื่อผู้เรียน' && ($content['content'][$index + 1]['type'] ?? '') === 'table') {
                $table = $content['content'][$index + 1];
                $rows = $this->tableRows($table);
                $state['attendance'] = ['heading_id' => $node['attrs']['id'], 'table_marker' => $this->firstParagraphId($table), 'rows' => []];
                foreach ($this->presentRows($snapshot) as $rowIndex => $row) {
                    $state['attendance']['rows'][(string) $row['student_id']] = $this->firstParagraphId($rows[$rowIndex + 1] ?? []);
                }
            }
        }

        return $state;
    }

    private function inferManagedState(Document $document, array &$content): array
    {
        $original = $document->versions()->reorder('revision')->first()?->snapshot_json;
        if (! $original) {
            $this->sourceConflict('ไม่พบข้อมูลเอกสารตั้งต้นสำหรับปรับข้อมูลล่าสุด กรุณาเก็บสำเนาแล้วติดต่อผู้ดูแล');
        }
        $snapshot = $document->binding_snapshot['original_snapshot'];
        $sourceObjects = array_values(array_filter($original['objects'], static fn (array $object): bool => str_starts_with($object['id'], 'record-image-')));
        $images = [];
        $assets = $document->assets()->get();
        foreach ($snapshot['images'] ?? [] as $index => $source) {
            $object = count($sourceObjects) === count($snapshot['images']) ? $sourceObjects[$index] : null;
            $matches = $assets->where('name', basename($source['file_path']));
            $asset = $object ? $assets->firstWhere('id', $object['assetId']) : ($matches->count() === 1 ? $matches->first() : null);
            if (! $asset && $matches->count() > 1) {
                $this->sourceConflict('ไม่สามารถจับคู่รูปเดิมกับรูปต้นทางได้อย่างแน่นอน กรุณาเก็บสำเนาแล้วติดต่อผู้ดูแล');
            }
            if ($asset) {
                $images[] = ['source_id' => (string) $source['id'], 'asset_id' => $asset->id];
            }
        }
        $state = $this->managedState($snapshot, $original['content'], $original['objects'], $images);
        $this->stampInfoRows($content);
        foreach ($content['content'] as $node) {
            if (($key = $this->infoKey($node)) !== null && array_key_exists($key, $state['info_rows'])) {
                $state['info_rows'][$key] = $node['attrs']['id'];
            }
        }

        return $state;
    }

    private function mergeInfoRows(array &$content, array &$managed, array $before, array $after): void
    {
        $beforeRows = [];
        $afterRows = [];
        foreach ($before['content'] as $node) {
            if (($key = $this->infoKey($node)) !== null) {
                $beforeRows[$key] = $node;
            }
        }
        foreach ($after['content'] as $node) {
            if (($key = $this->infoKey($node)) !== null) {
                $afterRows[$key] = $node;
            }
        }
        foreach ($afterRows as $key => $next) {
            $previous = $beforeRows[$key] ?? null;
            if ($previous && $this->plain($previous) === $this->plain($next)) {
                continue;
            }
            $matches = [];
            foreach ($content['content'] as $index => $node) {
                if (($managed['info_rows'][$key] ?? null) && ($node['attrs']['id'] ?? null) === $managed['info_rows'][$key]) {
                    $matches[] = $index;
                }
            }
            if (count($matches) !== 1 && $previous) {
                $this->sourceConflict('ช่องข้อมูลหัวบันทึกถูกเปลี่ยนโครงสร้าง กรุณาเก็บสำเนาแล้วเปิดเอกสารต้นทางอีกครั้ง');
            }
            if ($matches === []) {
                $next['attrs']['id'] = (string) Str::uuid();
                $insert = collect($content['content'])->search(static fn (array $node): bool => ($node['type'] ?? '') === 'recordSectionTitle');
                array_splice($content['content'], $insert === false ? count($content['content']) : $insert, 0, [$next]);
                $managed['info_rows'][$key] = $next['attrs']['id'];

                continue;
            }
            $index = $matches[0];
            foreach ($next['content'] as $cellIndex => $cell) {
                if (! isset($previous['content'][$cellIndex]) || $this->plain($previous['content'][$cellIndex]) !== $this->plain($cell)) {
                    $current = $content['content'][$index]['content'][$cellIndex] ?? null;
                    if (! $current) {
                        $this->sourceConflict('ช่องข้อมูลหัวบันทึกไม่ครบ กรุณาเก็บสำเนาแล้วติดต่อผู้ดูแล');
                    }
                    $content['content'][$index]['content'][$cellIndex]['content'] = $this->styledReplacement($current, $cell)['content'] ?? [];
                    if (isset($cell['attrs']['field'])) {
                        $content['content'][$index]['content'][$cellIndex]['attrs']['field'] = $cell['attrs']['field'];
                    }
                }
            }
        }
        foreach (array_diff_key($beforeRows, $afterRows) as $key => $removed) {
            $index = $this->topIndex($content, $managed['info_rows'][$key] ?? null);
            if ($index !== null) {
                array_splice($content['content'], $index, 1);
            }
            unset($managed['info_rows'][$key]);
        }
    }

    private function photoSource(array $snapshot): array
    {
        return ['images' => array_map(static fn (array $image): array => ['id' => (string) $image['id'], 'file_path' => $image['file_path']], $snapshot['images'] ?? []), 'visible' => $snapshot['settings']['show_image_page'] ?? '1'];
    }

    private function attendanceSource(array $snapshot): array
    {
        return ['rows' => $this->presentRows($snapshot), 'visible' => $snapshot['settings']['show_attendance_page'] ?? '1'];
    }

    private function presentRows(array $snapshot): array
    {
        return array_values(array_filter($snapshot['attendance'] ?? [], static fn (array $row): bool => (bool) ($row['present'] ?? false)));
    }

    private function mergePhotos(Document $document, array &$content, array &$objects, array &$managed, array $snapshot, array $before): void
    {
        $nextImages = [];
        $renderImages = [];
        $oldIds = array_column($before['images'] ?? [], 'file_path', 'id');
        foreach ($snapshot['images'] ?? [] as $image) {
            $id = (string) $image['id'];
            $entry = $managed['images'][$id] ?? ['path' => null, 'asset_id' => null, 'object_id' => null, 'object' => null];
            $current = collect($objects)->firstWhere('id', $entry['object_id'] ?? '');
            $entry['object'] = $current ?? $entry['object'];
            $changed = ! isset($oldIds[$id]) || $oldIds[$id] !== $image['file_path'];
            if ($changed) {
                $imported = $this->documents->importSnapshotAssets($document, ['images' => [$image]]);
                if (count($imported) !== 1) {
                    $this->sourceConflict('อ่านรูปต้นทางใหม่ไม่สำเร็จ กรุณาตรวจไฟล์รูปและลองโหลดฉบับล่าสุดอีกครั้ง');
                }
                $entry['asset_id'] = $imported[0]['asset_id'];
            }
            $entry['path'] = $image['file_path'];
            $nextImages[$id] = [...$entry, 'changed' => $changed];
            $asset = $entry['asset_id'] ? $document->assets()->find($entry['asset_id']) : null;
            $renderImages[] = [...$image, 'source_id' => $id, 'asset_id' => $asset?->id, 'url' => $asset ? $this->documents->assetResource($asset)['url'] : null];
        }
        $template = $this->template->build([...$snapshot, 'images' => $renderImages]);
        $generated = $this->managedState($snapshot, $template['content'], $template['objects'], array_map(static fn (array $image): array => ['source_id' => $image['source_id'], 'asset_id' => $image['asset_id']], $renderImages));
        $visible = ($snapshot['settings']['show_image_page'] ?? '1') === '1' && $renderImages !== [];
        $previousHeading = $managed['photo']['heading_id'] ?? null;
        if ($visible) {
            $managed['photo'] = $this->ensureSection($content, $managed['photo'] ?? null, $template['content'], $generated['photo'], $managed['attendance']['heading_id'] ?? null);
        }
        $ownedIds = array_filter(array_column($managed['images'], 'object_id'));
        $preserved = array_values(array_filter($objects, static fn (array $object): bool => ! in_array($object['id'], $ownedIds, true)));
        foreach ($nextImages as $id => &$entry) {
            if (! $visible || ! $entry['asset_id']) {
                unset($entry['changed']);

                continue;
            }
            $current = collect($objects)->firstWhere('id', $entry['object_id'] ?? '');
            $default = collect($template['objects'])->firstWhere('assetId', $entry['asset_id']);
            $wasHidden = ($before['settings']['show_image_page'] ?? '1') !== '1';
            if ($current || $entry['changed'] || $wasHidden) {
                $object = $current ?? $entry['object'] ?? $default;
                if (! $object || ! $default || ! ($managed['photo']['heading_id'] ?? null)) {
                    $this->sourceConflict('เตรียมตำแหน่งรูปต้นทางไม่สำเร็จ กรุณาลองใหม่');
                }
                $object['assetId'] = $entry['asset_id'];
                $object['src'] = $default['src'];
                if ((! $current && ! $entry['object']) || (($object['anchor']['kind'] ?? '') === 'paragraph' && ($object['anchor']['id'] ?? null) === $previousHeading && ! $this->containsId($content, $previousHeading))) {
                    $object['anchor'] = [...$object['anchor'], 'kind' => 'paragraph', 'id' => $managed['photo']['heading_id']];
                    unset($object['anchor']['page']);
                }
                $entry['object_id'] = $object['id'];
                $entry['object'] = $object;
                $preserved[] = $object;
            }
            unset($entry['changed']);
        }
        unset($entry);
        $objects = $preserved;
        $managed['images'] = $nextImages;
        if (! $visible && ($managed['photo'] ?? null)) {
            $heading = $managed['photo']['heading_id'];
            $anchored = collect($objects)->contains(static fn (array $object): bool => ($object['anchor']['kind'] ?? '') === 'paragraph' && ($object['anchor']['id'] ?? '') === $heading);
            if (! $anchored) {
                $this->removeSection($content, $managed['photo']);
            }
        }
    }

    private function ensureSection(array &$content, ?array $existing, array $templateContent, ?array $generated, ?string $beforeHeading = null): array
    {
        if (! $generated) {
            $this->sourceConflict('ไม่พบส่วนข้อมูลเอกสารสำหรับปรับฉบับล่าสุด');
        }
        if ($existing && $this->topIndex($content, $existing['heading_id']) !== null) {
            return $existing;
        }
        $index = $this->topIndex($templateContent, $generated['heading_id']);
        $nodes = [['type' => 'pageBreak'], $templateContent['content'][$index]];
        if (isset($generated['blank_id'])) {
            $nodes[] = $templateContent['content'][$index + 1];
        }
        $insert = $beforeHeading ? $this->topIndex($content, $beforeHeading) : null;
        if ($insert !== null && $insert > 0 && ($content['content'][$insert - 1]['type'] ?? '') === 'pageBreak') {
            $insert--;
        }
        array_splice($content['content'], $insert ?? count($content['content']), 0, $nodes);

        return $generated;
    }

    private function removeSection(array &$content, array $section): void
    {
        $index = $this->topIndex($content, $section['heading_id']);
        if ($index !== null) {
            array_splice($content['content'], $index, 1);
            if ($index > 0 && ($content['content'][$index - 1]['type'] ?? '') === 'pageBreak') {
                array_splice($content['content'], $index - 1, 1);
            }
        }
        if (($index = $this->topIndex($content, $section['blank_id'] ?? null)) !== null && $this->plain($content['content'][$index]) === '') {
            array_splice($content['content'], $index, 1);
        }
    }

    private function mergeAttendance(array &$content, array &$managed, array $templateContent, array $snapshot): void
    {
        $generated = $this->managedState($snapshot, $templateContent, [], [])['attendance'];
        $old = $managed['attendance'] ?? null;
        $tableIndex = null;
        if ($old) {
            foreach ($content['content'] as $index => $node) {
                if (($node['type'] ?? '') === 'table' && $this->containsId($node, $old['table_marker'])) {
                    $tableIndex = $index;
                    break;
                }
            }
        }
        if (! $generated) {
            if ($tableIndex !== null) {
                array_splice($content['content'], $tableIndex, 1);
            }
            if ($old) {
                $this->removeSection($content, $old);
            }
            $managed['attendance'] = null;

            return;
        }
        $headingIndex = $this->topIndex($templateContent, $generated['heading_id']);
        $next = $templateContent['content'][$headingIndex + 1];
        $section = $this->ensureSection($content, $old, $templateContent, $generated);
        $current = $tableIndex !== null ? $content['content'][$tableIndex] : null;
        $rows = $this->tableRows($next);
        $currentRows = $current ? $this->tableRows($current) : [];
        $knownRows = array_filter($old['rows'] ?? []);
        $rowMap = [];
        if ($currentRows !== []) {
            $rows[0] = $currentRows[0];
        }
        foreach ($this->presentRows($snapshot) as $index => $source) {
            $id = (string) $source['student_id'];
            $marker = $knownRows[$id] ?? null;
            $previous = $marker ? collect($currentRows)->first(fn (array $row): bool => $this->containsId($row, $marker)) : null;
            if ($previous) {
                $rows[$index + 1] = $this->styledReplacement($previous, $rows[$index + 1]);
            }
            $rowMap[$id] = $this->firstParagraphId($rows[$index + 1]);
        }
        foreach (array_slice($currentRows, 1) as $row) {
            if (! collect($knownRows)->contains(fn (string $marker): bool => $this->containsId($row, $marker))) {
                $rows[] = $row;
            }
        }
        if ($current) {
            $next = $current;
            $group = collect($current['content'])->firstWhere('type', 'tableRowGroup');
            $next['content'] = $group ? [[...$group, 'content' => $rows]] : $rows;
            $content['content'][$tableIndex] = $next;
        } else {
            $next['content'] = $rows;
            $insert = $this->topIndex($content, $section['heading_id']) + 1;
            array_splice($content['content'], $insert, 0, [$next]);
        }
        $managed['attendance'] = [...$section, 'table_marker' => $this->firstParagraphId($next), 'rows' => $rowMap];
    }

    private function styledReplacement(array $previous, array $next): array
    {
        $next['attrs'] = [...($next['attrs'] ?? []), ...($previous['attrs'] ?? [])];
        if (($next['type'] ?? '') === 'text') {
            if (! isset($next['marks']) && isset($previous['marks'])) {
                $next['marks'] = $previous['marks'];
            }
        } elseif (isset($next['content'])) {
            foreach ($next['content'] as $index => &$child) {
                if (isset($previous['content'][$index]) && $previous['content'][$index]['type'] === $child['type']) {
                    $child = $this->styledReplacement($previous['content'][$index], $child);
                } elseif (($child['type'] ?? '') === 'text' && ($marks = $this->firstTextMarks($previous)) !== [] && ! isset($child['marks'])) {
                    $child['marks'] = $marks;
                }
            }
        }

        return $next;
    }

    private function tableRows(array $table): array
    {
        $rows = [];
        foreach ($table['content'] ?? [] as $child) {
            if (($child['type'] ?? '') === 'tableRow') {
                $rows[] = $child;
            } elseif (($child['type'] ?? '') === 'tableRowGroup') {
                $rows = [...$rows, ...$this->tableRows($child)];
            }
        }

        return $rows;
    }

    private function firstParagraphId(array $node): ?string
    {
        if (($node['type'] ?? '') === 'paragraph') {
            return $node['attrs']['id'] ?? null;
        }
        foreach ($node['content'] ?? [] as $child) {
            if (($id = $this->firstParagraphId($child)) !== null) {
                return $id;
            }
        }

        return null;
    }

    private function containsId(array $node, ?string $id): bool
    {
        if ($id === null) {
            return false;
        }
        if (($node['attrs']['id'] ?? null) === $id) {
            return true;
        }
        foreach ($node['content'] ?? [] as $child) {
            if ($this->containsId($child, $id)) {
                return true;
            }
        }

        return false;
    }

    private function topIndex(array $content, ?string $id): ?int
    {
        if ($id !== null) {
            foreach ($content['content'] as $index => $node) {
                if (($node['attrs']['id'] ?? null) === $id) {
                    return $index;
                }
            }
        }

        return null;
    }

    private function rebaseFields(array &$node, array $changes): void
    {
        $field = $node['attrs']['field'] ?? null;
        if (isset($changes[$field ?? ''])) {
            $replacement = $changes[$field]['content'] ?? [];
            $marks = $this->firstTextMarks($node);
            $paragraphAttrs = [];
            $collect = function (array $child) use (&$collect, &$paragraphAttrs): void {
                if (($child['type'] ?? '') === 'paragraph') {
                    $paragraphAttrs[] = $child['attrs'] ?? [];
                }
                foreach ($child['content'] ?? [] as $item) {
                    $collect($item);
                }
            };
            foreach ($node['content'] ?? [] as $child) {
                $collect($child);
            }
            $paragraphIndex = 0;
            $decorate = function (array &$child) use (&$decorate, $marks, $paragraphAttrs, &$paragraphIndex): void {
                if (($child['type'] ?? '') === 'text' && ! isset($child['marks']) && $marks !== []) {
                    $child['marks'] = $marks;
                }
                if (($child['type'] ?? '') === 'paragraph' && $paragraphAttrs !== []) {
                    $previous = $paragraphAttrs[$paragraphIndex] ?? $paragraphAttrs[0];
                    if (! isset($paragraphAttrs[$paragraphIndex])) {
                        unset($previous['id']);
                    }
                    $child['attrs'] = [...($child['attrs'] ?? []), ...$previous];
                    $paragraphIndex++;
                }
                if (isset($child['content'])) {
                    foreach ($child['content'] as &$item) {
                        $decorate($item);
                    }
                }
            };
            foreach ($replacement as &$child) {
                $decorate($child);
            }
            $node['content'] = $replacement;

            return;
        }
        if (isset($node['content'])) {
            foreach ($node['content'] as &$child) {
                $this->rebaseFields($child, $changes);
            }
        }
    }

    private function firstTextMarks(array $node): array
    {
        if (($node['type'] ?? '') === 'text') {
            return $node['marks'] ?? [];
        }
        foreach ($node['content'] ?? [] as $child) {
            $marks = $this->firstTextMarks($child);
            if ($marks !== []) {
                return $marks;
            }
        }

        return [];
    }

    private function sourceSnapshot(array $snapshot): array
    {
        unset($snapshot['advanced_document']);

        return $snapshot;
    }

    private function recordFingerprint(TeachingRecord $record): string
    {
        $record->refresh()->load(['images', 'attendance', 'documentLayout']);

        return hash('sha256', json_encode(['record' => $record->attributesToArray(), 'images' => $record->images->toArray(), 'attendance' => $record->attendance->toArray(), 'layout' => $record->documentLayout?->toArray()], JSON_THROW_ON_ERROR));
    }

    private function snapshotFingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode($this->sourceSnapshot($snapshot), JSON_THROW_ON_ERROR));
    }

    private function sourceConflict(string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => 'source_record_conflict'], 409));
    }
}
