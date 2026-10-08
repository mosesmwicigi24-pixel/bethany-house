<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\Auth\SessionPolicy;
use App\Services\CurrencyPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Neema — the WhatsApp sales agent — creates pending orders through
 * /api/v1/admin/pos/pending-order with a long-lived token on a user_type
 * 'customer' account (neema-bot@…). The staff gate (#332, 2026-08-22) refused
 * every non-staff account on /admin/*, so from 2026-08-24 no order Neema
 * placed was created: 151 attempts, 76 conversations, all 403 "This action is
 * unauthorized." (proven 2026-10-05 with the live token).
 *
 * The fix names service accounts (config security.service_accounts, exact
 * email, active only): they pass the staff gate and NOTHING else changes —
 * per-route permissions still decide, console sign-in / 2FA / staff session
 * limits still do not apply (canAccessAdmin() untouched), and a storefront
 * customer is still refused.
 */
class ServiceAccountGateTest extends TestCase
{
    use RefreshDatabase;

    private const NEEMA = 'neema-bot@bethanyhouse.co.ke';

    private ?Outlet $outlet = null;

    protected function setUp(): void
    {
        parent::setUp();
        CurrencyPricing::forget();
        config(['security.service_accounts' => [self::NEEMA]]);
    }

    private function outlet(): Outlet
    {
        return $this->outlet ??= Outlet::factory()->create([
            'sales_channel' => 'whatsapp',
            'country_code'  => 'KE',
        ]);
    }

    /** Neema's production shape: customer type, till permission, two outlets. */
    private function account(string $email = self::NEEMA, string $status = 'active'): User
    {
        $user = User::factory()->customer()->create(['email' => $email, 'status' => $status]);
        $user->givePermissionTo(Permission::findOrCreate('pos.access', 'sanctum'));
        $user->outlets()->sync([$this->outlet()->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function product(): Product
    {
        DB::table('currencies')->updateOrInsert(['code' => 'KES'], [
            'name' => 'KES', 'symbol' => 'KES', 'exchange_rate' => 1.0, 'is_base' => true,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('countries')->updateOrInsert(['code' => 'KE'], [
            'name' => 'Kenya', 'default_currency_code' => 'KES', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        CurrencyPricing::forget();

        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        ProductPrice::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'currency_code' => 'KES', 'regular_price' => 18000,
        ]);
        InventoryItem::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'outlet_id' => $this->outlet()->id, 'quantity_on_hand' => 10,
            'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);

        return $product;
    }

    private function order(Product $product)
    {
        return $this->postJson('/api/v1/admin/pos/pending-order', [
            'outlet_id'             => $this->outlet()->id,
            'channel'               => 'whatsapp',
            'customer_country_code' => 'KE',
            'items'                 => [[
                'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 18000,
            ]],
        ]);
    }

    public function test_neema_creates_a_pending_order_again(): void
    {
        $product = $this->product();
        $this->account();

        $res = $this->order($product)->assertSuccessful();

        $order = Order::find($res->json('order_id'));
        $this->assertNotNull($order);
        $this->assertEqualsWithDelta(18000.0, (float) $order->total_amount, 0.01);
        $this->getJson('/api/v1/admin/pos/outlets')->assertOk();
    }

    public function test_past_the_gate_its_permissions_still_decide(): void
    {
        $this->account();
        // payments.transactions is not Neema's — the per-route gate refuses it.
        $this->getJson('/api/v1/admin/payment-transactions')->assertForbidden();
    }

    public function test_a_customer_with_the_same_permissions_is_still_refused(): void
    {
        $product = $this->product();
        $this->account('someone@example.com');

        $this->order($product)->assertForbidden()
            ->assertJson(['message' => 'This action is unauthorized.']);
        $this->getJson('/api/v1/admin/sidebar-badges')->assertForbidden();
    }

    public function test_an_inactive_or_unlisted_service_account_is_refused(): void
    {
        $product = $this->product();
        $this->account(self::NEEMA, 'inactive');
        $this->order($product)->assertForbidden();

        config(['security.service_accounts' => []]);
        $this->account('neema-bot2@bethanyhouse.co.ke');
        $this->order($product)->assertForbidden();
    }

    public function test_a_service_account_is_still_not_staff(): void
    {
        $user = $this->account();

        $this->assertTrue($user->isServiceAccount());
        // Console sign-in, 2FA and staff session limits key off canAccessAdmin():
        // a service account's long-lived token is not cut by the 12-hour limit.
        $this->assertFalse($user->canAccessAdmin());
        $this->assertNull(app(SessionPolicy::class)->limitsFor($user));
    }

    public function test_the_email_match_is_exact_and_case_insensitive(): void
    {
        $upper = User::factory()->customer()->create(['email' => 'Neema-Bot@BethanyHouse.co.ke']);
        $this->assertTrue($upper->isServiceAccount());

        $lookalike = User::factory()->customer()->create(['email' => 'neema-bot@bethanyhouse.co.ke.evil.com']);
        $this->assertFalse($lookalike->isServiceAccount());
    }
}
