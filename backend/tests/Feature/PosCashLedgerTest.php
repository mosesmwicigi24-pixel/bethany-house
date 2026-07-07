<?php

namespace Tests\Feature;

use App\Models\CashRegister;
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
 * MON-3: POS cash movements now append a row to the `cash_register_transactions`
 * ledger (previously never written — the register only carried running aggregate
 * totals with no per-movement audit trail). createSale, voidSale and processReturn
 * all use the same recordCashLedger() helper; this exercises it through the void
 * endpoint, which has the lightest setup.
 */
class PosCashLedgerTest extends TestCase
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

    private function openCashRegister(Outlet $outlet, User $user, float $expected = 5000): CashRegister
    {
        return CashRegister::create([
            'register_number'   => "REG-{$outlet->id}-{$user->id}",
            'outlet_id'         => $outlet->id,
            'register_name'     => 'Test Register',
            'status'            => 'open',
            'currency_code'     => 'KES',
            'opening_balance'   => $expected,
            'expected_cash'     => $expected,
            'total_sales'       => 0,
            'total_cash_sales'  => 0,
            'total_card_sales'  => 0,
            'total_mpesa_sales' => 0,
            'total_refunds'     => 0,
            'transaction_count' => 0,
            'opened_by'         => $user->id,
            'opened_at'         => now(),
        ]);
    }

    public function test_pos_cash_void_writes_a_ledger_row_with_running_balance(): void
    {
        $user     = $this->actingWithPermissions(['pos.access', 'pos.void']);
        $outlet   = Outlet::factory()->create();
        $register = $this->openCashRegister($outlet, $user, 5000);
        $order    = Order::factory()->create([
            'order_type'     => 'pos',
            'outlet_id'      => $outlet->id,
            'payment_method' => 'cash',
            'total_amount'   => 1000,
            'status'         => 'completed',
        ]);
        Payment::factory()->create([
            'order_id'       => $order->id,
            'amount'         => 1000,
            'status'         => 'paid',
            'payment_method' => 'cash',
        ]);

        $this->postJson("/api/v1/admin/pos/sales/{$order->id}/void", ['reason' => 'cashier error'])
            ->assertOk();

        // A per-movement ledger row is written, with balance_after = 5000 - 1000.
        $this->assertDatabaseHas('cash_register_transactions', [
            'cash_register_id' => $register->id,
            'transaction_type' => 'void',
            'payment_method'   => 'cash',
            'order_id'         => $order->id,
            'amount'           => 1000,
            'balance_after'    => 4000,
        ]);
    }

    public function test_non_cash_void_writes_no_ledger_row(): void
    {
        $user   = $this->actingWithPermissions(['pos.access', 'pos.void']);
        $outlet = Outlet::factory()->create();
        $this->openCashRegister($outlet, $user, 5000);
        $order  = Order::factory()->create([
            'order_type'     => 'pos',
            'outlet_id'      => $outlet->id,
            'payment_method' => 'mpesa',
            'total_amount'   => 1000,
            'status'         => 'completed',
        ]);
        Payment::factory()->create([
            'order_id'       => $order->id,
            'amount'         => 1000,
            'status'         => 'paid',
            'payment_method' => 'mpesa',
        ]);

        $this->postJson("/api/v1/admin/pos/sales/{$order->id}/void", ['reason' => 'wrong entry'])
            ->assertOk();

        // M-Pesa isn't a cash-drawer movement, so no cash ledger row.
        $this->assertDatabaseMissing('cash_register_transactions', ['order_id' => $order->id]);
    }
}
