<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductTranslation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "Whose order is this?" — production orders must surface a customer name on
 * the list, resolved across all three identity routes, and be searchable by
 * that name or phone. Previously the name came only from the sales-order
 * snapshot, so POS jobs that carry a customer_id with blank snapshot names
 * showed nothing at all.
 */
class ProductionCustomerIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function actAsManager(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('production_manager', 'sanctum'));
        foreach (['production.view', 'production.raise_order'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function product(string $name = 'Cassock'): Product
    {
        $product = Product::factory()->create(['status' => 'active', 'published_at' => now()->subDay()]);
        ProductTranslation::create([
            'product_id' => $product->id, 'language_code' => 'en', 'name' => $name,
        ]);
        return $product;
    }

    private function customer(string $first, string $last, ?string $phone = null): Customer
    {
        return Customer::create([
            'customer_number' => 'C-' . uniqid(),
            'first_name'      => $first,
            'last_name'       => $last,
            'email'           => strtolower($first . '.' . $last . '.' . uniqid()) . '@example.test',
            'phone'           => $phone,
        ]);
    }

    private function productionOrder(array $attrs = []): ProductionOrder
    {
        return ProductionOrder::create(array_merge([
            'order_number' => 'PO-' . uniqid(),
            'product_id'   => $this->product()->id,
            'quantity'     => 1,
            'status'       => 'draft',
            'priority'     => 'normal',
            'due_date'     => now()->addDays(7),
        ], $attrs));
    }

    private function listOrders(array $params = []): array
    {
        $res = $this->getJson('/api/v1/admin/production-orders?' . http_build_query($params))->assertOk();
        return $res->json('data') ?? [];
    }

    public function test_name_resolves_from_the_sales_order_snapshot(): void
    {
        $this->actAsManager();
        $order = Order::factory()->create([
            'customer_first_name' => 'Michael', 'customer_last_name' => 'Nzyoki',
            'customer_phone' => '0722270441',
        ]);
        $po = $this->productionOrder(['customer_order_id' => $order->id, 'is_customer_order' => true]);

        $row = collect($this->listOrders())->firstWhere('id', $po->id);
        $this->assertSame('Michael Nzyoki', $row['customer_label']);
        $this->assertSame('0722270441', $row['customer_contact']);
    }

    public function test_name_falls_back_to_the_orders_customer_record_when_snapshot_is_blank(): void
    {
        $this->actAsManager();
        // The POS case that used to render an empty cell.
        $customer = $this->customer('Peter', 'Mwangi', '0710881717');
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'customer_first_name' => null, 'customer_last_name' => null, 'customer_phone' => null,
        ]);
        $po = $this->productionOrder(['customer_order_id' => $order->id, 'is_customer_order' => true]);

        $row = collect($this->listOrders())->firstWhere('id', $po->id);
        $this->assertSame('Peter Mwangi', $row['customer_label']);
        $this->assertSame('0710881717', $row['customer_contact']);
    }

    public function test_direct_customer_on_the_production_order_wins(): void
    {
        $this->actAsManager();
        $direct = $this->customer('Grace', 'Wanjiru', '0700111222');
        $order  = Order::factory()->create([
            'customer_first_name' => 'Someone', 'customer_last_name' => 'Else',
        ]);
        $po = $this->productionOrder([
            'customer_id' => $direct->id, 'customer_order_id' => $order->id, 'is_customer_order' => true,
        ]);

        $row = collect($this->listOrders())->firstWhere('id', $po->id);
        $this->assertSame('Grace Wanjiru', $row['customer_label']);
    }

    public function test_stock_job_reports_no_customer(): void
    {
        $this->actAsManager();
        $po = $this->productionOrder(['is_customer_order' => false]);

        $row = collect($this->listOrders())->firstWhere('id', $po->id);
        $this->assertNull($row['customer_label']);
    }

    public function test_search_matches_customer_name_and_phone(): void
    {
        $this->actAsManager();
        $order = Order::factory()->create([
            'customer_first_name' => 'Sumih', 'customer_last_name' => 'Marete',
            'customer_phone' => '0713364212',
        ]);
        $mine = $this->productionOrder(['customer_order_id' => $order->id, 'is_customer_order' => true]);
        $other = $this->productionOrder(['is_customer_order' => false]);

        $byName = collect($this->listOrders(['search' => 'Marete']))->pluck('id');
        $this->assertTrue($byName->contains($mine->id));
        $this->assertFalse($byName->contains($other->id));

        $byPhone = collect($this->listOrders(['search' => '0713364212']))->pluck('id');
        $this->assertTrue($byPhone->contains($mine->id));
    }

    public function test_search_matches_a_customer_reachable_only_through_the_orders_customer_record(): void
    {
        $this->actAsManager();
        $customer = $this->customer('Samuel', 'Otieno', '0704817257');
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'customer_first_name' => null, 'customer_last_name' => null,
        ]);
        $po = $this->productionOrder(['customer_order_id' => $order->id, 'is_customer_order' => true]);

        $ids = collect($this->listOrders(['search' => 'Otieno']))->pluck('id');
        $this->assertTrue($ids->contains($po->id));
    }

    public function test_type_filter_separates_stock_from_customer_jobs(): void
    {
        $this->actAsManager();
        $order = Order::factory()->create(['customer_first_name' => 'Jane', 'customer_last_name' => 'Doe']);
        $customerJob = $this->productionOrder(['customer_order_id' => $order->id, 'is_customer_order' => true]);
        $stockJob    = $this->productionOrder(['is_customer_order' => false]);

        // The admin select has always sent ?type=; nothing read it, so choosing
        // "Customer Orders" silently returned everything.
        $customerIds = collect($this->listOrders(['type' => 'customer']))->pluck('id');
        $this->assertTrue($customerIds->contains($customerJob->id));
        $this->assertFalse($customerIds->contains($stockJob->id));

        $stockIds = collect($this->listOrders(['type' => 'stock']))->pluck('id');
        $this->assertTrue($stockIds->contains($stockJob->id));
        $this->assertFalse($stockIds->contains($customerJob->id));
    }
}
