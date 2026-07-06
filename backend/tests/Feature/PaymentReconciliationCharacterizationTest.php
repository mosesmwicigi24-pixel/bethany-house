<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests for the order/payment money-reconciliation logic.
 *
 * The model-level cases lock the reconciliation primitives (syncPaymentStatus /
 * totalPaid). The HTTP cases exercise the live void/refund endpoints and assert
 * the RECONCILED behavior delivered by roadmap P0.5 (audit findings MON-1/MON-2):
 *  - void and refund now re-sync payment_status;
 *  - totalPaid() is net of refunds, so refunded/voided money stops counting as
 *    collected;
 *  - a refund can no longer exceed the amount collected.
 *
 * (Before P0.5 these same paths left payment_status stale at 'paid' and allowed
 * over-refunds — see the git history of this file for the characterization form.)
 */
class PaymentReconciliationCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private function actingWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    // ── Model primitives ────────────────────────────────────────────────────

    public function test_sync_marks_fully_paid_order_as_paid(): void
    {
        $order = Order::factory()->create(['total_amount' => 1000, 'payment_status' => 'pending']);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000, 'status' => 'paid']);

        $order->syncPaymentStatus();

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(1000.0, $order->totalPaid());
    }

    public function test_sync_marks_order_with_no_paid_payments_as_pending(): void
    {
        $order = Order::factory()->create(['total_amount' => 1000, 'payment_status' => 'paid']);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000, 'status' => 'pending']);

        $order->syncPaymentStatus();

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(0.0, $order->totalPaid());
    }

    public function test_sync_marks_part_paid_order_as_partial(): void
    {
        $order = Order::factory()->create(['total_amount' => 1000, 'deposit_amount' => null]);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 400, 'status' => 'paid']);

        $order->syncPaymentStatus();

        $this->assertSame('partial', $order->fresh()->payment_status);
    }

    public function test_sync_marks_part_paid_deposit_order_as_deposit(): void
    {
        $order = Order::factory()->create(['total_amount' => 1000, 'deposit_amount' => 300]);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 300, 'status' => 'paid']);

        $order->syncPaymentStatus();

        $this->assertSame('deposit', $order->fresh()->payment_status);
    }

    /** P0.5: totalPaid() is now NET of refund_amount (MON-1 root fix). */
    public function test_total_paid_is_net_of_refund_amount(): void
    {
        $order = Order::factory()->create(['total_amount' => 1000]);
        Payment::factory()->create([
            'order_id'      => $order->id,
            'amount'        => 1000,
            'status'        => 'paid',
            'refund_amount' => 400,
        ]);

        $this->assertSame(600.0, $order->totalPaid());
    }

    // ── Live refund endpoint (OrderController::refund) ───────────────────────

    /** MON-1: a full refund reconciles — order nets to 0 and drops out of 'paid'. */
    public function test_full_refund_reconciles_payment_status(): void
    {
        $this->actingWithPermissions(['orders.refund']);
        $order = Order::factory()->create(['total_amount' => 1000, 'status' => 'completed', 'payment_status' => 'paid']);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000, 'status' => 'paid']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'amount' => 1000,
            'reason' => 'customer returned goods',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('refunded', $order->status);
        $this->assertSame('pending', $order->payment_status); // net collected is now 0
        $this->assertSame(0.0, $order->totalPaid());
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'refund_amount' => 1000]);
    }

    /** A partial refund reduces the net collected and shows 'partial'. */
    public function test_partial_refund_reduces_collected(): void
    {
        $this->actingWithPermissions(['orders.refund']);
        $order = Order::factory()->create([
            'total_amount' => 1000, 'status' => 'completed', 'payment_status' => 'paid', 'deposit_amount' => null,
        ]);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000, 'status' => 'paid']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'amount' => 400,
            'reason' => 'one item returned',
        ])->assertOk();

        $order->refresh();
        $this->assertSame(600.0, $order->totalPaid());
        $this->assertSame('partial', $order->payment_status);
    }

    /** MON-2: refunding more than was collected is rejected and changes nothing. */
    public function test_over_refund_is_rejected(): void
    {
        $this->actingWithPermissions(['orders.refund']);
        $order = Order::factory()->create(['total_amount' => 1000, 'status' => 'completed', 'payment_status' => 'paid']);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000, 'status' => 'paid']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'amount' => 1500,
            'reason' => 'over refund attempt',
        ])->assertStatus(422);

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame(1000.0, $order->totalPaid());
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'refund_amount' => 0]);
    }

    // ── Live void endpoints ──────────────────────────────────────────────────

    /** MON-1: admin void re-syncs payment_status to 'pending'. */
    public function test_void_order_reconciles_payment_status(): void
    {
        $this->actingWithPermissions(['orders.cancel']);
        $order = Order::factory()->create(['total_amount' => 1000, 'status' => 'confirmed', 'payment_status' => 'paid']);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000, 'status' => 'paid']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/void", [
            'reason' => 'entered in error',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('voided', $order->status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame(0.0, $order->totalPaid());
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'voided']);
    }

    /** MON-1: POS void now voids the payment rows (previously left them 'paid') and re-syncs. */
    public function test_pos_void_voids_payments_and_reconciles(): void
    {
        $this->actingWithPermissions(['pos.access', 'pos.void']);
        $outlet = Outlet::factory()->create();
        $order = Order::factory()->create([
            'order_type' => 'pos', 'outlet_id' => $outlet->id,
            'total_amount' => 1000, 'status' => 'completed', 'payment_status' => 'paid',
        ]);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000, 'status' => 'paid']);

        $this->postJson("/api/v1/admin/pos/sales/{$order->id}/void", [
            'reason' => 'cashier error',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('voided', $order->status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame(0.0, $order->totalPaid());
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'voided']);
    }
}
