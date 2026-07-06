<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization tests for the order/payment money-reconciliation primitives
 * (Order::syncPaymentStatus / totalPaid) and for the CURRENT — buggy — behavior
 * of void and refund on the live POS path.
 *
 * These lock in today's behavior so the upcoming money-reconciliation fix
 * (audit findings MON-1/MON-2, roadmap P0.5) can be verified to change exactly
 * what it intends and nothing else. Tests that document a defect say so and
 * name the finding; when the fix lands, those assertions flip and the docblock
 * is updated.
 *
 * No application code is invoked through HTTP here — void/refund reconciliation
 * is reproduced at the model level by replaying exactly what the controllers do
 * to the payment rows, which is deterministic and needs no auth/permission
 * plumbing. Endpoint-level tests land alongside the fix PR.
 */
class PaymentReconciliationCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    /** The core primitive: fully-covered order settles to 'paid'. */
    public function test_sync_marks_fully_paid_order_as_paid(): void
    {
        $order = Order::factory()->create(['total_amount' => 1000, 'payment_status' => 'pending']);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000, 'status' => 'paid']);

        $order->syncPaymentStatus();

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(1000.0, $order->totalPaid());
    }

    /** No settled payments → 'pending'. */
    public function test_sync_marks_order_with_no_paid_payments_as_pending(): void
    {
        $order = Order::factory()->create(['total_amount' => 1000, 'payment_status' => 'paid']);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000, 'status' => 'pending']);

        $order->syncPaymentStatus();

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(0.0, $order->totalPaid());
    }

    /** Part-paid, non-deposit order → 'partial'. */
    public function test_sync_marks_part_paid_order_as_partial(): void
    {
        $order = Order::factory()->create(['total_amount' => 1000, 'deposit_amount' => null]);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 400, 'status' => 'paid']);

        $order->syncPaymentStatus();

        $this->assertSame('partial', $order->fresh()->payment_status);
    }

    /** Part-paid order that carries a deposit_amount → 'deposit' (takes precedence over 'partial'). */
    public function test_sync_marks_part_paid_deposit_order_as_deposit(): void
    {
        $order = Order::factory()->create(['total_amount' => 1000, 'deposit_amount' => 300]);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 300, 'status' => 'paid']);

        $order->syncPaymentStatus();

        $this->assertSame('deposit', $order->fresh()->payment_status);
    }

    /**
     * Root cause of MON-1's double-count: totalPaid() sums only status='paid'
     * and IGNORES refund_amount entirely — a fully-refunded payment whose status
     * is still 'paid' still counts as fully collected.
     */
    public function test_total_paid_ignores_refund_amount(): void
    {
        $order = Order::factory()->create(['total_amount' => 1000]);
        Payment::factory()->create([
            'order_id'      => $order->id,
            'amount'        => 1000,
            'status'        => 'paid',
            'refund_amount' => 1000,   // fully refunded on paper…
        ]);

        // …yet still counted as fully collected.
        $this->assertSame(1000.0, $order->totalPaid());
    }

    /**
     * DEFECT MON-1 (refund): OrderController::refund sets refund_amount/refunded_at
     * on the latest paid payment, flips order.status to 'refunded', but never
     * changes the payment's status nor calls syncPaymentStatus(). Result: the
     * order still reports payment_status='paid' and totalPaid()=full amount, so
     * payment-based reports double-count the refunded sale.
     *
     * When P0.5 lands, refund will reconcile and this test's assertions flip.
     */
    public function test_refund_leaves_payment_status_stale_current_behavior(): void
    {
        $order = Order::factory()->create(['total_amount' => 1000, 'payment_status' => 'paid', 'status' => 'completed']);
        $payment = Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000, 'status' => 'paid']);

        // Replay exactly what OrderController::refund does to the money rows.
        $order->update(['status' => 'refunded']);
        $payment->update(['refund_amount' => 1000, 'refunded_at' => now()]);

        $this->assertSame('paid', $order->fresh()->payment_status, 'refund does not re-sync payment_status (MON-1)');
        $this->assertSame(1000.0, $order->totalPaid(), 'refund does not reduce collected total (MON-1)');
    }

    /**
     * DEFECT MON-1 (void): OrderController::voidOrder marks payment rows
     * 'voided' but never calls syncPaymentStatus(), so payment_status stays
     * stale at 'paid'. This test pins that gap AND shows that the single missing
     * call is the whole fix: invoking syncPaymentStatus() after the void yields
     * the correct 'pending'.
     */
    public function test_void_leaves_payment_status_stale_until_synced(): void
    {
        $order = Order::factory()->create(['total_amount' => 1000, 'payment_status' => 'paid']);
        $payment = Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000, 'status' => 'paid']);

        // Replay voidOrder's payment mutation (no re-sync).
        $payment->update(['status' => 'voided']);

        $this->assertSame('paid', $order->fresh()->payment_status, 'void does not re-sync payment_status (MON-1)');

        // The missing call, applied, produces the correct state.
        $order->syncPaymentStatus();
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(0.0, $order->totalPaid());
    }
}
