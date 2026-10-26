<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The order list row (owner's mock, 2026-10-26): Till Sales, Web Orders, Chat
 * Orders and Quoted Sales all read GET /admin/orders, and each row now says
 * who the customer is (name, phone, customer number), how the order was paid
 * (one state, the method(s)), when it was paid, and what was bought.
 *
 * The additions are display-only and additive: customer_number and
 * payment_summary ride on each row; items were already there. Masking is the
 * route's (contacts.mask) and must still apply to the new row; the extra data
 * must not cost a query per row.
 */
class OrderListRowTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '0712341853';

    private Outlet $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        $this->shop = Outlet::factory()->create();

        DB::table('payment_methods')->updateOrInsert(['code' => 'inmpaybill'], [
            'name' => 'I&M Paybill', 'type' => 'cash', 'is_active' => true,
            'requires_approval' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function as(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        $user->outlets()->attach($this->shop->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $user->fresh();
        Sanctum::actingAs($user);

        return $user;
    }

    private function order(array $extra = []): Order
    {
        return Order::factory()->create(array_merge([
            'order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'pending',
            'outlet_id' => $this->shop->id, 'currency_code' => 'KES', 'total_amount' => 4500,
            'customer_first_name' => 'Jesse', 'customer_last_name' => 'Kariuki',
            'customer_phone' => self::PHONE,
        ], $extra));
    }

    private function pay(Order $order, string $method, float $amount, array $extra = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'order_id' => $order->id, 'payment_method' => $method, 'amount' => $amount,
            'currency_code' => 'KES', 'status' => 'paid', 'paid_at' => now(),
        ], $extra));
    }

    private function row(Order $order): array
    {
        $rows = collect($this->getJson('/api/v1/admin/orders?per_page=50')->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($rows->has($order->id), "order {$order->id} is listed");

        return $rows[$order->id];
    }

    public function test_a_paid_order_row_carries_customer_number_methods_paid_at_and_lines(): void
    {
        $customer = Customer::create(['first_name' => 'Jesse', 'last_name' => 'Kariuki', 'phone' => self::PHONE]);
        $order = $this->order(['customer_id' => $customer->id, 'payment_status' => 'paid']);

        OrderItem::create(['order_id' => $order->id, 'product_id' => null, 'sku' => 'HW-1',
            'product_name' => 'Handwritten', 'variant_name' => 'Item 1', 'quantity' => 1,
            'unit_price' => 3000, 'total_price' => 3000]);
        OrderItem::create(['order_id' => $order->id, 'product_id' => null, 'sku' => 'CUP-50',
            'product_name' => 'Plastic Communion Cups', 'variant_name' => null, 'quantity' => 150,
            'unit_price' => 10, 'total_price' => 1500]);

        // Two methods, paid at different times: paid_at is the LATER one.
        $this->pay($order, 'inmpaybill', 3000, ['paid_at' => Carbon::parse('2026-10-26 10:15:00', 'Africa/Nairobi')]);
        $this->pay($order, 'cash', 1500, ['paid_at' => Carbon::parse('2026-10-26 14:30:00', 'Africa/Nairobi')]);
        // A voided payment is not how it was paid.
        $this->pay($order, 'mukuru', 999, ['status' => 'voided']);

        $this->as('admin');
        $row = $this->row($order);

        $this->assertNotNull($customer->fresh()->customer_number);
        $this->assertSame($customer->fresh()->customer_number, $row['customer_number']);
        $this->assertSame('Jesse Kariuki', $row['customer_name']);
        $this->assertSame(self::PHONE, $row['customer_phone']);

        $summary = $row['payment_summary'];
        $this->assertSame('paid', $summary['state']);
        $byCode = collect($summary['methods'])->keyBy('code');
        $this->assertEqualsCanonicalizing(['inmpaybill', 'cash'], $byCode->keys()->all());
        $this->assertSame('I&M Paybill', $byCode['inmpaybill']['name'], 'the configured name, not the code');
        $this->assertEquals(3000, $byCode['inmpaybill']['amount']);
        $this->assertEquals(1500, $byCode['cash']['amount']);
        // 14:30 Nairobi = 11:30 UTC.
        $this->assertSame('2026-10-26T11:30:00', substr($summary['paid_at'], 0, 19));

        $lines = collect($row['items'])->keyBy('sku');
        $this->assertSame('Item 1', $lines['HW-1']['variant_name']);
        $this->assertSame(150, $lines['CUP-50']['quantity']);
        $this->assertEquals(1500, $lines['CUP-50']['total_price']);

        // The full payments array is not shipped on the list.
        $this->assertArrayNotHasKey('payments', $row);
    }

    public function test_one_state_per_row(): void
    {
        $partial = $this->order(['payment_status' => 'partial']);
        $this->pay($partial, 'cash', 1000);

        $awaiting = $this->order(['payment_status' => 'pending']);
        $this->pay($awaiting, 'mukuru', 4500, ['status' => 'pending', 'paid_at' => null,
            'requires_approval' => true, 'approval_status' => 'pending_review']);

        $unpaid = $this->order(['payment_status' => 'pending']);

        // An old void path left payment_status at 'paid' with nothing settled.
        $voided = $this->order(['status' => 'voided', 'payment_status' => 'paid']);
        $this->pay($voided, 'cash', 4500, ['status' => 'voided']);

        $refunded = $this->order(['status' => 'refunded', 'payment_status' => 'pending']);

        $this->as('admin');

        $this->assertSame('partial', $this->row($partial)['payment_summary']['state']);

        $a = $this->row($awaiting)['payment_summary'];
        $this->assertSame('awaiting_approval', $a['state']);
        $this->assertSame(['mukuru'], array_column($a['methods'], 'code'), 'the method awaiting approval is named');
        $this->assertNull($a['paid_at'], 'nothing settled, so no paid date');

        $u = $this->row($unpaid)['payment_summary'];
        $this->assertSame('unpaid', $u['state']);
        $this->assertSame([], $u['methods']);
        $this->assertNull($u['paid_at']);

        $this->assertNull($this->row($voided)['payment_summary']['state']);
        $this->assertSame('refunded', $this->row($refunded)['payment_summary']['state']);
    }

    public function test_a_masked_role_gets_the_new_row_with_the_phone_still_masked(): void
    {
        $customer = Customer::create(['first_name' => 'Jesse', 'last_name' => 'Kariuki', 'phone' => self::PHONE]);
        $order = $this->order(['customer_id' => $customer->id, 'payment_status' => 'paid']);
        $this->pay($order, 'inmpaybill', 4500);

        $this->as('accountant');
        $response = $this->getJson('/api/v1/admin/orders?per_page=50')->assertOk();
        $this->assertStringNotContainsString(self::PHONE, $response->getContent());

        $row = collect($response->json('data'))->firstWhere('id', $order->id);
        $this->assertSame('07••••1853', $row['customer_phone']);
        $this->assertSame('Jesse Kariuki', $row['customer_name'], 'the name is never masked');
        $this->assertSame($customer->fresh()->customer_number, $row['customer_number']);
        $this->assertSame('paid', $row['payment_summary']['state']);
        $this->assertSame('I&M Paybill', $row['payment_summary']['methods'][0]['name']);
    }

    public function test_the_row_data_does_not_cost_a_query_per_row(): void
    {
        $this->as('admin');
        $make = function () {
            $customer = Customer::create(['first_name' => 'A', 'last_name' => 'B', 'phone' => self::PHONE]);
            $order = $this->order(['customer_id' => $customer->id, 'payment_status' => 'paid']);
            OrderItem::create(['order_id' => $order->id, 'product_id' => null, 'sku' => 'S-' . $order->id,
                'product_name' => 'Stole', 'quantity' => 1, 'unit_price' => 4500, 'total_price' => 4500]);
            $this->pay($order, 'inmpaybill', 4500);
        };

        $make();
        $this->getJson('/api/v1/admin/orders')->assertOk();   // warm caches (permissions, roles)

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/admin/orders')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $one = $count();
        foreach (range(1, 5) as $_) {
            $make();
        }
        $six = $count();

        $this->assertSame($one, $six, "1 row took {$one} queries, 6 rows took {$six}");
    }
}
