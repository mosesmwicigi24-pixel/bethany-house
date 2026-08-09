<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Reporting\MetricEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Institutional accounts — churches/institutions identified heuristically
 * (name keywords + customer_type='business'), rolled up on the canonical
 * customer key: revenue/orders/quiet-days, buyer-turnover signal, risk
 * flagging, cohort cross-sell, and the attention-feed detector.
 */
class InstitutionalAccountsTest extends TestCase
{
    use RefreshDatabase;

    /** A named product with an English translation, like the real catalogue. */
    private function product(string $name): Product
    {
        $product = Product::factory()->create();
        DB::table('product_translations')->insert([
            'product_id' => $product->id, 'language_code' => 'en', 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $product;
    }

    /** A KES sales-truth order for $name, optionally carrying product lines. */
    private function order(string $name, float $amount, int $daysAgo, array $attrs = [], array $lines = []): Order
    {
        $when  = now()->subDays($daysAgo);
        $order = Order::factory()->create(array_merge([
            'order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => $amount, 'currency_code' => 'KES',
            'customer_first_name' => $name, 'customer_last_name' => '',
            'created_at' => $when,
        ], $attrs));

        foreach ($lines as [$product, $lineTotal]) {
            DB::table('order_items')->insert([
                'order_id' => $order->id, 'product_id' => $product->id,
                'sku' => "SKU-{$product->id}", 'product_name' => 'line snapshot',
                'quantity' => 1, 'unit_price' => $lineTotal, 'total_price' => $lineTotal,
                'created_at' => $when, 'updated_at' => $when,
            ]);
        }

        return $order;
    }

    private function reportsViewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function report(): array
    {
        Cache::flush(); // each test seeds its own world — never share the 10-min cache

        return MetricEngine::for(User::factory()->create())->institutionalAccounts();
    }

    public function test_keyword_heuristic_catches_churches_and_spares_individuals(): void
    {
        $this->order('PCEA ST.LUKE', 20000, 10, ['customer_phone' => '0722000111']);
        $this->order('KARURA COMMUNITY CHAPEL', 15000, 10, ['customer_phone' => '0722000222']);
        $this->order('Apostolic carmel', 9000, 10, ['customer_phone' => '0722000333']);
        $this->order('RGC RUIRU', 5000, 10, ['customer_phone' => '0722000444']);
        // Individuals — including boundary traps: "Jackson" contains ACK,
        // "Mosaic" contains AIC. Word-bounded acronyms must spare them.
        $this->order('John Mutegi', 50000, 10, ['customer_phone' => '0733000111']);
        $this->order('Peter Jackson', 50000, 10, ['customer_phone' => '0733000222']);
        $this->order('Mary Mosaic', 50000, 10, ['customer_phone' => '0733000333']);

        $report = $this->report();

        $names = array_column($report['accounts'], 'name');
        sort($names);
        $this->assertSame(
            ['Apostolic carmel', 'KARURA COMMUNITY CHAPEL', 'PCEA ST.LUKE', 'RGC RUIRU'],
            $names,
        );
        $this->assertSame(4, $report['summary']['institutions']);
    }

    public function test_business_customer_type_is_caught_without_keywords(): void
    {
        $biz = Customer::create([
            'first_name' => 'Tegemeo', 'last_name' => 'Supplies',
            'customer_type' => 'business',
        ]);
        $this->order('Tegemeo Supplies', 30000, 5, ['customer_id' => $biz->id, 'customer_phone' => '0722000111']);

        $person = Customer::create([
            'first_name' => 'John', 'last_name' => 'Mutegi',
            'customer_type' => 'individual',
        ]);
        $this->order('John Mutegi', 30000, 5, ['customer_id' => $person->id, 'customer_phone' => '0733000111']);

        $report = $this->report();

        $this->assertSame(1, $report['summary']['institutions']);
        $this->assertSame('Tegemeo Supplies', $report['accounts'][0]['name']);
    }

    public function test_rollup_math_share_and_buyer_turnover_signal(): void
    {
        $church = Customer::create([
            'first_name' => 'PCEA', 'last_name' => 'ST.LUKE', 'customer_type' => 'individual',
        ]);
        // Two orders under the same customer_id but DIFFERENT contact phones —
        // the buyer-turnover signal (the person ordering changed).
        $this->order('PCEA ST.LUKE', 40000, 90, ['customer_id' => $church->id, 'customer_phone' => '0722000111']);
        $this->order('PCEA ST.LUKE', 10000, 30, ['customer_id' => $church->id, 'customer_phone' => '0744000999']);
        // A single-contact chapel for contrast.
        $this->order('AIC KASINA', 25000, 20, ['customer_phone' => '0722000555']);
        // An individual whose revenue only enters the share denominator.
        $this->order('John Mutegi', 25000, 10, ['customer_phone' => '0733000111']);

        $report = $this->report();

        $this->assertSame(2, $report['summary']['institutions']);
        $this->assertEqualsWithDelta(75000.0, $report['summary']['revenue_365_total'], 0.01);
        // 75,000 institutional of 100,000 total sales truth.
        $this->assertEqualsWithDelta(75.0, $report['summary']['share_of_total_revenue_pct'], 0.1);

        // Ranked revenue desc: the 50k church before the 25k chapel.
        $this->assertSame(['PCEA ST.LUKE', 'AIC KASINA'], array_column($report['accounts'], 'name'));

        $pcea = $report['accounts'][0];
        $this->assertEqualsWithDelta(50000.0, $pcea['revenue_365'], 0.01);
        $this->assertSame(2, $pcea['orders_365']);
        $this->assertEqualsWithDelta(30, $pcea['days_quiet'], 1);
        $this->assertSame(2, $pcea['buyer_contacts'], 'two distinct phones = the buyer changed');

        $aic = $report['accounts'][1];
        $this->assertSame(1, $aic['buyer_contacts']);
    }

    public function test_cross_sell_excludes_owned_products_and_computes_adoption(): void
    {
        $cups   = $this->product('Prefilled Communion Cups');
        $wine   = $this->product('Altar Wine');
        $candle = $this->product('Altar Candle');

        // 4 institutions. Cups: bought by 3 of 4 (75%). Wine: 2 of 4 (50%).
        // Candle: only 1 buyer — below the 2-buyer popularity gate, never a
        // cross-sell suggestion.
        $this->order('PCEA ST.LUKE', 5000, 10, ['customer_phone' => '0722000111'],
            [[$cups, 3000], [$wine, 2000]]);
        $this->order('AIC KASINA', 4000, 10, ['customer_phone' => '0722000222'],
            [[$cups, 3000], [$candle, 1000]]);
        $this->order('RGC RUIRU', 3000, 10, ['customer_phone' => '0722000333'],
            [[$cups, 3000]]);
        $this->order('KARURA COMMUNITY CHAPEL', 8000, 10, ['customer_phone' => '0722000444'],
            [[$wine, 8000]]);

        $report = $this->report();
        $accounts = collect($report['accounts'])->keyBy('name');

        // Karura never bought cups — suggested at 3-of-4 = 75% adoption. It
        // owns wine, so wine must NOT be suggested to it.
        $karura = $accounts['KARURA COMMUNITY CHAPEL'];
        $this->assertSame(
            [['product_id' => $cups->id, 'name' => 'Prefilled Communion Cups', 'adoption_pct' => 75.0]],
            $karura['cross_sell'],
        );
        $this->assertSame(1, $karura['products_bought']);
        $this->assertSame('Altar Wine', $karura['top_product']);

        // RGC owns cups only → suggested wine at 50%; the one-buyer candle
        // never surfaces.
        $rgc = $accounts['RGC RUIRU'];
        $this->assertSame(
            [['product_id' => $wine->id, 'name' => 'Altar Wine', 'adoption_pct' => 50.0]],
            $rgc['cross_sell'],
        );

        // PCEA owns both popular products → nothing left to suggest.
        $this->assertSame([], $accounts['PCEA ST.LUKE']['cross_sell']);
        $this->assertSame('Prefilled Communion Cups', $accounts['PCEA ST.LUKE']['top_product']);
    }

    public function test_risk_flag_needs_both_long_quiet_and_real_money(): void
    {
        // Quiet 90 days with 20k → at risk.
        $this->order('PCEA ST.LUKE', 20000, 90, ['customer_phone' => '0722000111']);
        // Quiet 90 days but only 5k → not at risk (below the 10k floor).
        $this->order('AIC KASINA', 5000, 90, ['customer_phone' => '0722000222']);
        // 50k but ordered 10 days ago → not at risk (not quiet).
        $this->order('RGC RUIRU', 50000, 10, ['customer_phone' => '0722000333']);

        $report = $this->report();
        $accounts = collect($report['accounts'])->keyBy('name');

        $this->assertTrue($accounts['PCEA ST.LUKE']['risk']);
        $this->assertFalse($accounts['AIC KASINA']['risk']);
        $this->assertFalse($accounts['RGC RUIRU']['risk']);

        $this->assertSame(1, $report['summary']['at_risk_count']);
        $this->assertEqualsWithDelta(20000.0, $report['summary']['at_risk_value'], 0.01);
    }

    public function test_attention_flags_institution_risk_with_value_scaled_severity(): void
    {
        Cache::flush();
        // 60k quiet church → at_risk_value >= 50,000 → severity high.
        $this->order('PCEA ST.LUKE', 60000, 90, ['customer_phone' => '0722000111']);

        $items = collect(MetricEngine::for(User::factory()->create())->attention());
        $item  = $items->firstWhere('key', 'institution_risk');

        $this->assertNotNull($item);
        $this->assertSame('high', $item['severity']);
        $this->assertSame(1, $item['count']);
        $this->assertStringContainsString('PCEA ST.LUKE', $item['detail']);
        $this->assertStringContainsString('KES', $item['title']);
        $this->assertSame('/reports/customers?tab=institutions', $item['link']);
        $this->assertSame('PCEA ST.LUKE', $item['entities'][0]['name']);
        $this->assertSame(
            [['type' => 'navigate', 'label' => 'Open institutional accounts', 'to' => '/reports/customers?tab=institutions']],
            $item['actions'],
        );

        // Shrink the money below 50k → medium.
        Cache::flush();
        DB::table('orders')->update(['total_amount' => 20000]);
        $item = collect(MetricEngine::for(User::factory()->create())->attention())
            ->firstWhere('key', 'institution_risk');
        $this->assertSame('medium', $item['severity']);

        // No quiet institutions → the detector stays silent.
        Cache::flush();
        DB::table('orders')->update(['created_at' => now()->subDays(5)]);
        $this->assertNull(
            collect(MetricEngine::for(User::factory()->create())->attention())
                ->firstWhere('key', 'institution_risk'),
        );
    }

    public function test_endpoint_exposes_report_and_requires_reports_view(): void
    {
        $this->order('PCEA ST.LUKE', 20000, 10, ['customer_phone' => '0722000111']);

        // No permission → 403.
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/reports/institutions')->assertForbidden();

        // reports.view → the full payload.
        Cache::flush();
        $this->reportsViewer();
        $res = $this->getJson('/api/v1/admin/reports/institutions')->assertOk()->json();
        $this->assertSame(1, $res['summary']['institutions']);
        $this->assertSame('PCEA ST.LUKE', $res['accounts'][0]['name']);
    }
}
