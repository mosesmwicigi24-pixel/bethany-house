<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The same question, asked of two different families, must get the same answer.
 *
 * Reports has two lineages: the MetricEngine endpoints (executive,
 * intelligence) and the legacy controllers (sales, customers, inventory,
 * financial). They compute independently. Cycle 1 verified ONE pair — legacy
 * sales revenue against an independent calculation — and noted that the risk
 * was drift rather than disagreement. Cycles 2 to 4 then changed money
 * arithmetic in both lineages, which is exactly when drift happens.
 *
 * These tests are the reconciliation the brief asks for in its first pass, and
 * they are written as identities so they keep working as the data changes:
 *
 *   - a window's revenue is one number, whichever family is asked
 *   - what customers owe is one number
 *   - the two inventory valuations differ ONLY by reserved stock, because one
 *     values what is available and the other what is on hand — a documented
 *     difference, and the only permitted one
 *
 * Every scenario carries foreign currency, because that is where the two
 * lineages most recently diverged.
 */
class CrossFamilyReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const WINDOW = 'start_date=2020-01-01&end_date=2030-12-31&from=2020-01-01&to=2030-12-31&period=custom';

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['KES', 1.0, true], ['USD', 128.0, false], ['ZMW', 6.5, false]] as [$c, $r, $base]) {
            DB::table('currencies')->updateOrInsert(['code' => $c], [
                'name' => $c, 'symbol' => $c, 'exchange_rate' => 1.0,
                'reporting_rate_to_kes' => $r, 'is_base' => $base, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        \App\Support\ReportingCurrency::forget();

        $this->outlet = Outlet::factory()->create(['name' => 'Sonalux']);

        $staff = User::factory()->create();
        foreach (['reports.view', 'reports.financial', 'customers.view', 'customers.insights'] as $p) {
            $staff->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($staff);
    }

    private function order(string $currency, float $total, string $status = 'completed', string $pay = 'paid'): Order
    {
        return Order::create([
            'order_number'   => 'XF-' . bin2hex(random_bytes(4)),
            'outlet_id'      => $this->outlet->id,
            'status'         => $status,
            'payment_status' => $pay,
            'currency_code'  => $currency,
            'subtotal'       => $total,
            'total_amount'   => $total,
        ]);
    }

    private function report(string $path): array
    {
        $join = str_contains($path, '?') ? '&' : '?';

        return $this->getJson($path . $join . self::WINDOW)->assertOk()->json();
    }

    public function test_a_windows_revenue_is_one_number_in_both_families(): void
    {
        $this->order('KES', 4_000);
        $this->order('USD', 25);          // 3,200
        $this->order('ZMW', 200);         // 1,300
        // Recognised but unpaid — income either way, and both families must agree
        // it is income.
        $this->order('KES', 1_500, 'confirmed', 'pending');
        // An abandoned cart is income to nobody.
        $this->order('KES', 90_000, 'pending', 'pending');

        $legacy = (float) $this->report('/api/v1/admin/reports/sales/summary')['summary']['total_revenue'];
        $engine = (float) $this->report('/api/v1/admin/reports/executive')['kpis']['sales']['revenue']['current'];

        $this->assertSame(10_000.0, round($legacy, 2), '4,000 + 3,200 + 1,300 + 1,500');
        $this->assertSame(round($legacy, 2), round($engine, 2),
            'the sales page and the executive page describe one business');
    }

    public function test_what_customers_owe_is_one_number_in_both_families(): void
    {
        // A part-paid foreign order: the case where every unit mistake shows.
        $order = $this->order('USD', 100, 'confirmed', 'partial');
        Payment::create([
            'order_id' => $order->id, 'amount' => 40, 'currency_code' => 'USD',
            'status' => 'paid', 'payment_method' => 'cash', 'paid_at' => now(),
        ]);
        $this->order('KES', 2_000, 'confirmed', 'pending');

        // USD 60 still owed = 7,680, plus 2,000 = 9,680.
        $engine = (float) $this->report('/api/v1/admin/reports/executive')['kpis']['money']['outstanding']['amount'];
        $this->assertSame(9_680.0, round($engine, 2));

        // The ledger reports balance per CHANNEL, over the orders CREATED in the
        // window; the executive tile is point-in-time, over every open order
        // there is. Those are different questions, and comparing them is only
        // valid when the window covers every open order — which is why this
        // test asks 2020-2030. Wire that caveat into the test rather than
        // discovering it later as a phantom disagreement.
        $ledger = $this->report('/api/v1/admin/reports/sales/ledger');
        $legacyBalance = collect($ledger['channels'])->sum('balance');

        $this->assertSame(round($engine, 2), round((float) $legacyBalance, 2),
            'over a window that contains every open order, the ledger and the '
            . 'executive tile are the same receivable');
    }

    public function test_the_two_inventory_valuations_differ_only_by_reserved_stock(): void
    {
        // The legacy report values what is AVAILABLE; the engine values what is
        // ON HAND. That difference is deliberate and documented (cycle 1), and
        // it is the ONLY difference permitted between them: 10 on the shelf,
        // 4 promised to orders already placed, at 1,000 each.
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        ProductPrice::create(['product_id' => $product->id, 'product_variant_id' => null,
                              'currency_code' => 'KES', 'regular_price' => 1_000]);
        InventoryItem::create(['product_id' => $product->id, 'product_variant_id' => null,
                               'outlet_id' => $this->outlet->id, 'quantity_on_hand' => 10,
                               'quantity_reserved' => 4, 'reorder_point' => 0]);

        $grand = $this->report('/api/v1/admin/reports/inventory/valuation')['grand_totals'];

        $this->assertSame(6_000.0, round((float) $grand['total_retail_value'], 2), 'available');
        $this->assertSame(10_000.0, round((float) $grand['total_retail_value_on_hand'], 2), 'on hand');
        $this->assertSame(
            round((float) $grand['total_retail_value_on_hand'] - (float) $grand['total_retail_value'], 2),
            4_000.0,
            'the gap between the two bases is exactly the reserved stock, never anything else',
        );
    }

    public function test_the_buyer_count_is_one_number_wherever_it_appears(): void
    {
        $a = \App\Models\Customer::create(['first_name' => 'Ann', 'last_name' => 'K',
            'email' => 'ann@example.test', 'phone' => '0722000201']);
        $b = \App\Models\Customer::create(['first_name' => 'Ben', 'last_name' => 'M',
            'email' => 'ben@example.test', 'phone' => '0722000202']);

        foreach ([[$a, 'KES', 1_000], [$a, 'USD', 10], [$b, 'KES', 500]] as [$c, $cur, $amt]) {
            $o = $this->order($cur, $amt);
            $o->forceFill(['customer_id' => $c->id])->saveQuietly();
        }

        $summaryBuyers = (int) $this->report('/api/v1/admin/reports/customers/summary')['unique_buyers'];
        $byCustomer    = collect($this->report('/api/v1/admin/reports/sales/by-customer')['customers']);

        $this->assertSame(2, $summaryBuyers, 'two people, three orders');
        $this->assertSame($summaryBuyers, $byCustomer->count(),
            'the count on the summary and the rows on the list are the same people');

        // And the money attributed to them adds up to the same revenue.
        $this->assertSame(2_780.0, round((float) $byCustomer->sum('total_spent'), 2),
            '1,000 + 1,280 + 500');
    }
}
