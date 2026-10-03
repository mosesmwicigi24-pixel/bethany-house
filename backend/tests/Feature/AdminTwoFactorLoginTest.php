<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The admin second step must follow a correct password, once, for the same
 * account — and must never be able to switch anyone's 2FA off. Both flaws
 * were found 2026-10-02; neither was usable then only because no account had
 * 2FA on, and both had to close before 2FA could be switched on.
 */
class AdminTwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $g;

    protected function setUp(): void
    {
        parent::setUp();
        $this->g = new Google2FA();
    }

    private function staffWith2fa(string $storedSecret, string $plainSecret): User
    {
        return User::factory()->create([
            'user_type' => 'staff', 'status' => 'active', 'password' => bcrypt('correct-horse'),
            'two_factor_enabled' => true, 'two_factor_secret' => $storedSecret,
        ]);
    }

    private function passwordStep(User $u): array
    {
        return $this->postJson('/api/v1/admin/auth/login', ['email' => $u->email, 'password' => 'correct-horse'])
            ->assertOk()->assertJson(['requires_2fa' => true])->json();
    }

    private function tokens(User $u): int
    {
        return DB::table('personal_access_tokens')->where('tokenable_id', $u->id)->count();
    }

    public function test_the_code_alone_is_not_a_login(): void
    {
        $secret = $this->g->generateSecretKey();
        $u = $this->staffWith2fa(encrypt($secret), $secret);

        $res = $this->postJson('/api/v1/admin/auth/2fa/verify', ['user_id' => $u->id, 'code' => $this->g->getCurrentOtp($secret)]);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertArrayNotHasKey('token', $res->json());
        $this->assertSame(0, $this->tokens($u));
    }

    public function test_password_then_code_signs_in_once(): void
    {
        $secret = $this->g->generateSecretKey();
        $u = $this->staffWith2fa(encrypt($secret), $secret);
        $step = $this->passwordStep($u);
        $this->assertNotEmpty($step['challenge']);

        $ok = $this->postJson('/api/v1/admin/auth/2fa/verify', ['user_id' => $u->id, 'code' => $this->g->getCurrentOtp($secret), 'challenge' => $step['challenge']]);
        $ok->assertOk()->assertJsonStructure(['token']);

        $again = $this->postJson('/api/v1/admin/auth/2fa/verify', ['user_id' => $u->id, 'code' => $this->g->getCurrentOtp($secret), 'challenge' => $step['challenge']]);
        $this->assertSame(422, $again->getStatusCode(), 'a challenge is single-use');
        $this->assertSame(1, $this->tokens($u));
    }

    public function test_a_challenge_cannot_be_spent_on_another_account(): void
    {
        $sa = $this->g->generateSecretKey();
        $sb = $this->g->generateSecretKey();
        $a = $this->staffWith2fa(encrypt($sa), $sa);
        $b = $this->staffWith2fa(encrypt($sb), $sb);
        $step = $this->passwordStep($a);

        $res = $this->postJson('/api/v1/admin/auth/2fa/verify', ['user_id' => $b->id, 'code' => $this->g->getCurrentOtp($sb), 'challenge' => $step['challenge']]);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertSame(0, $this->tokens($b));
    }

    public function test_five_wrong_codes_end_the_sign_in(): void
    {
        // The per-IP auth throttle (5/min) would answer 429 first; this test
        // is about the challenge's own five-try limit, so the throttle is off.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $secret = $this->g->generateSecretKey();
        $u = $this->staffWith2fa(encrypt($secret), $secret);
        $step = $this->passwordStep($u);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/admin/auth/2fa/verify', ['user_id' => $u->id, 'code' => '000000', 'challenge' => $step['challenge']]);
        }
        $res = $this->postJson('/api/v1/admin/auth/2fa/verify', ['user_id' => $u->id, 'code' => $this->g->getCurrentOtp($secret), 'challenge' => $step['challenge']]);

        $this->assertSame(422, $res->getStatusCode(), 'the right code after five wrong ones needs a fresh password step');
        $this->assertSame(0, $this->tokens($u));
    }

    public function test_a_secret_saved_by_the_web_screens_still_works(): void
    {
        $secret = $this->g->generateSecretKey();
        $u = $this->staffWith2fa($secret, $secret);   // Livewire stored it plain
        $step = $this->passwordStep($u);

        $this->postJson('/api/v1/admin/auth/2fa/verify', ['user_id' => $u->id, 'code' => $this->g->getCurrentOtp($secret), 'challenge' => $step['challenge']])
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_an_unreadable_secret_never_switches_2fa_off(): void
    {
        $u = $this->staffWith2fa('not-a-secret-in-any-form', 'x');

        // Without the password: refused before the secret is even read.
        $this->postJson('/api/v1/admin/auth/2fa/verify', ['user_id' => $u->id, 'code' => '123456', 'challenge' => 'guess'])->assertStatus(422);
        // With the password: refused, and 2FA stays on.
        $step = $this->passwordStep($u);
        $this->postJson('/api/v1/admin/auth/2fa/verify', ['user_id' => $u->id, 'code' => '123456', 'challenge' => $step['challenge']])->assertStatus(422);

        $fresh = $u->fresh();
        $this->assertTrue((bool) $fresh->two_factor_enabled, '2FA was switched off');
        $this->assertSame('not-a-secret-in-any-form', $fresh->two_factor_secret);
    }
}
