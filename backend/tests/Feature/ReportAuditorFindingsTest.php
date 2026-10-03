<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\CurrencyPricing;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cycle 9 — the hostile auditor. Each test is a figure an auditor rebuilt from
 * the rows on production (2026-10-01, read-only) and found the page stating
 * something the rows do not support.
 */
class ReportAuditorFindingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['KES', 1, 1, true], ['USD', 0.01, 128, false]] as [$code, $fx, $rate, $base]) {
            DB::table('currencies')->updateOrInsert(['code' => $code], [
                'name' => $code, 'symbol' => $code, 'exchange_rate' => $fx,
                'reporting_rate_to_kes' => $rate, 'is_base' => $base, 'is_active' => true,
            ]);
        }
        ReportingCurrency::forget();
        CurrencyPricing::forget();

        $staff = User::factory()->create();
        foreach ([...\Tests\ReportAccess::PAGES, 'reports.financial'] as $p) {
            $staff->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($staff);
    }

    private function order(array $attrs): Order
    {
        static $n = 0;

        return Order::create($attrs + [
            'order_number' => 'AUD-' . (++$n), 'order_type' => 'pos', 'status' => 'confirmed',
            'currency_code' => 'KES',
        ] + ['subtotal' => $attrs['total_amount']]);
    }

    private function pay(Order $o, float $amount, string $method, ?string $currency = null): void
    {
        Payment::create([
            'order_id' => $o->id, 'amount' => $amount, 'currency_code' => $currency ?? $o->currency_code,
            'payment_method' => $method, 'status' => 'paid', 'paid_at' => now(),
        ]);
    }

    /**
     * Production: order 830, USD 450 (57,600 KES) with 21,120 KES paid, was
     * counted as EARNED in September. earnedPnl compared money already in
     * SHILLINGS (21,120) against a total in DOLLARS (450): 21,120 ≥ 450.
     * The mixed-units class cycle 2 fixed in owed(), surviving in a sibling.
     */
    public function test_a_part_paid_dollar_order_is_not_earned(): void
    {
        $part = $this->order(['currency_code' => 'USD', 'total_amount' => 450, 'payment_status' => 'partial']);
        $this->pay($part, 165, 'mukuru');               // 165 × 128 = 21,120 KES of 57,600

        $full = $this->order(['currency_code' => 'USD', 'total_amount' => 100, 'payment_status' => 'paid']);
        $this->pay($full, 100, 'mukuru');               // genuinely settled: 12,800 KES

        $today = now()->format('Y-m-d');
        $pnl = $this->getJson("/api/v1/admin/reports/financial-intelligence?period=custom&from={$today}&to={$today}")
            ->assertOk()->json('pnl');

        $this->assertSame(1, (int) $pnl['earned_orders'], 'only the settled dollar order is earned');
        $this->assertSame(12_800.0, (float) $pnl['earned_revenue'], 'not 12,800 + 57,600');
    }

    /**
     * Production, September: "Payment Methods" summed to 1,523,450 across 157
     * orders — neither revenue (2,118,350) nor collected (1,942,320). It
     * grouped FULLY PAID orders by the order's single payment_method and summed
     * their totals, so a part payment counted nowhere and a split tender
     * (paybill + cash on one order) went wholly to one rail. A breakdown by
     * method is a breakdown of money received; it must foot to Collected.
     */
    public function test_payment_methods_foot_to_collected_and_split_a_split_tender(): void
    {
        $split = $this->order(['total_amount' => 1_000, 'payment_status' => 'paid', 'payment_method' => 'cash']);
        $this->pay($split, 500, 'cash');
        $this->pay($split, 500, 'inmpaybill');

        $part = $this->order(['total_amount' => 2_000, 'payment_status' => 'partial', 'payment_method' => 'cash']);
        $this->pay($part, 800, 'cash');

        $today = now()->format('Y-m-d');
        $q = "start_date={$today}&end_date={$today}";

        $collected = (float) $this->getJson("/api/v1/admin/reports/sales/summary?{$q}")->assertOk()
            ->json('summary.total_collected');
        $methods = collect($this->getJson("/api/v1/admin/reports/sales/by-payment-method?{$q}")->assertOk()
            ->json('payment_methods'))->mapWithKeys(fn ($m) => [$m['payment_method'] => (float) $m['total']]);

        $this->assertSame(1_800.0, $collected);
        $this->assertSame($collected, (float) $methods->sum(), 'the methods add up to the money received');
        $this->assertSame(1_300.0, $methods['cash'] ?? null, '500 of the split + the 800 part payment');
        $this->assertSame(500.0, $methods['inmpaybill'] ?? null, 'the other half of the split');
    }
}
