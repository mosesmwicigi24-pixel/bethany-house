<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
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
 * "Revenue" means SOLD or COLLECTED, is labelled either way, and never stands
 * alone. Owner's decision, 2026-09-30 (D3).
 *
 * Three figures called themselves revenue. Measured on Q3 2026, all converted:
 * sold 6,811,239 across 678 orders; collected 6,451,047 across 684; and
 * `/financial/revenue` 5,721,209 across 611 — a figure that was NEITHER. Its
 * `payment_status = 'paid'` filter dropped every part-paid and deposit order,
 * so it understated what was sold, while counting the full value of each paid
 * order whenever the cash arrived, so it was not collected either. It answered
 * no question anyone would ask, on a page titled Revenue.
 *
 * The same filter made the product and category breakdowns fail to add up to
 * the headline above them, and it made Sales by Customer contradict the
 * customer's own page, whose definition #378 had already settled: 450 buyers
 * and 5,820,409 against the page's 494 and 6,972,439.
 *
 * These tests assert the identities that were impossible before.
 */
class ReportRevenueBasisTest extends TestCase
{
    use RefreshDatabase;

    private const WINDOW = 'start_date=2020-01-01&end_date=2030-12-31&from=2020-01-01&to=2030-12-31&period=custom';

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

    /**
     * The shape that exposed the old filter: a fully-paid order, a DEPOSIT
     * order (recognised income, but not `payment_status = 'paid'`), and a
     * payment that arrived for an order sold in an earlier period.
     */
    private function seedTheAwkwardCases(): Product
    {
        // Categorised, so the category breakdown actually has something to
        // reconcile — an uncategorised product is joined out of that report.
        $categoryId = DB::table('categories')->insertGetId([
            'name_en' => 'Vestments', 'name_sw' => 'Vestments', 'slug' => 'vestments-' . bin2hex(random_bytes(2)),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $product  = Product::factory()->create([
            'status' => Product::STATUS_ACTIVE, 'category_id' => $categoryId,
        ]);
        $customer = Customer::create(['first_name' => 'Wanjiru', 'last_name' => 'N',
            'email' => 'wanjiru@example.test', 'phone' => '0722000301']);

        // Fully paid, in shillings.
        $paid = $this->order($customer, 'KES', 4_000, 'completed', 'paid');
        $this->item($paid, $product, 4_000);
        Payment::create(['order_id' => $paid->id, 'amount' => 4_000, 'currency_code' => 'KES',
            'status' => 'paid', 'payment_method' => 'cash', 'paid_at' => now()]);

        // A deposit order: real income, and the old filter could not see it.
        $deposit = $this->order($customer, 'KES', 2_000, 'confirmed', 'deposit');
        $this->item($deposit, $product, 2_000);
        Payment::create(['order_id' => $deposit->id, 'amount' => 500, 'currency_code' => 'KES',
            'status' => 'paid', 'payment_method' => 'cash', 'paid_at' => now()]);

        // Foreign, part-paid: the currency and the basis at once.
        $usd = $this->order($customer, 'USD', 100, 'processing', 'partial');   // 12,800 sold
        $this->item($usd, $product, 100);
        Payment::create(['order_id' => $usd->id, 'amount' => 25, 'currency_code' => 'USD',
            'status' => 'paid', 'payment_method' => 'card', 'paid_at' => now()]);  // 3,200 collected

        return $product;
    }

    private function order(Customer $c, string $currency, float $total, string $status, string $pay): Order
    {
        return Order::create([
            'order_number'        => 'RB-' . bin2hex(random_bytes(4)),
            'customer_id'         => $c->id,
            'customer_first_name' => $c->first_name,
            'customer_last_name'  => $c->last_name,
            'customer_email'      => $c->email,
            'customer_phone'      => $c->phone,
            'status'              => $status,
            'payment_status'      => $pay,
            'payment_method'      => 'cash',
            'currency_code'       => $currency,
            'subtotal'            => $total,
            'total_amount'        => $total,
        ]);
    }

    private function item(Order $order, Product $product, float $unit): void
    {
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_name' => $product->name_en ?? 'Chasuble',
            'sku' => $product->sku ?? ('SKU-' . $product->id),
            'quantity' => 1, 'unit_price' => $unit, 'total_price' => $unit,
        ]);
    }

    private function report(string $path): array
    {
        $join = str_contains($path, '?') ? '&' : '?';

        return $this->getJson($path . $join . self::WINDOW)->assertOk()->json();
    }

    public function test_the_revenue_page_states_both_bases_and_names_them(): void
    {
        $this->seedTheAwkwardCases();

        $body = $this->report('/api/v1/admin/reports/financial/revenue');

        // SOLD: 4,000 + 2,000 + 12,800 = 18,800.
        $this->assertSame(18_800.0, round((float) $body['sold']['total'], 2));
        // COLLECTED: 4,000 + 500 + 3,200 = 7,700.
        $this->assertSame(7_700.0, round((float) $body['collected']['total'], 2));

        // Each carries the basis in words, so neither can be quoted as "revenue".
        $this->assertStringContainsString('recognised', $body['sold']['basis']);
        $this->assertStringContainsString('money arrived', $body['collected']['basis']);

        // And the difference is named rather than left to look like an error.
        $this->assertSame(11_100.0, round((float) $body['receivable_movement']['amount'], 2));
        $this->assertStringContainsString('not a discrepancy', $body['receivable_movement']['note']);
    }

    public function test_sold_agrees_with_the_sales_summary_to_the_cent(): void
    {
        $this->seedTheAwkwardCases();

        $revenue = (float) $this->report('/api/v1/admin/reports/financial/revenue')['sold']['total'];
        $summary = (float) $this->report('/api/v1/admin/reports/sales/summary')['summary']['total_revenue'];

        $this->assertSame(round($summary, 2), round($revenue, 2),
            'the financial page and the sales page report one number for what was sold');
    }

    public function test_the_product_rows_finally_add_up_to_the_headline(): void
    {
        // This has never been true. The breakdown counted only fully-paid
        // orders while the headline counted recognised income, so a deposit
        // order appeared in the total and in no product row.
        $this->seedTheAwkwardCases();

        $headline = (float) $this->report('/api/v1/admin/reports/sales/summary')['summary']['total_revenue'];
        $products = collect($this->report('/api/v1/admin/reports/sales/by-product')['products']);

        $this->assertSame(18_800.0, round($headline, 2));
        $this->assertSame(round($headline, 2), round((float) $products->sum('total_revenue'), 2),
            'every shilling of the headline belongs to some product');
    }

    public function test_the_category_rows_add_up_to_the_same_headline(): void
    {
        $this->seedTheAwkwardCases();

        $headline   = (float) $this->report('/api/v1/admin/reports/sales/summary')['summary']['total_revenue'];
        $categories = collect($this->report('/api/v1/admin/reports/sales/by-category')['categories']);

        // Uncategorised products are joined out of the category report, so this
        // holds only when every product has a category — which the seed gives
        // it. Stated rather than assumed, because a silently-dropped category
        // is how the product breakdown lost its footing in the first place.
        $this->assertNotEmpty($categories, 'the seeded product is categorised');
        $this->assertSame(round($headline, 2), round((float) $categories->sum('total_revenue'), 2));
    }

    public function test_what_a_customer_spent_is_the_same_on_the_report_and_the_page(): void
    {
        $this->seedTheAwkwardCases();

        $row = collect($this->report('/api/v1/admin/reports/sales/by-customer')['customers'])->first();

        // All three orders belong to one customer: 18,800 of recognised spend.
        // The old paid-only basis showed 4,000 — and the customer's own page,
        // which #378 put on the recognised basis, showed 18,800. Same question,
        // two answers, until now.
        $this->assertSame(18_800.0, round((float) $row['total_spent'], 2));
        $this->assertSame(3, (int) $row['order_count']);
    }

    public function test_sales_by_payment_method_stays_on_the_paid_basis_deliberately(): void
    {
        $this->seedTheAwkwardCases();

        $rows      = collect($this->report('/api/v1/admin/reports/sales/by-payment-method')['payment_methods']);
        $collected = (float) $this->report('/api/v1/admin/reports/sales/summary')['summary']['total_collected'];

        // An order nobody has paid cannot be attributed to a payment rail, so
        // this ONE sales figure is never the recognised 18,800. Pinned so a
        // later sweep does not "fix" it into revenue.
        //
        // It used to be 4,000: the totals of FULLY PAID orders only — a proxy
        // for "money that moved" that dropped every part payment. Since cycle 9
        // it is the money itself: payments received, the same query the
        // summary's panel uses, so it equals Collected. The principle this
        // test guards is unchanged; only the proxy is gone.
        $this->assertNotSame(18_800.0, round((float) $rows->sum('total'), 2), 'never the revenue figure');
        $this->assertSame(round($collected, 2), round((float) $rows->sum('total'), 2), 'the money received, by rail');
    }

    public function test_the_dashboard_agrees_with_the_sales_page(): void
    {
        // The dashboard was the last paid-only revenue basis in the module, on
        // the first screen a manager opens. Measured live: 176 orders and KES
        // 1,655,650 where the sales page said 207 and 2,118,350 for the same
        // thirty days. Two pages, one month, a 28% gap.
        $this->seedTheAwkwardCases();

        $dash    = $this->getJson('/api/v1/admin/reports/dashboard/kpis?days=30')->assertOk()->json('kpis.sales');
        $summary = $this->report('/api/v1/admin/reports/sales/summary')['summary'];

        $this->assertSame(
            round((float) $summary['total_revenue'], 2),
            round((float) $dash['total'], 2),
            'the dashboard and the sales page describe the same sales',
        );
        $this->assertSame((int) $summary['total_orders'], (int) $dash['count']);
    }
}
