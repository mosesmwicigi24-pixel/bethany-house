<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Production Cycle 9 (owner-approved 7 Oct 2026). Every page load calls
 * GET /admin/auth/me. It sat inside the login limiter (5 a minute per IP), so
 * a shop of tablets behind one address shared five page loads a minute and
 * the sixth read "Can't reach Bethany House".
 *
 * /me and /logout now carry only the per-user API limit. Login itself must
 * stay at five a minute per address: that limiter is for guessing passwords.
 */
class SessionCheckRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP_IP = '197.248.10.20';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function staff(): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::findByName('tailor', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    public function test_a_shop_behind_one_address_can_load_pages_more_than_five_times_a_minute(): void
    {
        // Three tailors on three tablets, one router: 12 page loads.
        $tokens = collect(range(1, 3))->map(fn () => $this->staff()->createToken('tablet')->plainTextToken);

        for ($i = 0; $i < 12; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => self::SHOP_IP])
                ->withToken($tokens[$i % 3])
                ->getJson('/api/v1/admin/auth/me')
                ->assertOk();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_login_is_still_limited_to_five_tries_a_minute_per_address(): void
    {
        $u = $this->staff();

        for ($i = 1; $i <= 5; $i++) {
            $res = $this->withServerVariables(['REMOTE_ADDR' => self::SHOP_IP])
                ->postJson('/api/v1/admin/auth/login', ['email' => $u->email, 'password' => 'wrong-' . $i]);
            $this->assertNotSame(429, $res->status(), "attempt {$i} should reach the login check");
        }

        $this->withServerVariables(['REMOTE_ADDR' => self::SHOP_IP])
            ->postJson('/api/v1/admin/auth/login', ['email' => $u->email, 'password' => 'wrong-6'])
            ->assertStatus(429);
    }

    public function test_sign_out_does_not_spend_the_login_allowance(): void
    {
        // Five failed logins use up the address's login allowance; staff
        // already signed in on that network can still sign out.
        $u = $this->staff();
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => self::SHOP_IP])
                ->postJson('/api/v1/admin/auth/login', ['email' => $u->email, 'password' => 'wrong']);
        }

        $token = $this->staff()->createToken('tablet')->plainTextToken;
        $this->withServerVariables(['REMOTE_ADDR' => self::SHOP_IP])
            ->withToken($token)
            ->postJson('/api/v1/admin/auth/logout')
            ->assertOk();
    }
}
