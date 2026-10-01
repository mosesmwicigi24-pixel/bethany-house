<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cycle 3: the rest of the reports tell the truth about money and about who
 * bought.
 *
 * Two defect classes, both already fixed elsewhere and both still live here,
 * which is what "audit the whole class" is supposed to prevent:
 *
 *  1. **Money summed across currencies.** 68 unconverted aggregations across
 *     19 methods. On production (orders hold 61 USD and 1 ZMW): sales by
 *     product 6,592,367 shown against 7,089,826 true; by customer 6,350,450
 *     against 6,897,439; /financial/revenue 5,288,590 against 5,745,409; and
 *     Neema chat sales 900,880 against 3,419,690 — the WhatsApp and Messenger
 *     channel under-reported by 3.8x, because that is where the foreign
 *     orders are.
 *  2. **A buyer identified by their login.** NOT ONE of the 623 paid orders
 *     on production has a `user_id`. Sales by Customer and Lifetime Value
 *     were therefore EMPTY, active customers read 0, and every customer was
 *     segmented "New". The same defect as #377 and cycle 1's customer
 *     summary, third and fourth instance.
 *
 * The suite was 1,194 green while all of that was live, so these reports had
 * no tests at all. That is the real finding, and this file is the answer.
 *
 * 128 KES per USD throughout, so a missed conversion is unmissable.
 */
class ReportCurrencyAndBuyerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['KES', 1.0, true], ['USD', 128.0, false]] as [$c, $r, $base]) {
            DB::table('currencies')->updateOrInsert(['code' => $c], [
                'name' => $c, 'symbol' => $c, 'exchange_rate' => 1.0,
                'reporting_rate_to_kes' => $r, 'is_base' => $base, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        \App\Support\ReportingCurrency::forget();

        $staff = User::factory()->create();
        foreach (['reports.view', 'reports.financial', 'customers.view', 'customers.insights'] as $p) {
            $staff->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($staff);
    }

    /** A walk-in: a customer record with no login, which is what 683 of 684 are. */
    private function walkIn(string $name): Customer
    {
        return Customer::create([
            'first_name' => $name, 'last_name' => 'Buyer',
            'email' => strtolower($name) . '@example.test',
            'phone' => '07' . random_int(10000000, 99999999),
        ]);
    }

    /**
     * The money that settled a paid order, in its own currency. The payment-
     * method breakdown reads PAYMENTS (money received, cycle 9) — an order
     * marked paid with no payment row, which production never holds, has no
     * rail to be attributed to.
     */
    private function settled(Order $order): Order
    {
        \App\Models\Payment::create([
            'order_id' => $order->id, 'amount' => $order->total_amount, 'currency_code' => $order->currency_code,
            'payment_method' => 'cash', 'status' => 'paid', 'paid_at' => now(),
        ]);

        return $order;
    }

    private function paidOrder(Customer $customer, string $currency, float $total): Order
    {
        return Order::create([
            'order_number'         => 'PO-' . bin2hex(random_bytes(4)),
            'customer_id'          => $customer->id,
            'customer_first_name'  => $customer->first_name,
            'customer_last_name'   => $customer->last_name,
            'customer_email'       => $customer->email,
            'customer_phone'       => $customer->phone,
            'status'               => 'completed',
            'payment_status'       => 'paid',
            'payment_method'       => 'cash',
            'currency_code'        => $currency,
            'subtotal'             => $total,
            'total_amount'         => $total,
        ]);
    }

    private function itemOn(Order $order, Product $product, float $unit, int $qty = 1): void
    {
        OrderItem::create([
            'order_id'     => $order->id,
            'product_id'   => $product->id,
            'product_name' => $product->name_en ?? 'Item',
            'sku'          => $product->sku ?? ('SKU-' . $product->id),
            'quantity'     => $qty,
            'unit_price'   => $unit,
            'total_price'  => $unit * $qty,
        ]);
    }

    private function report(string $path): array
    {
        $join = str_contains($path, '?') ? '&' : '?';

        return $this->getJson($path . $join . 'start_date=2020-01-01&end_date=2030-12-31&from=2020-01-01&to=2030-12-31')
            ->assertOk()->json();
    }

    // ── Money in one unit ────────────────────────────────────────────────────

    public function test_sales_by_product_counts_the_foreign_business(): void
    {
        $product  = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        $kesOrder = $this->paidOrder($this->walkIn('Kesbuyer'), 'KES', 1_000);
        $usdOrder = $this->paidOrder($this->walkIn('Usdbuyer'), 'USD', 100);
        $this->itemOn($kesOrder, $product, 1_000);
        $this->itemOn($usdOrder, $product, 100);      // 12,800 in shillings

        $row = collect($this->report('/api/v1/admin/reports/sales/by-product')['products'])->first();

        $this->assertSame(13_800.0, round((float) $row['total_revenue'], 2),
            'KES 1,000 + USD 100 at 128');
    }

    public function test_sales_by_category_counts_it_too(): void
    {
        $category = Category::create(['name_en' => 'Vestments', 'name_sw' => 'Vestments', 'slug' => 'vestments', 'is_active' => true]);
        $product  = Product::factory()->create(['status' => Product::STATUS_ACTIVE, 'category_id' => $category->id]);
        $this->itemOn($this->paidOrder($this->walkIn('Cat1'), 'KES', 500), $product, 500);
        $this->itemOn($this->paidOrder($this->walkIn('Cat2'), 'USD', 10), $product, 10);   // 1,280

        $row = collect($this->report('/api/v1/admin/reports/sales/by-category')['categories'])->first();

        $this->assertSame(1_780.0, round((float) $row['total_revenue'], 2));
    }

    public function test_the_cash_revenue_report_converts(): void
    {
        $this->paidOrder($this->walkIn('R1'), 'KES', 2_000);
        $this->paidOrder($this->walkIn('R2'), 'USD', 100);      // 12,800

        $this->assertSame(14_800.0, round((float) $this->report('/api/v1/admin/reports/financial/revenue')['total'], 2));
    }

    public function test_sales_by_payment_method_converts(): void
    {
        $this->settled($this->paidOrder($this->walkIn('P1'), 'KES', 3_000));
        $this->settled($this->paidOrder($this->walkIn('P2'), 'USD', 50));       // 6,400

        $row = collect($this->report('/api/v1/admin/reports/sales/by-payment-method')['payment_methods'])->first();

        $this->assertSame(9_400.0, round((float) $row['total'], 2));
    }

    public function test_a_currency_with_no_reporting_rate_is_left_out_of_both_the_money_and_the_count(): void
    {
        DB::table('currencies')->updateOrInsert(['code' => 'GBP'], [
            'name' => 'GBP', 'symbol' => '£', 'exchange_rate' => 1.0,
            'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \App\Support\ReportingCurrency::forget();

        $this->settled($this->paidOrder($this->walkIn('Known'), 'KES', 1_000));
        $this->settled($this->paidOrder($this->walkIn('Unrated'), 'GBP', 9_999));

        $rows = collect($this->report('/api/v1/admin/reports/sales/by-payment-method')['payment_methods']);
        $row  = $rows->first();

        // The unrated order must not appear as 9,999 shillings, and must not
        // be counted in a row whose money excludes it either.
        $this->assertSame(1_000.0, round((float) $row['total'], 2));
        $this->assertSame(1, (int) $row['count'], 'the count agrees with the money');
    }

    public function test_asking_for_a_foreign_currency_reports_it_natively(): void
    {
        $this->settled($this->paidOrder($this->walkIn('N1'), 'USD', 100));
        $this->settled($this->paidOrder($this->walkIn('N2'), 'KES', 5_000));

        $row = collect($this->report('/api/v1/admin/reports/sales/by-payment-method?currency_code=USD')['payment_methods'])->first();

        // 100 dollars stay 100 dollars — converting here would be the mirror
        // of the bug, and the KES order must not join the figure.
        $this->assertSame(100.0, round((float) $row['total'], 2));
    }

    // ── Who bought ───────────────────────────────────────────────────────────

    public function test_sales_by_customer_is_not_empty_for_walk_ins(): void
    {
        $customer = $this->walkIn('Walkin');
        $this->paidOrder($customer, 'KES', 4_000);

        $rows = collect($this->report('/api/v1/admin/reports/sales/by-customer')['customers']);

        $this->assertCount(1, $rows, 'the report was empty on production');
        $this->assertSame(4_000.0, round((float) $rows->first()['total_spent'], 2));
    }

    public function test_a_walk_in_with_two_orders_is_one_line(): void
    {
        $customer = $this->walkIn('Twice');
        $this->paidOrder($customer, 'KES', 1_000);
        $this->paidOrder($customer, 'USD', 10);         // 1,280

        $rows = collect($this->report('/api/v1/admin/reports/sales/by-customer')['customers']);

        $this->assertCount(1, $rows, 'one person, not one row per order');
        $first = $rows->first();
        $this->assertSame(2, (int) $first['order_count']);
        $this->assertSame(2_280.0, round((float) $first['total_spent'], 2));
    }

    public function test_lifetime_value_lists_walk_ins(): void
    {
        $this->paidOrder($this->walkIn('Ltv'), 'USD', 100);     // 12,800

        $rows = collect($this->report('/api/v1/admin/reports/customers/lifetime-value')['customers']);

        $this->assertCount(1, $rows);
        $this->assertSame(12_800.0, round((float) $rows->first()['total_spent'], 2));
    }

    public function test_active_customers_counts_people_not_logins(): void
    {
        $this->paidOrder($this->walkIn('Active1'), 'KES', 100);
        $this->paidOrder($this->walkIn('Active2'), 'KES', 200);

        $stats = $this->getJson('/api/v1/admin/reports/customers/analytics')->assertOk()->json('stats');

        $this->assertSame(2, (int) $stats['active_customers'], 'read 0 on production while hundreds bought');
    }

    public function test_a_returning_customer_is_not_segmented_as_new(): void
    {
        $regular = $this->walkIn('Regular');
        for ($i = 0; $i < 5; $i++) {
            $this->paidOrder($regular, 'KES', 100);
        }

        $segments = $this->getJson('/api/v1/admin/reports/customers/analytics')->assertOk()->json('segments');

        $this->assertArrayHasKey('Regular', $segments, 'five orders is not a new customer');
        $this->assertSame(1, (int) $segments['Regular']);
    }

    public function test_a_dollar_customer_lands_in_the_right_spend_bracket(): void
    {
        // USD 1,280 is KES 163,840 — the top bracket. Read raw it is "1,280",
        // which lands in "Under 5k": the unit error, made visible by a
        // boundary stated in shillings.
        $this->paidOrder($this->walkIn('Big'), 'USD', 1_280);

        $brackets = $this->getJson('/api/v1/admin/reports/customers/analytics')->assertOk()->json('spend_brackets');

        $this->assertSame(1, (int) ($brackets['100k+'] ?? 0), 'KES 163,840 is a six-figure customer');
        $this->assertSame(0, (int) ($brackets['Under 5k'] ?? 0));
    }

    // ── The three reports that had no test at all ────────────────────────────

    public function test_the_purchase_order_report_states_foreign_orders_in_shillings(): void
    {
        // 52 of 52 purchase orders on production are KES, so this report was
        // right by luck. It has also never had a test, which is how eleven raw
        // sums survived in one method.
        $supplier = DB::table('suppliers')->insertGetId([
            'name' => 'Cloth Co', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([['KES', 1_000], ['USD', 100]] as $i => [$currency, $total]) {
            DB::table('purchase_orders')->insert([
                'po_number' => 'PO-TEST-' . $i, 'supplier_id' => $supplier,
                'order_date' => now()->toDateString(), 'status' => 'received',
                'currency_code' => $currency, 'subtotal' => $total,
                'total_amount' => $total, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $summary = $this->report('/api/v1/admin/reports/purchase-orders')['summary'];

        $this->assertSame(2, (int) $summary['total_orders']);
        $this->assertSame(13_800.0, round((float) $summary['total_value'], 2), 'KES 1,000 + USD 100 at 128');
        $this->assertSame(13_800.0, round((float) $summary['received_value'], 2));
    }

    public function test_a_refund_on_a_dollar_order_is_stated_in_shillings(): void
    {
        $customer = $this->walkIn('Returner');
        $order    = $this->paidOrder($customer, 'USD', 200);

        DB::table('order_returns')->insert([
            'return_number' => 'RET-1', 'order_id' => $order->id, 'status' => 'approved',
            'return_reason' => 'wrong size', 'refund_amount' => 50,      // 6,400
            'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $body = $this->report('/api/v1/admin/reports/sales/returns');

        $this->assertSame(6_400.0, round((float) $body['summary']['total_refunded'], 2));
        $this->assertSame(1, (int) $body['summary']['unique_customers'], 'a walk-in is a customer');
        $this->assertSame(6_400.0, round((float) collect($body['by_reason'])->first()['total_refunded'], 2),
            'the reason breakdown is in the same unit as the total above it');
    }

    public function test_the_dashboard_kpis_describe_the_same_orders(): void
    {
        // total and average filtered to one currency while count did not, so
        // the dashboard's average order value was a KES total divided by an
        // all-currency count.
        $this->paidOrder($this->walkIn('D1'), 'KES', 1_000);
        $this->paidOrder($this->walkIn('D2'), 'USD', 100);          // 12,800

        $sales = $this->getJson('/api/v1/admin/reports/dashboard/kpis')->assertOk()->json('kpis.sales');

        $this->assertSame(2, (int) $sales['count']);
        $this->assertSame(13_800.0, round((float) $sales['total'], 2));
        $this->assertSame(6_900.0, round((float) $sales['average'], 2), 'the total over its own count');
    }

    public function test_a_dollar_sold_production_job_does_not_report_a_ruinous_margin(): void
    {
        // Material costs are in shillings; revenue came from the order item
        // raw. A job sold for USD 100 against KES 5,000 of cloth therefore
        // reported revenue 100 and a margin of −4,900%. The report has never
        // had a test, which is how four raw sums survived in it.
        $product  = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        $customer = $this->walkIn('Prod');
        $order    = $this->paidOrder($customer, 'USD', 100);
        $this->itemOn($order, $product, 100);
        $item     = DB::table('order_items')->where('order_id', $order->id)->first();

        DB::table('production_orders')->insert([
            'order_number'      => 'PRD-1',
            'product_id'        => $product->id,
            'quantity'          => 1,
            'status'            => 'completed',
            'priority'          => 'normal',
            'is_customer_order' => true,
            'customer_order_id' => $order->id,
            'order_item_id'     => $item->id,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $body = $this->getJson('/api/v1/admin/reports/production/costing-summary?status=completed'
            . '&start_date=2020-01-01&end_date=2030-12-31')->assertOk()->json();

        $row = collect($body['orders'] ?? $body['rows'] ?? [])->first();

        $this->assertNotNull($row, 'the completed job must appear');
        $this->assertSame(12_800.0, round((float) $row['revenue'], 2), 'USD 100 is KES 12,800');
    }

    // ── The file, not just the screen ────────────────────────────────────────

    /** The CSV body for a report, as a staff member with permission to take it. */
    private function csv(string $path): string
    {
        $exporter = User::factory()->create();
        foreach (['reports.view', 'reports.financial', 'reports.export'] as $p) {
            $exporter->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($exporter);

        $join = str_contains($path, '?') ? '&' : '?';

        // csvResponse builds the file in memory rather than streaming it, on
        // purpose — its docblock explains why — so read the content directly.
        return $this->get($path . $join . 'export=csv&start_date=2020-01-01&end_date=2030-12-31')
            ->assertOk()->getContent();
    }

    public function test_the_downloaded_file_carries_the_corrected_product_revenue(): void
    {
        // The CSV branch of these methods was never exercised by a test, so a
        // figure fixed on the screen might not have followed into the file
        // people actually circulate.
        $product  = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        $this->itemOn($this->paidOrder($this->walkIn('Csv1'), 'KES', 1_000), $product, 1_000);
        $this->itemOn($this->paidOrder($this->walkIn('Csv2'), 'USD', 100), $product, 100);

        $csv = $this->csv('/api/v1/admin/reports/sales/by-product');

        $this->assertStringContainsString('13800', str_replace(['"', ','], '', $csv),
            'the file states KES 13,800, as the page does');
        $this->assertStringNotContainsString('Total Revenue,1100', $csv, 'not face value');
    }

    public function test_the_downloaded_customer_file_is_not_empty_either(): void
    {
        $customer = $this->walkIn('Downloadable');
        $this->paidOrder($customer, 'USD', 50);      // 6,400

        $csv = $this->csv('/api/v1/admin/reports/sales/by-customer');

        $this->assertStringContainsString('Downloadable', $csv, 'the file listed nobody before');
        $this->assertStringContainsString('6400', str_replace(['"', ','], '', $csv));
    }

    // ── The segment join, without its OR ─────────────────────────────────────

    /** A customer who ALSO has a web login — 1 of 718 on production. */
    private function customerWithLogin(string $name): array
    {
        $user = User::factory()->create();
        $customer = Customer::create([
            'customer_number' => 'CUST-LOGIN-' . bin2hex(random_bytes(3)),
            'first_name' => $name, 'last_name' => 'L', 'user_id' => $user->id,
            'email' => strtolower($name) . '.login@example.test',
            'phone' => '07' . random_int(10000000, 99999999),
        ]);

        return [$customer, $user];
    }

    private function recognisedOrder(?int $customerId, ?int $userId): void
    {
        Order::create([
            'order_number' => 'SG-' . bin2hex(random_bytes(4)),
            'customer_id' => $customerId, 'user_id' => $userId,
            'status' => 'completed', 'payment_status' => 'paid',
            'currency_code' => 'KES', 'subtotal' => 100, 'total_amount' => 100,
        ]);
    }

    public function test_an_order_matching_a_customer_by_both_keys_counts_once(): void
    {
        // The segment join used `customer_id = c.id OR user_id = c.user_id`,
        // which is quadratic at volume (cycle 7). Its replacement is a UNION of
        // two equality joins — and UNION, unlike UNION ALL, de-duplicates. This
        // pins that: four orders that match by BOTH keys are four orders, which
        // is "Repeat". Counted twice they would be eight, which is "Regular".
        [$customer, $user] = $this->customerWithLogin('Both');
        for ($i = 0; $i < 4; $i++) {
            $this->recognisedOrder($customer->id, $user->id);
        }

        $segments = $this->getJson('/api/v1/admin/reports/customers/analytics')->assertOk()->json('segments');

        $this->assertSame(1, (int) ($segments['Repeat'] ?? 0), 'four orders, counted once each');
        $this->assertArrayNotHasKey('Regular', $segments, 'double-counting would read eight');
    }

    public function test_an_order_placed_only_through_the_login_still_counts(): void
    {
        // The second arm of the UNION: an order carrying the login but no
        // customer record. Without that arm this customer has one order ("New");
        // with it, two ("Repeat").
        [$customer, $user] = $this->customerWithLogin('LoginOnly');
        $this->recognisedOrder($customer->id, null);   // by customer record
        $this->recognisedOrder(null, $user->id);       // by login only

        $segments = $this->getJson('/api/v1/admin/reports/customers/analytics')->assertOk()->json('segments');

        $this->assertSame(1, (int) ($segments['Repeat'] ?? 0), 'both routes to the same person count');
        $this->assertArrayNotHasKey('New', $segments);
    }
}
