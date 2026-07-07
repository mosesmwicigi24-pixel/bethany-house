<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The POS outstanding-balances (receivables) endpoint lists part-paid POS orders
 * with money still owed, and reports the correct balance (total − net collected).
 */
class PosOutstandingBalancesTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_only_part_paid_orders_with_their_balance(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $outlet = Outlet::factory()->create();

        $partial = Order::factory()->create(['order_type' => 'pos', 'outlet_id' => $outlet->id, 'total_amount' => 1000, 'payment_status' => 'partial']);
        Payment::factory()->create(['order_id' => $partial->id, 'amount' => 300, 'status' => 'paid']);

        $deposit = Order::factory()->create(['order_type' => 'pos', 'outlet_id' => $outlet->id, 'total_amount' => 1000, 'deposit_amount' => 400, 'payment_status' => 'deposit']);
        Payment::factory()->create(['order_id' => $deposit->id, 'amount' => 400, 'status' => 'paid']);

        // Fully paid and pending orders must NOT appear.
        $paid = Order::factory()->create(['order_type' => 'pos', 'outlet_id' => $outlet->id, 'total_amount' => 1000, 'payment_status' => 'paid']);
        Payment::factory()->create(['order_id' => $paid->id, 'amount' => 1000, 'status' => 'paid']);
        Order::factory()->create(['order_type' => 'pos', 'outlet_id' => $outlet->id, 'total_amount' => 1000, 'payment_status' => 'pending']);

        $response = $this->getJson('/api/v1/admin/pos/outstanding-balances')->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(
            [700, 600],
            collect($response->json('data'))->pluck('balance')->all()
        );
    }
}
