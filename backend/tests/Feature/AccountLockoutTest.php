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
 * Plan §12.4: 5 failed sign-ins → the account is locked for 15 minutes;
 * 10 within 24 h → locked until a system_admin / super_admin unlocks it.
 * Per ACCOUNT (the per-IP throttle still applies on top). Before Phase 4C
 * only the IP limiter existed, so a slow or distributed guesser was never
 * stopped.
 */
class AccountLockoutTest extends TestCase
{
    use RefreshDatabase, SignsInForReal;

    private const LOGIN = '/api/v1/admin/auth/login';

    protected function setUp(): void
    {
        parent::setUp();
        // The per-IP limiter (5/min) would answer 429 first; these tests are
        // about the per-account count, which works whatever the address.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function attempt(User $u, string $password)
    {
        return $this->postJson(self::LOGIN, ['email' => $u->email, 'password' => $password]);
    }

    public function test_five_failures_lock_the_account_for_fifteen_minutes(): void
    {
        $u = $this->staffWithRoles(['admin']);

        for ($i = 0; $i < 4; $i++) {
            $this->attempt($u, 'wrong')->assertStatus(422);
        }
        $this->attempt($u, 'wrong')->assertStatus(423)->assertJson(['reason' => 'account_locked', 'lock' => 'temporary']);

        // Locked: the right password is refused too, and not even checked.
        $this->attempt($u, 'password')->assertStatus(423);

        $this->travel(16)->minutes();
        $this->attempt($u, 'password')->assertOk()->assertJsonStructure(['token']);
    }

    public function test_a_successful_sign_in_restarts_the_count(): void
    {
        $u = $this->staffWithRoles(['admin']);

        for ($i = 0; $i < 4; $i++) {
            $this->attempt($u, 'wrong');
        }
        $this->attempt($u, 'password')->assertOk();
        for ($i = 0; $i < 4; $i++) {
            $this->attempt($u, 'wrong')->assertStatus(422);
        }
        $this->attempt($u, 'password')->assertOk();
    }

    public function test_ten_failures_in_a_day_hold_the_account_until_unlocked_and_end_its_sessions(): void
    {
        $u = $this->staffWithRoles(['admin']);
        $this->bearerFor($u);

        for ($i = 0; $i < 5; $i++) {
            $this->attempt($u, 'wrong');
        }
        $this->travel(16)->minutes();
        for ($i = 0; $i < 4; $i++) {
            $this->attempt($u, 'wrong')->assertStatus(422);
        }
        $this->attempt($u, 'wrong')->assertStatus(423)->assertJson(['lock' => 'held']);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $u->id)->count(), 'a held lock ends every session');

        $this->travel(2)->days();
        $this->attempt($u, 'password')->assertStatus(423)->assertJson(['lock' => 'held']);
        $this->assertDatabaseHas('activity_log', ['event' => 'account_locked', 'subject_id' => $u->id]);
    }

    public function test_a_system_admin_unlocks_a_held_account(): void
    {
        $u = $this->staffWithRoles(['outlet_manager']);
        $u->forceFill(['locked_at' => now(), 'lock_reason' => 'too_many_failed_sign_ins'])->save();
        $sys = $this->systemAdmin();

        $this->attempt($u, 'password')->assertStatus(423);
        $this->withBearer($this->bearerFor($sys), 'POST', "/api/v1/admin/users/{$u->id}/unlock")->assertOk();

        $this->attempt($u, 'password')->assertOk();
        $this->assertDatabaseHas('activity_log', ['event' => 'account_unlocked', 'subject_id' => $u->id, 'causer_id' => $sys->id]);
    }

    public function test_a_system_admin_cannot_unlock_a_tier_one_account_but_a_super_admin_can(): void
    {
        $fm = $this->staffWithRoles(['finance_manager']);
        $fm->forceFill(['locked_at' => now()])->save();
        $sys   = $this->systemAdmin();
        $owner = $this->staffWithRoles(['super_admin']);

        $this->withBearer($this->bearerFor($sys), 'POST', "/api/v1/admin/users/{$fm->id}/unlock")->assertForbidden();
        $this->assertNotNull($fm->fresh()->locked_at);

        $this->withBearer($this->bearerFor($owner), 'POST', "/api/v1/admin/users/{$fm->id}/unlock")->assertOk();
        $this->assertNull($fm->fresh()->locked_at);
    }

    public function test_an_admin_cannot_unlock_anyone(): void
    {
        $u = $this->staffWithRoles(['pos_clerk']);
        $u->forceFill(['locked_at' => now()])->save();
        $admin = $this->staffWithRoles(['admin']);

        $this->withBearer($this->bearerFor($admin), 'POST', "/api/v1/admin/users/{$u->id}/unlock")->assertForbidden();
    }

    public function test_wrong_second_step_codes_count_towards_the_lock(): void
    {
        $g = new Google2FA();
        $secret = $g->generateSecretKey();
        $u = $this->staffWithRoles(['admin'], ['two_factor_enabled' => true, 'two_factor_secret' => encrypt($secret)]);

        for ($i = 0; $i < 4; $i++) {
            $this->attempt($u, 'wrong');
        }
        $step = $this->attempt($u, 'password')->assertOk()->json();
        $this->postJson('/api/v1/admin/auth/2fa/verify', ['user_id' => $u->id, 'challenge' => $step['challenge'], 'code' => '000000'])
            ->assertStatus(423)->assertJson(['reason' => 'account_locked']);
    }

    public function test_the_storefront_door_applies_the_same_lock_to_staff(): void
    {
        $u = $this->staffWithRoles(['admin']);
        $u->forceFill(['locked_until' => now()->addMinutes(10)])->save();

        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'password'])->assertStatus(423);
    }

    public function test_the_unlock_command_is_the_break_glass(): void
    {
        $u = $this->staffWithRoles(['super_admin']);
        $u->forceFill(['locked_at' => now()])->save();

        $this->artisan('auth:unlock', ['email' => $u->email])->assertSuccessful();

        $this->assertNull($u->fresh()->locked_at);
    }
}
