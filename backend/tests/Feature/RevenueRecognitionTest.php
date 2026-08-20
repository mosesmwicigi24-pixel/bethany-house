<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Revenue recognition: an order is income only once a human accepted it, or
 * once real money arrived.
 *
 * The rule this replaced was `status NOT IN (voided, cancelled)`, which counted
 * every abandoned storefront and WhatsApp cart as revenue — KES 1,136,060 of
 * the last 30 days' reported sales, and 82% of the Online channel. These tests
 * pin the new boundary from both sides: what must be counted, and what must
 * never be.
 */
class RevenueRecognitionTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));

        return $user;
    }

    private function order(string $status, string $paymentStatus = 'pending'): Order
    {
        return Order::factory()->create([
            'status'         => $status,
            'payment_status' => $paymentStatus,
            'currency_code'  => 'KES',
            'total_amount'   => 1000,
        ]);
    }

    /** @return int[] ids the recognised scope returns */
    private function recognisedIds(): array
    {
        return Order::query()->recognised()->pluck('orders.id')->all();
    }

    private function pipelineIds(): array
    {
        return Order::query()->pipeline()->pluck('orders.id')->all();
    }

    public function test_an_unconfirmed_cart_is_not_income(): void
    {
        $cart = $this->order('pending');

        $this->assertNotContains($cart->id, $this->recognisedIds());
        $this->assertContains($cart->id, $this->pipelineIds());
    }

    public function test_every_confirmed_stage_is_income(): void
    {
        foreach (Order::RECOGNISED_STATUSES as $status) {
            $o = $this->order($status);
            $this->assertContains(
                $o->id,
                $this->recognisedIds(),
                "status '{$status}' must be recognised income",
            );
        }
    }

    public function test_money_arriving_recognises_an_order_no_one_has_opened_yet(): void
    {
        // A customer pays the payment link before any staff member touches it.
        $paid = $this->order('pending', 'paid');

        $this->assertContains($paid->id, $this->recognisedIds());
        $this->assertNotContains($paid->id, $this->pipelineIds(), 'a paid order is income, never pipeline');
    }

    public function test_a_deposit_recognises_a_made_to_order_sale(): void
    {
        $deposit = $this->order('pending', 'deposit');

        $this->assertContains($deposit->id, $this->recognisedIds());
    }

    public function test_a_cancelled_order_stays_out_even_when_it_was_paid(): void
    {
        // The dead check must beat the payment arm — a refunded/cancelled order
        // still carries its settled payment row.
        foreach (Order::DEAD_STATUSES as $status) {
            $o = $this->order($status, 'paid');
            $this->assertNotContains(
                $o->id,
                $this->recognisedIds(),
                "dead status '{$status}' must never be income, paid or not",
            );
        }
    }

    public function test_recognised_and_pipeline_never_overlap(): void
    {
        $this->order('pending');
        $this->order('pending', 'paid');
        $this->order('confirmed');
        $this->order('completed', 'paid');
        $this->order('cancelled', 'paid');

        $overlap = array_intersect($this->recognisedIds(), $this->pipelineIds());

        $this->assertSame([], $overlap, 'an order must not be both income and pipeline');
    }

    public function test_the_sales_ledger_excludes_carts_and_reports_them_as_pipeline(): void
    {
        $this->order('confirmed');                    // 1000, income
        $this->order('pending');                      // 1000, pipeline
        $this->order('pending');                      // 1000, pipeline

        $res = $this->actingAs($this->viewer(), 'sanctum')
            ->getJson('/api/v1/admin/reports/sales/ledger?start='
                . now()->subDay()->toDateString() . '&end=' . now()->addDay()->toDateString());

        $res->assertOk();

        $this->assertSame(1000.0, (float) collect($res->json('channels'))->sum('sales'),
            'only the confirmed order may reach the sales figure');
        $this->assertSame(2000.0, (float) $res->json('pipeline.total.sales'),
            'the two carts must be reported as pipeline, not silently dropped');
        $this->assertSame(2, (int) $res->json('pipeline.total.orders'));
    }

    public function test_the_ledger_stage_matrix_sums_to_the_channel_total(): void
    {
        $this->order('confirmed');
        $this->order('processing');
        $this->order('completed');
        $this->order('pending');   // must appear in neither

        $res = $this->actingAs($this->viewer(), 'sanctum')
            ->getJson('/api/v1/admin/reports/sales/ledger?start='
                . now()->subDay()->toDateString() . '&end=' . now()->addDay()->toDateString());

        $res->assertOk();

        $channelSales = (float) collect($res->json('channels'))->sum('sales');
        $stageSales   = (float) collect($res->json('by_stage'))
            ->flatMap(fn ($c) => array_values($c['stages']))
            ->sum('sales');

        $this->assertSame($channelSales, $stageSales,
            'the three stage columns must reconcile to the channel total');
        $this->assertSame(3000.0, $stageSales);
    }

    public function test_the_reconciliation_bridges_the_old_number_to_the_new_one(): void
    {
        $this->order('confirmed');   // 1000 recognised
        $this->order('pending');     // 1000 pipeline
        $this->order('cancelled');   // dead: in neither, and not in gross

        $res = $this->actingAs($this->viewer(), 'sanctum')
            ->getJson('/api/v1/admin/reports/sales/ledger?start='
                . now()->subDay()->toDateString() . '&end=' . now()->addDay()->toDateString());

        $res->assertOk();

        $this->assertSame(1000.0, (float) $res->json('reconciliation.recognised_sales'));
        $this->assertSame(1000.0, (float) $res->json('reconciliation.pipeline_sales'));
        $this->assertSame(2000.0, (float) $res->json('reconciliation.gross_order_book'),
            'gross must equal recognised + pipeline exactly, or the bridge does not bridge');
    }

    public function test_staff_can_confirm_an_unpaid_order_so_it_stops_looking_abandoned(): void
    {
        // The case that stranded 85 WhatsApp orders: agreed with the customer,
        // payment on delivery, so it could never leave 'pending'.
        $order = Order::factory()->create([
            'status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 5000,
        ]);

        $staff = User::factory()->create();
        $staff->assignRole(Role::findOrCreate('admin', 'sanctum'));
        foreach (['orders.view', 'orders.edit'] as $perm) {
            $staff->givePermissionTo(Permission::findOrCreate($perm, 'sanctum'));
        }

        $this->actingAs($staff, 'sanctum')
            ->putJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertOk();

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertContains($order->id, $this->recognisedIds(),
            'confirming makes it income even with the balance outstanding');
    }

    public function test_completing_an_unpaid_order_is_still_refused(): void
    {
        $order = Order::factory()->create([
            'status' => 'confirmed', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 5000,
        ]);

        $staff = User::factory()->create();
        $staff->assignRole(Role::findOrCreate('admin', 'sanctum'));
        foreach (['orders.view', 'orders.edit'] as $perm) {
            $staff->givePermissionTo(Permission::findOrCreate($perm, 'sanctum'));
        }

        $this->actingAs($staff, 'sanctum')
            ->putJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'unpaid_balance');
    }
}
