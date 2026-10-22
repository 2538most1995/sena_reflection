<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentAsset;
use App\Models\DocumentVersion;
use App\Models\TeachingRecord;
use App\Services\DocumentService;
use App\Services\RecordDocumentOptions;
use App\Services\RecordImageAssets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentController extends Controller
{
    public function indexPage(Request $request): Response
    {
        return Inertia::render('documents/index', ['documents' => $this->listing($request)]);
    }

    public function editPage(Request $request, Document $document, DocumentService $documents): Response
    {
        $this->authorizeDocument($request, $document);

        return Inertia::render('documents/edit', ['document' => $documents->resource($document)]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['documents' => $this->listing($request)]);
    }

    public function store(Request $request, DocumentService $documents): JsonResponse
    {
        return response()->json(['document' => $documents->resource($documents->create($request->user(), $request->all()))], 201);
    }

    public function show(Request $request, Document $document, DocumentService $documents): JsonResponse
    {
        $this->authorizeDocument($request, $document);

        return response()->json(['document' => $documents->resource($document)], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request, Document $document, DocumentService $documents): JsonResponse
    {
        $this->authorizeDocument($request, $document);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $updated = $documents->update($document, $request->user(), (int) $data['revision'], $request->all());

        return response()->json(['document' => $documents->resource($updated)]);
    }

    public function destroy(Request $request, Document $document): JsonResponse
    {
        $this->authorizeDocument($request, $document);
        DB::transaction(fn () => $document->delete(), 3);
        Storage::disk('local')->deleteDirectory('documents/'.$document->id);

        return response()->json(['deleted' => true]);
    }

    public function duplicate(Request $request, Document $document, DocumentService $documents): JsonResponse
    {
        $this->authorizeDocument($request, $document);
        $copy = $documents->duplicate($document, $request->user(), $request->all());

        return response()->json(['document' => $documents->resource($copy)], 201);
    }

    public function fromRecord(Request $request, TeachingRecord $record, DocumentService $documents, RecordDocumentOptions $options, RecordImageAssets $images): JsonResponse
    {
        abort_unless($request->user()->isDistrict() || $record->tambon === $request->user()->tambon, 403);
        $document = $documents->fromRecord($request->user(), $record, $options, $images);

        return response()->json(['document' => $documents->resource($document)], 201);
    }

    public function assets(Request $request, Document $document, DocumentService $documents): JsonResponse
    {
        $this->authorizeDocument($request, $document);

        return response()->json(['assets' => $document->assets->map(fn (DocumentAsset $asset): array => $documents->assetResource($asset))->all()]);
    }

    public function upload(Request $request, Document $document, DocumentService $documents): JsonResponse
    {
        $this->authorizeDocument($request, $document);
        $request->validate(['file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=12000,max_height=12000']]);
        $asset = $documents->upload($document, $request->file('file'));

        return response()->json(['asset' => $documents->assetResource($asset)], 201);
    }

    public function asset(Request $request, Document $document, DocumentAsset $asset, DocumentService $documents): BinaryFileResponse
    {
        $this->authorizeDocument($request, $document);

        return response()->file($documents->assetPath($document, $asset), ['Content-Type' => $asset->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'])->setPrivate();
    }

    public function versions(Request $request, Document $document): JsonResponse
    {
        $this->authorizeDocument($request, $document);

        return response()->json(['versions' => $document->versions()->limit(100)->get()->map(static fn (DocumentVersion $version): array => [
            'id' => $version->id, 'revision' => $version->revision, 'title' => $version->snapshot_json['title'], 'createdAt' => $version->created_at->toIso8601String(),
        ])->all()]);
    }

    public function restore(Request $request, Document $document, DocumentVersion $version, DocumentService $documents): JsonResponse
    {
        $this->authorizeDocument($request, $document);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $restored = $documents->restore($document, $version, $request->user(), (int) $data['revision']);

        return response()->json(['document' => $documents->resource($restored)]);
    }

    private function authorizeDocument(Request $request, Document $document): void
    {
        abort_unless($request->user()->isDistrict() || (string) $document->user_id === (string) $request->user()->id, 403);
    }

    private function listing(Request $request): array
    {
        return Document::query()->visibleTo($request->user())->orderByDesc('updated_at')->limit(100)->get(['id', 'title', 'revision', 'updated_at', 'source_record_id'])->map(static fn (Document $document): array => [
            'id' => $document->id, 'title' => $document->title, 'revision' => $document->revision, 'updatedAt' => $document->updated_at->toIso8601String(), 'sourceRecordId' => $document->source_record_id,
        ])->all();
    }
}
