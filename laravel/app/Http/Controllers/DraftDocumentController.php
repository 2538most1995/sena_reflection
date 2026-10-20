<?php

namespace App\Http\Controllers;

use App\Models\DraftImage;
use App\Models\TeachingDraft;
use App\Models\TeachingRecord;
use App\Services\DraftService;
use App\Services\LegacyPrintService;
use App\Services\RecordDocumentOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class DraftDocumentController extends Controller
{
    public function edit(Request $request, TeachingDraft $teachingDraft, string $levelCode, DraftService $drafts): InertiaResponse
    {
        $owned = $drafts->owned($request->user(), $teachingDraft);
        abort_unless($owned->status === 'active', 409, 'ฉบับร่างนี้บันทึกแล้ว กรุณาเปิดจากรายการบันทึก');
        $snapshot = $drafts->snapshotForPreview($request->user(), $owned, $levelCode);
        $images = collect($drafts->resource($owned)['images'])->where('level_code', $levelCode)->values();

        return Inertia::render('records/document', [
            'record' => [...$snapshot['record'], 'images' => $images->map(fn (array $image): array => [
                'id' => $image['id'], 'record_id' => $snapshot['record']['id'], 'file_path' => $image['url'],
            ])->all(), 'attendance' => $snapshot['attendance']],
            'settings' => collect($snapshot['settings'])->only(RecordDocumentOptions::SETTING_KEYS)->all(),
            'content' => $snapshot['content'],
            'canonicalContent' => $snapshot['content'],
            'contentConflicts' => [],
            'hasSavedLayout' => ! empty($owned->payload['levels'][$levelCode]['document']['settings']),
            'draftContext' => ['id' => $owned->id, 'levelCode' => $levelCode, 'lockVersion' => $owned->lock_version],
        ]);
    }

    public function preview(Request $request, TeachingDraft $teachingDraft, string $levelCode, DraftService $drafts, RecordDocumentOptions $options, LegacyPrintService $print): Response
    {
        $snapshot = $this->snapshot($request, $teachingDraft, $levelCode, $drafts, $options);

        return response($print->snapshotHtml($snapshot, $request->user(), true), 200, [
            'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function pdfPreview(Request $request, TeachingDraft $teachingDraft, string $levelCode, DraftService $drafts, RecordDocumentOptions $options, LegacyPrintService $print): Response
    {
        $snapshot = $this->snapshot($request, $teachingDraft, $levelCode, $drafts, $options);

        return response($print->snapshotPdf($snapshot, $request->user()), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="draft-document-preview.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(Request $request, TeachingDraft $teachingDraft, string $levelCode, DraftService $drafts, RecordDocumentOptions $options): RedirectResponse
    {
        $snapshot = $this->snapshot($request, $teachingDraft, $levelCode, $drafts, $options);
        $payload = $drafts->owned($request->user(), $teachingDraft)->payload;
        foreach ($snapshot['content'] as $key => $value) {
            $payload['levels'][$levelCode][substr($key, strlen('record_'))] = $value;
        }
        $payload['levels'][$levelCode]['document'] = ['settings' => collect($snapshot['settings'])->only(RecordDocumentOptions::SETTING_KEYS)->all()];
        $drafts->update($request->user(), $teachingDraft, (int) $request->input('lock_version'), $payload);

        return back()->with('success', 'บันทึกข้อความและรูปแบบเอกสารลงฉบับร่างแล้ว');
    }

    /** @return array<string, mixed> */
    private function snapshot(Request $request, TeachingDraft $draft, string $levelCode, DraftService $drafts, RecordDocumentOptions $options): array
    {
        $version = $request->validate(['lock_version' => ['required', 'integer', 'min:1']])['lock_version'];
        $snapshot = $drafts->snapshotForPreview($request->user(), $draft, $levelCode, [], (int) $version);
        $record = new TeachingRecord($snapshot['record']);
        $record->setRelation('images', DraftImage::hydrate($snapshot['images']));
        $document = $options->validate($record, $request->all());
        $snapshot['settings'] = array_merge($snapshot['settings'], $document['settings']);
        $snapshot['content'] = $document['content'];

        return $snapshot;
    }
}
