<?php

namespace App\Modules\Identity\Presentation;

use App\Models\User;
use App\Modules\Identity\Application\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class AuthController
{
    public function login(Request $r): mixed
    {
        $v = $r->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:255', 'device_name' => 'required|string|max:80']);
        $user = User::where('email', strtolower($v['email']))->first();
        // Always run password verification, including unknown accounts.
        $valid = Hash::check($v['password'], $user?->password ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (! $user || ! $valid || ! $user->active) {
            throw ValidationException::withMessages(['email' => 'Email atau kata sandi tidak valid.']);
        }

        return response()->json(['token' => $user->createToken($v['device_name'], ['*'], now()->addHours(8))->plainTextToken, 'user' => $user, 'expires_in' => 28800]);
    }

    public function password(Request $r): mixed
    {
        $v = $r->validate(['current_password' => 'required|string|max:255', 'password' => ['required', 'confirmed', 'string', Password::min(12)->mixedCase()->numbers()]]);
        if (! Hash::check($v['current_password'], $r->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'Kata sandi saat ini tidak sesuai.']);
        }
        DB::transaction(function () use ($r, $v) {
            $r->user()->forceFill(['password' => $v['password']])->save();
            $r->user()->tokens()->delete();
            Audit::record($r->user()->id, null, 'user.password_changed', (string) $r->user()->id, null, null);
        });

        return response()->noContent();
    }

    public function me(Request $r): mixed
    {
        return response()->json(['data' => $r->user()]);
    }

    public function logout(Request $r): mixed
    {
        $r->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }
}
