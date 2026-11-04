<?php

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class SecurityController
{
    public function mfaForm(): mixed
    {
        abort_unless(session('mfa_challenge') && session('mfa_expires_at', 0) > time(), 403, 'Masuk kembali untuk mendapat tantangan baru.');

        return response()->view('pages.mfa-login')->header('Cache-Control', 'private, no-store');
    }

    public function mfa(Request $r): mixed
    {
        $v = $r->validate(['code' => 'required|string|max:80']);
        abort_unless(session('mfa_challenge') && session('mfa_expires_at', 0) > time(), 403);
        try {
            $response = Http::timeout(8)->acceptJson()->post(rtrim(config('backend.url'), '/').'/api/v1/auth/login/mfa', ['challenge' => session('mfa_challenge'), 'code' => $v['code']]);
        } catch (ConnectionException) {
            return back()->withErrors(['code' => 'Hasil login belum pasti. Coba masuk kembali bila tantangan tidak berlaku.']);
        }if (! $response->successful()) {
            return back()->withErrors(['code' => 'Kode/tantangan tidak valid atau layanan belum tersedia.']);
        }abort_unless(is_string($response->json('token')) && is_int($response->json('user.id')) && is_string($response->json('user.name')) && is_int($response->json('expires_in')) && $response->json('expires_in') > 0, 503);
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        $r->session()->put(['api_token' => $response->json('token'), 'api_user' => $response->json('user'), 'api_expires_at' => time() + (int) $response->json('expires_in')]);

        return redirect('/app');
    }

    public function forgotForm(): mixed
    {
        return response()->view('pages.forgot-password')->header('Cache-Control', 'private, no-store');
    }

    public function forgot(Request $r): mixed
    {
        $v = $r->validate(['email' => 'required|email|max:255']);
        try {
            $response = Http::timeout(8)->acceptJson()->post(rtrim(config('backend.url'), '/').'/api/v1/auth/password/forgot', $v);
        } catch (ConnectionException) {
            return back()->withErrors(['email' => 'Layanan pemulihan belum tersedia.']);
        }if (! $response->successful()) {
            return back()->withErrors(['email' => 'Layanan email belum tersedia atau terlalu banyak percobaan.']);
        }

        return back()->with('status', 'Jika akun memenuhi syarat, instruksi pemulihan dikirim.');
    }

    public function resetForm(): mixed
    {
        return response()->view('pages.reset-password')->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function reset(Request $r): mixed
    {
        $v = $r->validate(['email' => 'required|email|max:255', 'token' => 'required|string|max:255', 'password_confirmation' => 'required|string|max:255', 'code' => 'nullable|string|max:80', 'password' => ['required', 'confirmed', 'string', Password::min(12)->mixedCase()->numbers()]]);
        try {
            $response = Http::timeout(8)->acceptJson()->post(rtrim(config('backend.url'), '/').'/api/v1/auth/password/reset', $v);
        } catch (ConnectionException) {
            return back()->withErrors(['form' => 'Hasil belum pasti. Coba masuk atau minta tautan baru; jangan mengulang otomatis.']);
        }if (! $response->successful()) {
            return back()->withErrors(['form' => 'Tautan/kode tidak valid, kata sandi belum memenuhi syarat atau layanan belum tersedia.']);
        }$r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login')->with('status', 'Kata sandi dipulihkan. Masuk kembali.');
    }

    public function account(Backend $api): mixed
    {
        $pending = session('mfa_enrollment');
        $enrollment = $pending && ($pending['expires_at'] ?? 0) > time() ? json_decode(Crypt::decryptString($pending['encrypted']), true) : null;
        if (! $enrollment) {
            session()->forget('mfa_enrollment');
        }$c = $api->request('GET', 'auth/security')->json('data');
        $sessions = $api->request('GET', 'auth/sessions')->json('data');

        return response()->view('pages.account-security', compact('enrollment', 'c', 'sessions'))->header('Cache-Control', 'private, no-store');
    }

    public function begin(Request $r, Backend $api): mixed
    {
        $v = $r->validate(['current_password' => 'required|string|max:255']);
        $e = $api->request('POST', 'auth/mfa/enroll', $v)->json('data');
        abort_unless(is_string($e['secret'] ?? null) && is_array($e['recovery_codes'] ?? null) && count($e['recovery_codes']) === 10 && is_int($e['version'] ?? null), 503);
        session()->put('mfa_enrollment', ['encrypted' => Crypt::encryptString(json_encode($e, JSON_THROW_ON_ERROR)), 'expires_at' => time() + 600]);

        return redirect('/app/account/security');
    }

    public function enable(Request $r, Backend $api): mixed
    {
        $v = $r->validate(['code' => 'required|string|size:6', 'codes_saved' => 'required|accepted']);
        $pending = session('mfa_enrollment');
        abort_unless(is_array($pending) && $pending['expires_at'] > time(), 409);
        $e = json_decode(Crypt::decryptString($pending['encrypted']), true);
        try {
            $api->request('POST', 'auth/mfa/enable', ['version' => $e['version'], 'code' => $v['code']]);
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable) {
            $r->session()->invalidate();
            $r->session()->regenerateToken();

            return redirect('/login')->with('status', 'Hasil perubahan MFA belum pasti. Masuk kembali dan periksa status.');
        }$r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login')->with('status', 'MFA diaktifkan. Masuk kembali dengan authenticator.');
    }

    public function disable(Request $r, Backend $api): mixed
    {
        $v = $r->validate(['current_password' => 'required|string|max:255', 'code' => 'required|string|max:80']);
        try {
            $api->request('POST', 'auth/mfa/disable', $v);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            $r->session()->invalidate();
            $r->session()->regenerateToken();

            return redirect('/login')->with('status', 'Hasil perubahan MFA belum pasti. Periksa saat masuk kembali.');
        }$r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login')->with('status', 'MFA dinonaktifkan; sesi dicabut.');
    }

    public function revoke(Request $r, Backend $api, string $token): mixed
    {
        $api->request('POST', 'auth/sessions/'.$token.'/revoke');

        return redirect('/app/account/security')->with('status', 'Sesi yang dipilih dicabut.');
    }
}
