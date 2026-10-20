<?php

namespace App\Http\Controllers;

use App\Models\TeachingRecord;
use App\Services\RecordPdfService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class ExportController extends Controller
{
    public function __invoke(Request $request, RecordPdfService $pdf): Response|BinaryFileResponse
    {
        $filters = $request->validate([
            'scope' => ['required', 'in:week,all'],
            'year' => ['nullable', 'required_if:scope,week', 'string', 'max:10'],
            'semester' => ['nullable', 'required_if:scope,week', 'in:1,2,3'],
            'week' => ['nullable', 'required_if:scope,week', 'integer', 'between:1,60'],
            'tambon' => ['nullable', 'string', 'max:20'],
        ]);

        $user = $request->user();
        $query = TeachingRecord::query()->visibleTo($user)
            ->when($user->isDistrict() && ($filters['tambon'] ?? null), fn (Builder $q) => $q->where('tambon', $filters['tambon']))
            ->when($filters['scope'] === 'week', fn (Builder $q) => $q
                ->where('year', $filters['year'])
                ->where('semester', $filters['semester'])
                ->where('week', $filters['week']))
            ->with(['tambonRelation:id,name', 'images'])
            ->orderBy('tambon')->orderBy('date')->orderBy('level');

        if (! (clone $query)->exists()) {
            return back()->withErrors(['download' => 'ไม่พบข้อมูลในขอบเขตที่เลือก']);
        }

        if ($filters['scope'] === 'week') {
            $records = $query->get();
            $filename = sprintf('sena-week-%s-%s-%02d.pdf', $filters['year'], $filters['semester'], $filters['week']);

            return response($pdf->render($records), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'sena-export-');
        if ($tempPath === false) {
            abort(500, 'ไม่สามารถสร้างไฟล์ชั่วคราวได้');
        }

        $zip = new ZipArchive;
        if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tempPath);
            abort(500, 'ไม่สามารถสร้าง ZIP ได้');
        }

        $csv = "ID,ตำบล,ปี,ภาคเรียน,สัปดาห์,ระดับ,รายวิชา,ผู้เรียน,มาเรียน\n";
        $query->chunk(25, function ($records) use ($zip, $pdf, &$csv): void {
            foreach ($records as $record) {
                $safeLevel = Str::slug($record->level) ?: 'level';
                $name = sprintf('week-%02d/%s-%s.pdf', $record->week, $record->id, $safeLevel);
                $zip->addFromString($name, $pdf->renderOne($record));
                $csv .= implode(',', array_map(fn ($value) => '"'.str_replace('"', '""', (string) $value).'"', [
                    $record->id, $record->tambonRelation?->name, $record->year, $record->semester,
                    $record->week, $record->level, $record->subject, $record->total, $record->present,
                ]))."\n";
            }
        });
        $zip->addFromString('index.csv', "\xEF\xBB\xBF".$csv);
        $zip->close();

        return response()->download($tempPath, 'sena-records-all-'.now()->format('Ymd-His').'.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }
}
