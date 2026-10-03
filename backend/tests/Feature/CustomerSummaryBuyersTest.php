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
 * The customer summary must count the people who actually bought.
 *
 * It counted distinct `user_id` with `whereNotNull('user_id')`. A walk-in never
 * gets a login, and 683 of 684 customers are walk-ins, so unique buyers, repeat
 * buyers, the repeat rate and returning buyers all read ZERO — while 566 people
 * bought in Q3 2026 (measured 2026-09-29). The same defect class as the customer
 * page fixed in #377.
 *
 * Buyers are now keyed on the customer record, with the web account as a second
 * arm, prefixed so the two id spaces cannot collide.
 */
class CustomerSummaryBuyersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $staff = User::factory()->create();
        \Tests\ReportAccess::grantPages($staff);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($staff);
    }

    private function customer(string $name): Customer
    {
        return Customer::create([
            'first_name' => $name, 'last_name' => 'T',
            'email' => strtolower($name) . '@example.test',
            'phone' => '07' . random_int(10000000, 99999999),
        ]);
    }

    private function order(array $attributes): Order
    {
        // created_at is not mass-assignable, so it is stamped after the fact —
        // without this every order lands on today and the window tests pass or
        // fail by accident of the calendar.
        $placedAt = $attributes['created_at'] ?? '2026-09-15 10:00:00';
        unset($attributes['created_at']);

        $order = Order::create(array_merge([
            'order_number'   => 'POS-' . bin2hex(random_bytes(4)),
            'status'         => 'completed',
            'payment_status' => 'paid',
            'currency_code'  => 'KES',
            'subtotal'       => 1000,
            'total_amount'   => 1000,
        ], $attributes));

        $order->forceFill(['created_at' => $placedAt])->saveQuietly();

        return $order->refresh();
    }

    private function summary(string $from = '2026-09-01', string $to = '2026-09-30'): array
    {
        return $this->getJson("/api/v1/admin/reports/customers/summary?start_date={$from}&end_date={$to}")
            ->assertOk()->json();
    }

    public function test_walk_in_buyers_are_counted(): void
    {
        $walkIn = $this->customer('Boniface');
        $this->assertNull($walkIn->user_id, 'the case that read zero');
        $this->order(['customer_id' => $walkIn->id]);

        $this->assertSame(1, (int) $this->summary()['unique_buyers']);
    }

    public function test_a_customer_who_bought_twice_is_one_buyer_and_a_repeat_buyer(): void
    {
        $twice = $this->customer('Mary');
        $this->order(['customer_id' => $twice->id]);
        $this->order(['customer_id' => $twice->id]);
        $once = $this->customer('Grace');
        $this->order(['customer_id' => $once->id]);

        $s = $this->summary();

        $this->assertSame(2, (int) $s['unique_buyers'], 'two people, three orders');
        $this->assertSame(1, (int) $s['repeat_buyers']);
        $this->assertSame(50.0, (float) $s['repeat_purchase_rate']);
    }

    public function test_a_web_customer_is_still_counted_through_their_login(): void
    {
        $user = User::factory()->create();
        $customer = $this->customer('Web');
        $customer->update(['user_id' => $user->id]);
        $this->order(['user_id' => $user->id]);   // placed on the website, no customer link

        $this->assertSame(1, (int) $this->summary()['unique_buyers']);
    }

    public function test_a_customer_and_a_user_are_never_confused_for_one_buyer(): void
    {
        // customer 1 and user 1 must not collapse into a single key.
        $walkIn = $this->customer('Walk');
        $webUser = User::factory()->create();
        $this->order(['customer_id' => $walkIn->id]);
        $this->order(['user_id' => $webUser->id]);

        $this->assertSame(2, (int) $this->summary()['unique_buyers'], 'two different people');
    }

    public function test_returning_buyers_are_those_who_bought_before_the_window(): void
    {
        $returning = $this->customer('Returning');
        $this->order(['customer_id' => $returning->id, 'created_at' => '2026-07-01 09:00:00']);
        $this->order(['customer_id' => $returning->id]);
        $fresh = $this->customer('Fresh');
        $this->order(['customer_id' => $fresh->id]);

        $s = $this->summary();

        $this->assertSame(2, (int) $s['unique_buyers']);
        $this->assertSame(1, (int) $s['returning_buyers'], 'only the one with an earlier order');
    }

    public function test_an_unlinked_order_counts_as_nobody(): void
    {
        // 219 orders carry no customer at all. They are sales, but they are not
        // a buyer anyone can name, and they must not inflate the count.
        $this->order([]);
        $known = $this->customer('Known');
        $this->order(['customer_id' => $known->id]);

        $this->assertSame(1, (int) $this->summary()['unique_buyers']);
    }

    public function test_orders_outside_the_window_do_not_count(): void
    {
        $customer = $this->customer('Earlier');
        $this->order(['customer_id' => $customer->id, 'created_at' => '2026-08-15 10:00:00']);

        $this->assertSame(0, (int) $this->summary()['unique_buyers']);
    }
}
