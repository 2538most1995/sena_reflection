<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReorderDraftImagesRequest;
use App\Http\Requests\UploadDraftImageRequest;
use App\Models\DraftImage;
use App\Models\TeachingDraft;
use App\Services\DraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DraftImageController extends Controller
{
    public function store(UploadDraftImageRequest $request, TeachingDraft $teachingDraft, DraftService $drafts): JsonResponse
    {
        $validated = $request->validated();
        $image = $drafts->uploadImage(
            $request->user(),
            $teachingDraft,
            (int) $validated['lock_version'],
            $validated['level_code'],
            $request->file('image'),
        );
        $draft = $drafts->owned($request->user(), $teachingDraft);

        return response()->json([
            'message' => 'อัปโหลดรูปแล้ว',
            'data' => [
                'image' => $drafts->imageResource($image),
                'lock_version' => $draft->lock_version,
                'last_saved_at' => $draft->last_saved_at?->toISOString(),
            ],
        ], 201);
    }

    public function show(Request $request, TeachingDraft $teachingDraft, DraftImage $draftImage, DraftService $drafts): BinaryFileResponse
    {
        $draft = $drafts->owned($request->user(), $teachingDraft);
        $image = $draft->images()->whereKey($draftImage->id)->firstOrFail();

        return response()->file(Storage::disk($image->storage_disk)->path($image->storage_path), [
            'Content-Type' => $image->mime_type,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, TeachingDraft $teachingDraft, DraftImage $draftImage, DraftService $drafts): JsonResponse
    {
        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $draft = $drafts->deleteImage($request->user(), $teachingDraft, $draftImage, (int) $validated['lock_version']);

        return response()->json([
            'message' => 'นำรูปออกจากร่างแล้ว',
            'data' => $this->mutationData($draft, $drafts),
        ]);
    }

    public function reorder(ReorderDraftImagesRequest $request, TeachingDraft $teachingDraft, DraftService $drafts): JsonResponse
    {
        $validated = $request->validated();
        $draft = $drafts->reorderImages(
            $request->user(),
            $teachingDraft,
            (int) $validated['lock_version'],
            $validated['level_code'],
            $validated['image_ids'],
        );

        return response()->json([
            'message' => 'เรียงรูปแล้ว',
            'data' => $this->mutationData($draft, $drafts),
        ]);
    }

    /** @return array<string, mixed> */
    private function mutationData(TeachingDraft $draft, DraftService $drafts): array
    {
        return [
            'lock_version' => $draft->lock_version,
            'images' => $draft->images->map(fn (DraftImage $image) => $drafts->imageResource($image))->values()->all(),
            'last_saved_at' => $draft->last_saved_at?->toISOString(),
        ];
    }
}
