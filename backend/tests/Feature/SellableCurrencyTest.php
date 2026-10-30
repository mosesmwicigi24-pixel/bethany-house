<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\CurrencyPricing;
use App\Services\PaymentCorrections;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\StepsUp;
use Tests\TestCase;

/**
 * Step 1 of the currency plan (owner, 2026-10-18): stop new wrong entries.
 *
 *  - a sale, quotation or order is never in a currency the books can't state
 *    in shillings (no reporting rate): named outright it is refused, derived
 *    from a foreign customer's country it falls back to USD (owner,
 *    2026-10-29; it was the shop's own currency until then);
 *  - a shipping fee set up in KES is charged in the order's currency at the
 *    pricing rate (KES 500 → USD 5.00 at 100 KES/USD), never as-is;
 *  - M-Pesa takes shillings only, for the balance due;
 *  - a payment moves only between orders in its own currency, and both
 *    orders' paid status follows;
 *  - money awaiting review is not yet paid: a cart stays a cart until finance
 *    approves the proof;
 *  - a currency in use cannot be deleted; a new one waits for its rates.
 *
 * Rates in the fixture: USD pricing 0.01 (100 KES/USD), reporting 128; GBP
 * active with NO reporting rate. Figures below are worked out by hand.
 */
class SellableCurrencyTest extends TestCase
{
    use RefreshDatabase;
    use StepsUp;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        $this->outlet = Outlet::factory()->create(['country_code' => 'KE']);

        DB::table('currencies')->updateOrInsert(['code' => 'KES'], ['name' => 'Kenyan Shilling', 'symbol' => 'KES',
            'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$',
            'exchange_rate' => 0.01, 'reporting_rate_to_kes' => 128, 'is_base' => false, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'GBP'], ['name' => 'Pound', 'symbol' => '£',
            'exchange_rate' => 0.0065, 'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true]);
        foreach ([['KE', 'Kenya', 'KES'], ['US', 'United States', 'USD'], ['GB', 'United Kingdom', 'GBP']] as [$code, $name, $cur]) {
            DB::table('countries')->updateOrInsert(['code' => $code], ['name' => $name, 'default_currency_code' => $cur,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        ReportingCurrency::forget();
        CurrencyPricing::forget();
    }

    private function person(string $role): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        $u->outlets()->attach($this->outlet->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function cashier(): User
    {
        $u = $this->person('outlet_manager');
        CashRegister::create([
            'outlet_id' => $this->outlet->id, 'register_name' => 'Till 1', 'status' => 'open', 'currency_code' => 'KES',
            'opening_balance' => 1000, 'expected_cash' => 1000, 'opened_by' => $u->id, 'opened_at' => now(),
        ]);
        Sanctum::actingAs($u);

        return $u;
    }

    private function product(float $kes = 1000): Product
    {
        $p = Product::factory()->create();
        DB::table('product_translations')->insert(['product_id' => $p->id, 'language_code' => 'en', 'name' => 'Chasuble', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_prices')->insert(['product_id' => $p->id, 'product_variant_id' => null, 'currency_code' => 'KES',
            'regular_price' => $kes, 'cost_price' => 100, 'created_at' => now(), 'updated_at' => now()]);
        InventoryItem::create(['product_id' => $p->id, 'product_variant_id' => null, 'outlet_id' => $this->outlet->id,
            'quantity_on_hand' => 100, 'quantity_reserved' => 0, 'reorder_point' => 0]);

        return $p;
    }

    private function courier(float $kesFee = 500): int
    {
        $zone = DB::table('shipping_zones')->insertGetId(['name' => 'Nairobi', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('shipping_methods')->insertGetId([
            'shipping_zone_id' => $zone, 'name' => 'Courier', 'cost_type' => 'flat_rate', 'flat_rate' => $kesFee,
            'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function pendingOrder(array $extra): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/admin/pos/pending-order', $extra + [
            'outlet_id' => $this->outlet->id,
            'items'     => [['product_id' => $this->product()->id, 'quantity' => 1, 'unit_price' => 1000]],
        ]);
    }

    // ── which currencies may be sold in ─────────────────────────────────────

    public function test_a_sale_for_a_customer_whose_currency_has_no_reporting_rate_is_rung_in_usd(): void
    {
        $this->cashier();

        // Owner, 2026-10-29: a foreign customer is never billed in the shop's
        // own currency by default — GBP can't be sold, so the UK pays in USD,
        // the same answer Set Country / Currency gives (one rule).
        $gb = $this->pendingOrder(['customer_country_code' => 'GB', 'customer_first_name' => 'Ann'])->assertSuccessful();
        $this->assertSame('USD', Order::find($gb->json('order.id') ?? $gb->json('id'))->currency_code, 'GBP has no reporting rate: the rest-of-the-world currency');

        $us = $this->pendingOrder(['customer_country_code' => 'US', 'customer_first_name' => 'Bob'])->assertSuccessful();
        $this->assertSame('USD', Order::find($us->json('order.id') ?? $us->json('id'))->currency_code, 'USD has one');

        // The public list the till reads says which ones it may sell in.
        $list = collect($this->getJson('/api/v1/settings/currencies')->assertOk()->json('data'))->keyBy('code');
        $this->assertTrue($list['USD']['sellable']);
        $this->assertFalse($list['GBP']['sellable']);
    }

    public function test_a_quotation_or_purchase_order_named_in_such_a_currency_is_refused(): void
    {
        Sanctum::actingAs($this->person('super_admin'));
        $quote = fn (string $c) => $this->postJson('/api/v1/admin/quotations', [
            'customer_first_name' => 'Jane', 'currency_code' => $c,
            'items' => [['product_name' => 'Cope', 'quantity' => 1, 'unit_price' => 100]],
        ]);
        $quote('GBP')->assertStatus(422)->assertJsonValidationErrors('currency_code');
        $this->assertStringContainsString('no reporting rate for GBP', $quote('GBP')->json('errors.currency_code.0'));
        $quote('USD')->assertSuccessful();

        $this->postJson('/api/v1/admin/quotations/reprice', ['from' => 'KES', 'to' => 'GBP', 'lines' => [['key' => 'a', 'unit_price' => 10]]])
            ->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_changing_an_orders_country_never_lands_it_in_an_unconvertible_currency(): void
    {
        Sanctum::actingAs($this->person('super_admin'));
        $order = Order::factory()->create(['currency_code' => 'KES', 'payment_status' => 'pending', 'status' => 'pending', 'total_amount' => 0, 'subtotal' => 0]);

        // GBP can't be sold in: a UK customer is priced like the rest of the
        // world (USD), never in pounds.
        $this->postJson("/api/v1/admin/orders/{$order->id}/update-currency", ['country_code' => 'GB'])->assertSuccessful();
        $this->assertSame('USD', $order->fresh()->currency_code);
        $this->postJson("/api/v1/admin/orders/{$order->id}/update-currency", ['country_code' => 'US'])->assertSuccessful();
        $this->assertSame('USD', $order->fresh()->currency_code);
    }

    // ── shipping in the order's currency ────────────────────────────────────

    public function test_a_kes_shipping_fee_is_charged_in_the_orders_currency_at_the_pricing_rate(): void
    {
        $courier = $this->courier(500);
        $this->cashier();

        // KES 500 at the pricing rate (0.01 per KES) = USD 5.00; the typed
        // figure is ignored once a method is chosen.
        $res = $this->pendingOrder(['customer_country_code' => 'US', 'customer_first_name' => 'Bob',
            'shipping_method_id' => $courier, 'shipping_amount' => 500])->assertSuccessful();
        $order = Order::find($res->json('order.id') ?? $res->json('id'));
        $this->assertSame('USD', $order->currency_code);
        $this->assertSame('5.00', (string) $order->shipping_amount);

        // The list the till shows prices each method in the cart's currency.
        $rows = collect($this->getJson('/api/v1/admin/pos/shipping-methods?currency=USD')->assertOk()->json('data'))->keyBy('id');
        $this->assertEquals(5.0, $rows[$courier]['fee']);
        $this->assertEquals(500.0, $rows[$courier]['flat_rate']);

        // Setting a method on an existing USD order charges USD 5.00 too.
        Sanctum::actingAs($this->person('super_admin'));
        $usd = Order::factory()->create(['currency_code' => 'USD', 'status' => 'processing', 'payment_status' => 'pending', 'total_amount' => 100, 'subtotal' => 100, 'shipping_amount' => 0]);
        $this->patchJson("/api/v1/admin/orders/{$usd->id}/shipping-fee", ['amount' => 500, 'shipping_method_id' => $courier])->assertSuccessful();
        $this->assertSame('5.00', (string) $usd->fresh()->shipping_amount);
        $this->assertSame('105.00', (string) $usd->fresh()->total_amount);

        // A custom fee (no method) is taken in the order's currency, as a
        // whole unit (CustomerMoney): 7.50 → 8.
        $this->patchJson("/api/v1/admin/orders/{$usd->id}/shipping-fee", ['amount' => 7.5])->assertSuccessful();
        $this->assertSame('8.00', (string) $usd->fresh()->shipping_amount);
    }

    // ── M-Pesa ──────────────────────────────────────────────────────────────

    public function test_mpesa_takes_shillings_only(): void
    {
        Sanctum::actingAs($this->person('super_admin'));
        $usd = Order::factory()->create(['currency_code' => 'USD', 'status' => 'processing', 'payment_status' => 'pending', 'total_amount' => 100]);
        $this->postJson("/api/v1/admin/orders/{$usd->id}/payment", ['payment_method' => 'mpesa', 'phone' => '0722123456'])
            ->assertStatus(422);
        $this->assertStringContainsString('shillings', $this->postJson("/api/v1/admin/orders/{$usd->id}/payment", ['payment_method' => 'mpesa', 'phone' => '0722123456'])->json('message'));
        $this->assertSame(0, Payment::where('order_id', $usd->id)->count());

        // The customer's own pay page says the same.
        $usd->update(['payment_token' => 'tok-usd-1']);
        $this->postJson('/api/v1/pay/tok-usd-1/initiate', ['method' => 'mpesa', 'phone' => '0722123456'])->assertStatus(422);
    }

    // ── moving a payment ────────────────────────────────────────────────────

    public function test_a_payment_moves_only_within_its_currency_and_both_orders_statuses_follow(): void
    {
        $fm = $this->person('finance_manager');
        $from = Order::factory()->create(['currency_code' => 'KES', 'status' => 'processing', 'payment_status' => 'paid', 'total_amount' => 5000]);
        $pay  = Payment::create(['order_id' => $from->id, 'payment_method' => 'cash', 'amount' => 5000, 'currency_code' => 'KES', 'status' => 'paid']);
        $usd  = Order::factory()->create(['currency_code' => 'USD', 'status' => 'processing', 'payment_status' => 'pending', 'total_amount' => 50]);
        $kes  = Order::factory()->create(['currency_code' => 'KES', 'status' => 'processing', 'payment_status' => 'pending', 'total_amount' => 5000]);

        Sanctum::actingAs($fm);
        $this->postJson("/api/v1/admin/payment-transactions/{$pay->id}/reassign-request", ['order_id' => $usd->id, 'reason' => 'Wrong order'])
            ->assertStatus(422);
        try {
            PaymentCorrections::reassign($pay, $usd->id, 'Wrong order', $fm);
            $this->fail('a KES payment must not land on a USD order');
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
        }
        $this->assertSame($from->id, (int) $pay->fresh()->order_id);

        PaymentCorrections::reassign($pay, $kes->id, 'Paid for the other order', $fm);
        $this->assertSame('pending', $from->fresh()->payment_status, 'the money left');
        $this->assertSame('paid', $kes->fresh()->payment_status, 'and arrived');
        $this->assertSame(5000.0, $kes->fresh()->totalPaid());
    }

    // ── money awaiting review ───────────────────────────────────────────────

    public function test_a_bank_proof_awaiting_review_does_not_make_a_cart_a_sale(): void
    {
        $order = Order::factory()->create(['currency_code' => 'KES', 'status' => 'pending', 'payment_status' => 'pending', 'total_amount' => 1000]);
        $pay = Payment::create(['order_id' => $order->id, 'payment_method' => 'bank_transfer', 'amount' => 1000, 'currency_code' => 'KES',
            'status' => 'paid', 'requires_approval' => true, 'approval_status' => 'pending_review']);

        $this->assertSame(0.0, $order->totalPaid());
        $this->assertSame(1000.0, $order->outstandingBalance());
        $order->syncPaymentStatus();
        $this->assertSame('pending', $order->fresh()->payment_status, 'still a cart');
        $this->assertSame(0, Order::recognised()->whereKey($order->id)->count(), 'not a sale');

        $pay->update(['approval_status' => 'approved']);
        $this->assertSame(1000.0, $order->totalPaid());
        $order->syncPaymentStatus();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(1, Order::recognised()->whereKey($order->id)->count(), 'now a sale');
    }

    // ── the currency table itself ───────────────────────────────────────────

    public function test_a_currency_in_use_is_not_deleted_and_a_new_one_waits_for_its_rates(): void
    {
        $owner = $this->person('super_admin');
        Sanctum::actingAs($owner);
        $this->stepUp($owner);   // currency edits are a step-up route
        Order::factory()->create(['currency_code' => 'USD', 'status' => 'processing', 'total_amount' => 10]);
        DB::table('countries')->where('code', 'US')->update(['default_currency_code' => 'KES']);   // so the orders guard is the one that fires
        $usdId = DB::table('currencies')->where('code', 'USD')->value('id');
        $this->assertStringContainsString('1 orders', $this->deleteJson("/api/v1/admin/currencies-management/{$usdId}")->assertStatus(422)->json('message'));
        $this->assertNotNull(DB::table('currencies')->find($usdId));

        $res = $this->postJson('/api/v1/admin/currencies-management', [
            'code' => 'ZAR', 'name' => 'Rand', 'symbol' => 'R', 'exchange_rate' => 0.14, 'reporting_rate_to_kes' => 7.2, 'decimal_places' => 2,
        ])->assertStatus(201);
        $row = DB::table('currencies')->where('code', 'ZAR')->first();
        $this->assertNull($row->reporting_rate_to_kes, 'the rate waits for approval');
        $this->assertEquals(1.0, (float) $row->exchange_rate);
        $this->assertCount(2, $res->json('proposals'));
        $this->assertFalse(\App\Support\SellableCurrency::isSellable('ZAR'));
    }
}
