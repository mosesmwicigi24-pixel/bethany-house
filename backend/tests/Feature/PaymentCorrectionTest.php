<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Correcting a payment after the fact.
 *
 * The situation this was built for, taken from a live order: the line items
 * were edited after the money was taken, so 33,000 sits against a 28,000 total
 * and the staff note reads "REFUND DUE: 5,000". Until now nothing on the order
 * screen could resolve that — the record simply stayed wrong.
 *
 * The rule every test here defends: whatever happens to a payment, the order's
 * `payment_status` and its derived balance are recomputed from the payments
 * that remain. A correction that leaves the order reading "paid" while the
 * money says otherwise is worse than no correction at all.
 */
class PaymentCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function actor(array $permissions, string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate($role, 'sanctum'));

        foreach (array_merge(['payments.view'], $permissions) as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'sanctum'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /** Order #389 as it stands in the screenshot: 28,000 due, 33,000 collected. */
    private function overpaidOrder(): Order
    {
        $order = Order::factory()->create([
            'order_type'     => 'pos',
            'status'         => 'processing',
            'total_amount'   => 28000,
            'payment_status' => 'paid',
        ]);

        foreach ([['inmpaybill', 16500], ['cash', 16500]] as $i => [$method, $amount]) {
            Payment::create([
                'order_id'       => $order->id,
                'payment_number' => 'PAY-TEST-'.$order->id.'-'.$i,
                'payment_method' => $method,
                'amount'         => $amount,
                'currency_code'  => 'KES',
                'status'         => 'paid',
                'paid_at'        => now()->subDays(2 - $i),
            ]);
        }

        return $order->fresh('payments');
    }

    private function payments(Order $order)
    {
        return Payment::where('order_id', $order->id)->orderBy('id')->get();
    }

    // ------------------------------------------------------------------

    /** The case from the screenshot: the cash payment was keyed 5,000 too high. */
    public function test_correcting_an_amount_rebalances_the_order(): void
    {
        $this->actor(['payments.edit']);
        $order = $this->overpaidOrder();
        $cash  = $this->payments($order)->last();

        $this->patchJson("/api/v1/admin/payment-transactions/{$cash->id}", [
            'amount' => 11500,
            'reason' => 'Line items edited after payment — cash actually collected was 11,500.',
        ])->assertOk()->assertJsonPath('changed.amount.to', '11500.00');

        $order->refresh();

        $this->assertSame(28000.0, $order->totalPaid());
        $this->assertSame(0.0, $order->outstandingBalance());
        $this->assertSame('paid', $order->payment_status);
    }

    /** Reducing below the total should reopen the balance, not leave it "paid". */
    public function test_reducing_a_payment_reopens_the_balance(): void
    {
        $this->actor(['payments.edit']);
        $order = $this->overpaidOrder();
        $cash  = $this->payments($order)->last();

        $this->patchJson("/api/v1/admin/payment-transactions/{$cash->id}", [
            'amount' => 1500,
            'reason' => 'Keying error.',
        ])->assertOk();

        $order->refresh();

        $this->assertSame(18000.0, $order->totalPaid());
        $this->assertSame(10000.0, $order->outstandingBalance());
        $this->assertSame('partial', $order->payment_status);
    }

    public function test_the_method_reference_and_date_can_be_corrected(): void
    {
        $this->actor(['payments.edit']);
        $order = $this->overpaidOrder();
        $payment = $this->payments($order)->first();

        $this->patchJson("/api/v1/admin/payment-transactions/{$payment->id}", [
            'payment_method'     => 'cash',
            'provider_reference' => 'MPESA-QWERTY123',
            'paid_at'            => '2026-08-05',
            'reason'             => 'Recorded against the wrong method.',
        ])->assertOk();

        $payment->refresh();

        $this->assertSame('cash', $payment->payment_method);
        $this->assertSame('MPESA-QWERTY123', $payment->provider_reference);
        $this->assertSame('2026-08-05', $payment->paid_at->toDateString());
    }

    /** A correction has to be as auditable as the payment it corrects. */
    public function test_only_the_fields_that_moved_are_recorded(): void
    {
        $this->actor(['payments.edit']);
        $order = $this->overpaidOrder();
        $cash  = $this->payments($order)->last();

        $response = $this->patchJson("/api/v1/admin/payment-transactions/{$cash->id}", [
            'amount'         => 11500,
            'payment_method' => 'cash',   // unchanged
            'reason'         => 'Overcollection corrected.',
        ])->assertOk();

        $changed = $response->json('changed');

        $this->assertArrayHasKey('amount', $changed);
        $this->assertArrayNotHasKey('payment_method', $changed);
        $this->assertSame('16500.00', $changed['amount']['from']);
    }

    public function test_a_voided_payment_cannot_be_edited(): void
    {
        $this->actor(['payments.edit', 'payments.void']);
        $order = $this->overpaidOrder();
        $cash  = $this->payments($order)->last();

        $cash->update(['status' => 'voided', 'voided_at' => now()]);

        $this->patchJson("/api/v1/admin/payment-transactions/{$cash->id}", [
            'amount' => 100,
            'reason' => 'Trying to revive a void.',
        ])->assertStatus(422);
    }

    /** Money already returned to a customer cannot be un-collected by an edit. */
    public function test_a_payment_cannot_shrink_below_what_was_refunded(): void
    {
        $this->actor(['payments.edit']);
        $order = $this->overpaidOrder();
        $cash  = $this->payments($order)->last();

        $cash->update(['refund_amount' => 5000]);

        $this->patchJson("/api/v1/admin/payment-transactions/{$cash->id}", [
            'amount' => 2000,
            'reason' => 'Should be refused.',
        ])->assertStatus(422);

        $this->assertSame(16500.0, (float) $cash->fresh()->amount);
    }

    public function test_editing_requires_the_permission(): void
    {
        $this->actor([]);   // payments.view only
        $order = $this->overpaidOrder();
        $cash  = $this->payments($order)->last();

        $this->patchJson("/api/v1/admin/payment-transactions/{$cash->id}", [
            'amount' => 100,
            'reason' => 'No permission.',
        ])->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Removal
    // ------------------------------------------------------------------

    public function test_a_super_admin_can_delete_a_payment_and_the_order_rebalances(): void
    {
        $this->superAdmin();
        $order = $this->overpaidOrder();
        $cash  = $this->payments($order)->last();

        $this->deleteJson("/api/v1/admin/payment-transactions/{$cash->id}", [
            'reason' => 'Recorded twice by mistake.',
        ])->assertOk();

        $this->assertDatabaseMissing('payments', ['id' => $cash->id]);

        $order->refresh();

        $this->assertSame(16500.0, $order->totalPaid());
        $this->assertSame(11500.0, $order->outstandingBalance());
        $this->assertSame('partial', $order->payment_status);
    }

    /**
     * The permission alone is not enough. Deleting is the one action here that
     * cannot be undone, so it is gated on the role directly as well.
     */
    public function test_an_admin_holding_the_permission_still_cannot_delete(): void
    {
        $this->actor(['payments.delete']);
        $order = $this->overpaidOrder();
        $cash  = $this->payments($order)->last();

        $this->deleteJson("/api/v1/admin/payment-transactions/{$cash->id}", [
            'reason' => 'Should be refused.',
        ])->assertStatus(403);

        $this->assertDatabaseHas('payments', ['id' => $cash->id]);
    }

    public function test_a_refunded_payment_cannot_be_deleted(): void
    {
        $this->superAdmin();
        $order = $this->overpaidOrder();
        $cash  = $this->payments($order)->last();

        $cash->update(['refund_amount' => 5000, 'refunded_at' => now()]);

        $this->deleteJson("/api/v1/admin/payment-transactions/{$cash->id}", [
            'reason' => 'Would orphan the refund.',
        ])->assertStatus(422);

        $this->assertDatabaseHas('payments', ['id' => $cash->id]);
    }

    public function test_deleting_requires_a_reason(): void
    {
        $this->superAdmin();
        $order = $this->overpaidOrder();
        $cash  = $this->payments($order)->last();

        $this->deleteJson("/api/v1/admin/payment-transactions/{$cash->id}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    /**
     * The safe path, asserted because it is the one the screen offers first:
     * a void drops the payment out of the order's money without destroying the
     * row — and every revenue query in this system filters on status, so it
     * disappears from reports for the same reason.
     */
    public function test_voiding_removes_the_money_but_keeps_the_record(): void
    {
        $this->actor(['payments.void']);
        $order = $this->overpaidOrder();
        $cash  = $this->payments($order)->last();

        $this->postJson("/api/v1/admin/payment-transactions/{$cash->id}/void", [
            'reason' => 'Duplicate capture.',
        ])->assertOk();

        $order->refresh();

        $this->assertSame(16500.0, $order->totalPaid());
        $this->assertSame('partial', $order->payment_status);
        $this->assertDatabaseHas('payments', ['id' => $cash->id, 'status' => 'voided']);

        // And it is gone from revenue, because reporting keys off status.
        $counted = (float) DB::table('payments')
            ->where('order_id', $order->id)->where('status', 'paid')->sum('amount');

        $this->assertSame(16500.0, $counted);
    }
}
