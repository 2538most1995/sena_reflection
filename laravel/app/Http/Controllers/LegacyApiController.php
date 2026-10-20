<?php

namespace App\Http\Controllers;

use App\Models\RecordAttendance;
use App\Models\RecordImage;
use App\Models\Setting;
use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Models\WeeklySubmission;
use App\Services\AttendanceReportService;
use App\Services\LegacyDocxService;
use App\Services\LegacyPrintService;
use App\Services\RecordPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class LegacyApiController extends Controller
{
    private const LEVELS = ['ประถมศึกษา', 'มัธยมศึกษาตอนต้น', 'มัธยมศึกษาตอนปลาย'];

    private const SIG1_KEYS = [
        'sig1_name', 'sig1_pos', 'sig1_sign_prefix', 'sig1_sign_suffix', 'sig1_align', 'sig1_dot_width', 'sig1_mt',
        'sig1_offset', 'sig1_offset_y', 'sig1_line_offset_x', 'sig1_line_offset_y', 'sig1_name_offset_x', 'sig1_name_offset_y',
        'sig1_pos_offset_x', 'sig1_pos_offset_y', 'sig1_sign_prefix_offset_x', 'sig1_sign_prefix_offset_y',
        'sig1_abs_x', 'sig1_abs_y', 'sig1_abs_w', 'sig1_abs_h', 'sig2_offset', 'sig2_offset_y', 'sig3_offset', 'sig3_offset_y',
        'font_size_offset', 'pdf_font_size_pt', 'pdf_line_spacing', 'pdf_dot_size', 'pdf_margin_top_px', 'pdf_margin_right_px',
        'pdf_margin_bottom_px', 'pdf_margin_left_px', 'signature_font_size_pt', 'attendance_font_size_pt',
        'image_width_px', 'image_height_px', 'image_offset_x', 'image_offset_y', 'image_gap_px',
        'image_1_width_px', 'image_1_height_px', 'image_1_offset_x', 'image_1_offset_y',
        'image_2_width_px', 'image_2_height_px', 'image_2_offset_x', 'image_2_offset_y',
        'image_3_width_px', 'image_3_height_px', 'image_3_offset_x', 'image_3_offset_y',
        'image_4_width_px', 'image_4_height_px', 'image_4_offset_x', 'image_4_offset_y',
    ];

    public function auth(Request $request): JsonResponse
    {
        $action = (string) $request->query('action');
        if (! $request->isMethod('post')) {
            return $this->error('Invalid action');
        }

        if ($action === 'login') {
            $username = trim((string) $request->input('username'));
            $password = (string) $request->input('password');
            if ($username === '' || $password === '') {
                return $this->error('กรุณากรอกข้อมูลให้ครบถ้วน');
            }
            $user = User::query()->where('username', $username)->first();
            $stored = (string) ($user?->password ?? '');
            $isHash = str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon');
            $valid = $user && ($isHash ? Hash::check($password, $stored) : hash_equals($stored, $password));
            if (! $valid) {
                return $this->error('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
            }
            if (! $isHash || Hash::needsRehash($stored)) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
            Auth::login($user);
            $request->session()->regenerate();

            return $this->success('เข้าสู่ระบบสำเร็จ', ['user' => $this->userPayload($user), 'print_token' => $this->printToken($user)]);
        }

        if ($action === 'logout') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->success('ออกจากระบบสำเร็จ');
        }

        $user = $this->authenticated();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if ($action === 'change_password') {
            $current = (string) $request->input('cur_pass');
            $new = (string) $request->input('new_pass');
            if (mb_strlen($new) < 6) {
                return $this->error('รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร');
            }
            if (! $this->passwordMatches($current, (string) $user->password)) {
                return $this->error('รหัสผ่านปัจจุบันไม่ถูกต้อง');
            }
            $user->forceFill(['password' => Hash::make($new)])->save();

            return $this->success('เปลี่ยนรหัสผ่านเรียบร้อยแล้ว', ['print_token' => $this->printToken($user)]);
        }

        return $this->error('Invalid action');
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $this->authenticated();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if (! $user->isDistrict()) {
            return $this->error('Unauthorized access');
        }

        $records = TeachingRecord::query()->select(['id', 'tambon', 'subject', 'subject_code', 'class_group_code', 'class_name', 'week', 'date', 'total', 'created_at'])->get();
        $uniqueStudents = RecordAttendance::query()->distinct('student_id')->count('student_id');
        $fallback = (int) $records->sum('total');
        $monthly = DB::table('record_attendance as ra')->join('records as r', 'r.id', '=', 'ra.record_id')
            ->select(['r.date', 'ra.student_id'])->get()->groupBy(fn ($row) => substr((string) $row->date, 0, 7));
        $thaiMonths = ['01' => 'ม.ค.', '02' => 'ก.พ.', '03' => 'มี.ค.', '04' => 'เม.ย.', '05' => 'พ.ค.', '06' => 'มิ.ย.', '07' => 'ก.ค.', '08' => 'ส.ค.', '09' => 'ก.ย.', '10' => 'ต.ค.', '11' => 'พ.ย.', '12' => 'ธ.ค.'];
        $monthlyStudents = $monthly->map(function ($rows, $key) use ($thaiMonths) {
            [$year, $month] = explode('-', $key);

            return ['month' => $key, 'label' => ($thaiMonths[$month] ?? $month).' '.((int) $year + 543), 'count' => $rows->pluck('student_id')->unique()->count()];
        })->sortBy('month')->values()->all();

        return $this->success('fetch dashboard data', [
            'stats' => [
                'total' => $records->count(),
                'thisMonth' => $records->filter(fn ($record) => optional($record->date)->format('Y-m') === now()->format('Y-m'))->count(),
                'tambonsDone' => $records->pluck('tambon')->unique()->count(),
                'totalStudents' => $uniqueStudents > 0 ? $uniqueStudents : $fallback,
                'uniqueStudents' => $uniqueStudents,
                'totalStudentsFallback' => $fallback,
            ],
            'monthlyStudents' => $monthlyStudents,
            'recentRecords' => $records->sortByDesc(fn ($record) => ((string) $record->date).((string) $record->created_at))->take(8)->map(fn ($record) => $this->recordPayload($record))->values()->all(),
            'tambonStatus' => $records->countBy('tambon')->all(),
        ]);
    }

    public function records(Request $request): JsonResponse
    {
        $user = $this->authenticated();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if ($request->isMethod('get')) {
            if ($request->filled('id')) {
                $record = TeachingRecord::query()->visibleTo($user)->with(['images', 'attendance'])->find($request->query('id'));
                if (! $record) {
                    return $this->error('Not found');
                }
                $payload = $this->recordPayload($record);
                $payload['images'] = $record->images->map(fn ($image) => ['name' => 'Image '.$image->id, 'data' => $image->file_path, 'exists' => $this->managedFileExists($image->file_path), 'missing' => ! $this->managedFileExists($image->file_path)])->values()->all();
                $payload['attendance'] = $record->attendance->map(fn ($row) => ['student_id' => $row->student_id, 'full_name' => $row->full_name, 'group_code' => $row->group_code, 'class_name' => $row->class_name, 'present' => (bool) $row->present, 'learning_method' => $row->learning_method])->values()->all();

                return $this->success('fetch record', $payload);
            }

            $query = TeachingRecord::query()->visibleTo($user)
                ->when($user->isDistrict() && $request->filled('tambon'), fn ($q) => $q->where('tambon', $request->query('tambon')))
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = addcslashes(trim((string) $request->query('search')), '%_\\');
                    $q->where(fn ($inner) => $inner->where('subject', 'like', "%{$search}%")->orWhere('subject_code', 'like', "%{$search}%"));
                })
                ->withCount('images')->orderByDesc('date')->orderByDesc('created_at');
            $records = $query->get();
            $ids = $records->pluck('id');
            $attendance = RecordAttendance::query()->whereIn('record_id', $ids)->get(['record_id', 'student_id']);
            $monthly = $records->groupBy(fn ($record) => optional($record->date)->format('Y-m'))->filter(fn ($rows, $key) => $key !== '');
            $thaiMonths = ['01' => 'ม.ค.', '02' => 'ก.พ.', '03' => 'มี.ค.', '04' => 'เม.ย.', '05' => 'พ.ค.', '06' => 'มิ.ย.', '07' => 'ก.ค.', '08' => 'ส.ค.', '09' => 'ก.ย.', '10' => 'ต.ค.', '11' => 'พ.ย.', '12' => 'ธ.ค.'];
            $monthlyStudents = $monthly->map(function ($rows, $key) use ($attendance, $thaiMonths) {
                [$year, $month] = explode('-', $key);

                return ['month' => $key, 'label' => ($thaiMonths[$month] ?? $month).' '.((int) $year + 543), 'count' => $attendance->whereIn('record_id', $rows->pluck('id'))->pluck('student_id')->unique()->count()];
            })->sortBy('month')->values()->all();

            return $this->success('fetch records', [
                'records' => $records->map(function ($record) {
                    $payload = $this->recordPayload($record);
                    $payload['image_count'] = (int) $record->images_count;
                    $payload['images'] = array_fill(0, (int) $record->images_count, ['name' => 'img']);

                    return $payload;
                })->all(),
                'uniqueStudents' => $attendance->pluck('student_id')->unique()->count(),
                'monthlyStudents' => $monthlyStudents,
            ]);
        }

        if ($request->isMethod('delete')) {
            $record = TeachingRecord::query()->visibleTo($user)->find($request->query('id'));
            if (! $record) {
                return $this->error('Record not found');
            }
            $submissionId = $record->weekly_submission_id;
            DB::transaction(function () use ($record, $submissionId) {
                $record->documentLayout()->delete();
                $record->attendance()->delete();
                $record->images()->delete();
                $record->delete();
                if ($submissionId && ($submission = WeeklySubmission::find($submissionId))) {
                    $submission->records()->exists() ? $submission->update(['status' => 'incomplete']) : $submission->delete();
                }
            });

            return $this->success('ลบบันทึกเรียบร้อยแล้ว');
        }

        if (! $request->isMethod('post') && ! $request->isMethod('put')) {
            return $this->error('Invalid Method');
        }

        $data = $request->all();
        $validator = Validator::make($data, [
            'week' => ['required', 'integer', 'between:1,20'], 'date' => ['required', 'date_format:Y-m-d'],
            'level' => ['required', 'string', 'max:50'], 'semester' => ['required', 'in:1,2,3'],
            'year' => ['required', 'digits:4', 'integer', 'between:2500,2800'], 'subject' => ['required', 'string', 'max:150'],
            'subject_code' => ['nullable', 'string', 'max:50'], 'class_group_code' => ['nullable', 'string', 'max:50'],
            'class_name' => ['nullable', 'string', 'max:255'], 'total' => ['nullable', 'integer', 'between:0,10000'],
            'present' => ['nullable', 'integer', 'between:0,10000'], 'result' => ['nullable', 'string', 'max:10000'],
            'problem' => ['nullable', 'string', 'max:10000'], 'suggestion' => ['nullable', 'string', 'max:10000'],
            'supervisorComment' => ['nullable', 'string', 'max:10000'], 'images' => ['nullable', 'array', 'max:4'],
            'attendance' => ['nullable', 'array', 'max:2000'],
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }
        $images = $this->normalizeImages($data['images'] ?? []);
        if (! in_array(count($images), [0, 2, 4], true)) {
            return $this->error('ภาพประกอบการจัดการเรียนการสอนต้องอัปโหลด 2 รูป หรือ 4 รูป เท่านั้น');
        }
        $attendance = $this->normalizeAttendance($data['attendance'] ?? []);
        $total = $attendance !== [] ? count($attendance) : (int) ($data['total'] ?? 0);
        $present = $attendance !== [] ? collect($attendance)->where('present', 1)->count() : (int) ($data['present'] ?? 0);
        if ($present > $total) {
            return $this->error('จำนวนมาเรียนต้องไม่เกินจำนวนผู้เรียนทั้งหมด');
        }
        $payload = [
            'week' => (int) $data['week'], 'date' => $data['date'], 'level' => trim((string) $data['level']),
            'semester' => (string) $data['semester'], 'year' => (string) $data['year'], 'subject' => trim((string) $data['subject']),
            'subject_code' => trim((string) ($data['subject_code'] ?? '')), 'class_group_code' => trim((string) ($data['class_group_code'] ?? '')),
            'class_name' => trim((string) ($data['class_name'] ?? '')), 'total' => $total, 'present' => $present,
            'result' => (string) ($data['result'] ?? ''), 'problem' => (string) ($data['problem'] ?? ''),
            'suggestion' => (string) ($data['suggestion'] ?? ''), 'supervisor_comment' => (string) ($data['supervisorComment'] ?? ''),
        ];

        if ($request->isMethod('post')) {
            $tambon = $user->isDistrict() ? trim((string) ($data['tambon'] ?? '')) : (string) $user->tambon;
            if ($tambon === '' || ! Tambon::query()->whereKey($tambon)->exists()) {
                return $this->error('ตำบล is required');
            }
            DB::transaction(function () use ($payload, $tambon, $user, $images, $attendance) {
                $record = TeachingRecord::create([...$payload, 'id' => 'r'.Str::lower(Str::random(19)), 'tambon' => $tambon, 'created_by' => $user->id]);
                $this->replaceRecordChildren($record, $images, $attendance);
            });

            return $this->success('บันทึกข้อมูลเรียบร้อยแล้ว');
        }

        $id = (string) ($request->query('id') ?: ($data['id'] ?? ''));
        $record = TeachingRecord::query()->visibleTo($user)->find($id);
        if (! $record) {
            return $this->error('Record not found');
        }
        DB::transaction(function () use ($record, $payload, $images, $attendance) {
            $record->update($payload);
            $this->replaceRecordChildren($record, $images, $attendance);
        });

        return $this->success('แก้ไขบันทึกเรียบร้อยแล้ว');
    }

    public function attendance(Request $request, AttendanceReportService $service): JsonResponse
    {
        $user = $this->authenticated();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->success('fetch attendance report', $service->build($user, $request->only(['tambon', 'level', 'class_group', 'student_id', 'search'])));
    }

    public function tambons(Request $request): JsonResponse
    {
        if ($request->isMethod('get')) {
            return $this->success('fetch tambons', Tambon::query()->orderBy('id')->get()->all());
        }
        $user = $this->authenticated();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if (! $user->isDistrict()) {
            return $this->error('Unauthorized access');
        }
        if ($request->isMethod('post')) {
            $name = trim((string) $request->input('name'));
            if ($name === '') {
                return $this->error('กรุณากรอกชื่อกลุ่มเรียน');
            }
            $ids = Tambon::query()->pluck('id');
            $numeric = $ids->filter(fn ($id) => ctype_digit((string) $id))->map(fn ($id) => (int) $id);
            $legacy = $ids->map(fn ($id) => preg_match('/^t(\d+)$/i', (string) $id, $match) ? (int) $match[1] : null)->filter();
            $id = $numeric->isNotEmpty() ? (string) ($numeric->max() + 1) : 't'.str_pad((string) (($legacy->max() ?? 0) + 1), 2, '0', STR_PAD_LEFT);
            Tambon::create(['id' => $id, 'name' => mb_substr($name, 0, 100), 'icon' => mb_substr((string) ($request->input('icon') ?: '📍'), 0, 20)]);

            return $this->success('เพิ่มกลุ่มเรียนเรียบร้อยแล้ว');
        }
        $tambon = Tambon::query()->find((string) ($request->query('id') ?: $request->input('id')));
        if (! $tambon) {
            return $this->error('ไม่พบตำบล');
        }
        if ($request->isMethod('put')) {
            $name = trim((string) $request->input('name'));
            if ($name === '') {
                return $this->error('ข้อมูลไม่ครบถ้วน');
            }
            $tambon->update(['name' => mb_substr($name, 0, 100), 'icon' => mb_substr((string) ($request->input('icon') ?: '📍'), 0, 20)]);

            return $this->success('แก้ไขข้อมูลเรียบร้อยแล้ว');
        }
        if (User::where('tambon', $tambon->id)->exists() || TeachingRecord::where('tambon', $tambon->id)->exists()) {
            return $this->error('ไม่สามารถลบได้ เนื่องจากกลุ่มเรียนนี้ยังถูกใช้งานอยู่');
        }
        $tambon->delete();

        return $this->success('ลบกลุ่มเรียนเรียบร้อยแล้ว');
    }

    public function users(Request $request): JsonResponse
    {
        $actor = $this->authenticated();
        if ($actor instanceof JsonResponse) {
            return $actor;
        }
        if (! $actor->isDistrict()) {
            return $this->error('Unauthorized access');
        }
        if ($request->isMethod('get')) {
            $last = TeachingRecord::query()->selectRaw('created_by, MAX(created_at) as last_record')->groupBy('created_by')->pluck('last_record', 'created_by');

            return $this->success('fetch users', User::query()->orderBy('role')->orderBy('tambon')->get()->map(fn ($user) => [...$this->userPayload($user), 'last_record' => $last[$user->id] ?? null])->all());
        }
        $id = (string) ($request->input('id') ?: $request->query('id'));
        if ($request->isMethod('delete')) {
            $user = User::find($id);
            if (! $user) {
                return $this->error('ไม่พบบัญชีผู้ใช้');
            }
            if ($user->id === $actor->id || $user->isDistrict()) {
                return $this->error('Cannot delete this account');
            }
            $user->delete();

            return $this->success('ลบผู้ดูแลเรียบร้อยแล้ว');
        }
        $isUpdate = $request->isMethod('put');
        $rules = [
            'name' => ['required', 'string', 'max:100'], 'username' => ['required', 'string', 'max:50'],
            'password' => [$isUpdate ? 'nullable' : 'required', 'string', 'min:6', 'max:255'],
            'role' => ['required', 'in:district,tambon'], 'tambon' => ['nullable', 'required_if:role,tambon', 'exists:tambons,id'],
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }
        $existingUsername = User::query()->where('username', $request->input('username'))->when($isUpdate, fn ($q) => $q->where('id', '<>', $id))->exists();
        if ($existingUsername) {
            return $this->error('ชื่อผู้ใช้นี้มีอยู่แล้ว');
        }
        $values = ['name' => $request->input('name'), 'username' => $request->input('username'), 'role' => $request->input('role'), 'tambon' => $request->input('role') === 'district' ? null : $request->input('tambon')];
        if ($request->filled('password')) {
            $values['password'] = Hash::make((string) $request->input('password'));
        }
        if ($isUpdate) {
            $user = User::find($id);
            if (! $user) {
                return $this->error('ไม่พบบัญชีผู้ใช้ที่ต้องการแก้ไข');
            }
            if ($user->id === $actor->id && $values['role'] !== 'district' && User::where('role', 'district')->count() <= 1) {
                return $this->error('บัญชีผู้ดูแลอำเภอหลักต้องคงสิทธิ์ไว้');
            }
            $user->update($values);

            return $this->success('แก้ไขข้อมูลเรียบร้อยแล้ว');
        }
        User::create(['id' => 'u'.Str::lower(Str::random(19)), ...$values]);

        return $this->success('เพิ่มผู้ดูแลเรียบร้อยแล้ว');
    }

    public function settings(Request $request): JsonResponse
    {
        $user = $this->authenticated();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if ($request->isMethod('get')) {
            $settings = Setting::query()->pluck('setting_value', 'setting_key')->all();
            if (! $user->isDistrict()) {
                foreach (self::SIG1_KEYS as $key) {
                    $tambonKey = 'tambon:'.$user->tambon.':'.$key;
                    $userKey = 'user:'.$user->id.':'.$key;
                    if (array_key_exists($tambonKey, $settings)) {
                        $settings[$key] = $settings[$tambonKey];
                    }
                    if (array_key_exists($userKey, $settings)) {
                        $settings[$key] = $settings[$userKey];
                    }
                }
                if (! array_key_exists('user:'.$user->id.':sig1_name', $settings) && ! array_key_exists('tambon:'.$user->tambon.':sig1_name', $settings)) {
                    $settings['sig1_name'] = $user->name;
                }
            }
            $settings = array_filter($settings, fn ($value, $key) => ! str_starts_with($key, 'user:') && ! str_starts_with($key, 'tambon:'), ARRAY_FILTER_USE_BOTH);

            return $this->success('fetch settings', $settings);
        }
        $data = $request->all();
        if (! $user->isDistrict()) {
            $data = collect($data)->only(self::SIG1_KEYS)->mapWithKeys(fn ($value, $key) => ['user:'.$user->id.':'.$key => $value])->all();
            if ($data === []) {
                return $this->error('ไม่มีข้อมูลที่อนุญาตให้บันทึก');
            }
        }
        foreach ($data as $key => $value) {
            if (mb_strlen((string) $key) > 100 || mb_strlen((string) $value) > 10000 || ($key === 'app_logo_path' && ! $user->isDistrict())) {
                return $this->error('ข้อมูลการตั้งค่าไม่ถูกต้องหรือมีขนาดเกินกำหนด');
            }
            Setting::query()->updateOrCreate(['setting_key' => $key], ['setting_value' => (string) $value]);
        }
        Cache::forget('pdf:settings');
        Cache::forget('pdf:settings:v2');

        return $this->success('บันทึกการตั้งค่าเรียบร้อยแล้ว');
    }

    public function profile(Request $request): JsonResponse
    {
        $user = $this->authenticated();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if ($request->isMethod('get')) {
            return $this->success('fetch profile', $this->userPayload($user));
        }
        if (! $request->hasFile('image')) {
            return $this->error('Invalid request');
        }
        $path = $this->storeUpload($request, 3072, 1200, 1200);
        if ($path instanceof JsonResponse) {
            return $path;
        }
        $user->update(['avatar_path' => $path]);

        return $this->success('อัปเดตรูปโปรไฟล์แล้ว', ['user' => $this->userPayload($user->fresh())]);
    }

    public function upload(Request $request): JsonResponse
    {
        $user = $this->authenticated();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if (! $request->isMethod('post') || ! $request->hasFile('image')) {
            return $this->error('Invalid request');
        }
        $path = $this->storeUpload($request, 5120, 5000, 5000);

        return $path instanceof JsonResponse ? $path : $this->success('uploaded', ['path' => $path]);
    }

    public function logo(Request $request): JsonResponse
    {
        $user = $this->authenticated();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if (! $user->isDistrict()) {
            return $this->error('Unauthorized access');
        }
        if ($request->isMethod('get')) {
            return $this->success('fetch logo', ['path' => Setting::whereKey('app_logo_path')->value('setting_value')]);
        }
        if (! $request->hasFile('image')) {
            return $this->error('Invalid request');
        }
        $path = $this->storeUpload($request, 4096, 2400, 1200);
        if ($path instanceof JsonResponse) {
            return $path;
        }
        Setting::updateOrCreate(['setting_key' => 'app_logo_path'], ['setting_value' => $path]);

        return $this->success('อัปเดตโลโก้แล้ว', ['path' => $path]);
    }

    public function studentApi(Request $request, StudentDirectoryController $controller): JsonResponse
    {
        $user = $this->authenticated();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $payload = $controller->legacyPayload($request, $user);

        return $payload['error'] ? $this->error($payload['error']) : $this->success('fetch student api', ['items' => $payload['items'], 'meta' => $payload['meta']]);
    }

    public function downloadPdf(Request $request, RecordPdfService $pdf): Response
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthorized');
        }
        $record = TeachingRecord::query()->visibleTo($user)->find($request->query('id'));
        if (! $record) {
            return $this->error('Not found');
        }
        $filename = 'บันทึกหลังสอน_สัปดาห์_'.$record->week.'_'.$record->id.'.pdf';

        return response($pdf->renderOne($record), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename*=UTF-8''".rawurlencode($filename), 'Cache-Control' => 'private, no-store']);
    }

    public function exportDoc(Request $request, LegacyDocxService $docx): Response
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthorized');
        }
        $id = trim(explode(',', (string) ($request->query('ids') ?: $request->query('id')))[0] ?? '');
        $record = TeachingRecord::query()->visibleTo($user)->find($id);
        if (! $record) {
            return response('ไม่พบรายการบันทึกสำหรับส่งออก', 404);
        }
        $filename = 'บันทึกหลังสอน_สัปดาห์_'.$record->week.'_'.$record->id.'.docx';

        return response($docx->render($record, $user), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'Content-Disposition' => "attachment; filename*=UTF-8''".rawurlencode($filename), 'Cache-Control' => 'private, no-store']);
    }

    public function exportBundle(Request $request, RecordPdfService $pdf, LegacyDocxService $docx): Response
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthorized');
        }
        $ids = collect(explode(',', (string) $request->input('ids', $request->query('ids', ''))))
            ->map(fn ($id) => trim($id))->filter(fn ($id) => preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id))->unique()->values();
        if ($ids->count() < 2) {
            return response('เลือกมากกว่า 1 รายการเพื่อดาวน์โหลดเป็น zip', 400);
        }
        $records = TeachingRecord::query()->visibleTo($user)->whereIn('id', $ids)->get()->keyBy('id');
        if ($records->count() !== $ids->count()) {
            return response('บางรายการไม่มีอยู่หรือไม่มีสิทธิ์เข้าถึง', 403);
        }
        $format = strtolower((string) $request->input('format', $request->query('format', 'docx')));
        $format = $format === 'doc' ? 'docx' : $format;
        if (! in_array($format, ['pdf', 'docx'], true)) {
            return response('ชนิดไฟล์ไม่ถูกต้อง', 400);
        }
        $temp = tempnam(sys_get_temp_dir(), 'sena-legacy-bundle-');
        $zip = new ZipArchive;
        if ($temp === false || $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return response('สร้างไฟล์ zip ไม่สำเร็จ', 500);
        }
        foreach ($ids as $index => $id) {
            $record = $records[$id];
            $name = sprintf('%02d_week-%02d_%s.%s', $index + 1, $record->week, $record->id, $format);
            $zip->addFromString($name, $format === 'pdf' ? $pdf->renderOne($record) : $docx->render($record, $user));
        }
        $zip->close();

        return response()->download($temp, 'บันทึกหลังสอน_'.strtoupper($format).'_'.now()->format('Y-m-d').'.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }

    public function printHtml(Request $request, LegacyPrintService $print): Response
    {
        $user = $request->user();
        if (! $user && $request->filled('access_token')) {
            try {
                $payload = json_decode(Crypt::decryptString((string) $request->query('access_token')), true, 512, JSON_THROW_ON_ERROR);
                if (($payload['scope'] ?? '') === 'print' && (int) ($payload['expires'] ?? 0) >= time()) {
                    $user = User::find($payload['user_id'] ?? '');
                }
            } catch (\Throwable) {
                $user = null;
            }
        }
        if (! $user) {
            return response('Unauthorized', 401);
        }
        $record = TeachingRecord::query()->visibleTo($user)->find($request->query('id'));
        if (! $record) {
            return response('ไม่พบข้อมูลบันทึก', 404);
        }

        return response($print->html($record, $user, $request->boolean('hide_attendance')), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function health(Request $request): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            $legacyStudentApi = is_file(base_path('../config/student_api.php'))
                ? require base_path('../config/student_api.php')
                : [];
            $studentApiConfigured = trim((string) env('SENA_STUDENT_API_URL', $legacyStudentApi['url'] ?? '')) !== ''
                && trim((string) env('SENA_STUDENT_API_KEY', $legacyStudentApi['key'] ?? '')) !== '';

            return $this->success('server ready', [
                'database' => 'connected',
                'records' => TeachingRecord::count(),
                'attendance' => RecordAttendance::count(),
                'images' => RecordImage::count(),
                'student_api_configured' => $studentApiConfigured,
            ]);
        } catch (\Throwable $exception) {
            return $this->error('Database unavailable');
        }
    }

    private function authenticated(): User|JsonResponse
    {
        return Auth::user() ?: $this->error('Unauthorized');
    }

    private function success(string $message, mixed $data = null): JsonResponse
    {
        $payload = ['status' => 'success', 'message' => $message];
        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function error(string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function userPayload(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'username' => $user->username, 'role' => $user->role, 'tambon' => $user->tambon, 'avatar_path' => $user->avatar_path];
    }

    private function recordPayload(TeachingRecord $record): array
    {
        $payload = $record->attributesToArray();
        $payload['date'] = $record->date?->format('Y-m-d');
        $payload['supervisorComment'] = $record->supervisor_comment;

        return $payload;
    }

    private function passwordMatches(string $plain, string $stored): bool
    {
        return (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon')) ? Hash::check($plain, $stored) : hash_equals($stored, $plain);
    }

    private function printToken(User $user): string
    {
        return Crypt::encryptString(json_encode(['user_id' => $user->id, 'scope' => 'print', 'expires' => time() + 1800], JSON_THROW_ON_ERROR));
    }

    private function normalizeAttendance(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = mb_substr(trim((string) ($row['student_id'] ?? $row['id'] ?? '')), 0, 50);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $present = ! empty($row['present']);
            $result[] = ['student_id' => $id, 'full_name' => mb_substr(trim((string) ($row['full_name'] ?? $row['name'] ?? $id)), 0, 255), 'group_code' => mb_substr(trim((string) ($row['group_code'] ?? '')), 0, 50), 'class_name' => mb_substr(trim((string) ($row['class_name'] ?? '')), 0, 255), 'present' => $present ? 1 : 0, 'learning_method' => $present ? (($row['learning_method'] ?? '') === 'ออนไลน์' ? 'ออนไลน์' : 'พบกลุ่ม') : null];
            $seen[$id] = true;
        }

        return $result;
    }

    private function normalizeImages(mixed $images): array
    {
        if (! is_array($images)) {
            return [];
        }
        $result = [];
        foreach ($images as $image) {
            $value = is_array($image) ? (string) ($image['data'] ?? '') : (string) $image;
            if (str_starts_with($value, 'data:image/')) {
                $stored = $this->storeBase64Image($value);
                if ($stored) {
                    $result[] = $stored;
                }
            } elseif ($this->managedFileExists($value)) {
                $result[] = ltrim($value, '/');
            }
        }

        return $result;
    }

    private function replaceRecordChildren(TeachingRecord $record, array $images, array $attendance): void
    {
        $record->images()->delete();
        foreach ($images as $path) {
            RecordImage::create(['record_id' => $record->id, 'file_path' => $path, 'created_at' => now()]);
        }
        $record->attendance()->delete();
        foreach ($attendance as $row) {
            RecordAttendance::create(['record_id' => $record->id, ...$row, 'created_at' => now()]);
        }
    }

    private function managedFileExists(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($path, 'uploads/')) {
            return is_file(dirname(base_path()).'/'.$path);
        }
        if (str_starts_with($path, 'storage/')) {
            return is_file(storage_path('app/public/'.substr($path, 8)));
        }

        return false;
    }

    private function storeBase64Image(string $data): ?string
    {
        if (! preg_match('#^data:image/(jpeg|jpg|png|gif|webp);base64,(.+)$#s', $data, $match)) {
            return null;
        }
        $binary = base64_decode($match[2], true);
        if ($binary === false || strlen($binary) > 5 * 1024 * 1024 || @getimagesizefromstring($binary) === false) {
            return null;
        }
        $extension = $match[1] === 'jpeg' ? 'jpg' : $match[1];
        $directory = dirname(base_path()).'/uploads';
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $name = bin2hex(random_bytes(18)).'.'.$extension;

        return file_put_contents($directory.'/'.$name, $binary, LOCK_EX) !== false ? 'uploads/'.$name : null;
    }

    private function storeUpload(Request $request, int $maxKb, int $maxWidth, int $maxHeight): string|JsonResponse
    {
        $validator = Validator::make($request->all(), ['image' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:'.$maxKb, "dimensions:max_width={$maxWidth},max_height={$maxHeight}"]]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }
        $file = $request->file('image');
        $name = bin2hex(random_bytes(18)).'.'.strtolower($file->extension());
        $directory = dirname(base_path()).'/uploads';
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $file->move($directory, $name);

        return 'uploads/'.$name;
    }
}
