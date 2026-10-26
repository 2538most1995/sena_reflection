<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\StudentApiClient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only Reflection integration; does not expose teacher authentication. */
final class ReflectionTeacherController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_if(filled($request->headers->get('Origin')), 403);
        abort_if($request->hasHeader('X-District-Id') || $request->query->has('district_id'), 422);

        $user = $request->user();
        $districtId = (int) $request->attributes->get('district_id', 0);
        abort_unless(
            $user instanceof User
            && in_array($user->role, ['admin', 'super_admin'], true)
            && $user->disabled_at === null
            && $districtId > 0
            && ($user->role === 'super_admin' || (int) $user->district_id === $districtId),
            401,
        );

        $plainToken = trim((string) $request->bearerToken());
        if ($plainToken === '') {
            $plainToken = trim((string) $request->headers->get('X-Student-Data-Token'));
        }
        abort_if($plainToken === '', 401);

        // Recheck the persisted credential and scope. Older middleware versions
        // do not attach the client model to the request.
        $client = StudentApiClient::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->where('user_id', $user->getKey())
            ->where('district_id', $districtId)
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
        abort_unless($client !== null, 401);

        $abilities = $client->abilities;
        if (is_string($abilities)) {
            $abilities = json_decode($abilities, true);
        }
        abort_unless(
            is_array($abilities)
            && in_array('student-data:read', $abilities, true)
            && in_array('sena-care:teachers', $abilities, true),
            403,
        );
        abort_unless(District::query()->whereKey($districtId)->where('is_active', true)->exists(), 401);

        $filters = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $teachers = User::query()
            ->where('district_id', $districtId)
            ->where('role', 'teacher')
            ->whereNull('disabled_at')
            ->orderBy('id')
            ->paginate(
                (int) ($filters['per_page'] ?? 100),
                ['id', 'username', 'name', 'assigned_groups'],
                'page',
                (int) ($filters['page'] ?? 1),
            );

        return response()->json([
            'data' => $teachers->getCollection()->map(function (User $teacher): array {
                $groups = $teacher->assigned_groups;
                if (is_string($groups)) {
                    $groups = json_decode($groups, true);
                }

                return [
                    'username' => trim((string) $teacher->username),
                    'name' => trim((string) $teacher->name),
                    'assigned_groups' => array_values(array_unique(array_map(
                        'trim',
                        array_filter(is_array($groups) ? $groups : [], fn ($group) => is_string($group) && trim($group) !== ''),
                    ))),
                ];
            })->values()->all(),
            'meta' => ['pagination' => [
                'current_page' => $teachers->currentPage(),
                'per_page' => $teachers->perPage(),
                'total' => $teachers->total(),
                'last_page' => $teachers->lastPage(),
            ]],
        ])->header('Cache-Control', 'private, no-store')
            ->header('Vary', 'Authorization, X-Student-Data-Token');
    }
}
