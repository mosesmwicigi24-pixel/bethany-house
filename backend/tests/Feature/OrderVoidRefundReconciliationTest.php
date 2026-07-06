<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedStaff;
use Tests\TestCase;

/**
 * Verifies the P0.5 money-reconciliation fixes on the admin order endpoints:
 *  - MON-1: voiding an order reconciles payment_status (was left stale at 'paid').
 *  - MON-2: a refund cannot exceed the amount actually collected.
 */
class OrderVoidRefundReconciliationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAuthenticatedStaff;

    public function test_voiding_an_order_reconciles_payment_status(): void
    {
        $this->actingAsSuperAdmin();

        $order = Order::factory()->create(['status' => 'confirmed', 'total_amount' => 1000]);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000, 'status' => 'paid']);
        $order->syncPaymentStatus();
        $this->assertSame('paid', $order->fresh()->payment_status);

        $this->postJson("/api/v1/admin/orders/{$order->id}/void", ['reason' => 'test void'])
            ->assertOk();

        $this->assertSame('voided', $order->fresh()->status);
        // The fix: payment_status is reconciled to reflect the now-voided payment,
        // instead of remaining stale at 'paid'.
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'voided']);
    }

    public function test_refund_cannot_exceed_amount_collected(): void
    {
        $this->actingAsSuperAdmin();

        $order = Order::factory()->create(['status' => 'completed', 'total_amount' => 1000]);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000, 'status' => 'paid']);

        // Over-refund is rejected (MON-2).
        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'amount' => 1500,
            'reason' => 'more than collected',
        ])->assertStatus(422);

        // A refund within the collected amount is accepted.
        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'amount' => 500,
            'reason' => 'partial refund',
        ])->assertOk();
    }

    public function test_full_refund_reconciles_payment_status(): void
    {
        $this->actingAsSuperAdmin();

        $order = Order::factory()->create(['status' => 'completed', 'total_amount' => 1000]);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000, 'status' => 'paid']);
        $order->syncPaymentStatus();
        $this->assertSame('paid', $order->fresh()->payment_status);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'amount' => 1000,
            'reason' => 'full refund',
        ])->assertOk();

        // The fix (MON-1): refund is recorded, the collected total nets to 0, and
        // payment_status is reconciled instead of staying stale at 'paid'.
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame(0.0, $order->fresh()->totalPaid());
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'refund_amount' => 1000]);
    }
}
