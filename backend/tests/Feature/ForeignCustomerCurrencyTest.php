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
use App\Support\SellableCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * One rule for what a customer abroad is billed in (owner, 2026-10-29).
 *
 * The incident: order WA-261028 for a customer in Botswana went out with a
 * KES payment link. The till and chat orders gave a country whose own
 * currency can't be sold (BWP has no reporting rate) the shop's currency,
 * while Set Country / Currency gave the same customer USD. Now, everywhere:
 *
 *     Kenya, or no country         → KES
 *     own currency is sellable      → that (ZMW for Zambia, ZAR once it has a rate)
 *     anyone else                   → USD
 *
 * Fixture rates, worked by hand: USD pricing 0.01 (100 KES/USD), reporting
 * 128; ZMW and ZAR pricing and reporting rates set; BWP and GBP active with
 * NO reporting rate. The Botswana basket mirrors the screenshot: KES 15,000
 * + 3,600 + 12,000 = 30,600 → USD 150 + 36 + 120 = 306.00.
 */
class ForeignCustomerCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        DB::table('settings')->updateOrInsert(['key' => 'app_country'], ['value' => 'KE']);
        $this->outlet = Outlet::factory()->create(['country_code' => 'KE', 'sales_channel' => 'whatsapp']);

        foreach ([
            ['KES', 1,      1,    true],
            ['USD', 0.01,   128,  false],
            ['ZMW', 0.2,    6.5,  false],
            ['ZAR', 0.14,   7.1,  false],
            ['BWP', 0.1,    null, false],
            ['GBP', 0.0065, null, false],
        ] as [$code, $pricing, $reporting, $base]) {
            DB::table('currencies')->updateOrInsert(['code' => $code], ['name' => $code, 'symbol' => $code,
                'exchange_rate' => $pricing, 'reporting_rate_to_kes' => $reporting, 'is_base' => $base, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([['KE', 'Kenya', 'KES'], ['BW', 'Botswana', 'BWP'], ['GB', 'United Kingdom', 'GBP'], ['ZM', 'Zambia', 'ZMW'],
                  ['ZA', 'South Africa', 'ZAR'], ['US', 'United States', 'USD'], ['UG', 'Uganda', 'KES'], ['NA', 'Namibia', null]] as [$code, $name, $cur]) {
            DB::table('countries')->updateOrInsert(['code' => $code], ['name' => $name, 'default_currency_code' => $cur,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        ReportingCurrency::forget();
        CurrencyPricing::forget();
    }

    private function staff(array $permissions, ?string $role = null): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        if ($role) $u->assignRole(Role::findByName($role, 'sanctum'));
        foreach ($permissions as $p) $u->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        $u->outlets()->sync([$this->outlet->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);

        return $u;
    }

    private function product(float $kes, array $other = []): Product
    {
        $p = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        foreach (['KES' => $kes] + $other as $code => $amount) {
            ProductPrice::create(['product_id' => $p->id, 'product_variant_id' => null, 'currency_code' => $code, 'regular_price' => $amount]);
        }
        InventoryItem::create(['product_id' => $p->id, 'product_variant_id' => null, 'outlet_id' => $this->outlet->id,
            'quantity_on_hand' => 50, 'quantity_reserved' => 0, 'reorder_point' => 0]);

        return $p;
    }

    /** The screenshot's three lines, KES 15,000 + 3,600 + 12,000. */
    private function basket(): array
    {
        return [
            ['product_id' => $this->product(15000)->id, 'quantity' => 1, 'unit_price' => 15000],
            ['product_id' => $this->product(3600)->id,  'quantity' => 1, 'unit_price' => 3600],
            ['product_id' => $this->product(12000)->id, 'quantity' => 1, 'unit_price' => 12000],
        ];
    }

    private function chatOrder(?string $country, array $items = null)
    {
        return $this->postJson('/api/v1/admin/pos/pending-order', array_filter([
            'outlet_id'             => $this->outlet->id,
            'channel'               => 'whatsapp',
            'customer_country_code' => $country,
            'customer_first_name'   => 'Kairos',
            'items'                 => $items ?? $this->basket(),
        ], fn ($v) => $v !== null));
    }

    /** An order written before 29 Oct: a Botswana customer billed in KES. */
    private function legacyBotswanaOrder(): Order
    {
        $this->staff(['pos.access']);
        $id = $this->chatOrder(null)->assertSuccessful()->json('order_id');
        $order = Order::findOrFail($id);
        $this->assertSame('KES', $order->currency_code);
        DB::table('orders')->where('id', $id)->update(['customer_country_code' => 'BW', 'is_international' => true]);

        return $order->fresh(['items']);
    }

    // ── the rule ────────────────────────────────────────────────────────────

    public function test_one_rule_for_every_kind_of_country(): void
    {
        $cases = [
            'KE' => 'KES',   // home
            ''   => 'KES',   // no country
            'BW' => 'USD',   // own currency (BWP) has no reporting rate
            'GB' => 'USD',   // same: GBP can't be sold
            'ZM' => 'ZMW',   // own currency sellable
            'ZA' => 'ZAR',   // the rand, now it has a reporting rate
            'US' => 'USD',
            'UG' => 'USD',   // a foreign country misconfigured with KES is still billed abroad
            'NA' => 'USD',   // no currency on the row
            'XX' => 'USD',   // no row at all
        ];
        foreach ($cases as $country => $expected) {
            $this->assertSame($expected, SellableCurrency::forCustomer($country), "country {$country}");
            $this->assertSame($expected, Order::resolveCurrency($country), "Order::resolveCurrency {$country}");
        }

        // The countries lists carry the same answer for the console.
        $this->staff(['settings.view']);
        $public = collect($this->getJson('/api/v1/countries')->assertOk()->json('data'))->pluck('sale_currency', 'code');
        $admin  = collect($this->getJson('/api/v1/admin/countries')->assertOk()->json('data'))->pluck('sale_currency', 'code');
        foreach (['KE', 'BW', 'GB', 'ZM', 'ZA', 'UG', 'NA'] as $c) {
            $this->assertSame($cases[$c], $public[$c], "public {$c}");
            $this->assertSame($cases[$c], $admin[$c], "admin {$c}");
        }

        // The rand without a reporting rate can't be sold: South Africa falls to USD.
        DB::table('currencies')->where('code', 'ZAR')->update(['reporting_rate_to_kes' => null]);
        ReportingCurrency::forget();
        $this->assertSame('USD', SellableCurrency::forCustomer('ZA'));
    }

    // ── every path that bills a customer ────────────────────────────────────

    public function test_a_botswana_chat_order_is_billed_in_usd_and_reported_at_128(): void
    {
        $this->staff(['pos.access']);
        $res = $this->chatOrder('BW')->assertSuccessful();
        $order = Order::with('items')->findOrFail($res->json('order_id'));

        $this->assertSame('USD', $order->currency_code);
        $this->assertTrue((bool) $order->is_international);
        $this->assertEqualsWithDelta(306.00, (float) $order->total_amount, 0.001);                // 30,600 / 100
        $this->assertEqualsWithDelta([150.0, 36.0, 120.0], $order->items->sortBy('id')->pluck('unit_price')->map(fn ($v) => (float) $v)->values()->all(), 0.001);
        $this->assertEqualsWithDelta(128.0, (float) $order->reporting_rate_to_kes, 0.000001);     // the books: USD 306 = KES 39,168
    }

    public function test_a_zambian_chat_order_stays_in_kwacha_and_a_kenyan_one_in_shillings(): void
    {
        $this->staff(['pos.access']);
        $zm = Order::findOrFail($this->chatOrder('ZM')->assertSuccessful()->json('order_id'));
        $this->assertSame('ZMW', $zm->currency_code);
        $this->assertEqualsWithDelta(30600 * 0.2, (float) $zm->total_amount, 0.01);              // ZMW 6,120.00

        $ke = Order::findOrFail($this->chatOrder('KE')->assertSuccessful()->json('order_id'));
        $this->assertSame('KES', $ke->currency_code);
        $this->assertFalse((bool) $ke->is_international);
        $this->assertEqualsWithDelta(30600, (float) $ke->total_amount, 0.01);
    }

    public function test_editing_a_till_order_to_botswana_moves_it_to_usd(): void
    {
        $this->staff(['pos.access']);
        $items = $this->basket();
        $id = $this->postJson('/api/v1/admin/pos/pending-order', ['outlet_id' => $this->outlet->id, 'items' => $items])
            ->assertSuccessful()->json('order_id');
        $this->assertSame('KES', Order::find($id)->currency_code);

        // The till re-asks the price list in the customer's currency (sale_currency) and sends those prices.
        $usd = array_map(fn ($i) => ['unit_price' => $i['unit_price'] / 100] + $i, $items);
        $this->patchJson("/api/v1/admin/pos/pending-order/{$id}", [
            'outlet_id' => $this->outlet->id, 'customer_country_code' => 'BW', 'items' => $usd,
        ])->assertSuccessful();
        $order = Order::find($id);
        $this->assertSame('USD', $order->currency_code);
        $this->assertEqualsWithDelta(306.00, (float) $order->total_amount, 0.001);
        $this->assertEqualsWithDelta(128.0, (float) $order->reporting_rate_to_kes, 0.000001);
    }

    public function test_the_website_bills_zambia_in_kwacha_and_botswana_in_usd(): void
    {
        $cope = $this->product(15000, ['USD' => 150, 'ZMW' => 3000]);
        DB::table('products')->where('id', $cope->id)->update(['published_at' => now()->subDay()]);
        DB::table('product_translations')->insert(['product_id' => $cope->id, 'language_code' => 'en', 'name' => 'Cope', 'created_at' => now(), 'updated_at' => now()]);
        $slug = $cope->fresh()->slug;
        $order = fn (string $country) => $this->postJson('/api/v1/storefront/orders', [
            'customer' => ['first_name' => 'Kairos', 'last_name' => 'M', 'phone' => '+26772247160'],
            'delivery' => ['method' => 'delivery', 'address' => 'Main Mall', 'city' => 'Gaborone'],
            'payment_method' => 'card', 'country_code' => $country,
            'items' => [['slug' => $slug, 'quantity' => 1]],
        ]);

        $order('BW')->assertStatus(201)->assertJsonPath('order.currency_code', 'USD');
        $order('ZM')->assertStatus(201)->assertJsonPath('order.currency_code', 'ZMW');   // owner, 29 Oct: kwacha everywhere
    }

    // ── Set Country / Currency ──────────────────────────────────────────────

    public function test_set_country_fixes_a_botswana_order_left_in_kes_without_changing_the_country(): void
    {
        $order = $this->legacyBotswanaOrder();
        $this->staff(['orders.view', 'orders.edit']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/update-currency", ['country_code' => 'BW'])
            ->assertOk()->assertJsonPath('currency_code', 'USD')->assertJsonPath('changed', true);

        $order->refresh();
        $this->assertSame('USD', $order->currency_code);
        $this->assertEqualsWithDelta(306.00, (float) $order->total_amount, 0.001);
        $this->assertEqualsWithDelta(128.0, (float) $order->reporting_rate_to_kes, 0.000001);
        $this->assertDatabaseHas('activity_log', ['event' => 'currency_changed', 'subject_id' => $order->id]);

        // Again: nothing to do.
        $this->postJson("/api/v1/admin/orders/{$order->id}/update-currency", ['country_code' => 'BW'])->assertOk()->assertJsonPath('changed', false);
    }

    public function test_money_that_has_moved_is_never_restated_in_another_currency(): void
    {
        $order = $this->legacyBotswanaOrder();
        $this->staff(['orders.view', 'orders.edit']);
        $pay = fn (string $status, $at = null) => DB::table('payments')->insertGetId([
            'order_id' => $order->id, 'payment_number' => 'PAY-' . uniqid(), 'amount' => 1000, 'currency_code' => 'KES', 'status' => $status, 'payment_method' => 'mpesa',
            'created_at' => $at ?? now(), 'updated_at' => now(),
        ]);

        // An M-Pesa push from ten minutes ago may still land in shillings.
        $p = $pay('pending', now()->subMinutes(10));
        $this->postJson("/api/v1/admin/orders/{$order->id}/update-currency", ['country_code' => 'BW'])->assertStatus(422);
        $this->assertSame('KES', $order->fresh()->currency_code);

        // An hour-old push that never completed is dead: the switch may go ahead.
        DB::table('payments')->where('id', $p)->update(['created_at' => now()->subHour()]);
        DB::table('payments')->where('id', $pay('paid'))->delete();   // (and a paid row blocks: shown below)
        $this->postJson("/api/v1/admin/orders/{$order->id}/update-currency", ['country_code' => 'BW'])->assertOk()->assertJsonPath('currency_code', 'USD');

        // Part-paid: refused, untouched.
        $other = $this->legacyBotswanaOrder();
        $this->staff(['orders.view', 'orders.edit']);
        DB::table('payments')->insert(['order_id' => $other->id, 'payment_number' => 'PAY-' . uniqid(), 'amount' => 5000, 'currency_code' => 'KES', 'status' => 'paid', 'payment_method' => 'mpesa', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('orders')->where('id', $other->id)->update(['payment_status' => 'partial']);
        $this->postJson("/api/v1/admin/orders/{$other->id}/update-currency", ['country_code' => 'BW'])->assertStatus(422);
        $this->assertSame('KES', $other->fresh()->currency_code);
        $this->assertEqualsWithDelta(30600, (float) $other->fresh()->total_amount, 0.01);
    }

    // ── the payment link ────────────────────────────────────────────────────

    public function test_the_payment_link_stops_a_foreign_order_in_kes_until_it_is_switched_or_kept_with_a_reason(): void
    {
        $a = $this->legacyBotswanaOrder();
        $b = $this->legacyBotswanaOrder();
        $ke = Order::findOrFail($this->chatOrder('KE')->assertSuccessful()->json('order_id'));
        $this->staff(['orders.view', 'orders.edit']);

        $stop = $this->getJson("/api/v1/admin/orders/{$a->id}/payment-link")->assertStatus(409)
            ->assertJsonPath('code', 'foreign_customer_in_home_currency')
            ->assertJsonPath('suggested_currency', 'USD')
            ->assertJsonPath('country_name', 'Botswana');
        $this->assertStringContainsString('Botswana', $stop->json('message'));

        // Switch, then the link: the same order, now in USD.
        $this->postJson("/api/v1/admin/orders/{$a->id}/update-currency", ['country_code' => 'BW'])->assertOk();
        $this->getJson("/api/v1/admin/orders/{$a->id}/payment-link")->assertOk()->assertJsonStructure(['payment_url']);

        // Keep KES on purpose: needs a reason, is logged, and is not asked again.
        $this->getJson("/api/v1/admin/orders/{$b->id}/payment-link?keep_currency=1")->assertStatus(422);
        $this->getJson("/api/v1/admin/orders/{$b->id}/payment-link?keep_currency=1&reason=" . urlencode('Her sister in Nairobi pays by M-Pesa'))->assertOk();
        $this->assertDatabaseHas('activity_log', ['event' => 'currency_kept', 'subject_id' => $b->id]);
        $this->getJson("/api/v1/admin/orders/{$b->id}/payment-link")->assertOk();
        $this->assertSame('KES', $b->fresh()->currency_code);

        // A Kenyan order is never stopped.
        $this->getJson("/api/v1/admin/orders/{$ke->id}/payment-link")->assertOk();
    }

    // ── the repair ──────────────────────────────────────────────────────────

    public function test_the_repair_lists_by_default_and_switches_only_unpaid_open_orders(): void
    {
        $unpaid    = $this->legacyBotswanaOrder();
        $partPaid  = $this->legacyBotswanaOrder();
        $cancelled = $this->legacyBotswanaOrder();
        $kept      = $this->legacyBotswanaOrder();
        $kenyan    = Order::findOrFail($this->chatOrder('KE')->assertSuccessful()->json('order_id'));
        DB::table('payments')->insert(['order_id' => $partPaid->id, 'payment_number' => 'PAY-' . uniqid(), 'amount' => 5000, 'currency_code' => 'KES', 'status' => 'paid', 'payment_method' => 'mpesa', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('orders')->where('id', $partPaid->id)->update(['payment_status' => 'partial']);
        DB::table('orders')->where('id', $cancelled->id)->update(['status' => 'cancelled']);
        $boss = $this->staff(['orders.view', 'orders.edit']);
        $this->getJson("/api/v1/admin/orders/{$kept->id}/payment-link?keep_currency=1&reason=" . urlencode('Paid by a relative in Kenya'))->assertOk();
        $before = DB::table('orders')->orderBy('id')->get(['id', 'currency_code', 'total_amount'])->toJson();

        // Dry run: says what it would do, writes nothing.
        $this->assertSame(0, Artisan::call('orders:foreign-currency'));
        $out = Artisan::output();
        $this->assertStringContainsString('Would switch 1; left paid 1, kept 1, closed 1.', $out);
        $this->assertStringContainsString("Order #{$unpaid->id} ", $out);
        $this->assertStringNotContainsString('Kairos', $out);                               // no customer names in a public log
        $this->assertSame($before, DB::table('orders')->orderBy('id')->get(['id', 'currency_code', 'total_amount'])->toJson());

        // Apply needs an actor.
        $this->assertSame(1, Artisan::call('orders:foreign-currency', ['--apply' => true]));

        $this->assertSame(0, Artisan::call('orders:foreign-currency', ['--apply' => true, '--actor' => $boss->email]));
        $this->assertStringContainsString('Switched 1, refused 0; left paid 1, kept 1, closed 1.', Artisan::output());
        $this->assertSame('USD', $unpaid->fresh()->currency_code);
        $this->assertEqualsWithDelta(306.00, (float) $unpaid->fresh()->total_amount, 0.001);
        foreach ([$partPaid, $cancelled, $kept] as $o) {
            $this->assertSame('KES', $o->fresh()->currency_code);
            $this->assertEqualsWithDelta(30600, (float) $o->fresh()->total_amount, 0.01);
        }
        $this->assertSame('KES', $kenyan->fresh()->currency_code);
        $this->assertDatabaseHas('activity_log', ['event' => 'currency_changed', 'subject_id' => $unpaid->id, 'causer_id' => $boss->id]);

        // Again: nothing left to switch.
        Artisan::call('orders:foreign-currency', ['--apply' => true, '--actor' => $boss->email]);
        $this->assertStringContainsString('Switched 0, refused 0;', Artisan::output());
    }
}
