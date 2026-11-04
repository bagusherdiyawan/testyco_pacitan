<?php

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Application\AccountSecurity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

final class SecurityController
{
    public function __construct(private AccountSecurity $security) {}

    public function context(Request $r): mixed
    {
        return response()->json(['data' => ['enabled' => $this->security->enabled($r->user()->id), 'email_reset_enabled' => (bool) config('security.email_reset_enabled')]])->header('Cache-Control', 'private, no-store');
    }

    public function begin(Request $r): mixed
    {
        $v = $r->validate(['current_password' => 'required|string|max:255']);

        return response()->json(['data' => $this->security->begin($r->user(), $v['current_password'])])->header('Cache-Control', 'private, no-store');
    }

    public function enable(Request $r): mixed
    {
        $v = $r->validate(['version' => 'required|integer|min:1', 'code' => 'required|string|size:6']);
        $this->security->enable($r->user(), (int) $v['version'], $v['code']);

        return response()->noContent();
    }

    public function disable(Request $r): mixed
    {
        $v = $r->validate(['current_password' => 'required|string|max:255', 'code' => 'required|string|max:80']);
        $this->security->disable($r->user(), $v['current_password'], $v['code']);

        return response()->noContent();
    }

    public function login(Request $r): mixed
    {
        $v = $r->validate(['challenge' => 'required|string|size:64', 'code' => 'required|string|max:80']);

        return response()->json($this->security->login($v['challenge'], $v['code']))->header('Cache-Control', 'private, no-store');
    }

    public function forgot(Request $r): mixed
    {
        $v = $r->validate(['email' => 'required|email|max:255']);
        $this->security->forgot($v['email']);

        return response()->json(['message' => 'Jika akun memenuhi syarat, instruksi pemulihan dikirim.'], 202);
    }

    public function reset(Request $r): mixed
    {
        $v = $r->validate(['email' => 'required|email|max:255', 'token' => 'required|string|max:255', 'code' => 'nullable|string|max:80', 'password' => ['required', 'confirmed', 'string', Password::min(12)->mixedCase()->numbers()]]);
        $this->security->reset($v);

        return response()->noContent();
    }

    public function sessions(Request $r): mixed
    {
        $current = $r->user()->currentAccessToken()?->id;

        return response()->json(['data' => $r->user()->tokens()->orderByDesc('id')->get(['id', 'name', 'created_at', 'last_used_at', 'expires_at'])->map(fn ($s) => [...$s->toArray(), 'is_current' => $s->id === $current])])->header('Cache-Control', 'private, no-store');
    }

    public function revoke(Request $r, string $token): mixed
    {
        $r->user()->tokens()->whereKey((int) $token)->delete();

        return response()->noContent();
    }
}
