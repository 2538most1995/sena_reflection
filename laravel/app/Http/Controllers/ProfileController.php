<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('profile/edit', ['user' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'], 'current_password' => ['nullable', 'required_with:password', 'string'], 'password' => ['nullable', 'string', 'min:8', 'confirmed']]);
        $user = $request->user();
        if (! empty($data['password']) && ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง']);
        }
        $user->name = $data['name'];
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        if ($request->hasFile('avatar')) {
            $old = $user->avatar_path;
            $path = $request->file('avatar')->store('avatars', 'public');
            $user->avatar_path = 'storage/'.$path;
            if ($old && str_starts_with($old, 'storage/')) {
                Storage::disk('public')->delete(substr($old, 8));
            }
        }
        $user->save();

        return back()->with('success', 'อัปเดตโปรไฟล์แล้ว');
    }
}
