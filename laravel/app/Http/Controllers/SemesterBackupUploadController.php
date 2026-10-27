<?php

namespace App\Http\Controllers;

use App\Services\SemesterBackupService;
use App\Services\SemesterBackupUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SemesterBackupUploadController extends Controller
{
    public function index(Request $request, SemesterBackupUploadService $uploads): JsonResponse
    {
        $this->district($request);

        return response()->json(['uploads' => $uploads->list($request->user())]);
    }

    public function start(Request $request, SemesterBackupUploadService $uploads): JsonResponse
    {
        $this->district($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'size' => ['required', 'integer', 'min:22', 'max:'.SemesterBackupService::MAX_BYTES]]);

        return response()->json($uploads->start($request->user(), $data['name'], (int) $data['size']), 201);
    }

    public function part(Request $request, string $upload, string $part, SemesterBackupUploadService $uploads): JsonResponse
    {
        $this->district($request);
        Validator::make(['part' => $part], ['part' => ['required', 'integer', 'min:0', 'max:32768']])->validate();
        $request->validate(['chunk' => ['required', 'file', 'max:8192']]);

        return response()->json($uploads->part($request->user(), $upload, (int) $part, $request->file('chunk')));
    }

    public function finish(Request $request, string $upload, SemesterBackupUploadService $uploads): JsonResponse
    {
        $this->district($request);

        return response()->json($uploads->finish($request->user(), $upload));
    }

    public function cancel(Request $request, string $upload, SemesterBackupUploadService $uploads): JsonResponse
    {
        $this->district($request);

        return response()->json($uploads->cancel($request->user(), $upload));
    }

    private function district(Request $request): void
    {
        abort_unless($request->user()?->isDistrict(), 403);
    }
}
