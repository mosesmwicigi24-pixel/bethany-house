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
    // ── the rand, priced from the USD list (owner, 2026-10-29) ─────────────

    /** Rand customer rate 0.18 per shilling = R18 per USD at the 100 KES/USD customer rate. */
    private function randFromUsd(?string $from = 'USD'): void
    {
        DB::table('currencies')->where('code', 'ZAR')->update(['exchange_rate' => 0.18, 'priced_from' => $from]);
        CurrencyPricing::forget();
    }

    public function test_the_rand_is_converted_from_the_usd_price_to_a_whole_rand_and_a_typed_rand_price_holds(): void
    {
        $this->randFromUsd();
        $usdSet   = $this->product(15000, ['USD' => 160]);        // USD 160 → R2,880.00 (not 15,000 × 0.18 = 2,700)
        $kesOnly  = $this->product(3600);                          // no USD price → KES 3,600 × 0.18 = R648.00
        $randSet  = $this->product(12000, ['ZAR' => 2999]);        // typed rand price holds: R2,999.00
        $odd      = $this->product(15000, ['USD' => 149.99]);      // 149.99 × 18 = R2,699.82 → R2,700 (whole rand, owner 30 Oct)

        $price = fn (Product $p, string $c) => CurrencyPricing::catalogue($p->id, null, $c)['regular_price'];
        $this->assertEqualsWithDelta(2880.00, $price($usdSet, 'ZAR'), 0.001);
        $this->assertEqualsWithDelta(648.00, $price($kesOnly, 'ZAR'), 0.001);
        $this->assertEqualsWithDelta(2999.00, $price($randSet, 'ZAR'), 0.001);
        $this->assertEqualsWithDelta(2700.00, $price($odd, 'ZAR'), 0.0001);
        $this->assertEqualsWithDelta(2999.00, $price($randSet, 'ZAR'), 0.0001, 'a typed rand price is never rounded');
        $this->assertEqualsWithDelta(3000.00, $price($usdSet, 'ZMW'), 0.001, 'kwacha still converts from shillings: 15,000 × 0.2');
        $this->assertSame('USD', CurrencyPricing::pricedFrom('ZAR'));
        $this->assertNull(CurrencyPricing::pricedFrom('ZMW'));

        // A South African chat order: R2,880 + R648 + R2,999 = R6,527.00, reported at the rand's own 7.1.
        $this->staff(['pos.access']);
        $res = $this->chatOrder('ZA', [
            ['product_id' => $usdSet->id, 'quantity' => 1, 'unit_price' => 15000],
            ['product_id' => $kesOnly->id, 'quantity' => 1, 'unit_price' => 3600],
            ['product_id' => $randSet->id, 'quantity' => 1, 'unit_price' => 12000],
        ])->assertSuccessful();
        $order = Order::findOrFail($res->json('order_id'));
        $this->assertSame('ZAR', $order->currency_code);
        $this->assertEqualsWithDelta(6527.00, (float) $order->total_amount, 0.001);
        $this->assertEqualsWithDelta(7.1, (float) $order->reporting_rate_to_kes, 0.000001);

        // Back to shillings as the source: the USD price no longer matters.
        $this->randFromUsd(null);
        $this->assertEqualsWithDelta(2700.00, $price($usdSet, 'ZAR'), 0.001);
    }

    public function test_a_customer_may_choose_any_currency_the_books_carry_and_kes_abroad_needs_a_reason(): void
    {
        $this->randFromUsd();
        $order = $this->legacyBotswanaOrder();                     // KES 15,000 + 3,600 + 12,000, no USD or rand rows
        $this->staff(['orders.view', 'orders.edit']);
        $url = "/api/v1/admin/orders/{$order->id}/update-currency";

        $this->postJson($url, ['country_code' => 'BW', 'currency_code' => 'BWP'])->assertStatus(422);              // no reporting rate
        $this->postJson($url, ['country_code' => 'BW', 'currency_code' => 'KES'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('KES', $order->fresh()->currency_code);

        // "When someone says rands, we quote in rands" — no reason needed.
        $this->postJson($url, ['country_code' => 'BW', 'currency_code' => 'ZAR', 'reason' => 'Asked for the price in rand'])
            ->assertOk()->assertJsonPath('currency_code', 'ZAR');
        $order->refresh();
        $this->assertSame('ZAR', $order->currency_code);
        $this->assertSame('BW', $order->customer_country_code);
        $this->assertEqualsWithDelta(30600 * 0.18, (float) $order->total_amount, 0.001);            // R5,508.00
        $log = DB::table('activity_log')->where('event', 'currency_changed')->where('subject_id', $order->id)->latest('id')->first();
        $props = json_decode($log->properties, true);
        $this->assertTrue($props['on_request']);
        $this->assertSame('Asked for the price in rand', $props['reason']);

        // Asking for shillings on purpose: kept, so the link check and the repair leave it alone.
        $this->postJson($url, ['country_code' => 'BW', 'currency_code' => 'KES', 'reason' => 'Sister in Nairobi pays by M-Pesa'])->assertOk();
        $this->assertSame('KES', $order->fresh()->currency_code);
        $this->getJson("/api/v1/admin/orders/{$order->id}/payment-link")->assertOk();
    }

    public function test_the_repair_dry_run_shows_each_new_total(): void
    {
        $order = $this->legacyBotswanaOrder();
        $this->assertSame(0, Artisan::call('orders:foreign-currency'));
        $this->assertStringContainsString("Order #{$order->id} {$order->order_number} BW KES 30,600.00 → would switch to USD 306.00", Artisan::output());
        $this->assertSame('KES', $order->fresh()->currency_code);
    }

    /**
     * South Africa moves to the rand (owner, 29 Oct): customers pay KES ÷ 6.5,
     * the books value a rand at 7.8 — KES 15,000 → R2,307.69 → KES 18,000.
     * The basket KES 30,600 → R2,308 + R554 + R1,846 = R4,708 (each line to a whole rand), booked at 7.8.
     */
    public function test_south_africas_unpaid_orders_move_to_the_rand_in_any_currency_but_chosen_ones_stay(): void
    {
        DB::table('currencies')->where('code', 'ZAR')->update(['exchange_rate' => round(1 / 6.5, 6), 'reporting_rate_to_kes' => 7.8, 'priced_from' => 'USD']);
        DB::table('countries')->where('code', 'ZA')->update(['default_currency_code' => 'USD']);   // production today
        ReportingCurrency::forget(); CurrencyPricing::forget();

        $this->staff(['pos.access']);
        $inUsd   = Order::findOrFail($this->chatOrder('ZA')->assertSuccessful()->json('order_id'));
        $partPd  = Order::findOrFail($this->chatOrder('ZA')->assertSuccessful()->json('order_id'));
        $asked   = Order::findOrFail($this->chatOrder('ZA')->assertSuccessful()->json('order_id'));
        $inKes   = Order::findOrFail($this->chatOrder(null)->assertSuccessful()->json('order_id'));
        DB::table('orders')->where('id', $inKes->id)->update(['customer_country_code' => 'ZA', 'is_international' => true]);
        $botswana = $this->legacyBotswanaOrder();
        $this->assertSame(['USD', 'USD', 'USD', 'KES'], [$inUsd->currency_code, $partPd->currency_code, $asked->currency_code, $inKes->fresh()->currency_code]);
        DB::table('payments')->insert(['order_id' => $partPd->id, 'payment_number' => 'PAY-' . uniqid(), 'amount' => 50, 'currency_code' => 'USD', 'status' => 'paid', 'payment_method' => 'card', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('orders')->where('id', $partPd->id)->update(['payment_status' => 'partial']);
        $boss = $this->staff(['orders.view', 'orders.edit']);
        $this->postJson("/api/v1/admin/orders/{$asked->id}/update-currency", ['country_code' => 'ZA', 'currency_code' => 'USD'])->assertOk();  // USD is the rule today: no reason needed

        // The owner switches South Africa to the rand; the customer of $asked then asks to stay in dollars.
        DB::table('countries')->where('code', 'ZA')->update(['default_currency_code' => 'ZAR']);
        $this->assertSame('ZAR', SellableCurrency::forCustomer('ZA'));
        $this->postJson("/api/v1/admin/orders/{$asked->id}/update-currency", ['country_code' => 'ZA', 'currency_code' => 'USD', 'reason' => 'Asked to keep paying in dollars'])->assertOk();

        $this->assertSame(0, Artisan::call('orders:foreign-currency', ['--country' => 'ZA']));
        $out = Artisan::output();
        $this->assertStringContainsString('for customers in ZA not in ZAR', $out);
        $this->assertStringContainsString("Order #{$inUsd->id} {$inUsd->order_number} ZA USD 306.00 → would switch to ZAR 4,708.00", $out);
        $this->assertStringContainsString("Order #{$inKes->id} {$inKes->order_number} ZA KES 30,600.00 → would switch to ZAR 4,708.00", $out);
        $this->assertStringContainsString('Would switch 2; left paid 1, kept 1, closed 0.', $out);
        $this->assertStringNotContainsString("Order #{$botswana->id} ", $out);

        $this->assertSame(0, Artisan::call('orders:foreign-currency', ['--country' => 'ZA', '--apply' => true, '--actor' => $boss->email]));
        foreach ([$inUsd, $inKes] as $o) {
            $o->refresh();
            $this->assertSame('ZAR', $o->currency_code);
            $this->assertEqualsWithDelta(4708.00, (float) $o->total_amount, 0.001);
            $this->assertEqualsWithDelta(7.8, (float) $o->reporting_rate_to_kes, 0.000001);          // R4,708 × 7.8 = KES 36,722.40 in the books
        }
        $this->assertSame('USD', $partPd->fresh()->currency_code);
        $this->assertSame('USD', $asked->fresh()->currency_code);
        $this->assertSame('KES', $botswana->fresh()->currency_code);

        Artisan::call('orders:foreign-currency', ['--country' => 'ZA', '--apply' => true, '--actor' => $boss->email]);
        $this->assertStringContainsString('Switched 0, refused 0;', Artisan::output());
        $this->assertSame(1, Artisan::call('orders:foreign-currency', ['--country' => 'KE']));     // the home country is refused
    }

    public function test_the_till_bills_in_the_currency_the_customer_chooses(): void
    {
        DB::table('currencies')->where('code', 'ZAR')->update(['exchange_rate' => round(1 / 6.5, 6), 'reporting_rate_to_kes' => 7.8]);
        ReportingCurrency::forget(); CurrencyPricing::forget();
        $this->staff(['pos.access']);
        $order = fn (?string $country, ?string $currency, ?string $why = null) => $this->postJson('/api/v1/admin/pos/pending-order', array_filter([
            'outlet_id' => $this->outlet->id, 'channel' => 'whatsapp', 'customer_first_name' => 'Thandi',
            'customer_country_code' => $country, 'currency_code' => $currency, 'currency_reason' => $why, 'items' => $this->basket(),
        ], fn ($v) => $v !== null));

        // South Africa's default is the rand, each line to a whole rand:
        // 15,000 ÷ 6.5 = 2,307.69 → 2,308; 3,600 → 553.85 → 554; 12,000 → 1,846.15 → 1,846. Total R4,708.
        $za = Order::findOrFail($order('ZA', null)->assertSuccessful()->json('order_id'));
        $this->assertSame('ZAR', $za->currency_code);
        $this->assertEqualsWithDelta(4708.00, (float) $za->total_amount, 0.001);

        // "When someone says USD, we quote in USD": USD 306.00, and the choice is recorded.
        $usd = Order::findOrFail($order('ZA', 'USD')->assertSuccessful()->json('order_id'));
        $this->assertSame('USD', $usd->currency_code);
        $this->assertEqualsWithDelta(306.00, (float) $usd->total_amount, 0.001);
        $this->assertTrue(\App\Services\OrderCurrencySwitch::chosenOnPurpose($usd));
        $this->assertFalse(\App\Services\OrderCurrencySwitch::chosenOnPurpose($za));

        // A Botswana customer who asks for rand gets rand; KES abroad needs a reason; Kenya stays in shillings.
        $this->assertSame('ZAR', Order::findOrFail($order('BW', 'ZAR')->assertSuccessful()->json('order_id'))->currency_code);
        $order('BW', 'KES')->assertStatus(422)->assertJsonValidationErrors('currency_reason');
        $this->assertSame('KES', Order::findOrFail($order('BW', 'KES', 'Relative in Nairobi pays by M-Pesa')->assertSuccessful()->json('order_id'))->currency_code);
        $order('KE', 'USD')->assertStatus(422)->assertJsonValidationErrors('currency_code');
        $order('ZA', 'BWP')->assertStatus(422);
    }

    /**
     * A rand order's payment link offered "No payment methods available" (owner,
     * 30 Oct). Mukuru, Western Union / MoneyGram and the I&M bank transfer now
     * take every sale currency, with the pay-to details on the page; Mukuru stays
     * for its send markets and Western Union stays off Kenyan numbers.
     */
    public function test_a_rand_order_can_be_paid_by_mukuru_western_union_or_bank_transfer(): void
    {
        DB::table('currencies')->where('code', 'ZAR')->update(['exchange_rate' => round(1 / 6.5, 6), 'reporting_rate_to_kes' => 7.8]);
        DB::table('countries')->where('code', 'ZA')->update(['default_currency_code' => 'ZAR']);
        ReportingCurrency::forget(); CurrencyPricing::forget();
        $this->staff(['pos.access']);
        $make = fn (string $country, string $phone) => Order::findOrFail($this->postJson('/api/v1/admin/pos/pending-order', [
            'outlet_id' => $this->outlet->id, 'channel' => 'whatsapp', 'customer_first_name' => 'Makwaba',
            'customer_phone' => $phone, 'customer_country_code' => $country, 'items' => $this->basket(),
        ])->assertSuccessful()->json('order_id'));
        $za = $make('ZA', '+27823846159');
        $ke = $make('KE', '+254722918403');
        $this->assertSame('ZAR', $za->currency_code);

        $this->staff(['orders.view', 'orders.edit']);
        $codes = function (Order $o) {
            $url = $this->getJson("/api/v1/admin/orders/{$o->id}/payment-link")->assertOk()->json('payment_url');
            $token = basename(parse_url($url, PHP_URL_PATH));
            return collect($this->getJson("/api/v1/pay/{$token}")->assertOk()->json('available_methods'))->keyBy('code');
        };

        $zaMethods = $codes($za);
        $this->assertTrue($zaMethods->has('mukuru'), 'Mukuru takes rand from a South African number');
        $this->assertTrue($zaMethods->has('wu_moneygram'));
        $this->assertTrue($zaMethods->has('bank_transfer'));
        $this->assertSame('+254700706875', $zaMethods['mukuru']['instructions']['recipient_phone']);
        foreach (['mukuru', 'wu_moneygram'] as $rail) {                                   // the number's registered holder
            $this->assertSame('Moses Nganga', $zaMethods[$rail]['instructions']['recipient_name']);
            $this->assertStringNotContainsString('Mwicigi', implode(' ', $zaMethods[$rail]['instructions']['steps']));
        }
        $this->assertStringContainsString('Send to M-Pesa', implode(' ', $zaMethods['mukuru']['instructions']['steps']));
        $bankSteps = implode(' ', $zaMethods['bank_transfer']['instructions']['steps']);
        foreach (['I&M Bank Kenya', '00106090796350', 'Bank code: 57', 'Branch code: 001', 'IMBLKENA', 'Kenyatta Avenue'] as $detail) {
            $this->assertStringContainsString($detail, $bankSteps);
        }

        $keMethods = $codes($ke);
        $this->assertFalse($keMethods->has('mukuru'), 'not offered to a Kenyan number');
        $this->assertFalse($keMethods->has('wu_moneygram'), 'not offered to a Kenyan number');
        $this->assertTrue($keMethods->has('bank_transfer'));
    }

}
