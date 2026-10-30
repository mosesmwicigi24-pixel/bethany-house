<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The token "customer realm" is gone.
 *
 * /auth/login, /auth/register, /auth/user, /cart, /customer/* and /checkout were
 * built for a hub customer portal that was never finished. User never had the
 * `customer` relation they read, so login answered 500 after minting a token it
 * never returned, and the cart wrote columns that do not exist. In production
 * (checked 2026-10-30) nothing had ever got through: no such error in the log
 * since May, no request in 14 days, no cart row, and all 49 online orders carry
 * no user — the storefront has its own OTP identity under /storefront/*.
 *
 * Giving User the relation would have made /auth/login work for staff too, past
 * the console's staff gate and new-device check. So the door was removed instead.
 */
class CustomerRealmRetiredTest extends TestCase
{
    use RefreshDatabase;

    public static function retiredRoutes(): array
    {
        return [
            ['GET',    '/api/v1/auth/user'],
            ['PUT',    '/api/v1/auth/profile'],
            ['POST',   '/api/v1/auth/logout'],
            ['POST',   '/api/v1/auth/change-password'],
            ['POST',   '/api/v1/auth/enable-2fa'],
            ['POST',   '/api/v1/auth/verify-2fa'],
            ['POST',   '/api/v1/auth/disable-2fa'],
            ['GET',    '/api/v1/customer/orders'],
            ['GET',    '/api/v1/customer/orders/1'],
            ['POST',   '/api/v1/customer/orders/1/cancel'],
            ['POST',   '/api/v1/customer/orders/1/return'],
            ['GET',    '/api/v1/customer/returns'],
            ['GET',    '/api/v1/customer/returns/1'],
            ['POST',   '/api/v1/customer/returns/1/cancel'],
            ['GET',    '/api/v1/customer/addresses'],
            ['POST',   '/api/v1/customer/addresses'],
            ['PUT',    '/api/v1/customer/addresses/1'],
            ['DELETE', '/api/v1/customer/addresses/1'],
            ['GET',    '/api/v1/customer/wishlist'],
            ['POST',   '/api/v1/customer/wishlist/1'],
            ['DELETE', '/api/v1/customer/wishlist/1'],
            ['POST',   '/api/v1/customer/products/1/reviews'],
            ['PUT',    '/api/v1/customer/reviews/1'],
            ['DELETE', '/api/v1/customer/reviews/1'],
            ['GET',    '/api/v1/cart'],
            ['POST',   '/api/v1/cart/items'],
            ['PUT',    '/api/v1/cart/items/1'],
            ['DELETE', '/api/v1/cart/items/1'],
            ['DELETE', '/api/v1/cart/clear'],
            ['POST',   '/api/v1/cart/apply-coupon'],
            ['DELETE', '/api/v1/cart/remove-coupon'],
            ['POST',   '/api/v1/checkout'],
            ['POST',   '/api/v1/orders/1/payment'],
        ];
    }

    /** @dataProvider retiredRoutes */
    public function test_the_route_is_gone(string $method, string $uri): void
    {
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->json($method, $uri)->assertNotFound();
    }

    public function test_the_customer_login_cannot_sign_in_staff_or_mint_a_token(): void
    {
        $staff = User::factory()->create(['password' => Hash::make('secret-pass-1'), 'status' => 'active']);

        $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'secret-pass-1'])
            ->assertNotFound();

        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $staff->id)->count());
    }

    public function test_public_registration_is_closed(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Sam Otieno',
            'email'                 => 'sam@example.com',
            'password'              => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ])->assertNotFound();

        $this->assertSame(0, User::where('email', 'sam@example.com')->count());
    }

    public function test_password_recovery_stays(): void
    {
        Notification::fake();

        foreach (['/api/v1/auth/forgot-password', '/api/v1/admin/auth/forgot-password'] as $uri) {
            $this->postJson($uri, ['email' => User::factory()->create()->email])->assertOk();
        }
    }
}
