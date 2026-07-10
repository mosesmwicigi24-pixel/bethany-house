<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Admin refund/void must keep the ledger honest: never refund more than was
 * collected, ACCUMULATE refund_amount (Order::totalPaid = SUM(amount -
 * refund_amount), so overwriting corrupts it), and reconcile payment_status.
 */
class PaymentRefundIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function actor(array $perms): void
    {
        $user = User::factory()->create();
        foreach ($perms as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    private function paidOrder(float $total = 1000): array
    {
        $order = Order::factory()->create([
            'order_type' => 'pos', 'status' => 'completed',
            'total_amount' => $total, 'currency_code' => 'KES', 'payment_status' => 'paid',
        ]);
        $payment = Payment::create([
            'order_id' => $order->id, 'payment_method' => 'cash',
            'amount' => $total, 'currency_code' => 'KES', 'status' => 'paid', 'paid_at' => now(),
        ]);
        return [$order, $payment];
    }

    public function test_refund_cannot_exceed_the_amount_still_refundable(): void
    {
        [, $payment] = $this->paidOrder(1000);
        $this->actor(['payments.view', 'orders.refund']);

        $this->postJson("/api/v1/admin/payment-transactions/{$payment->id}/refund", [
            'amount' => 1500, 'reason' => 'too much',
        ])->assertStatus(422);

        $this->assertSame(0.0, (float) $payment->fresh()->refund_amount);
    }

    public function test_partial_refunds_accumulate_not_overwrite(): void
    {
        [$order, $payment] = $this->paidOrder(1000);
        $this->actor(['payments.view', 'orders.refund']);

        $this->postJson("/api/v1/admin/payment-transactions/{$payment->id}/refund",
            ['amount' => 300, 'reason' => 'r1'])->assertOk();
        $this->assertSame(300.0, (float) $payment->fresh()->refund_amount);

        $this->postJson("/api/v1/admin/payment-transactions/{$payment->id}/refund",
            ['amount' => 400, 'reason' => 'r2'])->assertOk();
        // Accumulated to 700, not overwritten to 400.
        $this->assertSame(700.0, (float) $payment->fresh()->refund_amount);

        // Net collected dropped to 300, status reconciled to partial.
        $this->assertSame(300.0, $order->fresh()->totalPaid());

        // A third refund beyond the remaining 300 is rejected.
        $this->postJson("/api/v1/admin/payment-transactions/{$payment->id}/refund",
            ['amount' => 400, 'reason' => 'r3'])->assertStatus(422);
        $this->assertSame(700.0, (float) $payment->fresh()->refund_amount);
    }

    public function test_voiding_a_payment_reconciles_order_status(): void
    {
        [$order, $payment] = $this->paidOrder(1000);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->actor(['payments.view', 'payments.void']);

        $this->postJson("/api/v1/admin/payment-transactions/{$payment->id}/void",
            ['reason' => 'entered in error'])->assertOk();

        // The voided payment no longer counts as collected → back to pending.
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(0.0, $order->fresh()->totalPaid());
    }
}
