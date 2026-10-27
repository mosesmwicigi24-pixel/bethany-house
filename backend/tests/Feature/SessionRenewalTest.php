<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\TerminalPin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SignsInForReal;
use Tests\TestCase;

/**
 * Native apps Step 2: POST /admin/auth/renew replaces the bearer token for
 * the same session without widening any limit the role already has.
 */
class SessionRenewalTest extends TestCase
{
    use RefreshDatabase, SignsInForReal;

    private const RENEW = '/api/v1/admin/auth/renew';
    private const ME    = '/api/v1/admin/auth/me';

    private function tokenRows(User $u)
    {
        return DB::table('personal_access_tokens')->where('tokenable_id', $u->id)->get();
    }

    // ── Happy path ───────────────────────────────────────────────────────────

    public function test_renewal_returns_a_new_token_and_revokes_the_old_one(): void
    {
        config(['sanctum.access_ttl_minutes' => 7 * 24 * 60]);
        $u   = $this->staffWithRoles(['admin']);
        $old = $this->bearerFor($u);

        $res = $this->withBearer($old, 'POST', self::RENEW)->assertOk();
        $new = $res->json('token');

        $this->assertNotSame($old, $new);
        $this->assertCount(1, $this->tokenRows($u), 'one session, one token');
        $this->withBearer($old, 'GET', self::ME)->assertStatus(401);
        $this->withBearer($new, 'GET', self::ME)->assertOk();
        $res->assertJsonStructure(['token', 'expires_at', 'session_started_at', 'session_policy']);
    }

    public function test_renewed_expiry_is_capped_at_the_absolute_limit_from_the_original_sign_in(): void
    {
        // admin: absolute 10 h; TTL 7 days. After 4 h the new token may live 6 h, not 7 days.
        config(['sanctum.access_ttl_minutes' => 7 * 24 * 60]);
        $u   = $this->staffWithRoles(['admin']);
        $old = $this->bearerFor($u);
        $signedInAt = now();

        // Four hours of use: activity every 24 minutes keeps the 30-minute idle limit at bay.
        for ($i = 0; $i < 10; $i++) {
            $this->travel(24)->minutes();
            $this->withBearer($old, 'GET', self::ME)->assertOk();
        }
        $res = $this->withBearer($old, 'POST', self::RENEW)->assertOk();

        $row = $this->tokenRows($u)->first();
        $this->assertEqualsWithDelta($signedInAt->timestamp, strtotime($row->created_at), 5, 'session start is kept');
        $this->assertEqualsWithDelta($signedInAt->copy()->addHours(10)->timestamp, strtotime($row->expires_at), 5, 'expiry capped at the absolute limit');
        $this->assertEqualsWithDelta($signedInAt->copy()->addHours(10)->timestamp, strtotime($res->json('expires_at')), 5);
    }

    public function test_renewal_slides_the_ttl_when_the_absolute_limit_is_further_away(): void
    {
        // TTL shorter than the absolute limit: 2 h TTL, admin absolute 10 h.
        config(['sanctum.access_ttl_minutes' => 120]);
        $u   = $this->staffWithRoles(['admin']);
        $old = $this->bearerFor($u);

        $this->travel(25)->minutes();
        $this->withBearer($old, 'POST', self::RENEW)->assertOk();

        $row = $this->tokenRows($u)->first();
        $this->assertEqualsWithDelta(now()->addMinutes(120)->timestamp, strtotime($row->expires_at), 5);
    }

    public function test_renewal_does_not_inherit_step_up(): void
    {
        $u   = $this->staffWithRoles(['admin']);
        $old = $this->bearerFor($u);
        DB::table('personal_access_tokens')->where('tokenable_id', $u->id)->update(['stepped_up_at' => now()]);

        $this->withBearer($old, 'POST', self::RENEW)->assertOk();

        $this->assertNull($this->tokenRows($u)->first()->stepped_up_at);
    }

    // ── Refusals: nothing the session may not already do ─────────────────────

    public function test_a_session_past_its_absolute_limit_cannot_renew(): void
    {
        $u   = $this->staffWithRoles(['admin']);
        $old = $this->bearerFor($u);

        $this->travel(11)->hours();

        $this->withBearer($old, 'POST', self::RENEW)->assertStatus(401);
        $this->assertCount(0, $this->tokenRows($u));
    }

    public function test_an_idle_session_cannot_renew(): void
    {
        $u   = $this->staffWithRoles(['admin']);
        $old = $this->bearerFor($u);

        $this->travel(31)->minutes();

        $this->withBearer($old, 'POST', self::RENEW)->assertStatus(401);
    }

    public function test_a_pin_locked_clerk_session_cannot_renew(): void
    {
        $u = $this->staffWithRoles(['pos_clerk']);
        app(TerminalPin::class)->set($u, '2468');
        $old = $this->bearerFor($u);

        $this->travel(6)->minutes();

        $this->withBearer($old, 'POST', self::RENEW)->assertStatus(423);
        $this->assertCount(1, $this->tokenRows($u), 'the locked token stays; nothing was minted');
    }

    public function test_a_kiosk_token_cannot_renew(): void
    {
        $u = $this->staffWithRoles(['admin']);
        $t = $u->createToken('kiosk:1:door', ['kiosk'], now()->addDay())->plainTextToken;

        // The kiosk middleware already keeps a kiosk token at the kiosk (403);
        // the renewal service refuses it too, in case that gate ever moves.
        $this->withBearer($t, 'POST', self::RENEW)->assertStatus(403);
        $this->assertCount(1, $this->tokenRows($u));
        $this->assertSame('not_renewable', app(\App\Services\Auth\SessionRenewal::class)->refusal(
            \App\Models\PersonalAccessToken::findToken($t)
        ));
    }

    public function test_a_token_without_expiry_has_nothing_to_renew(): void
    {
        config(['sanctum.access_ttl_minutes' => 0]);
        $u = $this->staffWithRoles(['admin']);
        $t = $this->bearerFor($u);

        $this->withBearer($t, 'POST', self::RENEW)->assertStatus(409)->assertJsonPath('reason', 'no_expiry');
        $this->assertCount(1, $this->tokenRows($u));
    }

    public function test_renewal_needs_a_signed_in_staff_member(): void
    {
        $this->postJson(self::RENEW)->assertStatus(401);

        $customer = User::factory()->create(['user_type' => 'customer']);
        $t = $this->bearerFor($customer);
        $this->withBearer($t, 'POST', self::RENEW)->assertStatus(403);
    }

    public function test_renewing_twice_with_the_old_token_fails_the_second_time(): void
    {
        $u   = $this->staffWithRoles(['admin']);
        $old = $this->bearerFor($u);

        $this->withBearer($old, 'POST', self::RENEW)->assertOk();
        $this->withBearer($old, 'POST', self::RENEW)->assertStatus(401);
        $this->assertCount(1, $this->tokenRows($u));
    }
}
