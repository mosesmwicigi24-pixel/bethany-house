<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Capture-once, then locked. A walk-in / guest order can have its customer
 * details filled in at any time — including after it's completed, e.g. a
 * receipt printed before the cashier keyed the name in. Once details are
 * captured they are frozen: attach refuses to overwrite them.
 * (OrderController::attachCustomer, POST /admin/orders/{id}/attach-customer.)
 */
class OrderAttachCustomerTest extends TestCase
{
    use RefreshDatabase;

    private function actAsEditor(): User
    {
        // The reporter is a Super Admin; Gate::before clears the route's
        // orders.edit|orders.create middleware for them.
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
        return $user;
    }

    private function attach(int $orderId, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/admin/orders/{$orderId}/attach-customer", $body);
    }

    public function test_a_completed_guest_order_can_still_have_customer_details_added(): void
    {
        $this->actAsEditor();

        // The reported scenario: a POS walk-in sale, already completed, with
        // no customer captured (the receipt came out blank).
        $order = Order::factory()->create([
            'status'              => 'completed',
            'customer_id'         => null,
            'user_id'             => null,
            'customer_first_name' => null,
            'customer_last_name'  => null,
            'customer_phone'      => null,
        ]);

        $this->attach($order->id, [
            'new_customer' => ['first_name' => 'Rev. Mwangi', 'last_name' => 'Kamau', 'phone' => '+254727891989'],
        ])->assertOk();

        $order->refresh();
        $this->assertSame('Rev. Mwangi', $order->customer_first_name);
        $this->assertSame('+254727891989', $order->customer_phone);
    }

    public function test_once_captured_the_customer_is_locked_and_cannot_be_overwritten(): void
    {
        $this->actAsEditor();

        $order = Order::factory()->create([
            'status'              => 'completed',
            'customer_first_name' => 'Original',
            'customer_last_name'  => 'Buyer',
            'customer_phone'      => '+254700000000',
        ]);

        $this->attach($order->id, [
            'new_customer' => ['first_name' => 'Someone', 'last_name' => 'Else', 'phone' => '+254711111111'],
        ])->assertStatus(422)
          ->assertJsonPath('reason', 'customer_locked');

        // Untouched.
        $order->refresh();
        $this->assertSame('Original', $order->customer_first_name);
        $this->assertSame('+254700000000', $order->customer_phone);
    }

    public function test_a_phone_only_capture_still_locks_the_order(): void
    {
        $this->actAsEditor();

        // Even a bare phone number counts as captured — nothing may overwrite it.
        $order = Order::factory()->create([
            'status'              => 'processing',
            'customer_first_name' => null,
            'customer_last_name'  => null,
            'customer_phone'      => '+254733333333',
        ]);

        $this->attach($order->id, [
            'new_customer' => ['first_name' => 'Late', 'last_name' => 'Name', 'phone' => '+254744444444'],
        ])->assertStatus(422)
          ->assertJsonPath('reason', 'customer_locked');
    }
}
