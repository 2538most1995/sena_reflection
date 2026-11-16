<?php

namespace App\Http\Controllers;

use App\Models\RecordAttendance;
use App\Models\RecordImage;
use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\WeeklySubmission;
use App\Services\AcademicTermService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WeeklySubmissionController extends Controller
{
    private const LEVELS = ['ประถมศึกษา', 'มัธยมศึกษาตอนต้น', 'มัธยมศึกษาตอนปลาย'];

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tambon' => ['nullable', 'string', 'max:20'],
            'week' => ['required', 'integer', 'between:1,20'],
            'date' => ['required', 'date'],
            'semester' => ['required', 'in:1,2,3'],
            'year' => AcademicTermService::yearRules(),
            'records' => ['required', 'array', 'size:3'],
            'records.*.level' => ['required', 'distinct', Rule::in(self::LEVELS)],
            'records.*.subject' => ['required', 'string', 'max:150'],
            'records.*.subject_code' => ['nullable', 'string', 'max:50'],
            'records.*.class_group_code' => ['nullable', 'string', 'max:50'],
            'records.*.class_name' => ['nullable', 'string', 'max:255'],
            'records.*.total' => ['required', 'integer', 'min:0', 'max:10000'],
            'records.*.present' => ['required', 'integer', 'min:0', 'max:10000'],
            'records.*.result' => ['nullable', 'string', 'max:10000'],
            'records.*.problem' => ['nullable', 'string', 'max:10000'],
            'records.*.suggestion' => ['nullable', 'string', 'max:10000'],
            'records.*.attendance' => ['nullable', 'array', 'max:2000'],
            'records.*.attendance.*.student_id' => ['required', 'string', 'max:50'],
            'records.*.attendance.*.full_name' => ['required', 'string', 'max:255'],
            'records.*.attendance.*.group_code' => ['nullable', 'string', 'max:50'],
            'records.*.attendance.*.class_name' => ['nullable', 'string', 'max:255'],
            'records.*.attendance.*.present' => ['required', 'boolean'],
            'records.*.attendance.*.learning_method' => ['nullable', Rule::in(['พบกลุ่ม', 'ออนไลน์'])],
            'records.*.images' => ['nullable', 'array', 'max:4'],
            'records.*.images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=200,min_height=200,max_width=8000,max_height=8000'],
        ], ['year.regex' => 'เลือกปีการศึกษา พ.ศ. 4 หลัก เช่น 2569 โดยไม่ใส่ภาคเรียนในช่องปี']);

        foreach ($validated['records'] as $index => $entry) {
            if ((int) $entry['present'] > (int) $entry['total']) {
                throw ValidationException::withMessages(["records.{$index}.present" => 'จำนวนมาเรียนต้องไม่เกินจำนวนผู้เรียนทั้งหมด']);
            }
            $imageCount = count($request->file("records.{$index}.images", []));
            if (! in_array($imageCount, [0, 2, 4], true)) {
                throw ValidationException::withMessages(["records.{$index}.images" => 'ภาพประกอบต้องมี 2 หรือ 4 รูป']);
            }
        }

        $user = $request->user();
        $tambon = $user->isDistrict() ? ($validated['tambon'] ?? null) : $user->tambon;
        if (! $tambon || ! Tambon::query()->whereKey($tambon)->exists()) {
            throw ValidationException::withMessages(['tambon' => 'กรุณาเลือกตำบลที่ถูกต้อง']);
        }

        $storedPaths = [];
        try {
            DB::transaction(function () use ($validated, $tambon, $user, $request, &$storedPaths): void {
                $submission = WeeklySubmission::query()->where([
                    'tambon' => $tambon, 'week' => $validated['week'],
                    'semester' => $validated['semester'], 'year' => $validated['year'],
                ])->lockForUpdate()->first();
                if ($submission?->status === 'complete') {
                    throw ValidationException::withMessages(['week' => 'สัปดาห์นี้มีการส่งเรียบร้อยแล้ว']);
                }
                $submission ??= WeeklySubmission::create([
                    'tambon' => $tambon, 'week' => $validated['week'], 'semester' => $validated['semester'],
                    'year' => $validated['year'], 'submitted_by' => $user->id, 'status' => 'incomplete',
                ]);

                foreach ($validated['records'] as $index => $entry) {
                    $attendance = collect($entry['attendance'] ?? [])->unique('student_id')->values();
                    $recordData = [
                        ...collect($entry)->except(['images', 'attendance'])->all(),
                        'total' => $attendance->isNotEmpty() ? $attendance->count() : $entry['total'],
                        'present' => $attendance->isNotEmpty() ? $attendance->where('present', true)->count() : $entry['present'],
                        'weekly_submission_id' => $submission->id,
                        'tambon' => $tambon,
                        'week' => $validated['week'],
                        'date' => $validated['date'],
                        'semester' => $validated['semester'],
                        'year' => $validated['year'],
                        'created_by' => $user->id,
                    ];
                    $record = $submission->records()->where('level', $entry['level'])->oldest('created_at')->first();
                    if ($record) {
                        $record->update($recordData);
                    } else {
                        $record = TeachingRecord::create([
                            ...$recordData, 'id' => 'r'.substr(str_replace('-', '', (string) Str::uuid()), 0, 19),
                        ]);
                    }

                    if ($record->images()->count() + count($request->file("records.{$index}.images", [])) > 4) {
                        throw ValidationException::withMessages(["records.{$index}.images" => 'รูปภาพรวมของระดับนี้ต้องไม่เกิน 4 รูป']);
                    }

                    foreach ($request->file("records.{$index}.images", []) as $image) {
                        $path = $image->store("records/{$validated['year']}/{$validated['week']}", 'public');
                        $storedPaths[] = $path;
                        RecordImage::create([
                            'record_id' => $record->id,
                            'file_path' => 'storage/'.$path,
                            'created_at' => now(),
                        ]);
                    }

                    if ($attendance->isNotEmpty()) {
                        $record->attendance()->delete();
                        RecordAttendance::query()->insert($attendance->map(fn (array $row) => [
                            'record_id' => $record->id,
                            'student_id' => $row['student_id'],
                            'full_name' => $row['full_name'],
                            'group_code' => $row['group_code'] ?? null,
                            'class_name' => $row['class_name'] ?? null,
                            'present' => (bool) $row['present'],
                            'learning_method' => $row['present'] ? ($row['learning_method'] ?? 'พบกลุ่ม') : null,
                            'created_at' => now(),
                        ])->all());
                    }
                }
                $submission->update(['status' => 'complete', 'submitted_by' => $user->id]);
            }, 3);
        } catch (QueryException $e) {
            Storage::disk('public')->delete($storedPaths);
            if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                return back()->withInput()->withErrors(['week' => 'สัปดาห์นี้มีการส่งครบ 3 ระดับแล้ว']);
            }
            throw $e;
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($storedPaths);
            throw $e;
        }

        return redirect()->route('dashboard', [
            'year' => $validated['year'],
            'semester' => $validated['semester'],
            'week' => $validated['week'],
        ])->with('success', 'บันทึกครบ 3 ระดับชั้นแล้ว');
    }
}
