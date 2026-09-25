<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A customer's page must show the orders that customer placed.
 *
 * It read `user_id` alone, which is null for 683 of 684 customers — a walk-in
 * has no login — so `user_id = NULL` matched nothing and every till customer's
 * history showed empty while their orders sat in the table. The orders could
 * not be found the other way either: mass assignment had been dropping
 * `customer_id` since the POS was written (fixed 2026-09-25, #376).
 *
 * Both arms are pinned here, including the one that made it look fine in
 * testing: a customer WITH a web login kept working throughout.
 */
class CustomerPurchaseHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $staff = User::factory()->create();
        // Spend history is gated behind customers.insights — without it the
        // endpoint deliberately answers with identity only and stats: null.
        foreach (['customers.view', 'customers.insights'] as $p) {
            $staff->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($staff);
    }

    private function order(array $attributes): Order
    {
        return Order::create(array_merge([
            'order_number'   => 'POS-' . bin2hex(random_bytes(4)),
            'status'         => 'completed',
            'payment_status' => 'paid',
            'currency_code'  => 'KES',
            'subtotal'       => 3000,
            'total_amount'   => 3000,
        ], $attributes));
    }

    public function test_a_walk_in_customer_sees_the_orders_placed_at_the_till(): void
    {
        $customer = Customer::create([
            'first_name' => 'Boniface', 'last_name' => 'Karanja',
            'email' => 'boniface@example.test', 'phone' => '0727891989',
        ]);
        $this->assertNull($customer->user_id, 'a walk-in has no login — this is the case that was broken');

        $this->order(['customer_id' => $customer->id, 'total_amount' => 3000]);
        $this->order(['customer_id' => $customer->id, 'total_amount' => 5000]);

        $res = $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk();

        $res->assertJsonPath('stats.total_orders', 2);
        $this->assertSame(8000.0, (float) $res->json('stats.total_spent'));
        $this->assertCount(2, $res->json('customer.orders'));
    }

    public function test_a_customer_with_a_web_login_still_sees_theirs(): void
    {
        $user = User::factory()->create();
        $customer = Customer::create([
            'user_id' => $user->id, 'first_name' => 'Grace', 'last_name' => 'W',
            'email' => 'grace@example.test', 'phone' => '0722000111',
        ]);

        $this->order(['user_id' => $user->id, 'total_amount' => 7000]);           // placed on the website
        $this->order(['customer_id' => $customer->id, 'total_amount' => 1000]);   // placed at the till

        $res = $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk();

        $res->assertJsonPath('stats.total_orders', 2, 'both arms count');
        $this->assertSame(8000.0, (float) $res->json('stats.total_spent'));
    }

    public function test_one_customer_never_sees_another_customers_orders(): void
    {
        $mine  = Customer::create(['first_name' => 'Mine', 'last_name' => 'A', 'email' => 'a@example.test', 'phone' => '0700000001']);
        $yours = Customer::create(['first_name' => 'Yours', 'last_name' => 'B', 'email' => 'b@example.test', 'phone' => '0700000002']);

        $this->order(['customer_id' => $mine->id]);
        $this->order(['customer_id' => $yours->id]);
        $this->order([]);   // a walk-in nobody claimed: customer_id and user_id both null

        $this->getJson("/api/v1/admin/customers/{$mine->id}")->assertOk()
            ->assertJsonPath('stats.total_orders', 1);
    }

    public function test_a_customer_with_nothing_yet_reads_as_nothing_not_as_everything(): void
    {
        $customer = Customer::create(['first_name' => 'New', 'last_name' => 'C', 'email' => 'c@example.test', 'phone' => '0700000003']);
        $this->order([]);   // an unclaimed order — must not be attributed to them

        $res = $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk();

        $res->assertJsonPath('stats.total_orders', 0);
        $this->assertSame(0.0, (float) $res->json('stats.total_spent'));
        $this->assertSame([], $res->json('customer.orders'));
    }

    public function test_without_the_insights_permission_no_history_is_returned_at_all(): void
    {
        $picker = User::factory()->create();
        $picker->givePermissionTo(Permission::findOrCreate('customers.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($picker);

        $customer = Customer::create(['first_name' => 'Gated', 'last_name' => 'E', 'email' => 'e@example.test', 'phone' => '0700000005']);
        $this->order(['customer_id' => $customer->id]);

        // The picker view is identity and contact only — by design, not by accident.
        $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk()
            ->assertJsonPath('stats', null);
    }

    public function test_only_completed_orders_count_toward_what_they_have_spent(): void
    {
        $customer = Customer::create(['first_name' => 'Half', 'last_name' => 'D', 'email' => 'd@example.test', 'phone' => '0700000004']);
        $this->order(['customer_id' => $customer->id, 'total_amount' => 4000]);
        $this->order(['customer_id' => $customer->id, 'total_amount' => 9000, 'status' => 'pending']);

        $res = $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk();

        $res->assertJsonPath('stats.total_orders', 2, 'both orders are theirs');
        $this->assertSame(4000.0, (float) $res->json('stats.total_spent'), 'only the completed one is spend');
    }
}
