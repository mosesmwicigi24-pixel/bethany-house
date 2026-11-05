<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\CurrencyPricing;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\StepsUp;
use Tests\TestCase;

/**
 * "Bill in" USD or the customer's own currency (owner, 2026-11-05), before
 * and after payment. A payment keeps what it was paid in (cash truth); after
 * a switch it counts its share of the order in the new currency
 * (App\Support\PaymentCredit). Every expected figure is worked by hand.
 *
 * Prices: one shirt, USD 25 on the list; the rand is priced from the USD list
 * at R18 per dollar (customer rate 0.18 per shilling, 100 KES/USD), so the
 * shirt is R450.
 */
class BillCurrencyTest extends TestCase
{
    use RefreshDatabase, StepsUp;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        DB::table('settings')->updateOrInsert(['key' => 'app_country'], ['value' => 'KE']);
        $this->outlet = Outlet::factory()->create(['country_code' => 'KE', 'sales_channel' => 'whatsapp']);
        foreach ([['KES', 1, 1, true, null], ['USD', 0.01, 128, false, null], ['ZAR', 0.18, 7.1, false, 'USD']] as [$code, $pricing, $reporting, $base, $from]) {
            DB::table('currencies')->updateOrInsert(['code' => $code], ['name' => $code, 'symbol' => $code,
                'exchange_rate' => $pricing, 'reporting_rate_to_kes' => $reporting, 'is_base' => $base, 'is_active' => true,
                'priced_from' => $from, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([['KE', 'Kenya', 'KES'], ['ZA', 'South Africa', 'ZAR']] as [$code, $name, $cur]) {
            DB::table('countries')->updateOrInsert(['code' => $code], ['name' => $name, 'default_currency_code' => $cur,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        ReportingCurrency::forget();
        CurrencyPricing::forget();
    }

    private function as(array $permissions, ?string $role = null): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        if ($role) $u->assignRole(Role::findByName($role, 'sanctum'));
        foreach ($permissions as $p) $u->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        $u->outlets()->sync([$this->outlet->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);

        return $u;
    }

    /** A South African's shirt order, in rand by the rule. */
    private function shirtOrder(): Order
    {
        $p = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        ProductPrice::create(['product_id' => $p->id, 'product_variant_id' => null, 'currency_code' => 'KES', 'regular_price' => 3200]);
        ProductPrice::create(['product_id' => $p->id, 'product_variant_id' => null, 'currency_code' => 'USD', 'regular_price' => 25]);
        InventoryItem::create(['product_id' => $p->id, 'product_variant_id' => null, 'outlet_id' => $this->outlet->id,
            'quantity_on_hand' => 5, 'quantity_reserved' => 0, 'reorder_point' => 0]);
        $this->as(['pos.access']);
        $id = $this->postJson('/api/v1/admin/pos/pending-order', [
            'outlet_id' => $this->outlet->id, 'channel' => 'whatsapp', 'customer_country_code' => 'ZA',
            'customer_first_name' => 'Emmanuel', 'items' => [['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 3200]],
        ])->assertSuccessful()->json('order_id');

        return Order::findOrFail($id);
    }

    private function bill(Order $o, string $ccy, array $extra = [])
    {
        return $this->postJson("/api/v1/admin/orders/{$o->id}/bill-currency", ['currency_code' => $ccy] + $extra);
    }

    /** A settled payment, as recorded: in the order's currency of the moment. */
    private function pay(Order $o, float $amount, string $ccy): int
    {
        $id = DB::table('payments')->insertGetId([
            'order_id' => $o->id, 'payment_number' => 'PAY-' . uniqid(), 'amount' => $amount, 'currency_code' => $ccy,
            'reporting_rate_to_kes' => $ccy === 'USD' ? 128 : 7.1, 'status' => 'paid', 'payment_method' => 'other',
            'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $o->fresh()->syncPaymentStatus();

        return $id;
    }

    public function test_before_payment_staff_choose_usd_or_the_rand_and_nothing_else(): void
    {
        $o = $this->shirtOrder();
        $this->assertSame('ZAR', $o->currency_code);
        $this->assertEqualsWithDelta(450, (float) $o->total_amount, 0.01);       // USD 25 × R18

        $this->as(['orders.view', 'orders.edit']);
        $this->bill($o, 'USD')->assertOk()->assertJsonPath('currency_code', 'USD');
        $this->assertEqualsWithDelta(25, (float) $o->fresh()->total_amount, 0.01);
        $this->bill($o, 'KES')->assertStatus(422);                               // not one of the two
        $this->bill($o, 'ZAR')->assertOk();
        $this->assertEqualsWithDelta(450, (float) $o->fresh()->total_amount, 0.01);
    }

    public function test_after_payment_only_the_super_admin_switches_and_the_payment_keeps_its_dollars(): void
    {
        $o = $this->shirtOrder();
        $this->as(['orders.view', 'orders.edit']);
        $this->bill($o, 'USD')->assertOk();
        $payId = $this->pay($o, 25, 'USD');                                      // paid in full in dollars
        $this->assertSame('paid', $o->fresh()->payment_status);

        // Staff: refused once money has moved.
        $this->bill($o, 'ZAR')->assertStatus(403);
        $this->assertSame('USD', $o->fresh()->currency_code);

        // The super admin: step-up and a reason first.
        $boss = $this->as([], 'super_admin');
        $this->assertNotSame(200, $this->bill($o, 'ZAR', ['reason' => 'Customer pays in rand'])->status());
        $this->stepUp($boss);
        $this->bill($o, 'ZAR')->assertStatus(422);                               // no reason
        $this->bill($o, 'ZAR', ['reason' => 'Customer pays in rand'])->assertOk()
            ->assertJsonPath('currency_code', 'ZAR')->assertJsonPath('payment_status', 'paid');

        $o = $o->fresh();
        $pay = DB::table('payments')->find($payId);
        $this->assertEqualsWithDelta(450, (float) $o->total_amount, 0.01);       // R450 shirt
        $this->assertSame('USD', $pay->currency_code);                           // the dollars that arrived
        $this->assertEqualsWithDelta(25, (float) $pay->amount, 0.001);
        $this->assertEqualsWithDelta(128, (float) $pay->reporting_rate_to_kes, 0.001, 'cash is reported as the dollars it was');
        $this->assertEqualsWithDelta(450, (float) $pay->order_amount, 0.001);    // counts the whole R450
        $this->assertSame('ZAR', $pay->order_currency_code);
        $this->assertEqualsWithDelta(0, $o->outstandingBalance(), 0.001);
        $this->assertDatabaseHas('activity_log', ['event' => 'currency_changed', 'subject_id' => $o->id, 'causer_id' => $boss->id]);

        // And back: the dollars count as themselves again.
        $this->bill($o, 'USD', ['reason' => 'Back to dollars'])->assertOk();
        $pay = DB::table('payments')->find($payId);
        $this->assertNull($pay->order_currency_code);
        $this->assertSame('paid', $o->fresh()->payment_status);
        $this->assertEqualsWithDelta(0, $o->fresh()->outstandingBalance(), 0.001);
    }

    public function test_a_part_payment_counts_the_same_share_and_a_refund_goes_back_in_dollars(): void
    {
        $o = $this->shirtOrder();
        $this->as(['orders.view', 'orders.edit']);
        $this->bill($o, 'USD')->assertOk();
        $payId = $this->pay($o, 10, 'USD');                                      // 10 of 25 = 40%
        $boss = $this->as([], 'super_admin');
        $this->stepUp($boss);
        $this->bill($o, 'ZAR', ['reason' => 'Customer pays in rand'])->assertOk()->assertJsonPath('payment_status', 'partial');

        $o = $o->fresh();
        $this->assertEqualsWithDelta(180, (float) DB::table('payments')->find($payId)->order_amount, 0.001);   // 40% of R450
        $this->assertEqualsWithDelta(270, $o->outstandingBalance(), 0.001);                                     // R450 − R180

        // Refund R90 of it: half of what the payment counts for → USD 5 back.
        DB::table('orders')->where('id', $o->id)->update(['status' => 'delivered']);   // refunds follow delivery
        $this->postJson("/api/v1/admin/orders/{$o->id}/refund", ['amount' => 90, 'reason' => 'Customer changed the colour'])->assertOk();
        $this->assertEqualsWithDelta(5, (float) DB::table('payments')->find($payId)->refund_amount, 0.001);
        $this->assertEqualsWithDelta(90, $o->fresh()->totalPaid(), 0.001);                                    // (10 − 5) × 450/25... = R90
    }

    public function test_a_payment_waiting_for_approval_moves_with_the_order_too(): void
    {
        $o = $this->shirtOrder();
        $this->as(['orders.view', 'orders.edit']);
        $this->bill($o, 'USD')->assertOk();
        $payId = DB::table('payments')->insertGetId([
            'order_id' => $o->id, 'payment_number' => 'PAY-' . uniqid(), 'amount' => 25, 'currency_code' => 'USD', 'status' => 'pending',
            'payment_method' => 'other', 'requires_approval' => true, 'approval_status' => 'pending_review',
            'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);
        $this->bill($o, 'ZAR')->assertStatus(403);                               // money has moved: staff may not

        $boss = $this->as([], 'super_admin');
        $this->stepUp($boss);
        $this->bill($o, 'ZAR', ['reason' => 'Customer pays in rand'])->assertOk();
        $pay = DB::table('payments')->find($payId);
        $this->assertEqualsWithDelta(450, (float) $pay->order_amount, 0.001);    // once approved it settles the R450
        $this->assertSame('ZAR', $pay->order_currency_code);
    }

    public function test_the_buyer_chooses_dollars_or_rand_on_the_payment_page_until_money_moves(): void
    {
        $o = $this->shirtOrder();
        DB::table('orders')->where('id', $o->id)->update(['payment_token' => 'tok-' . $o->id, 'payment_token_expires_at' => now()->addDay()]);
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/pay/tok-{$o->id}")->assertOk()
            ->assertJsonPath('currency_code', 'ZAR')->assertJsonPath('currency_choices', ['ZAR', 'USD']);
        $this->postJson("/api/v1/pay/tok-{$o->id}/currency", ['currency_code' => 'GBP'])->assertStatus(422);
        $this->postJson("/api/v1/pay/tok-{$o->id}/currency", ['currency_code' => 'USD'])->assertOk()->assertJsonPath('currency_code', 'USD');
        $this->getJson("/api/v1/pay/tok-{$o->id}")->assertJsonPath('amount_due', 25);   // USD 25
        $this->assertDatabaseHas('activity_log', ['event' => 'currency_changed', 'subject_id' => $o->id]);

        // Once a payment is on its way the choice is gone and refused.
        DB::table('payments')->insert(['order_id' => $o->id, 'payment_number' => 'PAY-' . uniqid(), 'amount' => 25, 'currency_code' => 'USD',
            'status' => 'pending', 'payment_method' => 'other', 'requires_approval' => true, 'approval_status' => 'pending_review',
            'created_at' => now(), 'updated_at' => now()]);
        $this->getJson("/api/v1/pay/tok-{$o->id}")->assertJsonPath('currency_choices', []);
        $this->postJson("/api/v1/pay/tok-{$o->id}/currency", ['currency_code' => 'ZAR'])->assertStatus(409);
        $this->assertSame('USD', $o->fresh()->currency_code);
    }

    public function test_the_check_finds_an_order_like_1158_and_fixes_it_only_when_told_to(): void
    {
        // As #1158 was: USD 25 paid on a USD order, then the order moved to rand
        // before the guard existed, leaving the payment saying "25".
        $o = $this->shirtOrder();
        $this->as(['orders.view', 'orders.edit']);
        $this->bill($o, 'USD')->assertOk();
        $payId = $this->pay($o, 25, 'USD');
        DB::table('orders')->where('id', $o->id)->update(['currency_code' => 'ZAR', 'total_amount' => 450, 'subtotal' => 450]);
        DB::table('order_items')->where('order_id', $o->id)->update(['unit_price' => 450, 'total_price' => 450]);
        $o = $o->fresh();
        $this->assertEqualsWithDelta(425, $o->outstandingBalance(), 0.001);   // the wrong figure: 450 − "25"
        $boss = User::factory()->create(['status' => 'active', 'email' => 'owner@bethany.test']);

        $this->artisan('orders:payment-currency')
            ->expectsOutputToContain('Orders with a payment in another currency and no counted amount: 1')
            ->expectsOutputToContain('USD 25.00 [paid] → would count ZAR 450.00')
            ->assertSuccessful();
        $this->assertNull(DB::table('payments')->find($payId)->order_currency_code, 'listing writes nothing');

        $this->artisan('orders:payment-currency', ['--apply' => true, '--order' => (string) $o->id, '--actor' => 'owner@bethany.test'])
            ->expectsOutputToContain('now counts ZAR 450.00')->assertSuccessful();
        $this->assertEqualsWithDelta(0, $o->fresh()->outstandingBalance(), 0.001);
        $this->assertSame('paid', $o->fresh()->payment_status);
        $this->assertSame('USD', DB::table('payments')->find($payId)->currency_code);
        $this->artisan('orders:payment-currency')->expectsOutputToContain('no counted amount: 0')->assertSuccessful();
    }
}
