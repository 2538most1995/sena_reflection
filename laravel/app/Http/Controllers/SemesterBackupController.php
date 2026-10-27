<?php

namespace App\Http\Controllers;

use App\Services\SemesterBackupService;
use App\Services\SemesterTermRepairService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SemesterBackupController extends Controller
{
    public function index(Request $request, SemesterBackupService $backups): Response|JsonResponse
    {
        $this->district($request);
        $validated = $request->validate(['backup_page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);
        $data = $backups->index((int) ($validated['backup_page'] ?? 1));

        return $request->expectsJson() ? response()->json($data) : Inertia::render('admin/semester-backups', $data);
    }

    public function store(Request $request, SemesterBackupService $backups): JsonResponse
    {
        $this->district($request);
        $data = $request->validate([...$this->scopeRules(), 'allow_missing' => ['sometimes', 'boolean'],
            'preflight_fingerprint' => ['nullable', 'required_if:allow_missing,true,1', 'string', 'regex:/^[a-f0-9]{64}$/D']]);

        return response()->json(['backup' => $backups->create($request->user(), $data['year'], $data['semester'], $request->boolean('allow_missing'), $data['preflight_fingerprint'] ?? null)], 201);
    }

    public function preflight(Request $request, SemesterBackupService $backups): JsonResponse
    {
        $this->district($request);
        $data = $request->validate($this->scopeRules());

        return response()->json($backups->preflight($data['year'], $data['semester']));
    }

    public function repairPreview(Request $request, SemesterTermRepairService $repairs, SemesterBackupService $backups): JsonResponse
    {
        $this->district($request);
        $data = $request->validate($this->repairRules());
        $preview = $repairs->preview($request->user(), $data['source_year'], $data['source_semester'], $data['target_year'], $data['target_semester']);
        $preview['preflight'] = $backups->preflight($data['source_year'], $data['source_semester']);

        return response()->json($preview);
    }

    public function repair(Request $request, SemesterTermRepairService $repairs, SemesterBackupService $backups): JsonResponse
    {
        $this->district($request);
        $data = $request->validate([...$this->repairRules(), 'fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'backup_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'allow_missing' => ['sometimes', 'boolean'], 'confirmation' => ['required', 'string']]);
        $expected = 'MOVE '.$data['source_semester'].'/'.$data['source_year'].' -> '.$data['target_semester'].'/'.$data['target_year'];
        abort_unless(hash_equals($expected, $data['confirmation']), 422, 'กรุณาพิมพ์ข้อความยืนยันการย้ายภาคเรียนให้ตรง');
        $result = $repairs->repair($request->user(), $data['source_year'], $data['source_semester'], $data['target_year'], $data['target_semester'], $data['fingerprint'],
            fn (): array => $backups->create($request->user(), $data['source_year'], $data['source_semester'], $request->boolean('allow_missing'), $data['backup_fingerprint']));

        return response()->json($result);
    }

    public function show(Request $request, string $backup, SemesterBackupService $backups): JsonResponse
    {
        $this->district($request);

        return response()->json($backups->inspect($backup));
    }

    public function download(Request $request, string $backup, SemesterBackupService $backups): BinaryFileResponse
    {
        $this->district($request);
        $entry = $backups->find($backup);

        $label = preg_replace('~[^0-9-]~', '-', $entry->semester.'-'.$entry->year);

        return response()->download($backups->downloadPath($backup), 'sena-semester-'.$label.'-'.$backup.'.zip', [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function import(Request $request, SemesterBackupService $backups): JsonResponse
    {
        $this->district($request);
        $request->validate(['archive' => ['required', 'file', 'mimetypes:application/zip,application/x-zip-compressed,application/octet-stream', 'max:524288']], [
            'archive.uploaded' => 'อัปโหลดไฟล์สำรองไม่สำเร็จ กรุณาตรวจขนาดไฟล์กับ upload_max_filesize และ post_max_size ของ PHP แล้วลองใหม่',
            'archive.max' => 'ไฟล์สำรองต้องมีขนาดไม่เกิน 512 MB และไม่เกินขีดจำกัดอัปโหลดของเซิร์ฟเวอร์',
            'archive.mimetypes' => 'กรุณาเลือกไฟล์ ZIP สำรองที่สร้างจากระบบนี้',
        ]);

        return response()->json(['backup' => $backups->import($request->user(), $request->file('archive'))], 201);
    }

    public function archive(Request $request, string $backup, SemesterBackupService $backups): JsonResponse
    {
        $this->district($request);
        $entry = $backups->find($backup);
        $this->confirmation($request, $entry, 'ARCHIVE');
        $request->validate(['external_copy' => ['required', 'accepted'],
            'accept_missing_files' => (json_decode($entry->counts, true)['missing_files'] ?? 0) > 0 ? ['required', 'accepted'] : ['sometimes', 'boolean']]);

        return response()->json(['backup' => $backups->archive($backup)]);
    }

    public function release(Request $request, string $backup, SemesterBackupService $backups): JsonResponse
    {
        $this->district($request);
        $this->confirmation($request, $backups->find($backup), 'RELEASE');
        $request->validate(['external_copy' => ['required', 'accepted']]);

        return response()->json(['storage' => $backups->releaseFiles($backup)]);
    }

    public function closeout(Request $request, string $backup, SemesterBackupService $backups): JsonResponse
    {
        $this->district($request);
        $entry = $backups->find($backup);
        $this->confirmation($request, $entry, 'CLOSE');
        $data = $request->validate(['external_copy' => ['required', 'accepted'],
            'accept_missing_files' => (json_decode($entry->counts, true)['missing_files'] ?? 0) > 0 ? ['required', 'accepted'] : ['sometimes', 'boolean'],
            'pause_active_drafts' => ['sometimes', 'boolean'],
            'draft_fingerprint' => ['nullable', 'required_if:pause_active_drafts,true,1', 'string', 'regex:/^[a-f0-9]{64}$/D']]);

        return response()->json($backups->closeout($backup, $request->boolean('pause_active_drafts') ? $data['draft_fingerprint'] : null));
    }

    public function offload(Request $request, string $backup, SemesterBackupService $backups): JsonResponse
    {
        $this->district($request);
        $this->confirmation($request, $backups->find($backup), 'OFFLOAD');
        $request->validate(['external_copy' => ['required', 'accepted']]);

        return response()->json(['backup' => $backups->offload($backup)]);
    }

    public function restore(Request $request, string $backup, SemesterBackupService $backups): JsonResponse
    {
        $this->district($request);
        $this->confirmation($request, $backups->find($backup), 'RESTORE');
        $entry = $backups->restore($backup);

        return response()->json(['backup' => $entry, 'restored' => $entry['counts']]);
    }

    private function confirmation(Request $request, object $backup, string $action): void
    {
        $expected = $action.' '.$backup->semester.'/'.$backup->year;
        $request->validate(['confirmation' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($expected): void {
            if ($value !== $expected) {
                $fail('กรุณาพิมพ์ '.$expected.' ให้ตรงก่อนยืนยัน');
            }
        }]]);
    }

    private function district(Request $request): void
    {
        abort_unless($request->user()?->isDistrict(), 403);
    }

    private function scopeRules(): array
    {
        // Raw existing legacy periods may be backed up/repaired; new records use stricter canonical rules.
        return ['year' => ['required', 'string', 'regex:~^[0-9/]{1,10}$~D'], 'semester' => ['required', 'string', 'regex:~^[0-9/]{1,10}$~D']];
    }

    private function repairRules(): array
    {
        return ['source_year' => $this->scopeRules()['year'], 'source_semester' => $this->scopeRules()['semester'],
            'target_year' => ['required', 'string', 'regex:/^25[0-9]{2}$/D'], 'target_semester' => ['required', 'string', 'in:1,2']];
    }
}
