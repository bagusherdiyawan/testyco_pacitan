<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Jobs\SendPasswordReset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountSecurity
{
    public function enabled(int $id): bool
    {
        return DB::table('user_mfa')->where('user_id', $id)->where('status', 'enabled')->exists();
    }

    public function begin(User $u, string $password): array
    {
        abort_unless(Hash::check($password, $u->password), 422, 'Kata sandi saat ini tidak cocok.');

        return DB::transaction(function () use ($u, $password) {
            $u = User::lockForUpdate()->findOrFail($u->id);
            abort_unless(Hash::check($password, $u->password), 422, 'Kata sandi saat ini berubah.');
            abort_if($this->enabled($u->id), 409, 'MFA sudah aktif.');
            $secret = Totp::secret();
            $codes = [];
            for ($n = 0; $n < 10; $n++) {
                $codes[] = strtolower(Str::random(10).'-'.Str::random(10));
            }$old = DB::table('user_mfa')->where('user_id', $u->id)->first();
            $version = (int) ($old?->version ?? 0) + 1;
            DB::table('user_mfa')->updateOrInsert(['user_id' => $u->id], ['encrypted_secret' => Crypt::encryptString($secret), 'recovery_hashes' => json_encode(array_map(fn ($c) => hash('sha256', $c), $codes)), 'status' => 'pending', 'version' => $version, 'last_counter' => -1, 'created_at' => now(), 'enabled_at' => null]);

            return ['secret' => $secret, 'recovery_codes' => $codes, 'version' => $version, 'issuer' => 'Testyco', 'account' => $u->email, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30];
        });
    }

    public function enable(User $u, int $version, string $code): void
    {
        DB::transaction(function () use ($u, $version, $code) {
            $u = User::lockForUpdate()->findOrFail($u->id);
            $m = DB::table('user_mfa')->where('user_id', $u->id)->lockForUpdate()->first();
            abort_unless($m && $m->status === 'pending' && (int) $m->version === $version && CarbonImmutable::parse($m->created_at)->gte(now()->subMinutes(10)), 409, 'Enrollment berubah.');
            $counter = Totp::counter(Crypt::decryptString($m->encrypted_secret), $code);
            abort_unless($counter !== null, 422, 'Kode authenticator tidak valid.');
            DB::table('user_mfa')->where('user_id', $u->id)->update(['status' => 'enabled', 'last_counter' => $counter, 'enabled_at' => now()]);
            $this->revoke($u);
            Audit::record($u->id, null, 'user.mfa_enabled', (string) $u->id, null, null);
        });
    }

    private function revoke(User $u): void
    {
        $u->increment('access_version');
        $u->tokens()->delete();
        DB::table('login_challenges')->where('user_id', $u->id)->delete();
    }

    private function factor(User $u, string $code): bool
    {
        $m = DB::table('user_mfa')->where('user_id', $u->id)->where('status', 'enabled')->lockForUpdate()->first();
        if (! $m) {
            return false;
        }$counter = Totp::counter(Crypt::decryptString($m->encrypted_secret), $code, (int) $m->last_counter);
        if ($counter !== null) {
            DB::table('user_mfa')->where('user_id', $u->id)->update(['last_counter' => $counter]);

            return true;
        }$hash = hash('sha256', $code);
        $codes = json_decode($m->recovery_hashes, true);
        foreach ($codes as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($codes[$i]);
                DB::table('user_mfa')->where('user_id', $u->id)->update(['recovery_hashes' => json_encode(array_values($codes))]);

                return true;
            }
        }

        return false;
    }

    public function disable(User $u, string $password, string $code): void
    {
        abort_unless(Hash::check($password, $u->password), 422, 'Kata sandi tidak cocok.');
        DB::transaction(function () use ($u, $code, $password) {
            $u = User::lockForUpdate()->findOrFail($u->id);
            abort_unless(Hash::check($password, $u->password), 422, 'Kata sandi saat ini berubah.');
            abort_unless($this->factor($u, $code), 422, 'Kode MFA tidak valid.');
            DB::table('user_mfa')->where('user_id', $u->id)->delete();
            $this->revoke($u);
            Audit::record($u->id, null, 'user.mfa_disabled', (string) $u->id, null, null);
        });
    }

    public function challenge(User $u, string $device): array
    {
        $token = Str::random(64);
        DB::table('login_challenges')->where('user_id', $u->id)->where('expires_at', '<', now())->delete();
        DB::table('login_challenges')->insert(['token_hash' => hash('sha256', $token), 'user_id' => $u->id, 'access_version' => $u->access_version, 'device_name' => $device, 'expires_at' => now()->addMinutes(2)]);

        return ['requires_mfa' => true, 'challenge' => $token, 'expires_in' => 120];
    }

    public function login(string $token, string $code): array
    {
        $hash = hash('sha256', $token);
        $initial = DB::table('login_challenges')->where('token_hash', $hash)->first();
        $result = $initial ? DB::transaction(function () use ($hash, $code, $initial) {
            $u = User::lockForUpdate()->find($initial->user_id);
            $c = DB::table('login_challenges')->where('token_hash', $hash)->lockForUpdate()->first();
            if (! $u || ! $u->active || ! $c || $c->used_at || $c->expires_at <= now()->toDateTimeString() || (int) $c->attempts >= 5 || (int) $c->access_version !== $u->access_version) {
                return null;
            }DB::table('login_challenges')->where('token_hash', $hash)->increment('attempts');
            if (! $this->factor($u, $code)) {
                return null;
            }DB::table('login_challenges')->where('token_hash', $hash)->update(['used_at' => now()]);

            return ['token' => $u->createToken($c->device_name, ['*'], now()->addHours(8))->plainTextToken, 'user' => $u->toArray(), 'expires_in' => 28800];
        }) : null;
        if (! $result) {
            throw ValidationException::withMessages(['code' => 'Kode atau tantangan tidak valid/kedaluwarsa.']);
        }

        return $result;
    }

    public function forgot(string $email): void
    {
        abort_unless(config('security.email_reset_enabled') && in_array(config('mail.default'), app()->environment('testing') ? ['smtp', 'array'] : ['smtp'], true), 503, 'Pemulihan email belum dikonfigurasi.');
        $base = rtrim((string) config('security.public_web_url'), '/');
        abort_unless(str_starts_with($base, 'https://') || (app()->environment('testing') && str_starts_with($base, 'http://')), 503, 'Alamat web publik HTTPS belum dikonfigurasi.');
        $u = User::where('email', strtolower($email))->where('active', true)->first();
        if (! $u) {
            return;
        }$token = Password::broker()->createToken($u);
        $url = $base.'/reset-password#'.http_build_query(['token' => $token, 'email' => $u->email]);
        SendPasswordReset::dispatch($u->email, $url);
    }

    public function reset(array $v): void
    {
        $u = User::where('email', strtolower($v['email']))->where('active', true)->first();
        abort_unless($u && Password::broker()->tokenExists($u, $v['token']), 422, 'Tautan tidak valid/kedaluwarsa.');
        DB::transaction(function () use ($u, $v) {
            $u = User::lockForUpdate()->findOrFail($u->id);
            abort_unless($u->active && Password::broker()->tokenExists($u, $v['token']), 422, 'Tautan tidak valid.');
            if ($this->enabled($u->id)) {
                abort_unless($this->factor($u, $v['code'] ?? ''), 422, 'Kode MFA/recovery wajib.');
            }$u->forceFill(['password' => $v['password']])->save();
            $this->revoke($u);
            Password::broker()->deleteToken($u);
            Audit::record($u->id, null, 'user.password_reset', (string) $u->id, null, null);
        });
    }
}
