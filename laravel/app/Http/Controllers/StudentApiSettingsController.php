<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStudentApiSettingsRequest;
use App\Services\StudentApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StudentApiSettingsController extends Controller
{
    public function index(Request $request, StudentApiService $api): Response
    {
        abort_unless($request->user()?->isDistrict(), 403);

        return Inertia::render('admin/student-api', ['integration' => $api->status()]);
    }

    public function store(UpdateStudentApiSettingsRequest $request, StudentApiService $api): JsonResponse
    {
        return $this->verify($request, $api, true);
    }

    public function test(UpdateStudentApiSettingsRequest $request, StudentApiService $api): JsonResponse
    {
        return $this->verify($request, $api, false);
    }

    private function verify(UpdateStudentApiSettingsRequest $request, StudentApiService $api, bool $save): JsonResponse
    {
        try {
            $token = trim((string) ($request->validated('token') ?? '')) ?: null;
            $data = $save ? $api->saveToken($token) : $api->probe($token);

            return response()->json(['status' => 'success', 'message' => $save ? 'บันทึกการเชื่อมต่อ SDL School แล้ว' : 'ทดสอบการเชื่อมต่อ SDL School สำเร็จ', 'data' => $data]);
        } catch (\Throwable $exception) {
            Log::warning('SDL School connection verification failed', ['exception' => $exception::class]);
            throw ValidationException::withMessages(['token' => 'เชื่อมต่อ SDL School ไม่สำเร็จ กรุณาตรวจสอบ token ที่ยังใช้งานได้และสิทธิ์ student-data:read กับ sena-care:teachers']);
        }
    }
}
