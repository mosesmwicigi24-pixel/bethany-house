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
 * Business Explorer — a slice of the same numbers, never a new number. Its
 * totals are the Executive tiles (Sold, Collected) and Sales by Product's
 * line total for the same window; every dimension partitions them; every row
 * opens exactly the orders it counted.
 */
class ReportExplorerTest extends TestCase
{
    use RefreshDatabase;

    private User $ann;
    private int $albs;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('currencies')->updateOrInsert(['code' => 'KES'], [
            'name' => 'KES', 'symbol' => 'KES', 'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true,
        ]);
        DB::table('currencies')->updateOrInsert(['code' => 'USD'], [
            'name' => 'USD', 'symbol' => '$', 'exchange_rate' => 0.01, 'reporting_rate_to_kes' => 128, 'is_base' => false, 'is_active' => true,
        ]);
        \App\Support\ReportingCurrency::forget();

        $town      = Outlet::factory()->create(['name' => 'Town']);
        $this->ann = User::factory()->create(['first_name' => 'Ann', 'last_name' => 'K']);
        $ben       = User::factory()->create(['first_name' => 'Ben', 'last_name' => 'O']);
        $this->albs = DB::table('categories')->insertGetId(['slug' => 'albs', 'name_en' => 'Albs', 'created_at' => now(), 'updated_at' => now()]);
        $stoles     = DB::table('categories')->insertGetId(['slug' => 'stoles', 'name_en' => 'Stoles', 'created_at' => now(), 'updated_at' => now()]);
        $alb   = Product::factory()->create(['category_id' => $this->albs]);
        $stole = Product::factory()->create(['category_id' => $stoles]);

        $sale = function (?User $by, array $lines, string $cur = 'KES', string $method = 'cash', array $extra = []) use ($town) {
            $total = array_sum(array_column($lines, 1));
            $o = Order::create($extra + [
                'order_number' => 'EX-' . uniqid(), 'order_type' => 'pos', 'status' => 'completed', 'payment_status' => 'paid',
                'currency_code' => $cur, 'subtotal' => $total, 'total_amount' => $total, 'outlet_id' => $town->id,
                'created_by' => $by?->id, 'customer_phone' => '07' . random_int(10000000, 99999999),
            ]);
            foreach ($lines as [$product, $price]) {
                DB::table('order_items')->insert(['order_id' => $o->id, 'product_id' => $product->id, 'sku' => 'EX',
                    'product_name' => 'P' . $product->id, 'quantity' => 1, 'unit_price' => $price, 'total_price' => $price,
                    'created_at' => now(), 'updated_at' => now()]);
            }
            if (($extra['payment_status'] ?? 'paid') === 'paid') {
                Payment::create(['order_id' => $o->id, 'amount' => $total, 'currency_code' => $cur,
                    'payment_method' => $method, 'status' => 'paid', 'paid_at' => now()]);
            }
        };
        $sale($this->ann, [[$alb, 10_000]]);
        $sale($this->ann, [[$alb, 60], [$stole, 40]], 'USD', 'card');                // 12,800 KES; lines 7,680 + 5,120
        $sale($ben, [[$stole, 5_000]], 'KES', 'mpesa');
        $sale(null, [[$alb, 2_000]]);                                                 // web checkout
        $sale($ben, [[$alb, 9_999]], 'KES', 'cash', ['status' => 'pending', 'payment_status' => 'pending']); // a cart

        $u = User::factory()->create();
        foreach (['reports.view', 'orders.view'] as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);
    }

    private function explore(string $query): array
    {
        return $this->getJson('/api/v1/admin/reports/explorer?period=this_month&' . $query)->assertOk()->json();
    }

    public function test_totals_are_the_headline_figures_and_every_dimension_partitions_them(): void
    {
        $exec = $this->getJson('/api/v1/admin/reports/executive?period=this_month')->json('kpis');
        $sold = (float) $exec['sales']['revenue']['current'];
        $collected = (float) $exec['money']['collected']['current'];
        $this->assertSame(29_800.0, $sold);

        foreach (['month', 'week', 'day', 'outlet', 'channel', 'salesperson', 'currency'] as $by) {
            $r = $this->explore("by={$by}");
            $this->assertSame($sold, (float) $r['totals']['sold'], "{$by}: total is the Sold tile");
            $this->assertSame($collected, (float) $r['totals']['collected'], "{$by}: total is the Collected tile");
            $this->assertSame($sold, round(array_sum(array_column($r['rows'], 'sold')), 2), "{$by}: rows partition Sold");
            $this->assertSame($collected, round(array_sum(array_column($r['rows'], 'collected')), 2), "{$by}: rows partition Collected");
        }

        $method = $this->explore('by=method');
        $this->assertSame(['collected'], $method['measures'], 'a method belongs to a payment');
        $this->assertSame($collected, round(array_sum(array_column($method['rows'], 'collected')), 2));
    }

    public function test_line_dimensions_add_up_to_sales_by_product(): void
    {
        $t = now()->format('Y-m-d');
        $byProduct = round((float) collect($this->getJson("/api/v1/admin/reports/sales/by-product?start_date={$t}&end_date={$t}")
            ->assertOk()->json('products'))->sum('total_revenue'), 2);

        foreach (['product', 'category'] as $by) {
            $r = $this->explore("by={$by}");
            $this->assertSame('item', $r['level']);
            $this->assertSame($byProduct, (float) $r['totals']['line_value'], "{$by}: the same lines as Sales by Product");
            $this->assertSame($byProduct, round(array_sum(array_column($r['rows'], 'line_value')), 2));
        }

        $cats = collect($this->explore('by=category')['rows'])->keyBy('label');
        $this->assertSame(19_680.0, (float) $cats['Albs']['line_value'], '10,000 + 7,680 + 2,000');
        $this->assertSame(3, $cats['Albs']['orders']);
    }

    public function test_a_filter_narrows_and_a_line_filter_turns_the_question_into_lines(): void
    {
        $ann = $this->explore("by=currency&f_salesperson={$this->ann->id}");
        $this->assertSame(22_800.0, (float) $ann['totals']['sold']);

        // Albs sold by Ann: line value, not the whole of each basket.
        $r = $this->explore("by=salesperson&f_category={$this->albs}");
        $this->assertSame('item', $r['level']);
        $this->assertSame(17_680.0, (float) collect($r['rows'])->firstWhere('label', 'Ann K')['line_value']);
        $this->assertSame(2_000.0, (float) collect($r['rows'])->firstWhere('key', '__none__')['line_value']);

        $this->getJson("/api/v1/admin/reports/explorer?period=this_month&by=method&f_category={$this->albs}")->assertStatus(422);
    }

    public function test_each_row_opens_exactly_the_orders_it_counted(): void
    {
        $rows = collect($this->explore('by=salesperson')['rows'])->keyBy('key');

        foreach ([(string) $this->ann->id, '__none__'] as $key) {
            $orders = $this->getJson("/api/v1/admin/reports/explorer/orders?period=this_month&by=salesperson&key={$key}")
                ->assertOk()->json();
            $this->assertSame($rows[$key]['orders'], $orders['total']);
            $this->assertSame((float) $rows[$key]['sold'], round(array_sum(array_column($orders['rows'], 'amount')), 2));
            $this->assertStringStartsWith('/sales/orders/', $orders['rows'][0]['links']['order']);
        }

        // A line slice lists each order at the value of its matching lines.
        $albs = $this->getJson("/api/v1/admin/reports/explorer/orders?period=this_month&by=category&key={$this->albs}")->assertOk()->json();
        $this->assertSame(19_680.0, round(array_sum(array_column($albs['rows'], 'amount')), 2));

        $card = $this->getJson('/api/v1/admin/reports/explorer/orders?period=this_month&by=method&key=card')->assertOk()->json();
        $this->assertSame('payment', $card['rows'][0]['kind']);
        $this->assertSame(12_800.0, round((float) $card['rows'][0]['amount'], 2));
    }
}
