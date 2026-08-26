<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The unconfirmed-order work queue.
 *
 * Recognition correctly stopped counting abandoned carts as revenue. This is
 * the report that stops that being the end of the story — the list a human
 * works through to confirm the real orders and kill the dead ones.
 */
class OrderPipelineQueueTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));

        return $user;
    }

    private function cart(float $amount, int $ageDays, string $type = 'whatsapp', array $extra = []): Order
    {
        return Order::factory()->create(array_merge([
            'order_type'     => $type,
            'status'         => 'pending',
            'payment_status' => 'pending',
            'currency_code'  => 'KES',
            'total_amount'   => $amount,
            'created_at'     => now()->subDays($ageDays),
        ], $extra));
    }

    public function test_it_lists_only_unconfirmed_carts(): void
    {
        $cart = $this->cart(1000, 3);
        $confirmed = Order::factory()->create([
            'status' => 'confirmed', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 5000,
        ]);
        $paidButPending = Order::factory()->create([
            'status' => 'pending', 'payment_status' => 'paid',
            'currency_code' => 'KES', 'total_amount' => 7000,
        ]);

        $res = $this->actingAs($this->viewer(), 'sanctum')->getJson('/api/v1/admin/reports/order-pipeline');
        $res->assertOk();

        $ids = collect($res->json('orders'))->pluck('id')->all();
        $this->assertContains($cart->id, $ids);
        $this->assertNotContains($confirmed->id, $ids, 'a confirmed order is income, not queue work');
        $this->assertNotContains($paidButPending->id, $ids, 'money arrived — it is income, not an abandoned cart');
    }

    public function test_aging_buckets_partition_the_queue(): void
    {
        $this->cart(100, 1);     // fresh
        $this->cart(200, 20);    // recent
        $this->cart(400, 60);    // stale
        $this->cart(800, 200);   // dormant

        $res = $this->actingAs($this->viewer(), 'sanctum')->getJson('/api/v1/admin/reports/order-pipeline');
        $res->assertOk();

        $aging = collect($res->json('aging'));
        $this->assertSame(1, $aging->firstWhere('key', 'fresh')['orders']);
        $this->assertSame(1, $aging->firstWhere('key', 'recent')['orders']);
        $this->assertSame(1, $aging->firstWhere('key', 'stale')['orders']);
        $this->assertSame(1, $aging->firstWhere('key', 'dormant')['orders']);

        // Every order lands in exactly one bucket, so the buckets reconcile.
        $this->assertSame(
            (int) $res->json('summary.orders'),
            (int) $aging->sum('orders'),
            'aging buckets must sum to the queue total or a row is hidden',
        );
        $this->assertSame(1500.0, (float) $aging->sum('value'));
    }

    public function test_the_queue_leads_with_the_biggest_recoverable_money(): void
    {
        $this->cart(500, 90);
        $big = $this->cart(90000, 1);
        $this->cart(2000, 30);

        $res = $this->actingAs($this->viewer(), 'sanctum')->getJson('/api/v1/admin/reports/order-pipeline');

        $this->assertSame($big->id, (int) $res->json('orders.0.id'));
    }

    public function test_sorting_by_age_puts_the_oldest_first(): void
    {
        $this->cart(90000, 1);
        $oldest = $this->cart(500, 120);

        $res = $this->actingAs($this->viewer(), 'sanctum')
            ->getJson('/api/v1/admin/reports/order-pipeline?sort=age');

        $this->assertSame($oldest->id, (int) $res->json('orders.0.id'));
        $this->assertSame(120, (int) $res->json('orders.0.age_days'));
    }

    public function test_it_carries_the_contact_details_needed_to_act_on_a_cart(): void
    {
        $this->cart(1000, 5, 'whatsapp', [
            'customer_first_name' => 'Grace',
            'customer_last_name'  => 'Wanjiru',
            'customer_phone'      => '254722000111',
        ]);

        $res = $this->actingAs($this->viewer(), 'sanctum')->getJson('/api/v1/admin/reports/order-pipeline');

        $this->assertSame('Grace Wanjiru', $res->json('orders.0.customer_name'));
        $this->assertSame('254722000111', $res->json('orders.0.customer_phone'));
        $this->assertSame('whatsapp', $res->json('orders.0.channel'));
    }

    public function test_foreign_currency_carts_are_counted_at_the_reporting_rate(): void
    {
        // This test used to assert the opposite — that a USD cart was listed
        // but excluded from the total, because there was no rate to convert it
        // with and adding dollars to shillings would have been a lie. There is
        // a reporting rate now (128 KES to 1 USD), so the honest answer changed
        // from "cannot say" to a number.
        $this->cart(1000, 2);                                   // KES 1,000
        $usd = $this->cart(50, 2, 'whatsapp', ['currency_code' => 'USD']);  // => KES 6,400

        $res = $this->actingAs($this->viewer(), 'sanctum')->getJson('/api/v1/admin/reports/order-pipeline');

        $this->assertSame(7400.0, (float) $res->json('summary.value'),
            '1,000 KES + (50 USD x 128) = 7,400');
        $this->assertSame(0, (int) $res->json('summary.excluded_non_kes_orders'));

        $row = collect($res->json('orders'))->firstWhere('id', $usd->id);
        $this->assertSame(50.0, (float) $row['total_amount'], 'the native amount is still shown');
        $this->assertSame('USD', $row['currency_code']);
        $this->assertSame(6400.0, (float) $row['total_kes']);
    }

    public function test_a_currency_with_no_reporting_rate_is_listed_but_never_summed(): void
    {
        // The honesty that survives: a rate we do not have is not invented.
        DB::table('currencies')->updateOrInsert(
            ['code' => 'GBP'],
            ['name' => 'Pound', 'symbol' => '£', 'exchange_rate' => 1,
             'reporting_rate_to_kes' => null, 'is_base' => false, 'is_active' => true],
        );
        \App\Support\ReportingCurrency::forget();

        $this->cart(1000, 2);
        $gbp = $this->cart(80, 2, 'whatsapp', ['currency_code' => 'GBP']);

        $res = $this->actingAs($this->viewer(), 'sanctum')->getJson('/api/v1/admin/reports/order-pipeline');

        $this->assertSame(1000.0, (float) $res->json('summary.value'),
            'a currency with no reporting rate must not reach the total');
        $this->assertSame(1, (int) $res->json('summary.excluded_non_kes_orders'));
        $this->assertContains($gbp->id, collect($res->json('orders'))->pluck('id')->all(),
            'but it is still work, so it stays in the queue');
    }

    public function test_the_queue_needs_reports_view(): void
    {
        $nobody = User::factory()->create();
        $nobody->assignRole(Role::findOrCreate('admin', 'sanctum'));

        $this->actingAs($nobody, 'sanctum')
            ->getJson('/api/v1/admin/reports/order-pipeline')
            ->assertForbidden();
    }
}
