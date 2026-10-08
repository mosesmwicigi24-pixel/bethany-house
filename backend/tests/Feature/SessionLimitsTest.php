<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\TerminalPin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SignsInForReal;
use Tests\TestCase;

/**
 * Plan §12.3: every staff session has an idle and an absolute limit set by
 * role, enforced on the token on every bearer request; a person with several
 * roles gets the strictest. A clerk's idle limit locks the session behind the
 * terminal PIN instead of ending it (the register stays open).
 *
 * Before Phase 4C a staff token lived 7 days from sign-in whatever happened.
 */
class SessionLimitsTest extends TestCase
{
    use RefreshDatabase, SignsInForReal;

    private const ME = '/api/v1/admin/auth/me';

    private function tokenCount(User $u): int
    {
        return DB::table('personal_access_tokens')->where('tokenable_id', $u->id)->count();
    }

    // ── Idle ─────────────────────────────────────────────────────────────────

    public function test_an_admin_token_idle_past_thirty_minutes_signs_out(): void
    {
        $u = $this->staffWithRoles(['admin']);
        $t = $this->bearerFor($u);

        $this->travel(31)->minutes();

        $this->withBearer($t, 'GET', self::ME)->assertStatus(401);
        $this->assertSame(0, $this->tokenCount($u), 'a timed-out token is revoked, not left lying around');
    }

    public function test_activity_inside_the_idle_limit_keeps_the_session(): void
    {
        $u = $this->staffWithRoles(['admin']);
        $t = $this->bearerFor($u);

        $this->travel(29)->minutes();
        $this->withBearer($t, 'GET', self::ME)->assertOk();
        $this->travel(29)->minutes();
        $this->withBearer($t, 'GET', self::ME)->assertOk();
    }

    public function test_background_polling_does_not_count_as_activity(): void
    {
        $u = $this->staffWithRoles(['admin']);
        $t = $this->bearerFor($u);

        $this->travel(20)->minutes();
        $this->withBearer($t, 'GET', self::ME, [], ['X-Background-Request' => '1'])->assertOk();
        $this->travel(11)->minutes();

        // 31 minutes since the person last did anything.
        $this->withBearer($t, 'GET', self::ME)->assertStatus(401);
    }

    public function test_the_strictest_idle_limit_across_roles_applies(): void
    {
        // tailor 60 min, accountant 20 min → 20.
        $u = $this->staffWithRoles(['tailor', 'accountant']);
        $t = $this->bearerFor($u);

        $this->travel(21)->minutes();

        $this->withBearer($t, 'GET', self::ME)->assertStatus(401);
    }

    public function test_super_admin_idles_out_after_fifteen_minutes(): void
    {
        $u = $this->staffWithRoles(['super_admin']);
        $t = $this->bearerFor($u);

        $this->travel(16)->minutes();

        $this->withBearer($t, 'GET', self::ME)->assertStatus(401);
    }

    // ── Absolute ─────────────────────────────────────────────────────────────

    public function test_a_super_admin_session_ends_eight_hours_after_sign_in_however_busy(): void
    {
        $this->freezeSecond();
        $u = $this->staffWithRoles(['super_admin']);
        $t = $this->bearerFor($u);

        for ($i = 0; $i < 48; $i++) {           // a request every 10 minutes for 8 h
            $this->travel(10)->minutes();
            $this->withBearer($t, 'GET', self::ME)->assertOk();
        }
        $this->travel(1)->minutes();

        $this->withBearer($t, 'GET', self::ME)->assertStatus(401);
    }

    public function test_an_outlet_manager_session_lasts_up_to_twelve_hours(): void
    {
        $u = $this->staffWithRoles(['outlet_manager']);
        $t = $this->bearerFor($u);
        DB::table('personal_access_tokens')->where('tokenable_id', $u->id)
            ->update(['created_at' => now()->subHours(11), 'last_used_at' => now(), 'last_active_at' => now()]);

        $this->withBearer($t, 'GET', self::ME)->assertOk();

        DB::table('personal_access_tokens')->where('tokenable_id', $u->id)
            ->update(['created_at' => now()->subMinutes(12 * 60 + 1)]);
        $this->withBearer($t, 'GET', self::ME)->assertStatus(401);
    }

    // ── Who it does not touch ────────────────────────────────────────────────

    public function test_storefront_customers_are_not_subject_to_staff_limits(): void
    {
        $c = User::factory()->customer()->create();
        $t = $this->bearerFor($c);

        $this->travel(3)->hours();

        $this->withBearer($t, 'GET', '/api/v1/customer/orders')->assertOk();
    }

    public function test_the_kill_switch_turns_the_policy_off(): void
    {
        config(['security.sessions.enforce' => false]);
        $u = $this->staffWithRoles(['super_admin']);
        $t = $this->bearerFor($u);

        $this->travel(2)->hours();

        $this->withBearer($t, 'GET', self::ME)->assertOk();
    }

    // ── Clerk: idle → PIN lock ──────────────────────────────────────────────

    private function clerkWithPin(string $pin = '4821'): User
    {
        $u = $this->staffWithRoles(['pos_clerk']);
        app(TerminalPin::class)->set($u, $pin);

        return $u;
    }

    public function test_a_clerk_idle_five_minutes_is_pin_locked_not_signed_out(): void
    {
        $u = $this->clerkWithPin();
        $t = $this->bearerFor($u);

        $this->travel(6)->minutes();

        $res = $this->withBearer($t, 'GET', self::ME);
        $res->assertStatus(423)->assertJson(['reason' => 'pin_locked']);
        $this->assertSame(1, $this->tokenCount($u), 'the session is held, not ended');

        // Still locked on the next request, however soon.
        $this->withBearer($t, 'GET', '/api/v1/admin/sidebar-badges')->assertStatus(423);
    }

    public function test_the_right_pin_unlocks_the_same_session(): void
    {
        $u = $this->clerkWithPin('4821');
        $t = $this->bearerFor($u);
        $this->travel(6)->minutes();
        $this->withBearer($t, 'GET', self::ME)->assertStatus(423);

        $this->withBearer($t, 'POST', '/api/v1/admin/auth/pin/unlock', ['pin' => '9999'])->assertStatus(422);
        $this->withBearer($t, 'GET', self::ME)->assertStatus(423);

        $this->withBearer($t, 'POST', '/api/v1/admin/auth/pin/unlock', ['pin' => '4821'])->assertOk();
        $this->withBearer($t, 'GET', self::ME)->assertOk();
    }

    public function test_five_wrong_pins_end_the_session(): void
    {
        $u = $this->clerkWithPin('4821');
        $t = $this->bearerFor($u);
        $this->travel(6)->minutes();
        $this->withBearer($t, 'GET', self::ME)->assertStatus(423);

        for ($i = 0; $i < 5; $i++) {
            $this->withBearer($t, 'POST', '/api/v1/admin/auth/pin/unlock', ['pin' => '0000']);
        }

        $this->withBearer($t, 'POST', '/api/v1/admin/auth/pin/unlock', ['pin' => '4821'])->assertStatus(401);
        $this->assertSame(0, $this->tokenCount($u));
    }

    public function test_a_locked_clerk_can_still_sign_out(): void
    {
        $u = $this->clerkWithPin();
        $t = $this->bearerFor($u);
        $this->travel(6)->minutes();

        $this->withBearer($t, 'POST', '/api/v1/admin/auth/logout')->assertOk();
        $this->assertSame(0, $this->tokenCount($u));
    }

    public function test_a_clerk_without_a_pin_is_signed_out_at_the_idle_limit(): void
    {
        $u = $this->staffWithRoles(['pos_clerk']);
        $t = $this->bearerFor($u);

        $this->travel(6)->minutes();

        $this->withBearer($t, 'GET', self::ME)->assertStatus(401);
    }

    public function test_a_clerk_shift_ends_at_twelve_hours_even_with_a_pin(): void
    {
        $u = $this->clerkWithPin();
        $t = $this->bearerFor($u);
        DB::table('personal_access_tokens')->where('tokenable_id', $u->id)
            ->update(['created_at' => now()->subMinutes(12 * 60 + 1), 'last_used_at' => now(), 'last_active_at' => now()]);

        $this->withBearer($t, 'GET', self::ME)->assertStatus(401);
    }

    public function test_a_clerk_who_is_also_a_manager_is_pin_locked_at_five_and_signed_out_at_thirty(): void
    {
        $u = $this->staffWithRoles(['pos_clerk', 'outlet_manager']);
        app(TerminalPin::class)->set($u, '4821');
        $t = $this->bearerFor($u);

        $this->travel(6)->minutes();
        $this->withBearer($t, 'GET', self::ME)->assertStatus(423);

        $this->travel(25)->minutes();
        $this->withBearer($t, 'GET', self::ME)->assertStatus(401);
    }

    public function test_the_session_reports_its_limits_to_the_console(): void
    {
        $u = $this->staffWithRoles(['tailor', 'accountant']);
        $t = $this->bearerFor($u);

        $this->withBearer($t, 'GET', self::ME)->assertOk()
            ->assertJsonPath('user.session_policy.idle_minutes', 20)
            ->assertJsonPath('user.session_policy.absolute_minutes', 600);
    }
}
