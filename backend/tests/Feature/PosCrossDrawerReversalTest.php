<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * D8: a void/refund must reverse the drawer that ACTUALLY took the sale (found
 * via the cash ledger), not blindly the acting cashier's latest open register.
 * If that shift is already closed, the cash comes out of the current drawer.
 */
class PosCrossDrawerReversalTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function saleOnDrawer(Order $order, CashRegister $register, float $cash, float $balanceAfter): void
    {
        DB::table('cash_register_transactions')->insert([
            'cash_register_id' => $register->id,
            'transaction_type' => 'sale',
            'payment_method'   => 'cash',
            'amount'           => $cash,
            'balance_after'    => $balanceAfter,
            'order_id'         => $order->id,
            'created_by'       => $register->opened_by,
            'created_at'       => now()->subHours(2),
        ]);
    }

    public function test_void_reverses_the_originating_open_drawer_not_the_current_one(): void
    {
        $user   = $this->actingAsSuperAdmin();
        $outlet = Outlet::factory()->create();

        // Register A — took the sale, opened by a DIFFERENT cashier, still open.
        $regA = CashRegister::create([
            'outlet_id' => $outlet->id, 'register_name' => 'Till A', 'status' => 'open',
            'currency_code' => 'KES', 'opening_balance' => 1000, 'expected_cash' => 1300,
            'total_cash_sales' => 300, 'transaction_count' => 1,
            'opened_by' => User::factory()->create()->id, 'opened_at' => now()->subHours(2),
        ]);
        // Register B — the acting cashier's own current drawer.
        $regB = CashRegister::create([
            'outlet_id' => $outlet->id, 'register_name' => 'Till B', 'status' => 'open',
            'currency_code' => 'KES', 'opening_balance' => 5000, 'expected_cash' => 5000,
            'opened_by' => $user->id, 'opened_at' => now(),
        ]);

        $order = Order::factory()->create([
            'order_type' => 'pos', 'status' => 'confirmed', 'outlet_id' => $outlet->id,
            'total_amount' => 1000, 'payment_method' => 'cash',
        ]);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 300, 'status' => 'paid', 'payment_method' => 'cash']);
        $this->saleOnDrawer($order, $regA, 300, 1300);

        $this->postJson("/api/v1/admin/pos/sales/{$order->id}/void", ['reason' => 'test'])
            ->assertOk();

        $this->assertEquals(1000, $regA->fresh()->expected_cash); // reversed on the drawer that took it
        $this->assertEquals(5000, $regB->fresh()->expected_cash); // current drawer untouched
    }

    public function test_void_falls_back_to_current_drawer_when_the_originating_shift_is_closed(): void
    {
        $user   = $this->actingAsSuperAdmin();
        $outlet = Outlet::factory()->create();

        // Register A — took the sale but its shift is already CLOSED.
        $regA = CashRegister::create([
            'outlet_id' => $outlet->id, 'register_name' => 'Till A', 'status' => 'closed',
            'currency_code' => 'KES', 'opening_balance' => 1000, 'expected_cash' => 1300,
            'opened_by' => User::factory()->create()->id, 'opened_at' => now()->subHours(4),
        ]);
        // Register B — the acting cashier's current open drawer.
        $regB = CashRegister::create([
            'outlet_id' => $outlet->id, 'register_name' => 'Till B', 'status' => 'open',
            'currency_code' => 'KES', 'opening_balance' => 5000, 'expected_cash' => 5000,
            'opened_by' => $user->id, 'opened_at' => now(),
        ]);

        $order = Order::factory()->create([
            'order_type' => 'pos', 'status' => 'confirmed', 'outlet_id' => $outlet->id,
            'total_amount' => 1000, 'payment_method' => 'cash',
        ]);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 300, 'status' => 'paid', 'payment_method' => 'cash']);
        $this->saleOnDrawer($order, $regA, 300, 1300);

        $this->postJson("/api/v1/admin/pos/sales/{$order->id}/void", ['reason' => 'test'])
            ->assertOk();

        // Originating shift is closed → the 300 is paid out of the current drawer.
        $this->assertEquals(4700, $regB->fresh()->expected_cash);
        $this->assertEquals(1300, $regA->fresh()->expected_cash); // closed shift untouched
    }
}
