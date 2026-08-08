<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\FreezesClockMidMonth;
use Tests\TestCase;

/**
 * The sales ledger: what we sold, what we collected, what we are still owed —
 * per channel, per day / week / month.
 *
 * The invariant these tests exist to protect is
 *
 *     sales = cash + balance
 *
 * on EVERY row. It holds only because cash is attributed to the period the
 * ORDER falls in rather than the period the payment landed in. A future change
 * to bucket by payment date would silently break it — a row's cash could then
 * exceed its own sales and "balance" would stop meaning "still owed".
 */
class SalesLedgerTest extends TestCase
{
    use RefreshDatabase;
    use FreezesClockMidMonth;

    private function viewer(): User
    {
        $u = User::factory()->create(['user_type' => 'staff']);
        $u->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $u->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);

        return $u;
    }

    /** An order of $total with $paid settled against it. */
    private function order(string $type, float $total, float $paid = 0, ?int $outletId = null): Order
    {
        $o = Order::factory()->create([
            'order_type'     => $type,
            'outlet_id'      => $outletId,
            'total_amount'   => $total,
            'currency_code'  => 'KES',
            'status'         => 'confirmed',
            'created_at'     => now(),
        ]);
        if ($paid > 0) {
            Payment::factory()->create([
                'order_id'      => $o->id,
                'amount'        => $paid,
                'refund_amount' => 0,
                'status'        => 'paid',
            ]);
        }

        return $o;
    }

    private function ledger(): array
    {
        return $this->getJson('/api/v1/admin/reports/sales/ledger'
            .'?start_date='.now()->subDays(7)->format('Y-m-d')
            .'&end_date='.now()->format('Y-m-d'))
            ->assertOk()
            ->json();
    }

    public function test_each_channel_reports_its_own_sales_cash_and_balance(): void
    {
        $this->viewer();
        $waOutlet = Outlet::factory()->create(['sales_channel' => 'whatsapp']);

        $this->order('pos', 1000, 1000);          // fully paid
        $this->order('pos', 500, 200);            // part paid -> 300 owed
        $this->order('online', 800, 0);           // nothing paid -> 800 owed
        $this->order('whatsapp', 400, 400);       // tagged by type
        $this->order('pos', 600, 100, $waOutlet->id); // POS type BUT WhatsApp outlet

        $byChannel = collect($this->ledger()['channels'])->keyBy('channel');

        // The WhatsApp-outlet order belongs to WhatsApp, not POS — that is the
        // rule the three Sales pages use, and the report must agree.
        $this->assertSame(2, $byChannel['pos']['orders']);
        $this->assertSame(1500.0, $byChannel['pos']['sales']);
        $this->assertSame(1200.0, $byChannel['pos']['cash']);
        $this->assertSame(300.0,  $byChannel['pos']['balance']);

        $this->assertSame(1, $byChannel['online']['orders']);
        $this->assertSame(800.0, $byChannel['online']['balance']);

        $this->assertSame(2, $byChannel['whatsapp']['orders']);
        $this->assertSame(1000.0, $byChannel['whatsapp']['sales']);
        $this->assertSame(500.0,  $byChannel['whatsapp']['cash']);
    }

    public function test_sales_equals_cash_plus_balance_on_every_row(): void
    {
        $this->viewer();
        $this->order('pos', 1000, 250);
        $this->order('online', 700, 700);
        $this->order('whatsapp', 300, 0);

        $l = $this->ledger();

        foreach ($l['channels'] as $row) {
            $this->assertEqualsWithDelta($row['sales'], $row['cash'] + $row['balance'], 0.01,
                "channel {$row['channel']} does not reconcile");
        }
        foreach ($l['daily'] as $row) {
            $this->assertEqualsWithDelta($row['sales'], $row['cash'] + $row['credit'], 0.01,
                "day {$row['date']} does not reconcile");
        }
        foreach (['weekly', 'monthly'] as $grain) {
            foreach ($l[$grain] as $row) {
                $this->assertEqualsWithDelta($row['total']['sales'],
                    $row['total']['cash'] + $row['total']['balance'], 0.01,
                    "{$grain} {$row['period']} does not reconcile");
                foreach ($row['by_channel'] as $c => $v) {
                    $this->assertEqualsWithDelta($v['sales'], $v['cash'] + $v['balance'], 0.01,
                        "{$grain} {$row['period']} / {$c} does not reconcile");
                }
            }
        }
    }

    public function test_refunds_are_netted_off_cash_not_added_to_it(): void
    {
        $this->viewer();
        $o = $this->order('pos', 1000, 1000);
        Payment::where('order_id', $o->id)->update(['refund_amount' => 400]);

        $pos = collect($this->ledger()['channels'])->firstWhere('channel', 'pos');

        $this->assertSame(600.0, $pos['cash']);     // 1000 taken, 400 given back
        $this->assertSame(400.0, $pos['balance']);
    }

    public function test_a_channel_with_no_orders_still_appears_in_every_bucket(): void
    {
        // A ragged row reads as missing data rather than as a quiet week.
        $this->viewer();
        $this->order('pos', 100, 100);

        foreach (['weekly', 'monthly'] as $grain) {
            foreach ($this->ledger()[$grain] as $row) {
                $this->assertSame(['pos', 'online', 'whatsapp'],
                    array_keys($row['by_channel']), "{$grain} row is missing a channel");
            }
        }
    }

    public function test_cancelled_orders_are_excluded_from_sales(): void
    {
        $this->viewer();
        $this->order('pos', 1000, 0)->update(['status' => 'cancelled']);
        $this->order('pos', 250, 250);

        $pos = collect($this->ledger()['channels'])->firstWhere('channel', 'pos');

        $this->assertSame(1, $pos['orders']);
        $this->assertSame(250.0, $pos['sales']);
    }

    public function test_unique_customers_counts_walk_ins_not_only_registered_users(): void
    {
        // Every order in production has a NULL user_id — POS and WhatsApp
        // customers are walk-ins with a name and phone and no account — so
        // COUNT(DISTINCT user_id) reported 0 unique customers against 381 orders.
        $this->viewer();
        Order::factory()->create(['user_id' => null, 'customer_phone' => '0798238300', 'created_at' => now()]);
        Order::factory()->create(['user_id' => null, 'customer_phone' => '0798238300', 'created_at' => now()]);
        Order::factory()->create(['user_id' => null, 'customer_phone' => '0722270441', 'created_at' => now()]);

        $summary = $this->getJson('/api/v1/admin/reports/sales/summary'
            .'?start_date='.now()->subDays(7)->format('Y-m-d')
            .'&end_date='.now()->format('Y-m-d'))
            ->assertOk()->json();

        $this->assertSame(2, (int) ($summary['summary']['unique_customers'] ?? 0));
    }
}
