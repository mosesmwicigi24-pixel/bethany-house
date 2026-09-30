<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Every money figure Reports emits is in ONE unit: shillings.
 *
 * Two defects found 2026-09-30, both of them arithmetic across units:
 *
 *  1. `OWED` subtracted a payment already CONVERTED to KES from a RAW foreign
 *     total. Production read outstanding as KES 434,220 where it was 482,092
 *     — understated 47,872 (11%) — and each non-KES row was out by the rate
 *     itself: a USD 4,360 order listed as "4,360" instead of 558,080.
 *  2. Every drill arm selected the raw amount while its headline converted,
 *     so the rows could not sum to the figure they opened from, and no row
 *     carried a currency: a USD 200 row and a KES 200 row were identical.
 *
 * 128 KES per USD throughout, chosen so a missed conversion is unmissable.
 */
class ReportMoneyCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const USD_RATE = 128.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rate('KES', 1.0, isBase: true);
        $this->rate('USD', self::USD_RATE);

        $staff = User::factory()->create();
        foreach (['reports.view', 'reports.financial'] as $p) {
            $staff->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($staff);
    }

    /** KES per one unit — the REPORTING rate, not the pricing one. */
    private function rate(string $code, float $kesPerUnit, bool $isBase = false): void
    {
        DB::table('currencies')->updateOrInsert(
            ['code' => $code],
            ['name' => $code, 'symbol' => $code, 'exchange_rate' => 1.0,
             'reporting_rate_to_kes' => $kesPerUnit, 'is_base' => $isBase,
             'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        );
        \App\Support\ReportingCurrency::forget();
    }

    private function order(string $currency, float $total, string $paymentStatus = 'pending'): Order
    {
        return Order::create([
            'order_number'   => 'WA-' . bin2hex(random_bytes(4)),
            'status'         => 'confirmed',
            'payment_status' => $paymentStatus,
            'currency_code'  => $currency,
            'subtotal'       => $total,
            'total_amount'   => $total,
        ]);
    }

    private function payment(Order $order, string $currency, float $amount): void
    {
        Payment::create([
            'order_id'       => $order->id,
            'amount'         => $amount,
            'currency_code'  => $currency,
            'status'         => 'paid',
            'payment_method' => 'cash',
            'paid_at'        => now(),
        ]);
    }

    private function executive(): array
    {
        return $this->getJson('/api/v1/admin/reports/executive?period=this_month')->assertOk()->json('kpis');
    }

    // ── What customers owe ───────────────────────────────────────────────────

    public function test_an_unpaid_foreign_order_is_owed_in_shillings(): void
    {
        $this->order('USD', 200);   // owed: 200 × 128 = 25,600

        $outstanding = $this->executive()['money']['outstanding'] ?? null;

        $this->assertNotNull($outstanding, 'the executive page reports outstanding');
        $this->assertSame(25_600.0, round((float) $outstanding['amount'], 2),
            'a USD 200 order owes KES 25,600, not "200"');
    }

    public function test_a_part_paid_foreign_order_owes_the_converted_remainder(): void
    {
        // USD 200 ordered, USD 50 paid. Owed is USD 150 = KES 19,200.
        $order = $this->order('USD', 200, 'partial');
        $this->payment($order, 'USD', 50);

        $this->assertSame(19_200.0, round((float) $this->executive()['money']['outstanding']['amount'], 2));
    }

    public function test_a_fully_paid_foreign_order_owes_nothing_rather_than_a_negative(): void
    {
        // The old expression made this 0 by accident (200 - 25,600 clamped at
        // zero), which is how the defect hid on fully-settled orders.
        $order = $this->order('USD', 200, 'partial');
        $this->payment($order, 'USD', 200);

        $this->assertSame(0.0, round((float) $this->executive()['money']['outstanding']['amount'], 2));
    }

    public function test_shilling_orders_are_unaffected(): void
    {
        $this->order('KES', 5_000);

        $this->assertSame(5_000.0, round((float) $this->executive()['money']['outstanding']['amount'], 2));
    }

    public function test_mixed_currencies_add_up_in_one_unit(): void
    {
        $this->order('KES', 5_000);   //  5,000
        $this->order('USD', 100);     // 12,800

        $this->assertSame(17_800.0, round((float) $this->executive()['money']['outstanding']['amount'], 2));
    }

    // ── Drill rows against the figure they opened from ───────────────────────

    private function drill(string $metric): array
    {
        return $this->getJson("/api/v1/admin/reports/drill/{$metric}?period=this_month")
            ->assertOk()->json();
    }

    public function test_revenue_drill_rows_sum_to_the_revenue_headline(): void
    {
        $this->order('KES', 5_000, 'paid');
        $this->order('USD', 100, 'paid');     // 12,800

        $headline = (float) $this->executive()['sales']['revenue']['current'];
        $rows     = collect($this->drill('revenue')['rows']);

        $this->assertSame(17_800.0, round($headline, 2));
        $this->assertSame(round($headline, 2), round($rows->sum('amount'), 2),
            'the rows must add up to the number they opened from');
    }

    public function test_a_drill_row_says_what_the_customer_was_actually_charged(): void
    {
        $this->order('USD', 100, 'paid');

        $row = collect($this->drill('revenue')['rows'])->first();

        $this->assertSame(12_800.0, round((float) $row['amount'], 2), 'shillings');
        $this->assertSame(100.0, round((float) $row['amount_original'], 2), 'as charged');
        $this->assertSame('USD', $row['currency']);
    }

    public function test_two_rows_of_the_same_number_in_different_currencies_are_distinguishable(): void
    {
        // The case that was invisible: both rows used to render as "200".
        $this->order('KES', 200, 'paid');
        $this->order('USD', 200, 'paid');

        $amounts = collect($this->drill('revenue')['rows'])->pluck('amount')
            ->map(fn ($a) => round((float) $a, 2))->sort()->values()->all();

        $this->assertSame([200.0, 25_600.0], $amounts);
    }

    public function test_collected_drill_rows_sum_to_the_collected_headline(): void
    {
        $order = $this->order('USD', 100, 'paid');
        $this->payment($order, 'USD', 100);

        $headline = (float) $this->executive()['money']['collected']['current'];
        $rows     = collect($this->drill('collected')['rows']);

        $this->assertSame(12_800.0, round($headline, 2));
        $this->assertSame(round($headline, 2), round($rows->sum('amount'), 2));
        $this->assertSame('USD', $rows->first()['currency']);
    }

    public function test_outstanding_drill_rows_sum_to_the_outstanding_headline(): void
    {
        $this->order('USD', 200);
        $this->order('KES', 3_000);

        $headline = (float) $this->executive()['money']['outstanding']['amount'];
        $rows     = collect($this->drill('outstanding')['rows']);

        $this->assertSame(28_600.0, round($headline, 2));
        $this->assertSame(round($headline, 2), round($rows->sum('amount'), 2));
    }

    public function test_a_row_with_no_money_says_so_instead_of_borrowing_another_unit(): void
    {
        \App\Models\Customer::create([
            'first_name' => 'New', 'last_name' => 'Buyer',
            'email' => 'new.buyer@example.test', 'phone' => '0712345678',
        ]);

        $row = collect($this->drill('new_customers')['rows'])->first();

        $this->assertNull($row['amount_original']);
        $this->assertNull($row['currency']);
    }
}
