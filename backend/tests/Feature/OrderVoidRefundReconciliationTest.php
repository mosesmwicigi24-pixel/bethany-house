<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Verifies the P0.5 money-reconciliation fixes on the admin order endpoints:
 *  - MON-1: voiding an order reconciles payment_status (was left stale at 'paid').
 *  - MON-2: a refund cannot exceed the amount actually collected.
 */
class OrderVoidRefundReconciliationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Authenticate as a super_admin — the Gate::before bypass grants all
     * permissions, so this cleanly clears the route's permission middleware.
     * (These tests verify the void/refund business logic, not the RBAC gating.)
     */
    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

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
}
