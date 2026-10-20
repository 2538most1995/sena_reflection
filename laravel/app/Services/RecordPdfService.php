<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\TeachingRecord;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class RecordPdfService
{
    public function __construct(private readonly LegacyPrintService $legacy) {}

    public function renderOne(TeachingRecord $record): string
    {
        if (! app()->environment('testing') && Auth::user()) {
            return $this->legacy->pdf($record, Auth::user());
        }

        return $this->renderFallback(collect([$record]));
    }

    public function render(Collection $records): string
    {
        if (! app()->environment('testing') && Auth::user()) {
            return $this->legacy->combinedPdf($records, Auth::user());
        }

        return $this->renderFallback($records);
    }

    /** @param array<string, mixed> $snapshot */
    public function renderSnapshot(array $snapshot, User $user): string
    {
        return $this->legacy->snapshotPdf($snapshot, $user);
    }

    private function renderFallback(Collection $records): string
    {
        $records->each->loadMissing(['tambonRelation:id,name', 'images']);
        $images = $records->mapWithKeys(fn (TeachingRecord $record) => [
            $record->id => $record->images->take(4)->map(fn ($image) => $this->dataUri($image->file_path))->filter()->values(),
        ]);
        $settings = Cache::remember(
            'pdf:settings:v2',
            300,
            fn () => Setting::query()->pluck('setting_value', 'setting_key')->all()
        );

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->setChroot([base_path(), dirname(base_path())]);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml(view('exports.record-pdf', compact('records', 'images', 'settings'))->render(), 'UTF-8');
        $dompdf->render();

        return $dompdf->output();
    }

    private function dataUri(string $storedPath): ?string
    {
        $path = null;
        if (str_starts_with($storedPath, 'storage/')) {
            $path = storage_path('app/public/'.substr($storedPath, 8));
        } else {
            $legacy = dirname(base_path()).'/'.ltrim($storedPath, '/');
            $public = public_path(ltrim($storedPath, '/'));
            $path = is_file($legacy) ? $legacy : $public;
        }

        if (! is_file($path) || filesize($path) > 8 * 1024 * 1024) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
