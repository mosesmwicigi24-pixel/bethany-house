<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The sales report's fidelity contract: every panel on the page must
 * reconcile to every other panel, because the owner reads them side by side
 * and a screen that disagrees with itself teaches people to trust nothing
 * on it. Each test here pins one reconciliation the 2026-08 audit found
 * broken in production.
 */
class SalesReportFidelityTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));

        return $user;
    }

    private function summary(): array
    {
        return $this->actingAs($this->viewer(), 'sanctum')
            ->getJson('/api/v1/admin/reports/sales/summary?start='
                . now()->subDay()->toDateString() . '&end=' . now()->addDay()->toDateString())
            ->assertOk()->json();
    }

    public function test_the_four_bucket_tiles_sum_to_total_revenue(): void
    {
        // One order per bucket, plus a USD chat order at 128 — the case the
        // old Online/POS tile pair failed: chat belonged to neither tile and
        // the page showed 245 orders against a 248 total.
        Order::factory()->create(['sales_bucket' => 'till',   'status' => 'confirmed', 'payment_status' => 'paid', 'currency_code' => 'KES', 'total_amount' => 1000]);
        Order::factory()->create(['sales_bucket' => 'web',    'status' => 'confirmed', 'payment_status' => 'paid', 'currency_code' => 'KES', 'total_amount' => 2000]);
        Order::factory()->create(['sales_bucket' => 'chat',   'status' => 'confirmed', 'payment_status' => 'paid', 'currency_code' => 'USD', 'total_amount' => 10]);
        Order::factory()->create(['sales_bucket' => 'quoted', 'status' => 'confirmed', 'payment_status' => 'paid', 'currency_code' => 'KES', 'total_amount' => 4000]);

        $s = $this->summary()['summary'];

        $tiles = $s['till_revenue'] + $s['web_revenue'] + $s['chat_revenue'] + $s['quoted_revenue'];
        $this->assertSame((float) $s['total_revenue'], (float) $tiles,
            'the four tiles must cover the whole order book');
        $this->assertSame(8280.0, (float) $s['total_revenue'], '1000+2000+(10x128)+4000');
        $this->assertSame(1280.0, (float) $s['chat_revenue']);
        $this->assertSame(4, $s['till_count'] + $s['web_count'] + $s['chat_count'] + $s['quoted_count']);
    }

    public function test_a_null_bucket_order_still_lands_in_a_tile(): void
    {
        Order::factory()->create(['sales_bucket' => null, 'order_type' => 'whatsapp',
            'status' => 'confirmed', 'payment_status' => 'paid', 'currency_code' => 'KES', 'total_amount' => 700]);

        $s = $this->summary()['summary'];

        $this->assertSame(700.0, (float) $s['chat_revenue'],
            'the tile derivation must match the scope fallback');
    }

    public function test_payment_methods_foot_to_the_collected_tile(): void
    {
        // A KES rail and a USD rail. The methods panel used to hard-filter
        // KES while Collected converted — an unexplainable gap on screen.
        foreach ([['KES', 1000, 'cash'], ['USD', 10, 'card']] as [$ccy, $amount, $method]) {
            $order = Order::factory()->create([
                'status' => 'completed', 'payment_status' => 'paid',
                'currency_code' => $ccy, 'total_amount' => $amount,
            ]);
            DB::table('payments')->insert([
                'order_id' => $order->id, 'payment_number' => 'PAY-' . $ccy,
                'amount' => $amount, 'currency_code' => $ccy,
                'payment_method' => $method, 'status' => 'paid',
                'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $body = $this->summary();
        $methodsTotal = collect($body['by_payment_method'])->sum('total');

        $this->assertSame((float) $body['summary']['total_collected'], (float) $methodsTotal,
            'every shilling on the Collected tile must be attributable to a rail');
        $this->assertSame(2280.0, (float) $methodsTotal, '1000 + 10x128');
    }

    public function test_the_exclusion_note_only_names_money_that_is_actually_excluded(): void
    {
        DB::table('currencies')->updateOrInsert(
            ['code' => 'GBP'],
            ['name' => 'Pound', 'symbol' => '£', 'exchange_rate' => 1,
             'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true],
        );
        \App\Support\ReportingCurrency::forget();

        Order::factory()->create(['status' => 'confirmed', 'payment_status' => 'paid',
            'currency_code' => 'USD', 'total_amount' => 10]);   // convertible — IN totals
        Order::factory()->create(['status' => 'confirmed', 'payment_status' => 'paid',
            'currency_code' => 'GBP', 'total_amount' => 80]);   // rate-less — OUT of totals

        $body = $this->summary();

        $excluded  = collect($body['excluded_currencies'])->pluck('currency_code')->all();
        $converted = collect($body['converted_currencies'])->keyBy('currency_code');

        $this->assertSame(['GBP'], $excluded,
            'USD is IN the totals at 128 — calling it excluded contradicted the tiles');
        $this->assertSame(128.0, (float) $converted['USD']['rate']);
        $this->assertSame(1280.0, (float) $converted['USD']['total_kes']);
        $this->assertSame(1280.0, (float) $body['summary']['total_revenue'],
            'GBP stays out, USD comes in — and the note now agrees with the number');
    }

    public function test_the_outlet_table_reconciles_to_total_revenue(): void
    {
        $outlet = Outlet::factory()->create(['name' => 'Sonalux']);

        Order::factory()->create(['outlet_id' => $outlet->id, 'status' => 'confirmed',
            'payment_status' => 'paid', 'currency_code' => 'KES', 'total_amount' => 1000]);
        // The two shapes the old query dropped:
        Order::factory()->create(['outlet_id' => null, 'status' => 'confirmed',
            'payment_status' => 'paid', 'currency_code' => 'KES', 'total_amount' => 5000]);      // no outlet
        Order::factory()->create(['outlet_id' => $outlet->id, 'status' => 'confirmed',
            'payment_status' => 'deposit', 'currency_code' => 'KES', 'total_amount' => 3000]);   // part-paid

        $summaryTotal = (float) $this->summary()['summary']['total_revenue'];

        $rows = $this->actingAs($this->viewer(), 'sanctum')
            ->getJson('/api/v1/admin/reports/sales/by-outlet?start='
                . now()->subDay()->toDateString() . '&end=' . now()->addDay()->toDateString())
            ->assertOk()->json('outlets');

        $tableTotal = collect($rows)->sum('total_revenue');

        $this->assertSame($summaryTotal, (float) $tableTotal,
            'the outlet table must account for every shilling the tiles report');
        $this->assertSame(9000.0, (float) $tableTotal);

        $noOutlet = collect($rows)->firstWhere('outlet_name', 'No outlet (sales desk)');
        $this->assertNotNull($noOutlet, 'sales-desk orders need a visible home');
        $this->assertSame(5000.0, (float) $noOutlet['total_revenue']);
    }
}
