<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The four money definitions the owner delegated on 2026-10-02, each pinned
 * where every page that states it must agree:
 *   1. Collected = paid, and approved where approval applies (SettledPayment)
 *   2. A line's cost = its snapshot, else the price book, skipping empty rows (CostBasis)
 *   3. Cash in = Collected, month by month
 *   4. Tax on the Sold basis, each line under one rate
 */
class ReportMoneyDefinitionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('currencies')->updateOrInsert(['code' => 'KES'], [
            'name' => 'KES', 'symbol' => 'KES', 'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true,
        ]);
        \App\Support\ReportingCurrency::forget();

        $u = User::factory()->create();
        foreach ([...\Tests\ReportAccess::PAGES, 'reports.financial'] as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);
    }

    private function order(float $total, array $extra = []): Order
    {
        return Order::create($extra + [
            'order_number' => 'MD-' . uniqid(), 'order_type' => 'pos', 'status' => 'completed', 'payment_status' => 'paid',
            'currency_code' => 'KES', 'subtotal' => $total, 'total_amount' => $total, 'customer_phone' => '07' . random_int(10000000, 99999999),
        ]);
    }

    private function line(Order $o, Product $p, float $price, ?int $variantId = null, ?float $tax = null): void
    {
        DB::table('order_items')->insert(['order_id' => $o->id, 'product_id' => $p->id, 'product_variant_id' => $variantId,
            'sku' => 'MD', 'product_name' => 'Line', 'quantity' => 1, 'unit_price' => $price, 'total_price' => $price,
            'tax_amount' => $tax ?? 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Collected as each page states it, this month. */
    private function collectedEverywhere(): array
    {
        $t = now()->format('Y-m-d');
        $q = "start_date={$t}&end_date={$t}";

        return [
            'executive tile' => (float) $this->getJson('/api/v1/admin/reports/executive?period=this_month')->json('kpis.money.collected.current'),
            'sales summary'  => (float) $this->getJson("/api/v1/admin/reports/sales/summary?{$q}")->json('summary.total_collected'),
            'revenue page'   => (float) $this->getJson("/api/v1/admin/reports/financial/revenue?{$q}")->json('collected.total'),
            'cash in'        => (float) collect($this->getJson("/api/v1/admin/reports/financial/cash-flow?{$q}")->json('inflows'))->sum('inflow'),
            'explorer'       => (float) $this->getJson('/api/v1/admin/reports/explorer?period=this_month&by=month')->json('totals.collected'),
        ];
    }

    public function test_a_payment_back_under_review_is_not_collected_anywhere_until_approved(): void
    {
        $o = $this->order(5_000);
        Payment::create(['order_id' => $o->id, 'amount' => 5_000, 'currency_code' => 'KES', 'payment_method' => 'cash',
            'status' => 'paid', 'paid_at' => now()]);
        // PaymentApprovalController::uploadProof leaves the payment at 'paid'
        // while it goes back to review.
        $held = $this->order(3_000, ['payment_status' => 'pending']);
        $p = Payment::create(['order_id' => $held->id, 'amount' => 3_000, 'currency_code' => 'KES', 'payment_method' => 'bank_transfer',
            'status' => 'paid', 'paid_at' => now(), 'requires_approval' => true, 'approval_status' => 'pending_review']);

        foreach ($this->collectedEverywhere() as $page => $v) {
            $this->assertSame(5_000.0, $v, "{$page}: the payment under review is not collected");
        }

        $p->update(['approval_status' => 'approved']);
        foreach ($this->collectedEverywhere() as $page => $v) {
            $this->assertSame(8_000.0, $v, "{$page}: approved, it counts");
        }
    }

    public function test_cash_in_is_collected_by_payment_date_and_net_of_refunds(): void
    {
        $o = $this->order(10_000);
        // Recorded last month, settled this month, 1,500 refunded.
        $p = Payment::create(['order_id' => $o->id, 'amount' => 10_000, 'refund_amount' => 1_500, 'currency_code' => 'KES',
            'payment_method' => 'mpesa', 'status' => 'paid', 'paid_at' => now()]);
        DB::table('payments')->where('id', $p->id)->update(['created_at' => now()->subMonthNoOverflow()->startOfMonth()]);

        $all = $this->collectedEverywhere();
        $this->assertSame(8_500.0, $all['cash in'], 'this month, net of the refund');
        $this->assertSame($all['executive tile'], $all['cash in'], 'a month\'s cash in IS its Collected');
    }

    public function test_both_profit_and_loss_statements_cost_a_line_the_same_way(): void
    {
        // A variant price row with no cost must not hide the product's own cost.
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        DB::table('product_prices')->insert([
            ['product_id' => $product->id, 'product_variant_id' => null, 'currency_code' => 'KES', 'regular_price' => 4000,
                'cost_price' => 1500, 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'currency_code' => 'KES', 'regular_price' => 4000,
                'cost_price' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $o = $this->order(4_000);
        $this->line($o, $product, 4_000, $variant->id);
        Payment::create(['order_id' => $o->id, 'amount' => 4_000, 'currency_code' => 'KES', 'payment_method' => 'cash',
            'status' => 'paid', 'paid_at' => now()]);

        $t = now()->format('Y-m-d');
        $classic = $this->getJson("/api/v1/admin/reports/financial/profit-loss?start_date={$t}&end_date={$t}")->assertOk()->json();
        $earned  = $this->getJson('/api/v1/admin/reports/financial-intelligence?period=this_month')->assertOk()->json('pnl');
        $dq      = collect($this->getJson('/api/v1/admin/reports/data-quality?period=this_month')->json('checks'))->firstWhere('key', 'uncosted_lines');

        $this->assertSame(1500.0, (float) $classic['cost_of_goods_sold']);
        $this->assertSame(1500.0, (float) $earned['cogs_estimate'], 'the earned P&L finds the product cost too');
        $this->assertSame(0, (int) $earned['unpriced_lines']);
        $this->assertSame(0, (int) $classic['unpriced_lines']);
        $this->assertSame(0, $dq['count']);
    }

    public function test_tax_is_on_the_sold_basis_and_each_line_counts_once(): void
    {
        $vat   = DB::table('tax_rates')->insertGetId(['name' => 'VAT', 'rate' => 16, 'created_at' => now(), 'updated_at' => now()]);
        $none  = DB::table('tax_rates')->insertGetId(['name' => 'No Tax', 'rate' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $vatOnly  = Product::factory()->create();
        $confused = Product::factory()->create();   // tagged both — product 108's shape
        DB::table('product_tax_rates')->insert([
            ['product_id' => $vatOnly->id, 'tax_rate_id' => $vat],
            ['product_id' => $confused->id, 'tax_rate_id' => $vat],
            ['product_id' => $confused->id, 'tax_rate_id' => $none],
        ]);

        $paid = $this->order(1_000);
        $this->line($paid, $vatOnly, 1_000, null, 160);
        // Confirmed, not yet paid: a sale, so taxable.
        $confirmed = $this->order(2_000, ['status' => 'confirmed', 'payment_status' => 'pending']);
        $this->line($confirmed, $confused, 2_000, null, 320);
        // A cart is not a sale.
        $cart = $this->order(9_000, ['status' => 'pending', 'payment_status' => 'pending']);
        $this->line($cart, $vatOnly, 9_000, null, 1_440);

        $t = now()->format('Y-m-d');
        $r = $this->getJson("/api/v1/admin/reports/financial/tax?start_date={$t}&end_date={$t}")->assertOk()->json();

        $this->assertSame(3_000.0, (float) $r['totals']['total_taxable'], '1,000 paid + 2,000 confirmed; the cart is not a sale; no line twice');
        $this->assertSame(480.0, (float) $r['totals']['total_tax']);
        $this->assertSame(['VAT'], array_column($r['by_tax_rate'], 'tax_name'), 'the conflicted product reads at its higher rate');
        $this->assertSame([$confused->id], $r['conflicting_rate_products']);
        $this->assertSame('sold', $r['basis']);
    }
}
