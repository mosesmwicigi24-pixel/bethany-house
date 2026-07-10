<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * recordPosPay must size a payment against the OUTSTANDING balance, not the full
 * order total — so paying the balance on a deposited order settles it (rather
 * than being rejected / forcing a full re-tender) — and must reject a payment
 * that exceeds the balance.
 */
class PosPaymentBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function actor(array $perms = ['pos.access']): User
    {
        $user = User::factory()->create();
        foreach ($perms as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
        return $user;
    }

    private function openRegister(Outlet $outlet, User $user): CashRegister
    {
        return CashRegister::create([
            'register_number'   => "REG-{$outlet->id}-{$user->id}",
            'outlet_id'         => $outlet->id,
            'register_name'     => 'Test Register',
            'status'            => 'open',
            'currency_code'     => 'KES',
            'opening_balance'   => 5000,
            'expected_cash'     => 5000,
            'total_sales'       => 0, 'total_cash_sales' => 0, 'total_card_sales' => 0,
            'total_mpesa_sales' => 0, 'total_refunds' => 0, 'transaction_count' => 0,
            'opened_by'         => $user->id,
            'opened_at'         => now(),
        ]);
    }

    private function pendingOrder(Outlet $outlet, float $total = 1000): Order
    {
        return Order::factory()->create([
            'order_type'     => 'pos',
            'outlet_id'      => $outlet->id,
            'total_amount'   => $total,
            'status'         => 'processing',
            'payment_status' => 'pending',
            'currency_code'  => 'KES',
        ]);
    }

    public function test_paying_the_balance_after_a_deposit_settles_the_order(): void
    {
        $user   = $this->actor();
        $outlet = Outlet::factory()->create();
        $this->openRegister($outlet, $user);
        $order  = $this->pendingOrder($outlet, 1000);

        // 1) A 400 deposit.
        $this->postJson("/api/v1/admin/pos/pending-order/{$order->id}/pay", [
            'is_deposit' => true, 'deposit_amount' => 400,
            'method' => 'cash', 'amount' => 400, 'cash_received' => 400,
        ])->assertOk();
        $this->assertSame('deposit', $order->fresh()->payment_status);

        // 2) The 600 balance — previously rejected (600 < 1000 total); now settles.
        $this->postJson("/api/v1/admin/pos/pending-order/{$order->id}/pay", [
            'method' => 'cash', 'amount' => 600, 'cash_received' => 600,
        ])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_a_payment_exceeding_the_balance_is_rejected(): void
    {
        $user   = $this->actor();
        $outlet = Outlet::factory()->create();
        $this->openRegister($outlet, $user);
        $order  = $this->pendingOrder($outlet, 1000);

        $this->postJson("/api/v1/admin/pos/pending-order/{$order->id}/pay", [
            'method' => 'cash', 'amount' => 1500, 'cash_received' => 1500,
        ])->assertStatus(422);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_a_second_full_payment_on_a_paid_order_cannot_double_collect(): void
    {
        $user   = $this->actor();
        $outlet = Outlet::factory()->create();
        $this->openRegister($outlet, $user);
        $order  = $this->pendingOrder($outlet, 1000);

        $this->postJson("/api/v1/admin/pos/pending-order/{$order->id}/pay", [
            'method' => 'cash', 'amount' => 1000, 'cash_received' => 1000,
        ])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);

        // The order is fully paid — a second payment must be refused (the endpoint
        // rejects further payments once paid), so no over-collection.
        $this->postJson("/api/v1/admin/pos/pending-order/{$order->id}/pay", [
            'method' => 'cash', 'amount' => 1000, 'cash_received' => 1000,
        ])->assertStatus(422);
    }
}
