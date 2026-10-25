<?php

namespace App\Http\Controllers;

use App\Services\SemesterBackupService;
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
        $data = $request->validate(['year' => ['required', 'string', 'regex:/^[0-9]{4}$/D'], 'semester' => ['required', 'string', 'in:1,2']]);

        return response()->json(['backup' => $backups->create($request->user(), $data['year'], $data['semester'])], 201);
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

        return response()->download($backups->downloadPath($backup), 'sena-semester-'.$entry->semester.'-'.$entry->year.'-'.$backup.'.zip', [
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
        $this->confirmation($request, $backups->find($backup), 'ARCHIVE');

        return response()->json(['backup' => $backups->archive($backup)]);
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
}
