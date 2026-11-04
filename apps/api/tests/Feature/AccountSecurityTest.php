<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Application\AccountSecurity;
use App\Modules\Identity\Application\Totp;
use App\Modules\Identity\Jobs\SendPasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['active' => true, 'email' => 'security@example.test', 'password' => 'ExamplePassword123']);
    }

    public function test_rfc6238_vectors_with_six_digit_truncation(): void
    {
        foreach ([1 => '287082', 37037036 => '081804', 37037037 => '050471', 41152263 => '005924', 66666666 => '279037', 666666666 => '353130'] as $counter => $expected) {
            $this->assertSame($expected, Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $counter));
        }
    }

    public function test_enrollment_encrypted_secret_challenge_replay_and_single_use_recovery(): void
    {
        $this->travelTo(now()->startOfMinute());
        $u = $this->user();
        Sanctum::actingAs($u);
        $e = $this->postJson('/api/v1/auth/mfa/enroll', ['current_password' => 'ExamplePassword123'])->assertOk()->json('data');
        $this->assertNotSame($e['secret'], DB::table('user_mfa')->value('encrypted_secret'));
        $this->assertStringNotContainsString($e['recovery_codes'][0], DB::table('user_mfa')->value('recovery_hashes'));
        $this->postJson('/api/v1/auth/mfa/enable', ['version' => $e['version'], 'code' => Totp::code($e['secret'], intdiv(now()->timestamp, 30))])->assertNoContent();
        $this->assertTrue(app(AccountSecurity::class)->enabled($u->id));
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'ExamplePassword123', 'device_name' => 'test'])->assertOk()->assertJsonMissingPath('token')->json('challenge');
        $body = ['challenge' => $challenge, 'code' => $e['recovery_codes'][0]];
        $token = $this->postJson('/api/v1/auth/login/mfa', $body)->assertOk()->json('token');
        $this->assertNotEmpty($token);
        $this->postJson('/api/v1/auth/login/mfa', $body)->assertUnprocessable();
        $new = app(AccountSecurity::class)->challenge($u->fresh(), 'second');
        $this->expectException(ValidationException::class);
        app(AccountSecurity::class)->login($new['challenge'], $e['recovery_codes'][0]);
    }

    public function test_failed_factors_commit_attempt_count_and_password_reset_cannot_bypass_mfa(): void
    {
        $u = $this->user();
        $security = app(AccountSecurity::class);
        $e = $security->begin($u, 'ExamplePassword123');
        $security->enable($u, $e['version'], Totp::code($e['secret'], intdiv(now()->timestamp, 30)));
        $c = $security->challenge($u->fresh(), 'test');
        for ($n = 0; $n < 5; $n++) {
            try {
                $security->login($c['challenge'], 'invalid');
                $this->fail('Denied');
            } catch (ValidationException) {
            }
        }$this->assertSame(5, DB::table('login_challenges')->value('attempts'));
        $reset = Password::broker()->createToken($u);
        try {
            $security->reset(['email' => $u->email, 'token' => $reset, 'password' => 'DifferentPassword123']);
            $this->fail('MFA required');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }$this->assertTrue(Hash::check('ExamplePassword123', $u->fresh()->password));
        $security->reset(['email' => $u->email, 'token' => $reset, 'password' => 'DifferentPassword123', 'code' => $e['recovery_codes'][1]]);
    }

    public function test_reset_email_queues_encrypted_delivery_and_reset_revokes_sessions(): void
    {
        $u = $this->user();
        config(['security.email_reset_enabled' => true, 'security.public_web_url' => 'https://web.example.test', 'mail.default' => 'smtp']);
        Queue::fake();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'missing@example.test'])->assertStatus(202);
        $this->postJson('/api/v1/auth/password/forgot', ['email' => $u->email])->assertStatus(202);
        Queue::assertPushed(SendPasswordReset::class, 1);
        $u->createToken('old');
        $token = Password::broker()->createToken($u);
        $this->postJson('/api/v1/auth/password/reset', ['email' => $u->email, 'token' => $token, 'password' => 'ResetPassword123', 'password_confirmation' => 'ResetPassword123'])->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertTrue(Hash::check('ResetPassword123', $u->fresh()->password));
        $this->postJson('/api/v1/auth/password/reset', ['email' => $u->email, 'token' => $token, 'password' => 'SecondPassword123', 'password_confirmation' => 'SecondPassword123'])->assertUnprocessable();
    }

    public function test_password_rotation_invalidates_outstanding_second_factor_and_only_owned_sessions_can_be_revoked(): void
    {
        $u = $this->user();
        $security = app(AccountSecurity::class);
        $e = $security->begin($u, 'ExamplePassword123');
        $security->enable($u, $e['version'], Totp::code($e['secret'], intdiv(now()->timestamp, 30)));
        $c = $security->challenge($u->fresh(), 'Pending device');
        $mine = $u->createToken('mine');
        $other = User::factory()->create()->createToken('other');
        Sanctum::actingAs($u->fresh());
        $this->postJson('/api/v1/auth/sessions/'.$other->accessToken->id.'/revoke')->assertNoContent();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->postJson('/api/v1/auth/sessions/'.$mine->accessToken->id.'/revoke')->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $mine->accessToken->id]);
        $version = $u->fresh()->access_version;
        $this->postJson('/api/v1/auth/password', ['current_password' => 'ExamplePassword123', 'password' => 'ChangedPassword123', 'password_confirmation' => 'ChangedPassword123'])->assertNoContent();
        $this->assertSame($version + 1, $u->fresh()->access_version);
        $this->postJson('/api/v1/auth/login/mfa', ['challenge' => $c['challenge'], 'code' => $e['recovery_codes'][0]])->assertUnprocessable();
    }
}
