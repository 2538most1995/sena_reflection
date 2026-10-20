<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Tambon;
use App\Models\TeachingRecord;
use App\Models\User;
use App\Services\LegacyPrintService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    private const SETTING_KEYS = [
        'sig1_name', 'sig1_pos', 'sig1_align', 'sig1_offset', 'sig1_offset_y',
        'sig2_title', 'sig2_name', 'sig2_pos', 'sig2_align', 'sig2_offset', 'sig2_offset_y',
        'sig3_title', 'sig3_name', 'sig3_pos', 'sig3_align', 'sig3_offset', 'sig3_offset_y',
        'pdf_font_size_pt', 'pdf_line_spacing',
        'pdf_margin_top_px', 'pdf_margin_right_px', 'pdf_margin_bottom_px', 'pdf_margin_left_px',
        'show_image_page', 'show_attendance_page',
        'image_width_px', 'image_height_px', 'image_offset_x', 'image_offset_y', 'image_gap_px',
        'image_1_width_px', 'image_1_height_px', 'image_1_offset_x', 'image_1_offset_y',
        'image_2_width_px', 'image_2_height_px', 'image_2_offset_x', 'image_2_offset_y',
        'image_3_width_px', 'image_3_height_px', 'image_3_offset_x', 'image_3_offset_y',
        'image_4_width_px', 'image_4_height_px', 'image_4_offset_x', 'image_4_offset_y',
    ];

    private const NUMERIC_RANGES = [
        'sig1_offset' => [-400, 400], 'sig1_offset_y' => [-400, 400],
        'sig2_offset' => [-400, 400], 'sig2_offset_y' => [-400, 400],
        'sig3_offset' => [-400, 400], 'sig3_offset_y' => [-400, 400],
        'pdf_font_size_pt' => [10, 20], 'pdf_line_spacing' => [16, 44],
        'pdf_margin_top_px' => [0, 180], 'pdf_margin_right_px' => [0, 180],
        'pdf_margin_bottom_px' => [0, 180], 'pdf_margin_left_px' => [0, 180],
        'image_width_px' => [120, 760], 'image_height_px' => [100, 700],
        'image_offset_x' => [-400, 400], 'image_offset_y' => [-400, 400], 'image_gap_px' => [0, 100],
        'image_1_width_px' => [180, 620], 'image_1_height_px' => [120, 560], 'image_1_offset_x' => [-220, 220], 'image_1_offset_y' => [-220, 220],
        'image_2_width_px' => [180, 620], 'image_2_height_px' => [120, 560], 'image_2_offset_x' => [-220, 220], 'image_2_offset_y' => [-220, 220],
        'image_3_width_px' => [180, 620], 'image_3_height_px' => [120, 560], 'image_3_offset_x' => [-220, 220], 'image_3_offset_y' => [-220, 220],
        'image_4_width_px' => [180, 620], 'image_4_height_px' => [120, 560], 'image_4_offset_x' => [-220, 220], 'image_4_offset_y' => [-220, 220],
    ];

    private const SETTING_DEFAULTS = [
        'sig1_name' => '', 'sig1_pos' => 'ครูผู้สอน', 'sig1_align' => 'flex-end', 'sig1_offset' => '0', 'sig1_offset_y' => '0',
        'sig2_title' => 'ความคิดเห็นของหัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ', 'sig2_name' => '', 'sig2_pos' => 'หัวหน้างานการจัดการเรียนรู้คุณวุฒิตามระดับ', 'sig2_align' => 'flex-end', 'sig2_offset' => '0', 'sig2_offset_y' => '0',
        'sig3_title' => 'ข้อคิดเห็นของผู้อำนวยการสถานศึกษา', 'sig3_name' => '', 'sig3_pos' => 'ผู้อำนวยการสถานศึกษา', 'sig3_align' => 'center', 'sig3_offset' => '0', 'sig3_offset_y' => '0',
        'pdf_font_size_pt' => '16', 'pdf_line_spacing' => '26',
        'pdf_margin_top_px' => '57', 'pdf_margin_right_px' => '76', 'pdf_margin_bottom_px' => '38', 'pdf_margin_left_px' => '94',
        'show_image_page' => '1', 'show_attendance_page' => '1',
        'image_width_px' => '300', 'image_height_px' => '225', 'image_offset_x' => '0', 'image_offset_y' => '0', 'image_gap_px' => '16',
        'image_1_width_px' => '300', 'image_1_height_px' => '225', 'image_1_offset_x' => '0', 'image_1_offset_y' => '0',
        'image_2_width_px' => '300', 'image_2_height_px' => '225', 'image_2_offset_x' => '0', 'image_2_offset_y' => '0',
        'image_3_width_px' => '300', 'image_3_height_px' => '225', 'image_3_offset_x' => '0', 'image_3_offset_y' => '0',
        'image_4_width_px' => '300', 'image_4_height_px' => '225', 'image_4_offset_x' => '0', 'image_4_offset_y' => '0',
    ];

    public function index(Request $request): Response
    {
        $this->district($request);
        $users = User::query()->orderBy('role')->orderBy('tambon')->get();
        $tambons = Tambon::query()->orderBy('id')->get();
        $settings = Setting::query()->whereIn('setting_key', self::SETTING_KEYS)->pluck('setting_value', 'setting_key');

        return Inertia::render('admin/index', compact('users', 'tambons', 'settings'));
    }

    public function storeUser(Request $request): RedirectResponse
    {
        $this->district($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'username' => ['required', 'string', 'max:50', 'unique:users,username'], 'password' => ['required', 'string', 'min:8', 'max:255'], 'role' => ['required', 'in:district,tambon'], 'tambon' => ['nullable', 'required_if:role,tambon', 'exists:tambons,id']]);
        User::create(['id' => 'u'.substr(str_replace('-', '', (string) Str::uuid()), 0, 19), ...$data, 'tambon' => $data['role'] === 'district' ? null : $data['tambon'], 'password' => Hash::make($data['password'])]);

        return back()->with('success', 'เพิ่มผู้ใช้แล้ว');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $this->district($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'username' => ['required', 'string', 'max:50', Rule::unique('users')->ignore($user->id, 'id')], 'password' => ['nullable', 'string', 'min:8', 'max:255'], 'role' => ['required', 'in:district,tambon'], 'tambon' => ['nullable', 'required_if:role,tambon', 'exists:tambons,id']]);
        if ($user->id === $request->user()->id && $data['role'] !== 'district' && User::where('role', 'district')->count() <= 1) {
            return back()->withErrors(['role' => 'ต้องมีผู้ดูแลระดับอำเภออย่างน้อย 1 บัญชี']);
        }
        $user->fill(['name' => $data['name'], 'username' => $data['username'], 'role' => $data['role'], 'tambon' => $data['role'] === 'district' ? null : $data['tambon']]);
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return back()->with('success', 'แก้ไขผู้ใช้แล้ว');
    }

    public function destroyUser(Request $request, User $user): RedirectResponse
    {
        $this->district($request);
        if ($user->id === $request->user()->id || $user->role === 'district') {
            return back()->withErrors(['user' => 'ไม่สามารถลบบัญชีนี้ได้']);
        }
        $user->delete();

        return back()->with('success', 'ลบผู้ใช้แล้ว');
    }

    public function storeTambon(Request $request): RedirectResponse
    {
        $this->district($request);
        $data = $request->validate(['id' => ['required', 'string', 'max:20', 'unique:tambons,id'], 'name' => ['required', 'string', 'max:100'], 'icon' => ['nullable', 'string', 'max:20']]);
        Tambon::create($data);

        return back()->with('success', 'เพิ่มตำบลแล้ว');
    }

    public function updateTambon(Request $request, Tambon $tambon): RedirectResponse
    {
        $this->district($request);
        $tambon->update($request->validate(['name' => ['required', 'string', 'max:100'], 'icon' => ['nullable', 'string', 'max:20']]));

        return back()->with('success', 'แก้ไขตำบลแล้ว');
    }

    public function destroyTambon(Request $request, Tambon $tambon): RedirectResponse
    {
        $this->district($request);
        if (User::where('tambon', $tambon->id)->exists() || TeachingRecord::where('tambon', $tambon->id)->exists()) {
            return back()->withErrors(['tambon' => 'ตำบลนี้ยังมีผู้ใช้หรือบันทึก จึงลบไม่ได้']);
        } $tambon->delete();

        return back()->with('success', 'ลบตำบลแล้ว');
    }

    public function settings(Request $request): RedirectResponse
    {
        $this->district($request);
        foreach ($this->validatedSettings($request) as $key => $value) {
            Setting::query()->updateOrCreate(['setting_key' => $key], ['setting_value' => $value]);
        }
        Cache::forget('pdf:settings');
        Cache::forget('pdf:settings:v2');

        return back()->with('success', 'บันทึกการตั้งค่าแล้ว');
    }

    public function preview(Request $request, LegacyPrintService $print): \Symfony\Component\HttpFoundation\Response
    {
        $this->district($request);

        return response($print->settingsPreviewHtml($request->user(), $this->validatedSettings($request)), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, string> */
    private function validatedSettings(Request $request): array
    {
        $data = $request->validate(['settings' => ['required', 'array'], 'settings.*' => ['nullable', 'string', 'max:10000']]);
        $submitted = collect($data['settings'])->only(self::SETTING_KEYS)->map(fn ($value) => (string) ($value ?? ''))->all();
        $stored = Setting::query()->whereIn('setting_key', self::SETTING_KEYS)->pluck('setting_value', 'setting_key')->all();
        $settings = array_merge(self::SETTING_DEFAULTS, $stored, $submitted);

        foreach (self::NUMERIC_RANGES as $key => [$minimum, $maximum]) {
            if (! array_key_exists($key, $settings) || filter_var($settings[$key], FILTER_VALIDATE_INT) === false) {
                throw ValidationException::withMessages(["settings.{$key}" => 'กรุณากรอกจำนวนเต็มที่ถูกต้อง']);
            }
            $number = (int) $settings[$key];
            if ($number < $minimum || $number > $maximum) {
                throw ValidationException::withMessages(["settings.{$key}" => "ค่าต้องอยู่ระหว่าง {$minimum} ถึง {$maximum}"]);
            }
        }

        foreach (['sig1_align', 'sig2_align', 'sig3_align'] as $key) {
            if (! in_array($settings[$key] ?? '', ['flex-start', 'center', 'flex-end'], true)) {
                throw ValidationException::withMessages(["settings.{$key}" => 'ค่าการจัดแนวไม่ถูกต้อง']);
            }
        }

        foreach (['show_image_page', 'show_attendance_page'] as $key) {
            if (! in_array($settings[$key] ?? '', ['0', '1'], true)) {
                throw ValidationException::withMessages(["settings.{$key}" => 'ค่าการเปิดหรือปิดหน้าเอกสารไม่ถูกต้อง']);
            }
        }

        return $settings;
    }

    private function district(Request $request): void
    {
        abort_unless($request->user()->isDistrict(), 403);
    }
}
