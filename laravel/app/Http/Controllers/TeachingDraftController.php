<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinalizeTeachingDraftRequest;
use App\Http\Requests\PreviewTeachingDraftRequest;
use App\Http\Requests\StoreTeachingDraftRequest;
use App\Http\Requests\UpdateTeachingDraftRequest;
use App\Models\TeachingDraft;
use App\Services\DraftService;
use App\Services\FinalizeTeachingSubmission;
use App\Services\LegacyPrintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class TeachingDraftController extends Controller
{
    public function index(DraftService $drafts): JsonResponse
    {
        return response()->json([
            'data' => ['drafts' => $drafts->activeSummaries(request()->user())],
        ]);
    }

    public function store(StoreTeachingDraftRequest $request, DraftService $drafts): JsonResponse
    {
        $draft = $drafts->create($request->user(), $request->validated());

        return response()->json([
            'message' => 'สร้างฉบับร่างแล้ว',
            'data' => ['draft' => $drafts->resource($draft)],
        ], 201);
    }

    public function show(TeachingDraft $teachingDraft, DraftService $drafts): JsonResponse
    {
        $draft = $drafts->owned(request()->user(), $teachingDraft)->load('images');

        return response()->json(['data' => ['draft' => $drafts->resource($draft)]]);
    }

    public function update(UpdateTeachingDraftRequest $request, TeachingDraft $teachingDraft, DraftService $drafts): JsonResponse
    {
        $validated = $request->validated();
        $draft = $drafts->update(
            $request->user(),
            $teachingDraft,
            (int) $validated['lock_version'],
            $validated['payload'],
        );

        return response()->json([
            'message' => 'บันทึกร่างแล้ว',
            'data' => ['draft' => $drafts->resource($draft)],
        ]);
    }

    public function preview(
        PreviewTeachingDraftRequest $request,
        TeachingDraft $teachingDraft,
        DraftService $drafts,
        LegacyPrintService $print,
    ): Response {
        $validated = $request->validated();
        $snapshot = $drafts->snapshotForPreview(
            $request->user(),
            $teachingDraft,
            $validated['level_code'],
            $validated['document'] ?? [],
            (int) $validated['lock_version'],
        );

        $pdf = $print->snapshotPdf($snapshot, $request->user());
        Cache::put(
            DraftService::reviewCacheKey($teachingDraft->id, (int) $validated['lock_version'], $validated['level_code']),
            $snapshot['settings'],
            now()->addDay(),
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="draft-preview.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function finalize(
        FinalizeTeachingDraftRequest $request,
        TeachingDraft $teachingDraft,
        FinalizeTeachingSubmission $finalizer,
    ): JsonResponse {
        $result = $finalizer->finalize(
            $request->user(),
            $teachingDraft,
            (int) $request->validated('lock_version'),
            $request->validated('idempotency_key'),
            $request->validated('reviewed_level_codes'),
        );

        return response()->json([
            'message' => 'ยืนยันบันทึกเรียบร้อยแล้ว',
            'data' => $result,
        ]);
    }
}
