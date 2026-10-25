<?php

namespace App\Http\Controllers;

use App\Models\ExportJob;
use App\Models\RecordRevision;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\LegacyPrintService;
use App\Services\RecordRevisionService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class BrowserExportController extends Controller
{
    public function index(Request $request, RecordRevisionService $revisions): Response
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
        if (! $user->isDistrict()) {
            unset($filters['tambon']);
        }
        $records = TeachingRecord::query()->visibleTo($user)
            ->when($filters['tambon'] ?? null, fn (Builder $query, string $value) => $query->where('tambon', $value))
            ->when($filters['year'] ?? null, fn (Builder $query, string $value) => $query->where('year', $value))
            ->when($filters['semester'] ?? null, fn (Builder $query, string $value) => $query->where('semester', $value))
            ->when($filters['week'] ?? null, fn (Builder $query, mixed $value) => $query->where('week', $value))
            ->when($filters['search'] ?? null, function (Builder $query, string $value): void {
                $search = addcslashes($value, '%_\\');
                $query->where(fn (Builder $inner) => $inner->where('subject', 'like', "%{$search}%")
                    ->orWhere('subject_code', 'like', "{$search}%"));
            })
            ->orderBy('tambon')->orderBy('date')->orderBy('level')->orderBy('id')->get(['id']);
        $expiresAt = now()->addHour();
        $manifest = $records->map(function (TeachingRecord $record) use ($revisions, $user, $expiresAt): array {
            return $this->manifestEntry($revisions->capture($record, $user), $user, $expiresAt);
        })->all();

        return $this->page($request, $filters, $manifest, $expiresAt);
    }

    public function export(Request $request, ExportJob $export): Response
    {
        $user = $request->user();
        abort_unless((string) $export->user_id === (string) $user->id, 404);
        abort_if($export->expires_at->isPast(), 410, 'ลิงก์เอกสารหมดอายุแล้ว กรุณาสร้างใหม่');
        $recordIds = array_column($export->manifest, 'record_id');
        abort_unless(TeachingRecord::query()->visibleTo($user)->whereKey($recordIds)->count() === count($recordIds), 403);
        $expiresAt = now()->addHour()->min($export->expires_at);
        $manifest = [];
        foreach (array_chunk($export->manifest, 25) as $entries) {
            $revisions = RecordRevision::query()->whereKey(array_column($entries, 'revision_id'))->get()->keyBy('id');
            foreach ($entries as $entry) {
                $revision = $revisions->get($entry['revision_id']);
                abort_unless($revision && (string) $revision->record_id === (string) $entry['record_id'], 409, 'ฉบับเอกสารไม่ตรงกับรายการส่งออก');
                $manifest[] = $this->manifestEntry($revision, $user, $expiresAt);
            }
        }

        return $this->page($request, $export->filters, $manifest, $expiresAt);
    }

    /** @param array<string, int|string> $filters
     * @param  list<array<string, int|string>>  $records
     */
    private function page(Request $request, array $filters, array $records, CarbonInterface $expiresAt): Response
    {
        return Inertia::render('exports/browser-print', [
            'records' => $records,
            'filters' => $filters,
            'title' => $filters['scope'] === 'week'
                ? 'บันทึกหลังสอน สัปดาห์ที่ '.$filters['week'].' ปี '.$filters['year'].' ภาคเรียน '.$filters['semester']
                : 'บันทึกหลังสอนตามตัวกรอง',
            'expires_at' => $expiresAt->toIso8601String(),
        ])->toResponse($request)->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    public function revision(Request $request, RecordRevision $revision, LegacyPrintService $print): Response
    {
        abort_unless((string) $request->query('user') === (string) $request->user()->id, 403);
        abort_unless(TeachingRecord::query()->visibleTo($request->user())->whereKey($revision->record_id)->exists(), 403);
        abort_unless((string) ($revision->snapshot['record']['id'] ?? '') === (string) $revision->record_id, 409, 'ฉบับเอกสารไม่ตรงกับบันทึก');

        return response($print->browserSnapshotHtml($revision->snapshot, $request->user(), route('records.show', $revision->record_id)), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'same-origin',
        ]);
    }

    /** @return array<string, int|string> */
    private function manifestEntry(RecordRevision $revision, User $user, CarbonInterface $expiresAt): array
    {
        $data = $revision->snapshot['record'] ?? [];
        abort_unless((string) ($data['id'] ?? '') === (string) $revision->record_id, 409, 'ฉบับเอกสารไม่ตรงกับบันทึก');

        return [
            'record_id' => $revision->record_id,
            'revision_id' => $revision->id,
            'revision' => $revision->revision,
            'subject' => (string) ($data['subject'] ?? ''),
            'level' => (string) ($data['level'] ?? ''),
            'date' => (string) ($data['date'] ?? ''),
            'print_url' => URL::temporarySignedRoute('exports.browser-print.revision', $expiresAt, [
                'revision' => $revision->id,
                'user' => $user->id,
            ]),
        ];
    }
}
