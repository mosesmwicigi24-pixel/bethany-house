<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Customer geography intelligence — "which country has more customers?"
 * Resolved purely from order geography (customer → shipping → billing), one
 * country per customer (their latest order). Guests count toward orders/revenue
 * but not the customer head-count; currencies are never mixed across countries.
 * GET /admin/intelligence/customer-geography.
 */
class CustomerGeographyTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    private int $seq = 0;

    private function geography(): array
    {
        return $this->getJson('/api/v1/admin/intelligence/customer-geography')->assertOk()->json();
    }

    /** No CustomerFactory in this app — create the row directly. */
    private function newCustomer(): Customer
    {
        $this->seq++;
        return Customer::create([
            'first_name' => 'Cust',
            'last_name'  => "No{$this->seq}",
            'phone'      => '+2547' . str_pad((string) $this->seq, 8, '0', STR_PAD_LEFT),
        ]);
    }

    private function customerOrder(string $country, string $currency, float $total, array $over = []): Order
    {
        $customer = $this->newCustomer();
        return Order::factory()->create(array_merge([
            'customer_id'           => $customer->id,
            'user_id'               => $customer->user_id,
            'customer_country_code' => $country,
            'currency_code'         => $currency,
            'total_amount'          => $total,
            'status'                => 'completed',
        ], $over));
    }

    public function test_countries_are_ranked_by_customer_head_count(): void
    {
        $this->actAs();

        // Kenya: 2 customers. Uganda: 1 customer.
        $this->customerOrder('KE', 'KES', 5000);
        $this->customerOrder('KE', 'KES', 3000);
        $this->customerOrder('UG', 'USD', 40);

        $data = $this->geography();

        $this->assertSame('KE', $data['countries'][0]['country_code']);
        $this->assertSame(2, $data['countries'][0]['customers']);
        $this->assertSame('KE', $data['summary']['top_country_code']);
        $this->assertSame(3, $data['summary']['located_customers']);
        $this->assertSame(2, $data['summary']['distinct_countries']);
    }

    public function test_a_customer_is_counted_once_where_their_latest_order_is(): void
    {
        $this->actAs();

        $customer = $this->newCustomer();
        // Older order from Kenya, newer from Tanzania → counts as Tanzania, once.
        Order::factory()->create([
            'customer_id' => $customer->id, 'user_id' => $customer->user_id,
            'customer_country_code' => 'KE', 'currency_code' => 'KES',
            'total_amount' => 1000, 'status' => 'completed',
            'created_at' => now()->subDays(30),
        ]);
        Order::factory()->create([
            'customer_id' => $customer->id, 'user_id' => $customer->user_id,
            'customer_country_code' => 'TZ', 'currency_code' => 'USD',
            'total_amount' => 50, 'status' => 'completed',
            'created_at' => now()->subDay(),
        ]);

        $data = $this->geography();

        $this->assertSame(1, $data['summary']['located_customers']);
        $this->assertSame('TZ', $data['summary']['top_country_code']);
    }

    public function test_cancelled_orders_are_ignored(): void
    {
        $this->actAs();

        $this->customerOrder('KE', 'KES', 5000);
        $this->customerOrder('UG', 'USD', 40, ['status' => 'cancelled']);

        $data = $this->geography();

        $codes = array_column($data['countries'], 'country_code');
        $this->assertContains('KE', $codes);
        $this->assertNotContains('UG', $codes);
    }

    public function test_guest_orders_count_toward_orders_not_customers(): void
    {
        $this->actAs();

        $this->customerOrder('KE', 'KES', 5000);
        // Guest order (no identity) in Kenya — adds to orders/revenue, not head-count.
        Order::factory()->create([
            'customer_id' => null, 'user_id' => null,
            'customer_country_code' => 'KE', 'currency_code' => 'KES',
            'total_amount' => 2000, 'status' => 'completed',
        ]);

        $ke = collect($this->geography()['countries'])->firstWhere('country_code', 'KE');

        $this->assertSame(1, $ke['customers']);   // one identified customer
        $this->assertSame(2, $ke['orders']);      // both orders
        $this->assertSame('KES', $ke['currency']);
    }
}
