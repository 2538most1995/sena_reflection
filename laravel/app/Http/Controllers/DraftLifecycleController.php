<?php

namespace App\Http\Controllers;

use App\Models\TeachingDraft;
use App\Services\DraftLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DraftLifecycleController extends Controller
{
    public function index(Request $request, DraftLifecycleService $lifecycle): Response|JsonResponse
    {
        $data = ['drafts' => $lifecycle->summaries($request->user())];

        return $request->expectsJson() ? response()->json($data) : Inertia::render('drafts/manage', $data);
    }

    public function pause(Request $request, TeachingDraft $teachingDraft, DraftLifecycleService $lifecycle): JsonResponse
    {
        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $draft = $lifecycle->pause($request->user(), $teachingDraft, (int) $validated['lock_version']);

        return response()->json(['draft' => $lifecycle->summary($draft)]);
    }

    public function resume(Request $request, TeachingDraft $teachingDraft, DraftLifecycleService $lifecycle): JsonResponse
    {
        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);
        $draft = $lifecycle->resume($request->user(), $teachingDraft, (int) $validated['lock_version']);

        return response()->json(['draft' => $lifecycle->summary($draft)]);
    }
}
