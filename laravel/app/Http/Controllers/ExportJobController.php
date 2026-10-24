<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateRecordExport;
use App\Models\ExportJob;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\RecordExportService;
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
            ->where('expires_at', '>', now())
            ->orderByRaw("CASE WHEN status IN ('queued', 'processing') THEN 0 ELSE 1 END")
            ->latest('updated_at')->limit(10)
            ->get(['id', 'status', 'total', 'completed', 'filters', 'error', 'created_at', 'expires_at'])->map(fn ($job) => $this->payload($job))]);
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
        $filters = $this->normalizedFilters($filters, $user);
        $records = TeachingRecord::query()->visibleTo($user)
            ->when($user->isDistrict() && ! empty($filters['tambon']), fn ($q) => $q->where('tambon', $filters['tambon']))
            ->when($filters['year'] ?? null, fn ($q, $v) => $q->where('year', $v))
            ->when($filters['semester'] ?? null, fn ($q, $v) => $q->where('semester', $v))
            ->when($filters['week'] ?? null, fn ($q, $v) => $q->where('week', $v))
            ->when($filters['search'] ?? null, function ($q, string $value): void {
                $search = addcslashes($value, '%_\\');
                $q->where(fn ($inner) => $inner->where('subject', 'like', "%{$search}%")->orWhere('subject_code', 'like', "{$search}%"));
            })->orderBy('tambon')->orderBy('date')->orderBy('level')->orderBy('id')->get(['id']);
        if ($records->isEmpty()) {
            return response()->json(['message' => 'ไม่พบบันทึกตามตัวกรองที่เลือก'], 422);
        }
        $rendererFingerprint = app(RecordExportService::class)->rendererFingerprint();
        [$export, $created] = DB::transaction(function () use ($records, $revisions, $filters, $user, $rendererFingerprint): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $manifest = $records->map(function ($record) use ($revisions, $user): array {
                $revision = $revisions->capture($record, $user);

                return ['record_id' => $record->id, 'revision_id' => $revision->id];
            })->all();

            $fingerprint = $this->fingerprint($filters, $manifest, $user, $rendererFingerprint);
            if ($existing = $this->reusableExport($user, $fingerprint, $rendererFingerprint)) {
                return [$existing, false];
            }
            abort_if(ExportJob::query()->where('user_id', $user->id)->whereIn('status', ['queued', 'processing'])->where('expires_at', '>', now())->count() >= 3, 429, 'มีงานสร้างเอกสารกำลังทำอยู่ กรุณารอให้เสร็จก่อน');

            return [ExportJob::create([
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'status' => 'queued',
                'filters' => $filters, 'manifest' => $manifest, 'total' => count($manifest), 'fingerprint' => $fingerprint,
                'expires_at' => now()->addDay(),
            ]), true];
        });
        if ($created) {
            GenerateRecordExport::dispatch($export->id)->onConnection('exports')->onQueue('exports');
        }

        return response()->json($this->payload($export), $created ? 202 : 200);
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
        [$result, $created] = DB::transaction(function () use ($request, $export): array {
            $user = $request->user();
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $renderer = app(RecordExportService::class)->rendererFingerprint();
            $fingerprint = $this->fingerprint($export->filters, $export->manifest, $user, $renderer);
            if ($existing = $this->reusableExport($user, $fingerprint, $renderer)) {
                return [$existing, false];
            }
            abort_if(ExportJob::query()->where('user_id', $user->id)->whereIn('status', ['queued', 'processing'])->where('expires_at', '>', now())->count() >= 3, 429, 'มีงานสร้างเอกสารกำลังทำอยู่ กรุณารอให้เสร็จก่อน');
            $updated = ExportJob::query()->whereKey($export->id)->where('status', 'failed')->update([
                'status' => 'queued', 'completed' => 0, 'error' => null, 'fingerprint' => $fingerprint,
            ]);
            abort_unless($updated, 409, 'มีการลองสร้างเอกสารใหม่แล้ว');

            return [$export->fresh(), true];
        });
        if ($created) {
            GenerateRecordExport::dispatch($result->id)->onConnection('exports')->onQueue('exports');
        }

        return response()->json($this->payload($result), $created ? 202 : 200);
    }

    public function download(Request $request, ExportJob $export): BinaryFileResponse
    {
        $this->authorizeExport($request, $export);
        abort_unless($export->status === 'ready' && $export->path && Storage::disk('local')->exists($export->path), 409, 'เอกสารยังไม่พร้อมดาวน์โหลด');

        return response()->download(Storage::disk('local')->path($export->path), basename($export->path), ['Cache-Control' => 'private, no-store']);
    }

    private function reusableExport(User $user, string $fingerprint, string $rendererFingerprint): ?ExportJob
    {
        $candidates = ExportJob::query()->where('user_id', $user->id)->where('expires_at', '>', now())
            ->whereIn('status', ['queued', 'processing', 'ready'])
            ->where(fn ($query) => $query->where('fingerprint', $fingerprint)
                ->orWhere(fn ($legacy) => $legacy->whereNull('fingerprint')->whereIn('status', ['queued', 'processing'])))
            ->latest()->limit(20)->get();
        foreach ($candidates as $candidate) {
            if (($candidate->status === 'ready' && $candidate->fingerprint === null)
                || $this->fingerprint($candidate->filters, $candidate->manifest, $user, $rendererFingerprint) !== $fingerprint
                || ($candidate->status === 'ready' && (! $candidate->path || ! Storage::disk('local')->exists($candidate->path)))) {
                continue;
            }
            if ($candidate->fingerprint === null) {
                $candidate->update(['fingerprint' => $fingerprint]);
            }

            $candidate->touch();

            return $candidate;
        }

        return null;
    }

    /** @return array<string, int|string> */
    private function normalizedFilters(array $filters, User $user): array
    {
        $normalized = ['scope' => $filters['scope']];
        foreach (['year', 'semester', 'week', 'tambon', 'search'] as $key) {
            if (($filters[$key] ?? '') === '' || $filters[$key] === null || ($key === 'tambon' && ! $user->isDistrict())) {
                continue;
            }
            $normalized[$key] = $key === 'week' ? (int) $filters[$key] : (string) $filters[$key];
        }
        ksort($normalized);

        return $normalized;
    }

    private function fingerprint(array $filters, array $manifest, User $user, string $rendererFingerprint): string
    {
        usort($manifest, static fn (array $left, array $right): int => strcmp($left['record_id'], $right['record_id']));

        return hash('sha256', json_encode([$this->normalizedFilters($filters, $user), $manifest, $rendererFingerprint], JSON_THROW_ON_ERROR));
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
