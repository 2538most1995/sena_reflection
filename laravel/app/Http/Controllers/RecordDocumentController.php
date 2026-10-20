<?php

namespace App\Http\Controllers;

use App\Models\RecordDocumentLayout;
use App\Models\TeachingRecord;
use App\Services\LegacyPrintService;
use App\Services\RecordDocumentOptions;
use App\Services\RecordRevisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class RecordDocumentController extends Controller
{
    public function edit(Request $request, TeachingRecord $record, RecordDocumentOptions $options, LegacyPrintService $print): InertiaResponse
    {
        $this->authorizeScope($request, $record);
        $record->load(['tambonRelation:id,name', 'images:id,record_id,file_path', 'attendance', 'documentLayout']);
        $layout = $record->documentLayout;
        $canonicalContent = $options->content($record);
        $documentContent = $options->content($record, $layout?->content ?? []);
        $contentConflicts = collect($documentContent)
            ->filter(fn (string $value, string $key): bool => $value !== ($canonicalContent[$key] ?? ''))
            ->keys()
            ->values()
            ->all();
        $imageIds = $record->images->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $snapshot = $print->savedSnapshot($record, $request->user());

        return Inertia::render('records/document', [
            'record' => $record->toArray(),
            'settings' => $options->settings($snapshot['settings'] ?? [], $imageIds, $record->tambon),
            'content' => $documentContent,
            'canonicalContent' => $canonicalContent,
            'contentConflicts' => $contentConflicts,
            'hasSavedLayout' => $layout !== null,
        ]);
    }

    public function preview(
        Request $request,
        TeachingRecord $record,
        RecordDocumentOptions $options,
        LegacyPrintService $print,
    ): Response {
        $this->authorizeScope($request, $record);
        $document = $options->validate($record, $request->all());
        $snapshot = $print->snapshotForRecord($record, $document['settings'], $document['content'], $request->user());

        return response($print->snapshotHtml($snapshot, $request->user(), true), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function pdfPreview(
        Request $request,
        TeachingRecord $record,
        RecordDocumentOptions $options,
        LegacyPrintService $print,
    ): Response {
        $this->authorizeScope($request, $record);
        $document = $options->validate($record, $request->all());
        $snapshot = $print->snapshotForRecord($record, $document['settings'], $document['content'], $request->user());

        return response($print->snapshotPdf($snapshot, $request->user()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="document-preview.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(
        Request $request,
        TeachingRecord $record,
        RecordDocumentOptions $options,
        RecordRevisionService $revisions,
    ): RedirectResponse {
        $this->authorizeScope($request, $record);
        $record->loadMissing('documentLayout');
        $canonicalContent = $options->content($record);
        $storedDocumentContent = $options->content($record, $record->documentLayout?->content ?? []);
        $hasConflict = collect($storedDocumentContent)->contains(
            fn (string $value, string $key): bool => $value !== ($canonicalContent[$key] ?? '')
        );
        $resolution = $request->validate([
            'content_resolution' => [$hasConflict ? 'required' : 'nullable', Rule::in(['document', 'record'])],
        ])['content_resolution'] ?? 'document';
        $payload = $request->all();
        if ($resolution === 'record') {
            $payload['content'] = $canonicalContent;
        }
        $document = $options->validate($record, $payload);

        DB::transaction(function () use ($record, $document, $request, $revisions): void {
            // Preserve the last canonical state before this editor replaces it,
            // then capture the new state below in the same transaction.
            $revisions->capture($record, $request->user());
            $record->update([
                'subject' => $document['content']['record_subject'],
                'subject_code' => $document['content']['record_subject_code'],
                'class_group_code' => $document['content']['record_class_group_code'],
                'class_name' => $document['content']['record_class_name'],
                'result' => $document['content']['record_result'],
                'problem' => $document['content']['record_problem'],
                'suggestion' => $document['content']['record_suggestion'],
                'supervisor_comment' => $document['content']['record_supervisor_comment'],
            ]);
            RecordDocumentLayout::query()->updateOrCreate(
                ['record_id' => $record->id],
                ['settings' => $document['settings'], 'content' => [], 'updated_by' => $request->user()->id],
            );
            $revisions->capture($record->fresh(), $request->user());
        }, 3);

        return back()->with('success', 'บันทึกรูปแบบเอกสารสำหรับครั้งนี้แล้ว');
    }

    public function destroy(Request $request, TeachingRecord $record, RecordRevisionService $revisions): RedirectResponse
    {
        $this->authorizeScope($request, $record);
        DB::transaction(function () use ($record, $request, $revisions): void {
            $revisions->capture($record, $request->user());
            RecordDocumentLayout::query()->whereKey($record->id)->delete();
            $revisions->capture($record, $request->user(), resetSettings: true);
        }, 3);

        return back()->with('success', 'คืนรูปแบบเอกสารเป็นค่าเริ่มต้นแล้ว');
    }

    private function authorizeScope(Request $request, TeachingRecord $record): void
    {
        abort_unless($request->user()->isDistrict() || $record->tambon === $request->user()->tambon, 403);
    }
}
