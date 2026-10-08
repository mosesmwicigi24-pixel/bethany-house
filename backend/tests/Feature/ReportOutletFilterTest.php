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
 * Reports build — the outlet is a FILTER, applied at the query layer, on every
 * endpoint a page draws from. Before this, the sales summary honoured it while
 * sales by product, category and customer, the customer pages, the P&L,
 * revenue, production, purchasing, tax and cash flow ignored it: a headline
 * for one shop over a table for all of them.
 *
 * Outlet A sells 1,000; outlet B sells 50,000 — far enough apart that a leak
 * cannot hide in rounding.
 */
class ReportOutletFilterTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $a;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('currencies')->updateOrInsert(['code' => 'KES'], [
            'name' => 'KES', 'symbol' => 'KES', 'exchange_rate' => 1, 'reporting_rate_to_kes' => 1,
            'is_base' => true, 'is_active' => true,
        ]);
        \App\Support\ReportingCurrency::forget();

        $this->a = Outlet::factory()->create();
        $b = Outlet::factory()->create();
        $product = Product::factory()->create();
        $owner   = User::factory()->create();
        $cat     = DB::table('expense_categories')->insertGetId([
            'name' => 'Rent', 'code' => 'RENT-OF', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([[$this->a, 1_000, '0711111111'], [$b, 50_000, '0722222222']] as $i => [$outlet, $amount, $phone]) {
            $o = Order::create([
                'order_number' => "OF-{$i}", 'order_type' => 'pos', 'status' => 'completed', 'payment_status' => 'paid',
                'currency_code' => 'KES', 'subtotal' => $amount, 'total_amount' => $amount, 'outlet_id' => $outlet->id,
                'customer_first_name' => "Buyer{$i}", 'customer_phone' => $phone,
            ]);
            DB::table('order_items')->insert([
                'order_id' => $o->id, 'product_id' => $product->id, 'sku' => 'OF', 'product_name' => 'Alb',
                'quantity' => 1, 'unit_price' => $amount, 'total_price' => $amount, 'created_at' => now(), 'updated_at' => now(),
            ]);
            Payment::create(['order_id' => $o->id, 'amount' => $amount, 'currency_code' => 'KES',
                'payment_method' => 'cash', 'status' => 'paid', 'paid_at' => now()]);
            DB::table('expenses')->insert([
                'reference_number' => "EXP-OF-{$i}", 'title' => 'Rent', 'amount' => $amount, 'amount_kes' => $amount,
                'currency_code' => 'KES', 'expense_date' => now()->format('Y-m-d'), 'status' => 'approved',
                'payment_method' => 'cash', 'outlet_id' => $outlet->id, 'category_id' => $cat, 'created_by' => $owner->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $u = User::factory()->create();
        foreach ([...\Tests\ReportAccess::PAGES, 'reports.financial', 'customers.view'] as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);
    }

    private function report(string $endpoint): array
    {
        $t = now()->format('Y-m-d');
        $sep = str_contains($endpoint, '?') ? '&' : '?';

        return $this->getJson("/api/v1/admin/reports/{$endpoint}{$sep}start_date={$t}&end_date={$t}&outlet_id={$this->a->id}")
            ->assertOk()->json();
    }

    public function test_every_sales_and_finance_figure_answers_for_the_chosen_outlet_only(): void
    {
        $sum = fn (array $rows, string $k) => round((float) collect($rows)->sum($k), 2);

        $this->assertSame(1_000.0, (float) $this->report('sales/summary')['summary']['total_revenue'], 'summary');
        $this->assertSame(1_000.0, $sum($this->report('sales/by-product')['products'], 'total_revenue'), 'by product');
        $this->assertSame(1_000.0, $sum($this->report('sales/by-customer')['customers'], 'total_spent'), 'by customer');
        $this->assertSame(1_000.0, (float) $this->report('financial/profit-loss')['revenue'], 'P&L revenue');
        $this->assertSame(1_000.0, (float) $this->report('financial/profit-loss')['operating_expenses'], 'P&L expenses');
        $this->assertSame(1_000.0, (float) $this->report('financial/revenue')['sold']['total'], 'sold');
        $this->assertSame(1_000.0, (float) $this->report('financial/revenue')['collected']['total'], 'collected');
        $this->assertSame(1, (int) $this->report('customers/summary')['unique_buyers'], 'buyers');
    }

    public function test_without_an_outlet_the_whole_business_is_reported(): void
    {
        $t = now()->format('Y-m-d');
        $rev = (float) $this->getJson("/api/v1/admin/reports/sales/by-product?start_date={$t}&end_date={$t}")
            ->assertOk()->json('products.0.total_revenue');
        $this->assertSame(51_000.0, $rev, 'no outlet chosen: both shops — the control for the test above');
    }
}
