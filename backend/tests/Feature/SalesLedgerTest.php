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
 *     sales = paid + balance
 *
 * on EVERY row. It holds only because paid is attributed to the period the
 * ORDER falls in rather than the period the payment landed in. A future change
 * to bucket by payment date would silently break it — a row's paid figure could then
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
        $this->assertEqualsWithDelta(1500.0, (float) $byChannel['pos']['sales'], 0.01);
        $this->assertEqualsWithDelta(1200.0, (float) $byChannel['pos']['paid'], 0.01);
        $this->assertEqualsWithDelta(300.0, (float) $byChannel['pos']['balance'], 0.01);

        $this->assertSame(1, $byChannel['online']['orders']);
        $this->assertEqualsWithDelta(800.0, (float) $byChannel['online']['balance'], 0.01);

        $this->assertSame(2, $byChannel['whatsapp']['orders']);
        $this->assertEqualsWithDelta(1000.0, (float) $byChannel['whatsapp']['sales'], 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $byChannel['whatsapp']['paid'], 0.01);
    }

    public function test_sales_equals_paid_plus_balance_on_every_row(): void
    {
        $this->viewer();
        $this->order('pos', 1000, 250);
        $this->order('online', 700, 700);
        $this->order('whatsapp', 300, 0);

        $l = $this->ledger();

        foreach ($l['channels'] as $row) {
            $this->assertEqualsWithDelta($row['sales'], $row['paid'] + $row['balance'], 0.01,
                "channel {$row['channel']} does not reconcile");
        }
        foreach ($l['daily'] as $row) {
            $this->assertEqualsWithDelta($row['sales'], $row['paid'] + $row['credit'], 0.01,
                "day {$row['date']} does not reconcile");
        }
        foreach (['weekly', 'monthly'] as $grain) {
            foreach ($l[$grain] as $row) {
                $this->assertEqualsWithDelta($row['total']['sales'],
                    $row['total']['paid'] + $row['total']['balance'], 0.01,
                    "{$grain} {$row['period']} does not reconcile");
                foreach ($row['by_channel'] as $c => $v) {
                    $this->assertEqualsWithDelta($v['sales'], $v['paid'] + $v['balance'], 0.01,
                        "{$grain} {$row['period']} / {$c} does not reconcile");
                }
            }
        }
    }

    public function test_refunds_are_netted_off_the_paid_figure(): void
    {
        $this->viewer();
        $o = $this->order('pos', 1000, 1000);
        Payment::where('order_id', $o->id)->update(['refund_amount' => 400]);

        $pos = collect($this->ledger()['channels'])->firstWhere('channel', 'pos');

        $this->assertEqualsWithDelta(600.0, (float) $pos['paid'], 0.01);     // 1000 taken, 400 given back
        $this->assertEqualsWithDelta(400.0, (float) $pos['balance'], 0.01);
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
        $this->assertEqualsWithDelta(250.0, (float) $pos['sales'], 0.01);
    }

    public function test_paid_excludes_a_payment_awaiting_approval(): void
    {
        // Mukuru / Western Union / M-Pesa Send Money are keyed in by a clerk from
        // the customer's word. Counting one before it is verified would report
        // money we do not yet know we have.
        $this->viewer();
        $o = $this->order('pos', 1000, 1000);
        Payment::where('order_id', $o->id)->update([
            'requires_approval' => true,
            'approval_status'   => 'pending',
        ]);

        $pos = collect($this->ledger()['channels'])->firstWhere('channel', 'pos');

        $this->assertEqualsWithDelta(0.0,    (float) $pos['paid'], 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $pos['balance'], 0.01);
    }

    public function test_paid_counts_it_once_approval_is_granted(): void
    {
        $this->viewer();
        $o = $this->order('pos', 1000, 1000);
        Payment::where('order_id', $o->id)->update([
            'requires_approval' => true,
            'approval_status'   => 'approved',
        ]);

        $pos = collect($this->ledger()['channels'])->firstWhere('channel', 'pos');

        $this->assertEqualsWithDelta(1000.0, (float) $pos['paid'], 0.01);
        $this->assertEqualsWithDelta(0.0,    (float) $pos['balance'], 0.01);
    }

    public function test_payment_method_is_resolved_from_the_payment_when_the_order_has_none(): void
    {
        // orders.payment_method is a denormalised convenience field and is NULL
        // on 144 of 381 production orders — but 95 of those DO have a settled
        // payment carrying the method. Grouping by the order column alone
        // dropped that collected money out of the breakdown entirely.
        $this->viewer();
        $o = Order::factory()->create([
            'payment_method' => null,
            'order_type'     => 'pos',
            'total_amount'   => 5000,
            'currency_code'  => 'KES',
            'status'         => 'confirmed',
            'created_at'     => now(),
        ]);
        Payment::factory()->create([
            'order_id'       => $o->id,
            'payment_method' => 'inmpaybill',
            'amount'         => 5000,
            'status'         => 'paid',
        ]);
        // The display name lives in payment_methods; there is no seeder for it,
        // so the row is created here rather than asserting against a code.
        \DB::table('payment_methods')->insert([
            'code'       => 'inmpaybill',
            'name'       => 'I&M Paybill',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = $this->getJson('/api/v1/admin/reports/sales/summary'
            .'?start_date='.now()->subDays(7)->format('Y-m-d')
            .'&end_date='.now()->format('Y-m-d'))
            ->assertOk()->json();

        $row = collect($summary['by_payment_method'])->firstWhere('payment_method', 'inmpaybill');

        $this->assertNotNull($row, 'the order was dropped from the breakdown');
        $this->assertSame(1, (int) $row['count']);
        // and it carries the configured display name, not the raw code
        $this->assertSame('I&M Paybill', $row['method_name']);
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
