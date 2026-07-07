<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedStaff;
use Tests\TestCase;

/**
 * End-to-end proof of the register-reversal fix: voiding a POS sale reverses the
 * cash the sale ACTUALLY collected, not the order total. Before the fix a
 * deposit/partial/split cash sale was over-debited (order total) and clamped,
 * silently corrupting the drawer.
 */
class PosRegisterReconciliationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAuthenticatedStaff;

    public function test_voiding_a_deposit_cash_sale_reverses_only_the_cash_collected(): void
    {
        $user   = $this->actingAsSuperAdmin();
        $outlet = Outlet::factory()->create();

        // Open drawer with 1000 float, then post a 300 cash deposit on a 1000
        // order (so expected_cash = 1300) through the same ledger the sale uses.
        $register = CashRegister::create([
            'outlet_id'       => $outlet->id,
            'register_name'   => 'Till',
            'status'          => 'open',
            'opening_balance' => 1000,
            'expected_cash'   => 1000,
            'opened_by'       => $user->id,
            'opened_at'       => now(),
        ]);

        $order = Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'confirmed',
            'outlet_id'      => $outlet->id,
            'total_amount'   => 1000,
            'payment_method' => 'cash',
        ]);
        Payment::factory()->create([
            'order_id'       => $order->id,
            'amount'         => 300,
            'status'         => 'paid',
            'payment_method' => 'cash',
        ]);
        $register->postSale(300, 0, 0, 300, $order->id);
        $register->refresh();
        $this->assertEquals(1300, $register->expected_cash);

        $this->postJson("/api/v1/admin/pos/sales/{$order->id}/void", ['reason' => 'test void'])
            ->assertOk();

        $register->refresh();
        // Reversed by the 300 actually collected — NOT the 1000 order total.
        $this->assertEquals(1000, $register->expected_cash);
        $this->assertEquals(0, $register->total_cash_sales);
        $this->assertDatabaseHas('cash_register_transactions', [
            'cash_register_id' => $register->id,
            'transaction_type' => 'void',
            'order_id'         => $order->id,
        ]);
    }
}
