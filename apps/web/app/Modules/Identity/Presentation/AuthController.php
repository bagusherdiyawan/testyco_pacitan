<?php

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class AuthController
{
    public function form(): mixed
    {
        return view('pages.login');
    }

    public function login(Request $r): mixed
    {
        $v = $r->validate(['email' => 'required|email', 'password' => 'required|string|max:255']);
        try {
            $response = Http::timeout(8)->acceptJson()->post(rtrim(config('backend.url'), '/').'/api/v1/auth/login', [...$v, 'device_name' => 'web']);
        } catch (ConnectionException $e) {
            return back()->withInput($r->only('email'))->withErrors(['email' => 'API belum tersedia. Coba kembali.']);
        }
        if (! $response->successful()) {
            return back()->withInput($r->only('email'))->withErrors(['email' => $response->status() === 429 ? 'Terlalu banyak percobaan. Tunggu sebentar.' : 'Email atau kata sandi tidak valid, atau layanan sedang tidak tersedia.']);
        }
        $r->session()->forget(['order_changes_pending', 'user_reactivation_pending', 'shipment_discrepancy_pending', 'pos_payment_pending', 'refund_pending', 'refund_review', 'voucher_pending', 'billing_pending', 'return_pending', 'replacement_pending', 'order_revisions_pending', 'order_revision_review', 'contract_pending', 'catalog_configuration_pending', 'bundle_pending']);
        $r->session()->regenerate();
        $r->session()->put(['api_token' => $response->json('token'), 'api_user' => $response->json('user'), 'api_expires_at' => time() + (int) $response->json('expires_in')]);

        return redirect('/app');
    }

    public function passwordForm(): mixed
    {
        return view('pages.password');
    }

    public function password(Request $r, Backend $api): mixed
    {
        $api->request('POST', 'auth/password', $r->only('current_password', 'password', 'password_confirmation'));
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login')->with('status', 'Kata sandi diganti. Masuk kembali dengan kata sandi baru.');
    }

    public function logout(Request $r): mixed
    {
        try {
            Http::timeout(3)->withToken($r->session()->get('api_token', ''))->post(rtrim(config('backend.url'), '/').'/api/v1/auth/logout');
        } catch (ConnectionException $e) { /* Remote token still expires in eight hours. */
        }
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }
}
