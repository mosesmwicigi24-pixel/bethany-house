<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\User;
use App\Services\CurrencyPricing;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Currency plan Step 3 (owner, 2026-10-18): the reporting rate stays with
 * the money. A rate change approved today never rewrites last month.
 *
 * Figures by hand. USD reporting 128 → 140 after a change; a USD 100 order
 * made under 128 is KES 12,800 for ever, one made under 140 is KES 14,000.
 */
class ReportingRateSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('currencies')->updateOrInsert(['code' => 'KES'], ['name' => 'Kenyan Shilling', 'symbol' => 'KES',
            'exchange_rate' => 1, 'reporting_rate_to_kes' => 1, 'is_base' => true, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$',
            'exchange_rate' => 0.01, 'reporting_rate_to_kes' => 128, 'is_base' => false, 'is_active' => true]);
        DB::table('currencies')->updateOrInsert(['code' => 'GBP'], ['name' => 'Pound', 'symbol' => '£',
            'exchange_rate' => 0.0065, 'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true]);
        ReportingCurrency::forget();
        CurrencyPricing::forget();
    }

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        foreach ([...\Tests\ReportAccess::PAGES, 'reports.financial'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        \Tests\ReportAccess::asOwner($user);

        return $user;
    }

    /** The live USD rate changes — written the way the approval flow's handler writes it. */
    private function changeUsdRate(float $to): void
    {
        DB::table('currencies')->where('code', 'USD')->update(['reporting_rate_to_kes' => $to, 'updated_at' => now()]);
        ReportingCurrency::forget();
    }

    private function summaryRevenue(): float
    {
        $res = $this->actingAs($this->viewer(), 'sanctum')
            ->getJson('/api/v1/admin/reports/sales/summary?start=' . now()->subDays(2)->toDateString() . '&end=' . now()->addDay()->toDateString())
            ->assertOk();

        return (float) $res->json('summary.total_revenue');
    }

    // ── the stamp ────────────────────────────────────────────────────────────

    public function test_every_money_record_carries_the_rate_in_force_when_it_is_made(): void
    {
        $usd = Order::factory()->create(['currency_code' => 'USD', 'total_amount' => 100, 'status' => 'confirmed', 'payment_status' => 'paid']);
        $kes = Order::factory()->create(['currency_code' => 'KES', 'total_amount' => 1000, 'status' => 'confirmed', 'payment_status' => 'paid']);
        $gbp = Order::factory()->create(['currency_code' => 'GBP', 'total_amount' => 50, 'status' => 'confirmed', 'payment_status' => 'paid']);
        $pay = Payment::factory()->create(['order_id' => $usd->id, 'currency_code' => 'USD', 'amount' => 100]);
        $quote = Quotation::create(['quote_number' => 'Q-1', 'currency_code' => 'USD', 'status' => 'draft', 'subtotal' => 100, 'total_amount' => 100, 'customer_first_name' => 'A']);
        $supplierId = DB::table('suppliers')->insertGetId(['code' => 'SUP-1', 'name' => 'Cloth Ltd', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $po = PurchaseOrder::create(['supplier_id' => $supplierId, 'currency_code' => 'USD', 'status' => 'draft', 'subtotal' => 100, 'total_amount' => 100, 'order_date' => today()]);

        $this->assertEquals(128, $usd->fresh()->reporting_rate_to_kes);
        $this->assertSame('current', $usd->fresh()->reporting_rate_source);
        $this->assertEquals(1, $kes->fresh()->reporting_rate_to_kes);
        $this->assertSame('kes', $kes->fresh()->reporting_rate_source);
        $this->assertNull($gbp->fresh()->reporting_rate_to_kes, 'no rate: nothing is guessed');
        $this->assertSame('none', $gbp->fresh()->reporting_rate_source);
        $this->assertEquals(128, $pay->fresh()->reporting_rate_to_kes);
        $this->assertEquals(128, $quote->fresh()->reporting_rate_to_kes);
        $this->assertEquals(128, $po->fresh()->reporting_rate_to_kes);
    }

    public function test_a_rate_change_reaches_new_records_and_leaves_old_ones_as_they_were(): void
    {
        $old = Order::factory()->create(['currency_code' => 'USD', 'total_amount' => 100, 'status' => 'confirmed', 'payment_status' => 'paid']);
        $this->assertSame(12800.0, $this->summaryRevenue(), '100 × 128');

        $this->changeUsdRate(140);
        $new = Order::factory()->create(['currency_code' => 'USD', 'total_amount' => 100, 'status' => 'confirmed', 'payment_status' => 'paid']);

        $this->assertEquals(128, $old->fresh()->reporting_rate_to_kes, 'the old order keeps its rate');
        $this->assertEquals(140, $new->fresh()->reporting_rate_to_kes);
        // 12,800 + 14,000 — not 28,000, which is what today's rate on both would say.
        $this->assertSame(26800.0, $this->summaryRevenue());

        // The engine agrees with the controller, and the old basis is still readable for the comparison.
        $engine = \App\Services\Reporting\MetricEngine::unscoped();
        $s = now()->subDays(2)->startOfDay(); $e = now()->addDay()->endOfDay();
        $this->assertSame(26800.0, (float) $engine->revenue($s, $e, $s->copy()->subDays(4), $s->copy()->subSecond())['current']);
        ReportingCurrency::useBasis('current');
        $this->assertSame(28000.0, (float) $engine->revenue($s, $e, $s->copy()->subDays(4), $s->copy()->subSecond())['current'], 'current basis: both at 140');
        ReportingCurrency::useBasis('stored');
    }

    public function test_a_currency_change_on_an_order_restamps_it(): void
    {
        $order = Order::factory()->create(['currency_code' => 'KES', 'total_amount' => 1000, 'status' => 'pending', 'payment_status' => 'pending']);
        $this->assertEquals(1, $order->reporting_rate_to_kes);
        $order->update(['currency_code' => 'USD']);
        $this->assertEquals(128, $order->fresh()->reporting_rate_to_kes);
        $this->assertSame('current', $order->fresh()->reporting_rate_source);
    }

    public function test_a_row_with_no_rate_at_the_time_reads_at_the_live_rate_once_one_exists(): void
    {
        $gbp = Order::factory()->create(['currency_code' => 'GBP', 'total_amount' => 50, 'status' => 'confirmed', 'payment_status' => 'paid']);
        $this->assertSame(0.0, $this->summaryRevenue(), 'no rate: reported apart, not in KES');

        DB::table('currencies')->where('code', 'GBP')->update(['reporting_rate_to_kes' => 160]);
        ReportingCurrency::forget();
        $this->assertSame(8000.0, $this->summaryRevenue(), '50 × 160 — the row still says its source is none');
        $this->assertSame('none', $gbp->fresh()->reporting_rate_source);
    }

    // ── the backfill ─────────────────────────────────────────────────────────

    public function test_existing_rows_are_stamped_from_the_rate_history(): void
    {
        $usdId = DB::table('currencies')->where('code', 'USD')->value('id');
        // Rows from before the migration: made with no stamp at all.
        $mk = function (string $code, string $at) {
            $o = Order::factory()->create(['currency_code' => $code, 'total_amount' => 100, 'status' => 'confirmed', 'payment_status' => 'paid', 'created_at' => Carbon::parse($at)]);
            DB::table('orders')->where('id', $o->id)->update(['reporting_rate_to_kes' => null, 'reporting_rate_source' => null, 'created_at' => Carbon::parse($at)]);

            return $o->id;
        };
        $before  = $mk('USD', '2026-07-01 10:00:00');   // before any reporting rate existed
        $initial = $mk('USD', '2026-09-01 10:00:00');   // under the 20 Aug rate (128)
        $changed = $mk('USD', '2026-10-10 10:00:00');   // after an approved change to 135 on 5 Oct
        $kes     = $mk('KES', '2026-09-01 10:00:00');
        $gbp     = $mk('GBP', '2026-09-01 10:00:00');

        DB::table('change_proposals')->insert([
            'event' => 'reporting_fx_change', 'subject_type' => 'currency', 'subject_id' => $usdId, 'subject_label' => 'USD',
            'changeset' => json_encode(['reporting_rate_to_kes' => ['old' => 128, 'new' => 135]]),
            'effective_from' => '2026-10-05 00:00:00', 'applied_at' => '2026-10-04 12:00:00', 'status' => 'applied',
            'created_at' => '2026-10-03 09:00:00', 'updated_at' => '2026-10-04 12:00:00',
        ]);

        // Run the backfill again: it only touches unstamped rows.
        $m = require database_path('migrations/2026_10_18_210000_reporting_rate_snapshots.php');
        $m->up();

        $row = fn (int $id) => DB::table('orders')->where('id', $id)->first(['reporting_rate_to_kes', 'reporting_rate_source']);
        $this->assertEquals(128, $row($before)->reporting_rate_to_kes);
        $this->assertSame('earliest_known', $row($before)->reporting_rate_source);
        $this->assertEquals(128, $row($initial)->reporting_rate_to_kes);
        $this->assertSame('initial', $row($initial)->reporting_rate_source);
        $this->assertEquals(135, $row($changed)->reporting_rate_to_kes);
        $this->assertSame('history', $row($changed)->reporting_rate_source);
        $this->assertEquals(1, $row($kes)->reporting_rate_to_kes);
        $this->assertSame('kes', $row($kes)->reporting_rate_source);
        $this->assertNull($row($gbp)->reporting_rate_to_kes);
        $this->assertSame('none', $row($gbp)->reporting_rate_source);
    }

    // ── the comparison ───────────────────────────────────────────────────────

    public function test_the_comparison_command_shows_what_moved_between_the_bases(): void
    {
        Order::factory()->create(['currency_code' => 'USD', 'total_amount' => 100, 'status' => 'confirmed', 'payment_status' => 'paid']);
        $this->changeUsdRate(140);

        // Artisan::output(): the table is written through the output style,
        // which the pending-command expectations do not see.
        $code = \Illuminate\Support\Facades\Artisan::call('reports:fx-basis-compare', ['--from' => now()->subDays(2)->toDateString(), '--to' => now()->addDay()->toDateString()]);
        $out  = \Illuminate\Support\Facades\Artisan::output();
        $this->assertSame(0, $code);
        foreach (['revenue (sales truth)', '12,800.00', '14,000.00', '-1,200.00', '| orders | USD      | current | 1'] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
        $this->assertStringContainsString('1 figure(s) differ', $out);
    }
}
