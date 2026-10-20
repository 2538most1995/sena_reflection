<?php

namespace App\Http\Controllers;

use App\Models\Tambon;
use App\Services\AttendanceReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceAnalysisController extends Controller
{
    public function __invoke(Request $request, AttendanceReportService $service): Response
    {
        $filters = $request->validate([
            'tambon' => ['nullable', 'string', 'max:20'],
            'level' => ['nullable', 'string', 'max:50'],
            'class_group' => ['nullable', 'string', 'max:255'],
            'student_id' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $user = $request->user();

        return Inertia::render('attendance/index', [
            'report' => $service->build($user, $filters),
            'filters' => $filters,
            'tambonOptions' => $user->isDistrict() ? Tambon::query()->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }
}
