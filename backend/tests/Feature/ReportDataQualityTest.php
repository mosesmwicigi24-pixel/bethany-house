<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Audit & Data Quality — each check finds exactly the rows planted for it, on
 * the same definitions the figures use, so a clean check means the figure is
 * whole on that count. One of each problem, in outlet A, so a count of 1 is a
 * proof and a count of 2 is a leak.
 */
class ReportDataQualityTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $a;
    private Outlet $b;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('currencies')->updateOrInsert(['code' => 'KES'], [
            'name' => 'KES', 'symbol' => 'KES', 'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true,
        ]);
        // A currency with a pricing rate and no reporting rate — GBP on 2026-10-01.
        DB::table('currencies')->updateOrInsert(['code' => 'GBP'], [
            'name' => 'GBP', 'symbol' => '£', 'exchange_rate' => 0.0067, 'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true,
        ]);
        \App\Support\ReportingCurrency::forget();

        $this->a = Outlet::factory()->create(['name' => 'Town']);
        $this->b = Outlet::factory()->create(['name' => 'Mall']);
        $costed   = Product::factory()->create();
        $uncosted = Product::factory()->create();
        DB::table('product_prices')->insert(['product_id' => $costed->id, 'currency_code' => 'KES',
            'regular_price' => 3000, 'cost_price' => 1200, 'created_at' => now(), 'updated_at' => now()]);

        $order = function (array $attrs, array $lines = []) {
            $o = Order::create($attrs + [
                'order_number' => 'DQ-' . uniqid(), 'order_type' => 'pos', 'status' => 'completed', 'payment_status' => 'paid',
                'currency_code' => 'KES', 'outlet_id' => $this->a->id,
            ]);
            foreach ($lines as [$product, $price]) {
                DB::table('order_items')->insert(['order_id' => $o->id, 'product_id' => $product->id, 'sku' => 'DQ',
                    'product_name' => 'Line', 'quantity' => 1, 'unit_price' => $price, 'total_price' => $price,
                    'created_at' => now(), 'updated_at' => now()]);
            }

            return $o;
        };

        // A clean sale: a buyer, a priced and costed line, an outlet.
        $order(['subtotal' => 3000, 'total_amount' => 3000, 'customer_phone' => '0700000001'], [[$costed, 3000]]);
        // A note typed into the phone field, and nobody else to say who bought — anonymous too.
        $order(['subtotal' => 1000, 'total_amount' => 1000, 'customer_phone' => 'cash'], [[$costed, 1000]]);
        // A line given away, and a line with no cost anywhere.
        $order(['subtotal' => 2500, 'total_amount' => 2500, 'customer_phone' => '0700000002'], [[$costed, 0], [$uncosted, 2500]]);
        // A staff quote with no outlet.
        $order(['subtotal' => 6000, 'total_amount' => 6000, 'customer_phone' => '0700000003', 'outlet_id' => null], [[$costed, 6000]]);
        // Paid 2,500 on a 2,000 order.
        $over = $order(['subtotal' => 2000, 'total_amount' => 2000, 'customer_phone' => '0700000004'], [[$costed, 2000]]);
        Payment::create(['order_id' => $over->id, 'amount' => 2500, 'currency_code' => 'KES', 'payment_method' => 'cash',
            'status' => 'paid', 'paid_at' => now()]);
        // A payment claimed and not approved.
        $claimed = $order(['subtotal' => 900, 'total_amount' => 900, 'customer_phone' => '0700000005', 'payment_status' => 'pending'], [[$costed, 900]]);
        Payment::create(['order_id' => $claimed->id, 'amount' => 900, 'currency_code' => 'KES', 'payment_method' => 'mpesa',
            'status' => 'pending', 'requires_approval' => true, 'approval_status' => 'pending_review']);
        // A pound sale no report can value.
        $order(['subtotal' => 50, 'total_amount' => 50, 'currency_code' => 'GBP', 'customer_phone' => '0700000006']);
        // A cart left 40 days.
        $cart = $order(['subtotal' => 7000, 'total_amount' => 7000, 'status' => 'pending', 'payment_status' => 'pending', 'customer_phone' => '0700000007']);
        DB::table('orders')->where('id', $cart->id)->update(['created_at' => now()->subDays(40)]);

        // One person registered twice (two spellings of one number), and a record whose phone is a note.
        foreach ([['Ann', '0711000001'], ['Ann', '+254711000001'], ['Ben', 'refer to ian']] as $i => [$name, $phone]) {
            DB::table('customers')->insert(['customer_number' => "DQC-{$i}", 'first_name' => $name, 'last_name' => 'K',
                'email' => "dq{$i}@example.test", 'phone' => $phone, 'created_at' => now(), 'updated_at' => now()]);
        }

        $owner = User::factory()->create();
        $cat   = DB::table('expense_categories')->insertGetId(['name' => 'Rent', 'code' => 'RENT-DQ', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('expenses')->insert(['reference_number' => 'EXP-DQ-1', 'title' => 'Rent', 'amount' => 15000, 'amount_kes' => 15000,
            'currency_code' => 'KES', 'expense_date' => now()->format('Y-m-d'), 'status' => 'pending_approval', 'payment_method' => 'cash',
            'outlet_id' => $this->a->id, 'category_id' => $cat, 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function actingWith(array $perms): void
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);
    }

    private function checks(string $query = ''): array
    {
        $r = $this->getJson('/api/v1/admin/reports/data-quality?period=this_month' . $query)->assertOk()->json();

        return collect($r['checks'])->keyBy('key')->all() + ['_coverage' => $r['coverage'], '_gaps' => $r['gaps']];
    }

    public function test_each_check_finds_exactly_what_was_planted(): void
    {
        $this->actingWith(['reports.view', 'reports.financial', 'orders.view', 'customers.view', 'products.view', 'expenses.view']);
        $c = $this->checks();

        $this->assertSame(1, $c['anonymous_sales']['count']);
        $this->assertSame(1000.0, (float) $c['anonymous_sales']['value']);
        $this->assertSame(1, $c['unreadable_order_phones']['count']);
        $this->assertSame('cash', $c['unreadable_order_phones']['rows'][0]['phone_field']);
        $this->assertSame(1, $c['unpriced_lines']['count']);
        $this->assertSame(1, $c['uncosted_lines']['count']);
        $this->assertSame(2500.0, (float) $c['uncosted_lines']['value'], 'the revenue on the uncosted line');
        $this->assertSame(1, $c['orders_without_outlet']['count']);
        $this->assertSame(6000.0, (float) $c['orders_without_outlet']['value']);
        $this->assertSame(1, $c['overpaid_orders']['count']);
        $this->assertSame(500.0, (float) $c['overpaid_orders']['value']);
        $this->assertSame(1, $c['unrated_currency_sales']['count']);
        $this->assertSame('GBP', $c['unrated_currency_sales']['rows'][0]['ref']);
        $this->assertSame(1, $c['payments_awaiting_approval']['count']);
        $this->assertSame(900.0, (float) $c['payments_awaiting_approval']['value']);
        $this->assertSame(1, $c['expenses_awaiting_approval']['count']);
        $this->assertSame(15000.0, (float) $c['expenses_awaiting_approval']['value']);
        $this->assertSame(1, $c['stale_unconfirmed_carts']['count']);
        $this->assertSame(1, $c['duplicate_customer_records']['count'], 'two spellings of one number are one person');
        $this->assertSame(2, (int) $c['duplicate_customer_records']['rows'][0]['records']);
        $this->assertSame(1, $c['unreadable_customer_phones']['count']);
        $this->assertSame(['expenses_never_approved'], array_column($c['_gaps'], 'key'));
    }

    public function test_the_uncosted_count_is_the_p_and_l_s_own_count(): void
    {
        $this->actingWith(['reports.view', 'reports.financial']);
        $t  = now()->format('Y-m-d');
        $pl = $this->getJson("/api/v1/admin/reports/financial/profit-loss?start_date={$t}&end_date={$t}")->assertOk()->json();
        $c  = $this->checks();

        $this->assertSame((int) $pl['unpriced_lines'], $c['uncosted_lines']['count'], 'one definition: Data Quality states what the P&L leaves out');
        // Seven lines sold in rated currencies; one has no cost anywhere.
        $this->assertSame(7, $c['_coverage']['lines']);
        $this->assertSame(85.7, $c['_coverage']['lines_costed']);
    }

    public function test_the_outlet_filter_narrows_what_it_should_and_nothing_else(): void
    {
        $this->actingWith(['reports.view', 'reports.financial']);
        $c = $this->checks("&outlet_id={$this->b->id}");

        $this->assertSame(0, $c['anonymous_sales']['count']);
        $this->assertSame(0, $c['overpaid_orders']['count']);
        $this->assertSame(0, $c['expenses_awaiting_approval']['count']);
        $this->assertSame(1, $c['orders_without_outlet']['count'], 'the orders no outlet view can show — named whatever the filter');
        $this->assertSame(1, $c['duplicate_customer_records']['count'], 'customers belong to the whole business');
        $this->assertFalse($c['orders_without_outlet']['outlet']);
    }

    public function test_links_and_contacts_follow_the_viewer_s_permissions(): void
    {
        // A report reader with nothing else: figures and names, no contacts,
        // no links to screens they cannot open, no expense money.
        $this->actingWith(['reports.view']);
        $c = $this->checks();

        $this->assertArrayNotHasKey('expenses_awaiting_approval', $c, 'expense money stays behind reports.financial');
        $this->assertNotSame('cash', $c['unreadable_order_phones']['rows'][0]['phone_field'] ?? null, 'contacts need customers.view');
        $this->assertSame([], $c['anonymous_sales']['rows'][0]['links']);
        $this->assertNull($c['unrated_currency_sales']['fix']['to'], 'Settings → Currencies is super_admin only');
        $this->assertNull($c['payments_awaiting_approval']['fix']['to']);

        $this->actingWith(['reports.view', 'orders.view', 'customers.view', 'products.view']);
        $c = $this->checks();
        $this->assertStringStartsWith('/sales/orders/', $c['anonymous_sales']['rows'][0]['links']['order']);
        $this->assertStringStartsWith('/catalogue/products/', $c['uncosted_lines']['rows'][0]['links']['product']);
        $this->assertStringStartsWith('/sales/customers/', $c['duplicate_customer_records']['rows'][0]['links']['customer']);
    }
}
