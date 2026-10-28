<?php

namespace App\Http\Controllers;

use App\Models\Tambon;
use App\Services\ActiveAcademicTermService;
use App\Services\AttendanceReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceAnalysisController extends Controller
{
    public function __invoke(Request $request, AttendanceReportService $service, ActiveAcademicTermService $activeTerms): Response
    {
        $filters = $request->validate([
            'tambon' => ['nullable', 'string', 'max:20'],
            'level' => ['nullable', 'string', 'max:50'],
            'class_group' => ['nullable', 'string', 'max:255'],
            'student_id' => ['nullable', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'regex:/^25[0-9]{2}$/D'],
            'semester' => ['nullable', 'in:1,2,3'],
            'scope' => ['nullable', 'in:current,selected,all'],
        ]);
        $activeTerm = $activeTerms->resolve();
        if (($filters['scope'] ?? null) === 'all') {
            unset($filters['year'], $filters['semester']);
        } elseif (! $request->filled('year') && ! $request->filled('semester')) {
            $filters = [...$filters, 'year' => $activeTerm['year'], 'semester' => $activeTerm['semester'], 'scope' => 'current'];
        } else {
            $filters['scope'] = ($filters['year'] ?? null) === $activeTerm['year'] && ($filters['semester'] ?? null) === $activeTerm['semester'] && ($filters['scope'] ?? null) === 'current' ? 'current' : 'selected';
        }
        $user = $request->user();

        return Inertia::render('attendance/index', [
            'report' => $service->build($user, $filters),
            'filters' => $filters,
            'activeTerm' => $activeTerm,
            'tambonOptions' => $user->isDistrict() ? Tambon::query()->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }
}
