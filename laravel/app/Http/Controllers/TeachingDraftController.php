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

        $browser = ($validated['format'] ?? 'pdf') === 'browser';
        $content = $browser
            ? $print->browserSnapshotHtml($snapshot, $request->user(), route('records.create', ['draft' => $teachingDraft->id, 'review' => 1]))
            : $print->snapshotPdf($snapshot, $request->user());
        Cache::put(
            DraftService::reviewCacheKey($teachingDraft->id, (int) $validated['lock_version'], $validated['level_code']),
            $snapshot['settings'],
            now()->addDay(),
        );

        return response($content, 200, [
            'Content-Type' => $browser ? 'text/html; charset=UTF-8' : 'application/pdf',
            'Content-Disposition' => $browser ? 'inline' : 'inline; filename="draft-preview.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function finalize(
        FinalizeTeachingDraftRequest $request,
        TeachingDraft $teachingDraft,
        FinalizeTeachingSubmission $finalizer,
    ): JsonResponse {
        try {
            $result = $finalizer->finalize(
                $request->user(),
                $teachingDraft,
                (int) $request->validated('lock_version'),
                $request->validated('idempotency_key'),
                $request->validated('reviewed_level_codes'),
                $request->boolean('confirmed'),
            );

            return response()->json([
                'message' => 'ยืนยันบันทึกเรียบร้อยแล้ว',
                'data' => $result,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\App\Exceptions\DraftVersionConflictException $e) {
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            throw $e;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Draft finalization failed: '.$e->getMessage(), [
                'draft_id' => $teachingDraft->id,
                'user_id' => $request->user()->id,
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'ระบบบันทึกขัดข้อง: '.$e->getMessage(),
            ], 500);
        }
    }
}
