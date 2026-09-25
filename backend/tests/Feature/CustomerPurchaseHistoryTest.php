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

    /**
     * Spend is recognised income — Order::scopeRecognised, what every report
     * uses. It used to count status='completed' alone, which matched 47 of 817
     * live orders because a till sale is confirmed when paid and stays there:
     * the page read KES 806,915 against the reports' KES 6,087,000.
     */
    public function test_spend_is_recognised_income_not_only_completed_orders(): void
    {
        $customer = Customer::create(['first_name' => 'Half', 'last_name' => 'D', 'email' => 'd@example.test', 'phone' => '0700000004']);
        $this->order(['customer_id' => $customer->id, 'total_amount' => 4000]);                            // completed
        $this->order(['customer_id' => $customer->id, 'total_amount' => 9000, 'status' => 'confirmed']);   // a paid till sale
        $this->order(['customer_id' => $customer->id, 'total_amount' => 500, 'status' => 'processing']);   // being made

        $res = $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk();

        $res->assertJsonPath('stats.total_orders', 3);
        $res->assertJsonPath('stats.recognised_orders', 3);
        $this->assertSame(13500.0, (float) $res->json('stats.total_spent'));
        $this->assertSame(4500.0, (float) $res->json('stats.average_order_value'));
    }

    public function test_a_voided_or_cancelled_order_is_never_spend(): void
    {
        // The case that prompted this: a customer whose only two orders were
        // voided must still read zero, however they were paid.
        $customer = Customer::create(['first_name' => 'John', 'last_name' => '', 'email' => 'john@example.test', 'phone' => '0700000006']);
        $this->order(['customer_id' => $customer->id, 'total_amount' => 350, 'status' => 'voided', 'payment_status' => 'paid']);
        $this->order(['customer_id' => $customer->id, 'total_amount' => 4500, 'status' => 'cancelled', 'payment_status' => 'paid']);

        $res = $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk();

        $res->assertJsonPath('stats.total_orders', 2, 'the orders are still their history');
        $res->assertJsonPath('stats.recognised_orders', 0);
        $this->assertSame(0.0, (float) $res->json('stats.total_spent'));
    }

    public function test_an_unpaid_cart_is_history_but_not_spend(): void
    {
        $customer = Customer::create(['first_name' => 'Cart', 'last_name' => 'F', 'email' => 'f@example.test', 'phone' => '0700000007']);
        $this->order(['customer_id' => $customer->id, 'total_amount' => 2000, 'status' => 'pending', 'payment_status' => 'pending']);

        $res = $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk();

        $res->assertJsonPath('stats.total_orders', 1);
        $res->assertJsonPath('stats.recognised_orders', 0);
        $this->assertSame(0.0, (float) $res->json('stats.total_spent'));
    }

    public function test_a_pending_order_that_was_paid_is_income(): void
    {
        // The payment arm of the house rule: money arrived, so it is a sale
        // whatever queue the order is sitting in.
        $customer = Customer::create(['first_name' => 'Paid', 'last_name' => 'G', 'email' => 'g@example.test', 'phone' => '0700000008']);
        $this->order(['customer_id' => $customer->id, 'total_amount' => 6000, 'status' => 'pending', 'payment_status' => 'paid']);

        $res = $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk();

        $res->assertJsonPath('stats.recognised_orders', 1);
        $this->assertSame(6000.0, (float) $res->json('stats.total_spent'));
    }

    public function test_the_three_cards_that_rendered_blank_now_carry_numbers(): void
    {
        // CustomerDetailPage has always drawn Online / POS / Cancelled cards,
        // and the endpoint never sent those keys, so they read as nothing.
        $customer = Customer::create(['first_name' => 'Cards', 'last_name' => 'I', 'email' => 'i@example.test', 'phone' => '0700000014']);
        $this->order(['customer_id' => $customer->id, 'order_type' => 'pos']);
        $this->order(['customer_id' => $customer->id, 'order_type' => 'pos']);
        $this->order(['customer_id' => $customer->id, 'order_type' => 'online']);
        $this->order(['customer_id' => $customer->id, 'order_type' => 'pos', 'status' => 'voided']);

        $res = $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk();

        $res->assertJsonPath('stats.pos_orders', 3)
            ->assertJsonPath('stats.online_orders', 1)
            ->assertJsonPath('stats.cancelled_orders', 1);
    }

    public function test_the_page_agrees_with_the_house_definition(): void
    {
        // Whatever the page says a customer spent must equal what Order's own
        // recognised scope says — one definition, not a second opinion.
        $customer = Customer::create(['first_name' => 'Agree', 'last_name' => 'H', 'email' => 'h@example.test', 'phone' => '0700000009']);
        foreach ([
            ['completed', 'paid', 1000], ['confirmed', 'paid', 2000], ['processing', 'pending', 3000],
            ['pending', 'pending', 4000], ['voided', 'paid', 5000], ['cancelled', 'paid', 6000],
            ['shipped', 'paid', 7000], ['delivered', 'paid', 8000],
        ] as [$status, $paymentStatus, $amount]) {
            $this->order(['customer_id' => $customer->id, 'status' => $status,
                          'payment_status' => $paymentStatus, 'total_amount' => $amount]);
        }

        $houseRule = (float) Order::where('customer_id', $customer->id)->recognised()->sum('total_amount');
        $page      = (float) $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk()->json('stats.total_spent');

        $this->assertSame($houseRule, $page);
        $this->assertSame(21000.0, $page, 'completed + confirmed + processing + shipped + delivered');
    }
}
