<?php

namespace App\Http\Middleware;

use App\Models\District;
use App\Models\StudentApiClient;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateStudentDataClient
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(
            filled($request->headers->get('Origin')),
            403,
            'API นี้รองรับการเรียกจาก backend แบบ server-to-server เท่านั้น',
        );

        abort_if(
            $request->hasHeader('X-District-Id') || $request->query->has('district_id'),
            422,
            'อำเภอถูกกำหนดจาก API token และไม่สามารถเปลี่ยนผ่าน request ได้',
        );

        $attemptKey = 'student-data-auth:'.hash('sha256', (string) $request->ip());
        $attemptLimit = (int) config('system_data.student_api_auth_rate_limit_per_minute', 20);
        if (RateLimiter::tooManyAttempts($attemptKey, $attemptLimit)) {
            return response()->json(
                ['message' => 'เรียก API บ่อยเกินไป กรุณาลองใหม่ภายหลัง'],
                429,
                ['Retry-After' => (string) RateLimiter::availableIn($attemptKey)],
            );
        }

        // Some managed hosting proxies do not forward Authorization to PHP-FPM.
        // Keep Bearer auth as the primary contract and accept a dedicated
        // server-to-server header as a transport-safe fallback.
        $plainToken = trim((string) $request->bearerToken());
        if ($plainToken === '') {
            $plainToken = trim((string) $request->headers->get('X-Student-Data-Token'));
        }
        if ($plainToken === '') {
            $this->rejectInvalidCredential($attemptKey);
        }

        // Non-expiring access is opt-in for reviewed client IDs only.
        $nonExpiringClientIds = config('reflection_integration.non_expiring_client_ids', []);
        $nonExpiringClientIds = is_array($nonExpiringClientIds)
            ? array_values(array_filter($nonExpiringClientIds, fn ($id): bool => is_int($id) && $id > 0))
            : [];

        $client = StudentApiClient::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('revoked_at')
            ->where(function ($query) use ($nonExpiringClientIds): void {
                $query->where('expires_at', '>', now());
                if ($nonExpiringClientIds !== []) {
                    $query->orWhere(fn ($allowed) => $allowed
                        ->whereNull('expires_at')
                        ->whereIn('id', $nonExpiringClientIds));
                }
            })
            ->first();
        if (! $client?->allows('student-data:read')) {
            $this->rejectInvalidCredential($attemptKey);
        }

        $user = User::query()
            ->whereKey($client->user_id)
            ->whereIn('role', ['admin', 'super_admin'])
            ->whereNull('disabled_at')
            ->first();
        if ($user === null || ($user->role === 'admin' && (int) $user->district_id !== (int) $client->district_id)) {
            $this->rejectInvalidCredential($attemptKey);
        }

        $districtExists = District::query()
            ->whereKey($client->district_id)
            ->where('is_active', true)
            ->exists();
        if (! $districtExists) {
            $this->rejectInvalidCredential($attemptKey);
        }

        $districtId = (int) $client->district_id;
        $user->setRelation('selectedDistrictContext', $districtId);
        $request->setUserResolver(static fn (): User => $user);
        $request->attributes->set('district_id', $districtId);
        $request->attributes->set('student_api_client_id', (int) $client->id);

        if ($client->last_used_at === null || $client->last_used_at->lt(now()->subMinutes(5))) {
            $client->forceFill(['last_used_at' => now()])->save();
        }

        return $next($request);
    }

    private function rejectInvalidCredential(string $attemptKey): never
    {
        RateLimiter::hit($attemptKey, 60);
        abort(401, 'ข้อมูลยืนยันตัวตนไม่ถูกต้อง');
    }
}
