<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedStaff;
use Tests\TestCase;

/**
 * Verifies the MON-1 fix on the LIVE money path: PosController::voidSale now
 * reverses the sale's payments and reconciles payment_status, instead of leaving
 * the payment rows at 'paid' (which caused payment-based reports to keep counting
 * a voided POS sale).
 */
class PosSaleVoidReconciliationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAuthenticatedStaff;

    public function test_voiding_a_pos_sale_reverses_payments_and_reconciles_status(): void
    {
        $this->actingAsSuperAdmin();

        $outlet = Outlet::factory()->create();
        $order = Order::factory()->create([
            'order_type'   => 'pos',
            'status'       => 'confirmed',
            'total_amount' => 1000,
            'outlet_id'    => $outlet->id,
        ]);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000, 'status' => 'paid']);
        $order->syncPaymentStatus();
        $this->assertSame('paid', $order->fresh()->payment_status);

        $this->postJson("/api/v1/admin/pos/sales/{$order->id}/void", ['reason' => 'test void'])
            ->assertOk();

        $this->assertSame('voided', $order->fresh()->status);
        // The fix: the payment is reversed and payment_status reconciled.
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'voided']);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }
}
