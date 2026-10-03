<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\SignsInForReal;
use Tests\TestCase;

/**
 * Plan §12.3–12.4, owner's choice of a STAGED rollout: a per-role switch
 * (all off by default) decides who must use two-step sign-in. When it is on
 * and the person has none, the API sign-in returns a setup step — QR,
 * verification, 8 recovery codes — and no session until it is done.
 * Recovery codes are hashed and single-use. Resets follow the tiers.
 */
class TwoFactorRolloutTest extends TestCase
{
    use RefreshDatabase, SignsInForReal;

    private Google2FA $g;

    protected function setUp(): void
    {
        parent::setUp();
        $this->g = new Google2FA();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function login(User $u)
    {
        return $this->postJson('/api/v1/admin/auth/login', ['email' => $u->email, 'password' => 'password']);
    }

    public function test_every_switch_is_off_by_default(): void
    {
        foreach ((array) config('security.two_factor.required_roles') as $role => $on) {
            $this->assertFalse($on, "2FA rollout switch for {$role} must ship OFF");
        }
        $u = $this->staffWithRoles(['super_admin']);
        $this->login($u)->assertOk()->assertJsonStructure(['token']);
    }

    public function test_when_the_role_requires_it_sign_in_walks_through_setup_and_issues_recovery_codes(): void
    {
        config(['security.two_factor.required_roles.finance_manager' => true]);
        $u = $this->staffWithRoles(['finance_manager']);

        $step = $this->login($u)->assertOk()->assertJson(['requires_2fa_setup' => true])->assertJsonMissing(['token'])->json();
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $u->id)->count());

        $setup = $this->postJson('/api/v1/admin/auth/2fa/setup', ['user_id' => $u->id, 'setup_token' => $step['setup_token']])
            ->assertOk()->json();
        $this->assertStringStartsWith('otpauth://totp/', $setup['qr_code_url']);

        $this->postJson('/api/v1/admin/auth/2fa/setup/confirm', ['user_id' => $u->id, 'setup_token' => $step['setup_token'], 'code' => '000000'])
            ->assertStatus(422);

        $done = $this->postJson('/api/v1/admin/auth/2fa/setup/confirm', [
            'user_id' => $u->id, 'setup_token' => $step['setup_token'], 'code' => $this->g->getCurrentOtp($setup['secret_key']),
        ])->assertOk()->assertJsonStructure(['token', 'recovery_codes'])->json();

        $this->assertCount(8, $done['recovery_codes']);
        $fresh = $u->fresh();
        $this->assertTrue((bool) $fresh->two_factor_enabled);
        foreach ($done['recovery_codes'] as $code) {
            $this->assertStringNotContainsString($code, (string) $fresh->getAttributes()['two_factor_recovery_codes'], 'codes are stored hashed');
        }

        // The setup token is single-use.
        $this->postJson('/api/v1/admin/auth/2fa/setup', ['user_id' => $u->id, 'setup_token' => $step['setup_token']])->assertStatus(422);
        // Next sign-in asks for the code.
        $this->login($u)->assertOk()->assertJson(['requires_2fa' => true]);
    }

    public function test_the_switch_only_reaches_the_roles_it_names(): void
    {
        config(['security.two_factor.required_roles.finance_manager' => true]);
        $this->login($this->staffWithRoles(['admin']))->assertOk()->assertJsonStructure(['token']);
        $this->login($this->staffWithRoles(['pos_clerk', 'finance_manager']))->assertOk()->assertJson(['requires_2fa_setup' => true]);
    }

    public function test_a_recovery_code_signs_in_once(): void
    {
        $secret = $this->g->generateSecretKey();
        $u = $this->staffWithRoles(['admin'], ['two_factor_enabled' => true, 'two_factor_secret' => encrypt($secret)]);
        $codes = app(\App\Services\Auth\TwoFactor::class)->generateRecoveryCodes($u);

        $step = $this->login($u)->json();
        $this->postJson('/api/v1/admin/auth/2fa/verify', ['user_id' => $u->id, 'challenge' => $step['challenge'], 'recovery_code' => $codes[3]])
            ->assertOk()->assertJsonStructure(['token'])->assertJson(['recovery_codes_left' => 7]);

        $step = $this->login($u)->json();
        $this->postJson('/api/v1/admin/auth/2fa/verify', ['user_id' => $u->id, 'challenge' => $step['challenge'], 'recovery_code' => $codes[3]])
            ->assertStatus(422);
        $this->assertDatabaseHas('activity_log', ['event' => 'two_factor_recovery_code_used', 'subject_id' => $u->id]);
    }

    public function test_switching_2fa_on_from_the_profile_issues_recovery_codes(): void
    {
        $u = $this->staffWithRoles(['admin']);
        $t = $this->bearerFor($u);
        $setup = $this->withBearer($t, 'POST', '/api/v1/admin/auth/2fa/enable')->assertOk()->json();

        $this->withBearer($t, 'POST', '/api/v1/admin/auth/2fa/confirm', ['code' => $this->g->getCurrentOtp($setup['secret_key'])])
            ->assertOk()->assertJsonCount(8, 'recovery_codes');
    }

    public function test_a_holder_cannot_switch_off_2fa_their_role_requires(): void
    {
        config(['security.two_factor.required_roles.admin' => true]);
        $secret = $this->g->generateSecretKey();
        $u = $this->staffWithRoles(['admin'], ['two_factor_enabled' => true, 'two_factor_secret' => encrypt($secret)]);

        $this->withBearer($this->bearerFor($u), 'POST', '/api/v1/admin/auth/2fa/disable', ['password' => 'password'])->assertForbidden();
        $this->assertTrue((bool) $u->fresh()->two_factor_enabled);
    }

    public function test_the_storefront_door_cannot_skip_a_required_setup(): void
    {
        config(['security.two_factor.required_roles.admin' => true]);
        $u = $this->staffWithRoles(['admin']);

        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'password'])->assertStatus(422);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $u->id)->count());
    }

    // ── Resets (tiers) ───────────────────────────────────────────────────────

    private function with2fa(array $roles): User
    {
        return $this->staffWithRoles($roles, ['two_factor_enabled' => true, 'two_factor_secret' => encrypt($this->g->generateSecretKey())]);
    }

    private function resetAs(User $actor, User $target)
    {
        $t = $this->bearerFor($actor);
        $this->withBearer($t, 'POST', '/api/v1/admin/auth/step-up', ['password' => 'password'])->assertOk();

        return $this->withBearer($t, 'POST', "/api/v1/admin/users/{$target->id}/2fa/reset", ['reason' => 'lost phone, identity checked in person']);
    }

    public function test_a_system_admin_resets_a_tier_two_or_three_account_and_its_sessions_end(): void
    {
        $sys   = $this->systemAdmin();
        $clerk = $this->with2fa(['pos_clerk']);
        $session = $this->bearerFor($clerk);

        $this->resetAs($sys, $clerk)->assertOk();

        $this->assertFalse((bool) $clerk->fresh()->two_factor_enabled);
        $this->withBearer($session, 'GET', '/api/v1/admin/auth/me')->assertStatus(401);
        $this->assertDatabaseHas('activity_log', ['event' => 'two_factor_reset', 'subject_id' => $clerk->id, 'causer_id' => $sys->id]);
    }

    public function test_a_system_admin_cannot_reset_a_tier_zero_or_one_account(): void
    {
        $sys = $this->systemAdmin();
        foreach (['finance_manager', 'admin', 'super_admin', 'system_admin'] as $role) {
            $target = $this->with2fa([$role]);
            $this->resetAs($sys, $target)->assertForbidden();
            $this->assertTrue((bool) $target->fresh()->two_factor_enabled, "{$role} was reset by a system_admin");
        }
    }

    public function test_a_super_admin_resets_tier_one_and_another_super_admin_but_not_themselves(): void
    {
        $owner = $this->with2fa(['super_admin']);
        $fm    = $this->with2fa(['finance_manager']);
        $other = $this->with2fa(['super_admin']);
        $g = $this->g;

        $t = $this->bearerFor($owner);
        // The owner has 2FA on, so step-up takes the code.
        $secret = \App\Services\Auth\TwoFactor::readSecret($owner->fresh()->two_factor_secret);
        $this->withBearer($t, 'POST', '/api/v1/admin/auth/step-up', ['code' => $g->getCurrentOtp($secret)])->assertOk();

        $this->withBearer($t, 'POST', "/api/v1/admin/users/{$fm->id}/2fa/reset")->assertOk();
        $this->withBearer($t, 'POST', "/api/v1/admin/users/{$other->id}/2fa/reset")->assertOk();
        $this->withBearer($t, 'POST', "/api/v1/admin/users/{$owner->id}/2fa/reset")->assertForbidden();
        $this->assertTrue((bool) $owner->fresh()->two_factor_enabled);
    }

    public function test_an_admin_cannot_reset_anyone(): void
    {
        $admin = $this->staffWithRoles(['admin']);
        $admin->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('users.view', 'sanctum'));
        $clerk = $this->with2fa(['pos_clerk']);

        $this->resetAs($admin, $clerk)->assertForbidden();
    }
}
