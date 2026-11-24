<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SalesDocument;
use App\Models\User;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * What a customer has ordered, for the chat agent (2026-11-24).
 *
 * Its account held a till role: no customers.insights, so the customer record
 * came back with identity only — no orders, no stats — and an own-only data
 * scope, so a customer the search found by phone answered 404. The
 * `customer_history_reader` role, held BESIDE the working role, opens both to
 * read and nothing to write — and, because history has its own scope
 * (customers.insights) rather than borrowing orders.view, it widens no order
 * ACTION: the account still opens, links and takes payment on its own orders only.
 *
 * Made-up fixture, every figure worked by hand. One customer, "Amani Test",
 * phone 0712 000 111:
 *
 *   T  till sale     KES 3,000  paid       confirmed   (sold by a cashier)
 *   C  chat order    KES 4,500  pending    pending     (raised by the agent)
 *   I  invoice       KES 9,000  paid       confirmed   (quote → invoice INV-T-1)
 *   X  cancelled     KES 1,200  pending    cancelled
 *   W  walk-in sale  KES   800  paid       confirmed   (same phone typed on the
 *                                                       receipt, no customer record)
 *
 *   On the customer record: 4 orders; spent (recognised) = 3,000 + 9,000 = 12,000;
 *   average 6,000. By phone: 5 orders; spent = 12,800.
 */
class CustomerOrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private User $cashier;
    private Customer $amani;
    /** @var array<string, Order> */
    private array $o = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        DB::table('currencies')->updateOrInsert(['code' => 'KES'], ['name' => 'Kenyan Shilling', 'symbol' => 'KES',
            'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true]);
        ReportingCurrency::forget();

        $this->cashier = $this->person('pos_clerk');
        // The agent's account as it is: a till role (own-only), plus the reader.
        $this->agent = $this->person('pos_clerk', 'customer_history_reader');

        $this->amani = Customer::create(['first_name' => 'Amani', 'last_name' => 'Test', 'phone' => '0712000111']);

        $this->o['T'] = $this->order('T', 3000, ['order_type' => 'pos', 'sales_bucket' => 'till', 'status' => 'confirmed',
            'payment_status' => 'paid', 'customer_id' => $this->amani->id, 'created_by' => $this->cashier->id], '2026-09-01 10:00:00');
        $this->o['C'] = $this->order('C', 4500, ['order_type' => 'whatsapp', 'sales_bucket' => 'chat', 'status' => 'pending',
            'payment_status' => 'pending', 'customer_id' => $this->amani->id, 'created_by' => $this->agent->id], '2026-10-05 15:30:00');
        $this->o['I'] = $this->order('I', 9000, ['order_type' => 'online', 'sales_bucket' => 'quoted', 'status' => 'confirmed',
            'payment_status' => 'paid', 'customer_id' => $this->amani->id, 'created_by' => $this->cashier->id], '2026-11-02 09:15:00');
        $this->o['X'] = $this->order('X', 1200, ['order_type' => 'pos', 'sales_bucket' => 'till', 'status' => 'cancelled',
            'payment_status' => 'pending', 'customer_id' => $this->amani->id, 'created_by' => $this->cashier->id], '2026-08-20 12:00:00');
        $this->o['W'] = $this->order('W', 800, ['order_type' => 'pos', 'sales_bucket' => 'till', 'status' => 'confirmed',
            'payment_status' => 'paid', 'customer_id' => null, 'customer_phone' => '+254 712 000 111',
            'created_by' => $this->cashier->id], '2026-11-10 17:45:00');

        SalesDocument::create([
            'type' => SalesDocument::INVOICE, 'number' => 'INV-T-1', 'status' => 'paid',
            'documentable_type' => (new Order())->getMorphClass(), 'documentable_id' => $this->o['I']->id,
            'issued_at' => '2026-11-02 09:15:00',
        ]);

        Sanctum::actingAs($this->agent);
    }

    private function person(string ...$roles): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        foreach ($roles as $r) {
            $u->assignRole(Role::findByName($r, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function order(string $tag, float $total, array $attrs, string $at): Order
    {
        $order = Order::withoutViewerScope()->create($attrs + [
            'order_number' => "T-HIST-{$tag}", 'currency_code' => 'KES', 'subtotal' => $total, 'total_amount' => $total,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_name' => "Test item {$tag}", 'sku' => "T-SKU-{$tag}", 'quantity' => 1,
            'unit_price' => $total, 'total_price' => $total,
        ]);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $at]);

        return $order->fresh();
    }

    public function test_the_customer_record_carries_every_channels_orders_newest_first_and_real_stats(): void
    {
        $res = $this->getJson("/api/v1/admin/customers/{$this->amani->id}")->assertOk();

        $orders = collect($res->json('customer.orders'));
        $this->assertSame(['T-HIST-I', 'T-HIST-C', 'T-HIST-T', 'T-HIST-X'], $orders->pluck('order_number')->all());

        $by = $orders->keyBy('order_number');
        $this->assertSame('pos', $by['T-HIST-T']['order_type']);
        $this->assertSame('till', $by['T-HIST-T']['channel']);
        $this->assertSame('whatsapp', $by['T-HIST-C']['order_type']);
        $this->assertSame('chat', $by['T-HIST-C']['channel']);
        $this->assertSame('online', $by['T-HIST-I']['order_type']);
        $this->assertSame('quoted', $by['T-HIST-I']['channel']);
        $this->assertSame('INV-T-1', $by['T-HIST-I']['invoice_number']);
        $this->assertNull($by['T-HIST-T']['invoice_number']);

        foreach ($orders as $o) {
            foreach (['id', 'order_number', 'status', 'payment_status', 'order_type', 'total_amount', 'subtotal', 'currency_code', 'created_at', 'items'] as $field) {
                $this->assertArrayHasKey($field, $o);
            }
            foreach (['product_name', 'quantity', 'unit_price', 'total_price'] as $field) {
                $this->assertArrayHasKey($field, $o['items'][0]);
            }
        }
        $this->assertSame('paid', $by['T-HIST-T']['payment_status']);
        $this->assertSame('pending', $by['T-HIST-C']['payment_status']);
        $this->assertEquals(4500, $by['T-HIST-C']['total_amount']);

        // 4 orders; spent is recognised income: 3,000 + 9,000 (not the unpaid
        // chat order, not the cancelled one); average 12,000 / 2.
        $res->assertJsonPath('stats.total_orders', 4);
        $this->assertEquals(12000.0, $res->json('stats.total_spent'));
        $this->assertEquals(6000.0, $res->json('stats.average_order_value'));
        $this->assertStringStartsWith('2026-11-02', (string) $res->json('stats.last_order_date'));
        $res->assertJsonPath('orders_total', 4)->assertJsonPath('orders_truncated', false);

        // Independent of the endpoint: the same figures straight from the table.
        $this->assertEquals(12000.0, (float) DB::table('orders')->where('customer_id', $this->amani->id)
            ->where('payment_status', 'paid')->whereNotIn('status', ['cancelled', 'voided'])->sum('total_amount'));
    }

    public function test_a_long_history_returns_the_latest_fifty_and_says_so_while_stats_cover_all(): void
    {
        for ($i = 1; $i <= 52; $i++) {
            $this->order("L{$i}", 100, ['order_type' => 'pos', 'sales_bucket' => 'till', 'status' => 'confirmed',
                'payment_status' => 'paid', 'customer_id' => $this->amani->id, 'created_by' => $this->cashier->id],
                sprintf('2026-07-%02d 10:00:00', ($i % 28) + 1));
        }

        $res = $this->getJson("/api/v1/admin/customers/{$this->amani->id}")->assertOk();

        $this->assertCount(50, $res->json('customer.orders'));
        $res->assertJsonPath('orders_truncated', true)->assertJsonPath('orders_total', 56);
        $res->assertJsonPath('stats.total_orders', 56);
        // 12,000 + 52 × 100
        $this->assertEquals(17200.0, $res->json('stats.total_spent'));
        $this->assertSame('T-HIST-I', $res->json('customer.orders.0.order_number'), 'newest first');
    }

    public function test_without_the_reader_role_the_agent_sees_what_it_saw_before(): void
    {
        $tillOnly = $this->person('pos_clerk');
        Sanctum::actingAs($tillOnly);

        // Own-only scope: a customer with none of its open sales is not its to open.
        $this->getJson("/api/v1/admin/customers/{$this->amani->id}")->assertNotFound();
        $this->getJson('/api/v1/admin/orders/by-phone?phone=0712000111')->assertForbidden();
    }

    public function test_every_customer_the_search_returns_can_be_read(): void
    {
        // Somebody the agent has never sold to, found by their whole number.
        $stranger = Customer::create(['first_name' => 'Baraka', 'last_name' => 'Test', 'phone' => '0733000222']);
        $this->order('S', 2500, ['order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'customer_id' => $stranger->id, 'created_by' => $this->cashier->id], '2026-10-10 10:00:00');

        $found = $this->getJson('/api/v1/admin/pos/customers/search?q=0733000222')->assertOk()->json('data');
        $this->assertSame([$stranger->id], array_column($found, 'id'));

        $this->getJson("/api/v1/admin/customers/{$stranger->id}")->assertOk()
            ->assertJsonPath('stats.total_orders', 1)
            ->assertJsonPath('customer.orders.0.order_number', 'T-HIST-S');
    }

    public function test_by_phone_finds_the_same_person_in_every_format_and_the_walk_in_sale_too(): void
    {
        $expected = ['T-HIST-W', 'T-HIST-I', 'T-HIST-C', 'T-HIST-T', 'T-HIST-X'];

        foreach (['0712000111', '254712000111', '+254712000111', '0712 000 111', '712000111', '+254 (712) 000-111'] as $format) {
            $res = $this->getJson('/api/v1/admin/orders/by-phone?phone=' . urlencode($format))->assertOk();

            $this->assertSame($expected, array_column($res->json('orders'), 'order_number'), $format);
            $this->assertSame([$this->amani->id], array_column($res->json('customers'), 'id'), $format);
        }

        $res  = $this->getJson('/api/v1/admin/orders/by-phone?phone=0712000111')->assertOk();
        $rows = collect($res->json('orders'))->keyBy('order_number');

        $this->assertSame($this->amani->id, $rows['T-HIST-T']['customer_id']);
        $this->assertNull($rows['T-HIST-W']['customer_id'], 'the walk-in sale has no customer record');
        $this->assertSame('till', $rows['T-HIST-W']['channel']);
        $this->assertSame('INV-T-1', $rows['T-HIST-I']['invoice_number']);
        $this->assertSame(
            ['id', 'order_number', 'customer_id', 'status', 'payment_status', 'order_type', 'channel', 'invoice_number',
             'total_amount', 'subtotal', 'currency_code', 'created_at', 'items'],
            array_keys($rows['T-HIST-C']),
        );
        $this->assertSame(['product_name', 'quantity', 'unit_price', 'total_price'], array_keys($rows['T-HIST-C']['items'][0]));

        // 5 orders; spent 3,000 + 9,000 + 800.
        $res->assertJsonPath('stats.total_orders', 5)->assertJsonPath('orders_total', 5)->assertJsonPath('orders_truncated', false);
        $this->assertEquals(12800.0, $res->json('stats.total_spent'));
    }

    public function test_a_ugandan_number_with_the_same_trailing_digits_is_somebody_else(): void
    {
        $ugandan = Customer::create(['first_name' => 'Kato', 'last_name' => 'Test', 'phone' => '+256712000111']);
        $this->order('U', 7000, ['order_type' => 'whatsapp', 'status' => 'confirmed', 'payment_status' => 'paid',
            'customer_id' => $ugandan->id, 'created_by' => $this->agent->id], '2026-11-12 08:00:00');

        $kenyan = $this->getJson('/api/v1/admin/orders/by-phone?phone=0712000111')->assertOk();
        $this->assertNotContains('T-HIST-U', array_column($kenyan->json('orders'), 'order_number'));
        $this->assertSame([$this->amani->id], array_column($kenyan->json('customers'), 'id'));

        foreach (['+256712000111', '256712000111'] as $format) {
            $res = $this->getJson('/api/v1/admin/orders/by-phone?phone=' . urlencode($format))->assertOk();
            $this->assertSame(['T-HIST-U'], array_column($res->json('orders'), 'order_number'), $format);
            $this->assertSame([$ugandan->id], array_column($res->json('customers'), 'id'), $format);
        }
    }

    public function test_since_narrows_the_list_on_the_nairobi_day_and_leaves_the_stats_whole(): void
    {
        $res = $this->getJson('/api/v1/admin/orders/by-phone?phone=0712000111&since=2026-11-02')->assertOk();

        // I was made 2026-11-02 09:15 Nairobi time: on the boundary day, so in.
        $this->assertSame(['T-HIST-W', 'T-HIST-I'], array_column($res->json('orders'), 'order_number'));
        $res->assertJsonPath('orders_total', 2)->assertJsonPath('stats.total_orders', 5);
    }

    public function test_a_number_nobody_holds_an_empty_answer_and_a_partial_number_is_refused(): void
    {
        $this->getJson('/api/v1/admin/orders/by-phone?phone=0799999999')->assertOk()
            ->assertJsonPath('orders', [])->assertJsonPath('customers', [])
            ->assertJsonPath('stats.total_orders', 0)->assertJsonPath('orders_truncated', false);

        // Not a way to walk the phone book: a prefix is not a number.
        $this->getJson('/api/v1/admin/orders/by-phone?phone=0712')->assertStatus(422);
        $this->getJson('/api/v1/admin/orders/by-phone?phone=amani')->assertStatus(422);
        $this->getJson('/api/v1/admin/orders/by-phone')->assertStatus(422);
    }

    public function test_it_is_read_only_and_needs_a_signed_in_staff_account(): void
    {
        $before = [Order::withoutViewerScope()->count(), Customer::count()];
        $this->getJson('/api/v1/admin/orders/by-phone?phone=0712000111')->assertOk();
        $this->getJson("/api/v1/admin/customers/{$this->amani->id}")->assertOk();
        $this->assertSame($before, [Order::withoutViewerScope()->count(), Customer::count()]);

        $this->postJson('/api/v1/admin/orders/by-phone', ['phone' => '0712000111'])->assertStatus(405);

        // The reader role grants nothing that writes.
        $perms = Role::findByName('customer_history_reader', 'sanctum')->permissions->pluck('name')->sort()->values()->all();
        $this->assertSame(['customers.insights', 'customers.view'], $perms);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/orders/by-phone?phone=0712000111')->assertUnauthorized();
    }

    public function test_what_an_item_cost_us_is_never_in_the_history_without_the_cost_permission(): void
    {
        DB::table('order_items')->update(['cost_price' => 1234.56, 'cost_source' => 'product_price']);

        foreach ([
            "/api/v1/admin/customers/{$this->amani->id}",
            "/api/v1/admin/customers/{$this->amani->id}/orders",
            '/api/v1/admin/orders/by-phone?phone=0712000111',
        ] as $url) {
            $body = (string) $this->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('cost_price', $body, $url);
            $this->assertStringNotContainsString('1234.56', $body, $url);
        }

        // The owner still sees it on the customer record, as before.
        Sanctum::actingAs($this->person('super_admin'));
        $this->assertEquals(1234.56, $this->getJson("/api/v1/admin/customers/{$this->amani->id}")
            ->assertOk()->json('customer.orders.0.items.0.cost_price'));
    }

    public function test_the_phone_coverage_count_is_counts_only_and_right(): void
    {
        // Till sales in the fixture, not dead, last 400 days: T (customer with a
        // phone), W (phone typed, no record) — X is cancelled, C and I are not till.
        // Plus one sale with nothing: 3 sales, 2 with a phone, 1 without.
        $this->order('N', 500, ['order_type' => 'pos', 'sales_bucket' => 'till', 'status' => 'confirmed',
            'payment_status' => 'paid', 'customer_id' => null, 'customer_phone' => 'Via Mpesa',
            'created_by' => $this->cashier->id], '2026-11-15 11:00:00');

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-24 12:00:00', config('app.timezone')));
        Artisan::call('orders:phone-coverage', ['--days' => 400]);
        $out = Artisan::output();

        $this->assertStringContainsString('Till sales, last 400 days: 3', $out);
        $this->assertStringContainsString('With a phone number: 2', $out);
        $this->assertStringContainsString('Without a phone number: 1', $out);
        $this->assertStringContainsString('Phone typed on the sale itself: 1', $out);
        $this->assertStringContainsString('Attached to a customer record: 1', $out);
        $this->assertStringContainsString('Phone typed but no customer record: 1', $out);
        // Counts only: no name, no number.
        $this->assertStringNotContainsString('Amani', $out);
        $this->assertStringNotContainsString('0712', $out);
        $this->assertStringNotContainsString('712000111', $out);

        Artisan::call('orders:phone-coverage', ['--days' => 5]);
        $this->assertStringContainsString('Till sales, last 5 days: 0', Artisan::output());
    }

    public function test_reading_history_business_wide_widens_no_order_action(): void
    {
        $theirs = $this->o['T'];   // a cashier's till sale, in the agent's history
        $mine   = $this->o['C'];   // the agent's own chat order
        DB::table('orders')->where('id', $theirs->id)->update(['status' => 'pending', 'payment_status' => 'pending']);

        // It can READ the cashier's sale as history …
        $this->assertContains('T-HIST-T', array_column(
            $this->getJson("/api/v1/admin/customers/{$this->amani->id}")->assertOk()->json('customer.orders'), 'order_number'));

        // … and cannot open it, mint its payment link, or take payment on it.
        $this->getJson("/api/v1/admin/orders/{$theirs->id}")->assertNotFound();
        $this->getJson("/api/v1/admin/orders/{$theirs->id}/payment-link")->assertNotFound();
        $this->postJson("/api/v1/admin/orders/{$theirs->id}/payments", ['method' => 'cash', 'amount' => 3000])
            ->assertStatus(404);
        $this->postJson("/api/v1/admin/orders/{$theirs->id}/payment", ['payment_method' => 'mpesa', 'phone' => '0712000111'])
            ->assertStatus(404);

        $row = DB::table('orders')->where('id', $theirs->id)->first();
        $this->assertNull($row->payment_token, 'no payment link was minted');
        $this->assertSame(0, DB::table('payments')->where('order_id', $theirs->id)->count());

        // Its own order is still its to open, exactly as before.
        $this->getJson("/api/v1/admin/orders/{$mine->id}")->assertOk();

        // The orders list is still its own sales only.
        $listed = array_column($this->getJson('/api/v1/admin/orders')->assertOk()->json('data'), 'order_number');
        $this->assertSame(['T-HIST-C'], $listed);
    }

    public function test_the_order_detail_carries_no_cost_for_a_cost_blind_viewer(): void
    {
        DB::table('order_items')->update(['cost_price' => 1234.56, 'cost_source' => 'product_price']);

        $body = (string) $this->getJson("/api/v1/admin/orders/{$this->o['C']->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('cost_price', $body);
        $this->assertStringNotContainsString('cost_source', $body);
        $this->assertStringNotContainsString('1234.56', $body);

        // The orders list carries the same items; the same rule.
        $list = $this->getJson('/api/v1/admin/orders')->assertOk();
        $this->assertSame(['T-HIST-C'], array_column($list->json('data'), 'order_number'));
        $this->assertStringNotContainsString('cost_price', (string) $list->getContent());
        foreach (['data', 'current_page', 'per_page', 'total', 'last_page'] as $key) {
            $this->assertArrayHasKey($key, $list->json(), 'the list keeps its paginated shape');
        }

        Sanctum::actingAs($this->person('super_admin'));
        $this->assertEquals(1234.56, $this->getJson("/api/v1/admin/orders/{$this->o['C']->id}")
            ->assertOk()->json('order.items.0.cost_price'));
        $this->assertStringContainsString('cost_price', (string) $this->getJson('/api/v1/admin/orders')->assertOk()->getContent());
    }

    public function test_by_phone_tells_a_shop_manager_nothing_about_another_shops_customer(): void
    {
        $here  = \App\Models\Outlet::factory()->create();
        $there = \App\Models\Outlet::factory()->create();

        $manager = $this->person('outlet_manager');   // holds customers.insights, outlet-scoped
        $manager->outlets()->attach($here->id);

        // A customer of the OTHER shop, with an order there.
        $elsewhere = Customer::create(['first_name' => 'Zawadi', 'last_name' => 'Test', 'phone' => '0733111222']);
        $elsewhere->forceFill(['created_outlet_id' => $there->id])->save();
        $this->order('E', 2000, ['order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'customer_id' => $elsewhere->id, 'outlet_id' => $there->id, 'created_by' => $this->cashier->id], '2026-11-01 10:00:00');

        // A customer of THIS shop.
        $mine = Customer::create(['first_name' => 'Neema', 'last_name' => 'Test', 'phone' => '0733111333']);
        $mine->forceFill(['created_outlet_id' => $here->id])->save();
        $this->order('H', 1500, ['order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'customer_id' => $mine->id, 'outlet_id' => $here->id, 'created_by' => $this->cashier->id], '2026-11-03 10:00:00');

        Sanctum::actingAs($manager->fresh());

        $other = $this->getJson('/api/v1/admin/orders/by-phone?phone=0733111222')->assertOk();
        $this->assertSame([], $other->json('customers'), 'not even the id, name or phone');
        $this->assertSame([], $other->json('orders'));
        $other->assertJsonPath('stats.total_orders', 0);
        $this->assertStringNotContainsString('Zawadi', (string) $other->getContent());

        $own = $this->getJson('/api/v1/admin/orders/by-phone?phone=0733111333')->assertOk();
        $this->assertSame([$mine->id], array_column($own->json('customers'), 'id'));
        $this->assertSame(['T-HIST-H'], array_column($own->json('orders'), 'order_number'));

        // And the business-wide reader sees both.
        Sanctum::actingAs($this->agent);
        $this->assertSame(['T-HIST-E'], array_column(
            $this->getJson('/api/v1/admin/orders/by-phone?phone=0733111222')->assertOk()->json('orders'), 'order_number'));
    }

    public function test_the_orders_tab_no_longer_lists_other_walk_ins_orders(): void
    {
        $other = Customer::create(['first_name' => 'Baraka', 'last_name' => 'Test', 'phone' => '0733000222']);
        $this->order('O', 2500, ['order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'customer_id' => $other->id, 'created_by' => $this->cashier->id], '2026-10-10 10:00:00');

        $numbers = array_column($this->getJson("/api/v1/admin/customers/{$this->amani->id}/orders")->assertOk()->json('data'), 'order_number');

        $this->assertEqualsCanonicalizing(['T-HIST-I', 'T-HIST-C', 'T-HIST-T', 'T-HIST-X'], $numbers);
    }
}
