<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateRecordExport;
use App\Models\ExportJob;
use App\Models\TeachingRecord;
use App\Services\RecordRevisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportJobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['items' => ExportJob::query()->where('user_id', $request->user()->id)
            ->where('expires_at', '>', now())->latest()->limit(10)->get()->map(fn ($job) => $this->payload($job))]);
    }

    public function store(Request $request, RecordRevisionService $revisions): JsonResponse
    {
        $filters = $request->validate([
            'scope' => ['required', 'in:week,all'],
            'year' => ['nullable', 'required_if:scope,week', 'digits:4'],
            'semester' => ['nullable', 'required_if:scope,week', 'in:1,2,3'],
            'week' => ['nullable', 'required_if:scope,week', 'integer', 'between:1,60'],
            'tambon' => ['nullable', 'string', 'max:20'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();
        abort_if(ExportJob::query()->where('user_id', $user->id)->whereIn('status', ['queued', 'processing'])->where('expires_at', '>', now())->count() >= 3, 429, 'มีงานสร้างเอกสารกำลังทำอยู่ กรุณารอให้เสร็จก่อน');
        $records = TeachingRecord::query()->visibleTo($user)
            ->when($user->isDistrict() && ! empty($filters['tambon']), fn ($q) => $q->where('tambon', $filters['tambon']))
            ->when($filters['year'] ?? null, fn ($q, $v) => $q->where('year', $v))
            ->when($filters['semester'] ?? null, fn ($q, $v) => $q->where('semester', $v))
            ->when($filters['week'] ?? null, fn ($q, $v) => $q->where('week', $v))
            ->when($filters['search'] ?? null, function ($q, string $value): void {
                $search = addcslashes($value, '%_\\');
                $q->where(fn ($inner) => $inner->where('subject', 'like', "%{$search}%")->orWhere('subject_code', 'like', "{$search}%"));
            })->orderBy('tambon')->orderBy('date')->orderBy('level')->get();
        if ($records->isEmpty()) {
            return response()->json(['message' => 'ไม่พบบันทึกตามตัวกรองที่เลือก'], 422);
        }
        $export = DB::transaction(function () use ($records, $revisions, $filters, $user): ExportJob {
            $manifest = $records->map(function ($record) use ($revisions, $user): array {
                $revision = $revisions->capture($record, $user);

                return ['record_id' => $record->id, 'revision_id' => $revision->id];
            })->all();

            return ExportJob::create([
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'status' => 'queued',
                'filters' => $filters, 'manifest' => $manifest, 'total' => count($manifest),
                'expires_at' => now()->addDay(),
            ]);
        });
        GenerateRecordExport::dispatch($export->id)->onConnection('exports')->onQueue('exports');

        return response()->json($this->payload($export), 202);
    }

    public function show(Request $request, ExportJob $export): JsonResponse
    {
        $this->authorizeExport($request, $export);

        return response()->json($this->payload($export));
    }

    public function retry(Request $request, ExportJob $export): JsonResponse
    {
        $this->authorizeExport($request, $export);
        abort_unless($export->status === 'failed', 409, 'งานนี้ยังไม่อยู่ในสถานะที่ลองใหม่ได้');
        $updated = ExportJob::query()->whereKey($export->id)->where('status', 'failed')->update(['status' => 'queued', 'completed' => 0, 'error' => null]);
        abort_unless($updated, 409, 'มีการลองสร้างเอกสารใหม่แล้ว');
        GenerateRecordExport::dispatch($export->id)->onConnection('exports')->onQueue('exports');

        return response()->json($this->payload($export->fresh()), 202);
    }

    public function download(Request $request, ExportJob $export): BinaryFileResponse
    {
        $this->authorizeExport($request, $export);
        abort_unless($export->status === 'ready' && $export->path && Storage::disk('local')->exists($export->path), 409, 'เอกสารยังไม่พร้อมดาวน์โหลด');

        return response()->download(Storage::disk('local')->path($export->path), basename($export->path), ['Cache-Control' => 'private, no-store']);
    }

    private function authorizeExport(Request $request, ExportJob $export): void
    {
        abort_unless($export->user_id === $request->user()->id, 404);
        abort_if($export->expires_at->isPast(), 410, 'ลิงก์เอกสารหมดอายุแล้ว กรุณาสร้างใหม่');
        $ids = collect($export->manifest)->pluck('record_id')->all();
        abort_unless(TeachingRecord::query()->visibleTo($request->user())->whereKey($ids)->count() === count($ids), 403);
    }

    private function payload(ExportJob $export): array
    {
        return [
            'id' => $export->id, 'status' => $export->status, 'total' => $export->total,
            'completed' => $export->completed, 'filters' => $export->filters, 'error' => $export->error,
            'download_url' => $export->status === 'ready' ? route('exports.download', $export) : null,
            'created_at' => $export->created_at->toIso8601String(), 'expires_at' => $export->expires_at->toIso8601String(),
        ];
    }
}
