<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Register-accounting corrections:
 *  - a void reverses the cash the sale ACTUALLY collected, not the order total
 *    (fixes the drift/over-debit on deposit/partial/split cash sales);
 *  - a refund is bounded to what was actually paid, not unit_price × qty
 *    (closes the cash leak on discounted / part-paid orders).
 */
class PosRegisterAccountingTest extends TestCase
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

    public function test_void_reverses_only_the_cash_a_deposit_sale_collected(): void
    {
        $user   = $this->actingWithPermissions(['pos.access', 'pos.void']);
        $outlet = Outlet::factory()->create();

        // Drawer: 1000 float + a 300 cash deposit taken on a 1000 order.
        $register = CashRegister::create([
            'outlet_id'         => $outlet->id,
            'register_name'     => 'Till',
            'status'            => 'open',
            'currency_code'     => 'KES',
            'opening_balance'   => 1000,
            'expected_cash'     => 1300,
            'total_sales'       => 300,
            'total_cash_sales'  => 300,
            'transaction_count' => 1,
            'opened_by'         => $user->id,
            'opened_at'         => now(),
        ]);

        $order = Order::factory()->create([
            'order_type'     => 'pos',
            'outlet_id'      => $outlet->id,
            'payment_method' => 'cash',
            'total_amount'   => 1000,
            'status'         => 'confirmed',
        ]);
        Payment::factory()->create([
            'order_id'       => $order->id,
            'amount'         => 300,
            'status'         => 'paid',
            'payment_method' => 'cash',
        ]);

        $this->postJson("/api/v1/admin/pos/sales/{$order->id}/void", ['reason' => 'test'])
            ->assertOk();

        $register->refresh();
        // Reversed by the 300 actually collected — NOT the 1000 order total.
        $this->assertEquals(1000, $register->expected_cash);
        $this->assertEquals(0, $register->total_cash_sales);
        $this->assertDatabaseHas('cash_register_transactions', [
            'cash_register_id' => $register->id,
            'transaction_type' => 'void',
            'amount'           => 300,
            'balance_after'    => 1000,
        ]);
    }

    public function test_refund_is_bounded_to_the_amount_actually_collected(): void
    {
        $user   = $this->actingWithPermissions(['pos.access', 'pos.returns']);
        $outlet = Outlet::factory()->create();

        $register = CashRegister::create([
            'outlet_id'       => $outlet->id,
            'register_name'   => 'Till',
            'status'          => 'open',
            'currency_code'   => 'KES',
            'opening_balance' => 1000,
            'expected_cash'   => 1000,
            'opened_by'       => $user->id,
            'opened_at'       => now(),
        ]);

        $variant = ProductVariant::factory()->create();
        $order   = Order::factory()->create([
            'order_type'   => 'pos',
            'outlet_id'    => $outlet->id,
            'total_amount' => 1000,
            'status'       => 'completed',
        ]);
        // A 1000 list-price item, but the customer only paid a 300 cash deposit.
        DB::table('order_items')->insert([
            'order_id'           => $order->id,
            'product_id'         => $variant->product_id,
            'product_variant_id' => $variant->id,
            'sku'                => 'SKU-1',
            'product_name'       => 'Chasuble',
            'quantity'           => 1,
            'unit_price'         => 1000,
            'total_price'        => 1000,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
        Payment::factory()->create([
            'order_id'       => $order->id,
            'amount'         => 300,
            'status'         => 'paid',
            'payment_method' => 'cash',
        ]);

        $this->postJson('/api/v1/admin/pos/returns', [
            'original_order_id' => $order->id,
            'items'             => [['variant_id' => $variant->id, 'quantity' => 1]],
            'reason'            => 'changed mind',
            'refund_method'     => 'cash',
        ])->assertOk();

        // unit_price × qty = 1000, but only 300 was collected — refund is capped.
        $this->assertDatabaseHas('order_returns', [
            'order_id'      => $order->id,
            'refund_amount' => 300,
        ]);
        $register->refresh();
        $this->assertEquals(700, $register->expected_cash); // 1000 − 300
    }
}
