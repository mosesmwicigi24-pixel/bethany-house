<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Support\CancelAbandonedPosPendingOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cleanup cancels genuinely-abandoned POS pending orders (old, unpaid, no money)
 * while never touching recent orders or any order with money attached.
 */
class CancelAbandonedPosPendingOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_cancels_old_unpaid_pending_pos_orders(): void
    {
        $old = Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'pending',
            'payment_status' => 'pending',
            'total_amount'   => 17500,
            'created_at'     => now()->subDays(5),
        ]);

        $result = CancelAbandonedPosPendingOrders::run(24);

        $this->assertSame(1, $result['cancelled']);
        $this->assertSame('cancelled', $old->fresh()->status);
    }

    public function test_it_leaves_recent_pending_orders_alone(): void
    {
        $recent = Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'pending',
            'payment_status' => 'pending',
            'total_amount'   => 500,
            'created_at'     => now()->subHours(2),
        ]);

        $result = CancelAbandonedPosPendingOrders::run(24);

        $this->assertSame(0, $result['cancelled']);
        $this->assertSame('pending', $recent->fresh()->status);
    }

    public function test_it_never_cancels_an_order_with_money_attached(): void
    {
        $order = Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'pending',
            'payment_status' => 'pending',
            'total_amount'   => 1000,
            'created_at'     => now()->subDays(3),
        ]);
        DB::table('payments')->insert([
            'order_id'       => $order->id,
            'payment_number' => 'PMT-' . $order->id,
            'payment_method' => 'cash',
            'amount'         => 1000,
            'currency_code'  => 'KES',
            'status'         => 'paid',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $result = CancelAbandonedPosPendingOrders::run(24);

        $this->assertSame(0, $result['cancelled']);
        $this->assertSame('pending', $order->fresh()->status);
    }
}
