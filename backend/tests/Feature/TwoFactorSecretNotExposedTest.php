<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * No 2FA secret leaves the server in a response that serialises the user
 * (found 2026-11-10 while building the native 2FA screens): during a setup,
 * GET /v1/admin/profile carried `two_factor_secret_temp`, the pending secret
 * encrypted with the app key, because User::$hidden listed the confirmed
 * secret and the recovery codes but not the pending one.
 */
class TwoFactorSecretNotExposedTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_FIELDS = ['two_factor_secret', 'two_factor_secret_temp', 'two_factor_recovery_codes'];

    private function user(): User
    {
        Artisan::call('permission:sync');
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        Sanctum::actingAs($u);
        return $u;
    }

    private function assertNoSecrets(array $user, string $when): void
    {
        foreach (self::SECRET_FIELDS as $f) {
            $this->assertArrayNotHasKey($f, $user, "{$f} must not be in the profile {$when}");
        }
    }

    public function test_the_pending_secret_is_not_in_the_profile_during_a_setup(): void
    {
        $u = $this->user();
        $setup = $this->postJson('/api/v1/admin/auth/2fa/enable')->assertOk();
        $this->assertNotEmpty($setup->json('secret_key'), 'the setup answer itself still carries the key to scan');
        $this->assertNotNull($u->fresh()->two_factor_secret_temp, 'the setup is pending');

        $this->assertNoSecrets($this->getJson('/api/v1/admin/profile')->assertOk()->json('user'), 'during a setup');
    }

    public function test_no_secret_is_in_the_profile_once_two_step_sign_in_is_on(): void
    {
        $u = $this->user();
        $secret = $this->postJson('/api/v1/admin/auth/2fa/enable')->assertOk()->json('secret_key');
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->postJson('/api/v1/admin/auth/2fa/confirm', ['code' => $code])->assertOk();
        $this->assertTrue((bool) $u->fresh()->two_factor_enabled);

        $this->assertNoSecrets($this->getJson('/api/v1/admin/profile')->assertOk()->json('user'), 'after confirming');
    }

    public function test_the_model_hides_every_secret_wherever_it_is_serialised(): void
    {
        $u = User::factory()->create(['two_factor_secret_temp' => encrypt('ABC'), 'two_factor_secret' => encrypt('DEF')]);
        $this->assertNoSecrets($u->toArray(), 'when the model is serialised');
        $this->assertNoSecrets(json_decode($u->toJson(), true), 'as JSON');
    }
}
