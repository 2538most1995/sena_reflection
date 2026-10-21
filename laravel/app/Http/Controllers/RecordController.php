<?php

namespace App\Http\Controllers;

use App\Exceptions\DocumentRuntimeUnavailableException;
use App\Models\RecordAttendance;
use App\Models\RecordImage;
use App\Models\RecordRevision;
use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\WeeklySubmission;
use App\Services\EducationLevelService;
use App\Services\LegacyPrintService;
use App\Services\RecordImageAssets;
use App\Services\RecordPdfService;
use App\Services\RecordRevisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class RecordController extends Controller
{
    private const LEVELS = ['ประถมศึกษา', 'มัธยมศึกษาตอนต้น', 'มัธยมศึกษาตอนปลาย'];

    public function show(Request $request, TeachingRecord $record): InertiaResponse
    {
        $this->authorizeScope($request, $record);
        $record->load(['tambonRelation:id,name', 'images', 'attendance']);

        return Inertia::render('records/show', ['record' => $this->recordPayload($record)]);
    }

    public function print(Request $request, TeachingRecord $record, RecordPdfService $pdf): Response
    {
        $this->authorizeScope($request, $record);
        $record->load(['tambonRelation:id,name', 'images']);
        $filename = sprintf('sena-record-week-%02d-%s.pdf', $record->week, $record->id);

        try {
            $content = $pdf->renderOne($record);
        } catch (DocumentRuntimeUnavailableException $exception) {
            $accept = strtolower((string) $request->header('Accept', ''));
            if (str_contains($accept, 'text/html') && ! $request->expectsJson()
                && $request->prefers(['text/html', 'application/pdf', 'application/json']) === 'text/html') {
                report($exception);

                return redirect()->route('records.browser-print', $record);
            }
            throw $exception;
        }

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function browserPrint(Request $request, TeachingRecord $record, LegacyPrintService $print): Response
    {
        $this->authorizeScope($request, $record);

        return response($print->browserPrintHtml($record, $request->user()), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    public function image(Request $request, TeachingRecord $record, RecordImage $image): BinaryFileResponse
    {
        $this->authorizeScope($request, $record);
        abort_unless((string) $image->record_id === (string) $record->getKey(), 404);

        $realPath = app(RecordImageAssets::class)->resolve((string) $image->file_path);
        abort_if($realPath === null, 404);

        return response()->file($realPath, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function edit(Request $request, TeachingRecord $record, RecordCreateController $create, StudentDirectoryController $students, EducationLevelService $levels): InertiaResponse
    {
        $this->authorizeScope($request, $record);
        $record->load(['images', 'attendance']);

        $optionsRequest = $request->duplicate([...$request->query(), 'tambon' => $record->tambon]);
        $options = $create->options($optionsRequest, $students, $levels);
        $data = $options['data'];

        return Inertia::render('records/edit', [
            'record' => $this->recordPayload($record),
            'groups' => $data['groups'], 'subjects' => $data['subjects'],
            'tambonOptions' => $request->user()->isDistrict() ? Tambon::query()->orderBy('name')->get(['id', 'name']) : [],
            'studentSource' => $data['source'], 'studentError' => $data['error_code'] ? $options['message'] : null,
            'defaults' => ['year' => $record->year, 'semester' => $record->semester, 'date' => $record->date->format('Y-m-d')],
            'workspace' => ['mode' => 'edit', 'draft' => null, 'activeRecordId' => $record->id],
        ]);
    }

    public function update(Request $request, TeachingRecord $record): RedirectResponse
    {
        $this->authorizeScope($request, $record);
        $data = $request->validate([
            'week' => ['required', 'integer', 'between:1,20'], 'date' => ['required', 'date'],
            'year' => ['required', 'digits:4', 'integer', 'between:2500,2800'], 'semester' => ['required', 'in:1,2,3'],
            'level' => ['required', Rule::in(self::LEVELS)], 'subject' => ['required', 'string', 'max:150'],
            'total' => ['required', 'integer', 'min:0', 'max:10000'], 'present' => ['required', 'integer', 'min:0', 'lte:total'],
            'subject_code' => ['nullable', 'string', 'max:50'], 'class_group_code' => ['nullable', 'string', 'max:50'],
            'class_name' => ['nullable', 'string', 'max:255'], 'result' => ['nullable', 'string', 'max:10000'],
            'problem' => ['nullable', 'string', 'max:10000'], 'suggestion' => ['nullable', 'string', 'max:10000'],
            'keep_images' => ['nullable', 'array', 'max:4'], 'keep_images.*' => ['integer'],
            'images' => ['nullable', 'array', 'max:4'], 'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'attendance' => ['nullable', 'array', 'max:1000'], 'attendance.*.student_id' => ['required', 'string', 'max:50', 'distinct'],
            'attendance.*.full_name' => ['required', 'string', 'max:255'], 'attendance.*.group_code' => ['nullable', 'string', 'max:50'],
            'attendance.*.class_name' => ['nullable', 'string', 'max:255'], 'attendance.*.present' => ['nullable', 'boolean'],
            'attendance.*.learning_method' => ['nullable', Rule::in(['พบกลุ่ม', 'ออนไลน์'])],
        ]);

        $keepIds = collect($data['keep_images'] ?? [])->map(fn ($id) => (int) $id);
        $ownedKeepIds = $record->images()->whereIn('id', $keepIds)->pluck('id');
        if (! in_array($ownedKeepIds->count() + count($request->file('images', [])), [0, 2, 4], true)) {
            return back()->withInput()->withErrors(['images' => 'ภาพประกอบต้องมี 0, 2 หรือ 4 รูป']);
        }

        $stored = [];
        $deletedStorage = [];
        try {
            DB::transaction(function () use ($record, $data, $request, $ownedKeepIds, &$stored, &$deletedStorage): void {
                $record = TeachingRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                app(RecordRevisionService::class)->capture($record, $request->user());
                $previous = $record->only(['subject', 'subject_code', 'class_group_code', 'class_name', 'result', 'problem', 'suggestion']);
                $oldSubmissionId = $record->weekly_submission_id;
                if ((int) $record->week !== (int) $data['week'] || $record->year !== $data['year'] || $record->semester !== $data['semester'] || $record->level !== $data['level']) {
                    $target = WeeklySubmission::query()->where([
                        'tambon' => $record->tambon, 'year' => $data['year'], 'semester' => $data['semester'], 'week' => $data['week'],
                    ])->lockForUpdate()->first();
                    $duplicate = TeachingRecord::query()->where('tambon', $record->tambon)->where('year', $data['year'])
                        ->where('semester', $data['semester'])->where('week', $data['week'])->where('level', $data['level'])->where('id', '!=', $record->id)->exists();
                    if ($duplicate) {
                        throw ValidationException::withMessages(['week' => 'มีบันทึกระดับนี้ในสัปดาห์ปลายทางแล้ว']);
                    }
                    $target ??= WeeklySubmission::create([
                        'id' => (string) Str::uuid(), 'tambon' => $record->tambon, 'year' => $data['year'],
                        'semester' => $data['semester'], 'week' => $data['week'], 'status' => 'incomplete', 'submitted_by' => $request->user()->id,
                    ]);
                    $record->weekly_submission_id = $target->id;
                }
                $attendance = collect($data['attendance'] ?? []);
                $record->update([
                    ...collect($data)->except(['keep_images', 'images', 'attendance'])->all(),
                    'total' => $attendance->isNotEmpty() ? $attendance->count() : $data['total'],
                    'present' => $attendance->isNotEmpty() ? $attendance->where('present', true)->count() : $data['present'],
                ]);

                $toDelete = $record->images()->whereNotIn('id', $ownedKeepIds)->get();
                $deletedStorage = $toDelete->pluck('file_path')->filter(fn ($path) => str_starts_with($path, 'storage/'))->all();
                $record->images()->whereNotIn('id', $ownedKeepIds)->delete();

                foreach ($request->file('images', []) as $image) {
                    $path = $image->store("records/{$record->year}/{$record->week}", 'public');
                    $stored[] = $path;
                    RecordImage::create(['record_id' => $record->id, 'file_path' => 'storage/'.$path, 'created_at' => now()]);
                }

                $ids = $attendance->pluck('student_id')->all();
                $record->attendance()->when($ids, fn ($q) => $q->whereNotIn('student_id', $ids))->when(! $ids, fn ($q) => $q)->delete();
                if ($attendance->isNotEmpty()) {
                    RecordAttendance::query()->upsert($attendance->map(fn ($row) => [
                        'record_id' => $record->id, 'student_id' => $row['student_id'], 'full_name' => $row['full_name'],
                        'group_code' => $row['group_code'] ?? null, 'class_name' => $row['class_name'] ?? null,
                        'present' => (bool) ($row['present'] ?? false),
                        'learning_method' => ($row['present'] ?? false) ? ($row['learning_method'] ?? 'พบกลุ่ม') : null,
                        'created_at' => now(),
                    ])->all(), ['record_id', 'student_id'], ['full_name', 'group_code', 'class_name', 'present', 'learning_method']);
                }
                $layout = $record->documentLayout()->first();
                if ($layout) {
                    $content = $layout->content ?? [];
                    foreach ($previous as $key => $value) {
                        if ((string) $value !== (string) ($data[$key] ?? $value)) {
                            $content['record_'.$key] = (string) ($data[$key] ?? '');
                        }
                    }
                    $layout->update(['content' => $content]);
                }
                foreach (array_unique(array_filter([$oldSubmissionId, $record->weekly_submission_id])) as $submissionId) {
                    $parent = WeeklySubmission::query()->find($submissionId);
                    if ($parent) {
                        $parent->update(['status' => $parent->records()->distinct()->count('level') === 3 ? 'complete' : 'incomplete']);
                    }
                }
                app(RecordRevisionService::class)->capture($record, $request->user());
            }, 3);
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($stored);
            throw $e;
        }

        $this->deleteUnreferencedImages($deletedStorage);

        return redirect()->route('records.edit', $record)->with('success', 'แก้ไขบันทึกแล้ว');
    }

    public function destroy(Request $request, TeachingRecord $record): RedirectResponse
    {
        $this->authorizeScope($request, $record);
        $storagePaths = $record->images()->pluck('file_path')->filter(fn ($path) => str_starts_with($path, 'storage/'))->all();
        $submissionId = $record->weekly_submission_id;
        DB::transaction(function () use ($record, $submissionId, $request): void {
            app(RecordRevisionService::class)->capture($record, $request->user());
            $record->documentLayout()->delete();
            $record->attendance()->delete();
            $record->images()->delete();
            $record->delete();
            if ($submissionId) {
                $submission = WeeklySubmission::query()->find($submissionId);
                if ($submission && ! $submission->records()->exists()) {
                    $submission->delete();
                } elseif ($submission) {
                    $submission->update(['status' => 'incomplete']);
                }
            }
        });
        $this->deleteUnreferencedImages($storagePaths);

        return redirect()->route('dashboard')->with('success', 'ลบบันทึกแล้ว');
    }

    private function authorizeScope(Request $request, TeachingRecord $record): void
    {
        abort_unless($request->user()->isDistrict() || $record->tambon === $request->user()->tambon, 403);
    }

    private function deleteUnreferencedImages(array $paths): void
    {
        foreach ($paths as $path) {
            if (! RecordRevision::query()->whereJsonContains('snapshot->images', ['file_path' => $path])->exists()) {
                Storage::disk('public')->delete(substr($path, 8));
            }
        }
    }

    /** @return array<string, mixed> */
    private function recordPayload(TeachingRecord $record): array
    {
        $payload = $record->toArray();
        $payload['date'] = $record->date?->format('Y-m-d');
        if ($record->relationLoaded('images')) {
            $assets = app(RecordImageAssets::class);
            $payload['images'] = $record->images->map(fn (RecordImage $image): array => [
                ...$image->toArray(),
                'preview_src' => $assets->previewSrc((string) $image->file_path),
            ])->all();
        }

        return $payload;
    }
}
