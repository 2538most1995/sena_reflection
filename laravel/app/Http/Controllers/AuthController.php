<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = User::query()->where('username', $credentials['username'])->first();
        $stored = (string) ($user?->password ?? '');
        $isHash = str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon');
        $valid = $user && ($isHash
            ? Hash::check($credentials['password'], $stored)
            : hash_equals($stored, $credentials['password']));

        if (! $valid) {
            return back()->withErrors(['username' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'])->onlyInput('username');
        }

        if (! $isHash || Hash::needsRehash($stored)) {
            $user->forceFill(['password' => Hash::make($credentials['password'])])->save();
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
