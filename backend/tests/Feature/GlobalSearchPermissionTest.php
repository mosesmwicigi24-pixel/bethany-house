<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 1C, item 3 — the ⌘K search answers only with what you could open.
 *
 * GET /admin/search is open to every staff login and returned customers (with
 * email and phone), orders, suppliers and purchase orders to anyone who typed
 * two letters — a tailor included. Orders went through Eloquent, so the
 * ViewerScope applied; but DataScopeResolver answers "all" when NO role
 * grants orders.view, so a tailor (who holds no orders.view at all) saw the
 * whole order book. Each type is now gated by the permission that opens it.
 */
class GlobalSearchPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    /** One matching record of every type, all containing "Zebra". */
    private function world(?User $orderOwner = null): array
    {
        $product = Product::factory()->create(['sku' => 'ZEBRA-STOLE']);
        ProductTranslation::create(['product_id' => $product->id, 'language_code' => 'en', 'name' => 'Zebra Stole']);

        Customer::create([
            'customer_number' => 'C-' . uniqid(), 'first_name' => 'Zebra', 'last_name' => 'Kamau',
            'email' => 'zebra.kamau@example.test', 'phone' => '0711000999',
        ]);

        $mine   = Order::factory()->create(['customer_first_name' => 'Zebra', 'customer_last_name' => 'Mine',
            'created_by' => $orderOwner?->id]);
        $theirs = Order::factory()->create(['customer_first_name' => 'Zebra', 'customer_last_name' => 'Theirs',
            'created_by' => User::factory()->create()->id]);

        $supplierId = DB::table('suppliers')->insertGetId([
            'code' => 'SUP-Z', 'name' => 'Zebra Textiles', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_orders')->insert([
            'po_number' => 'PO-ZEBRA-1', 'supplier_id' => $supplierId,
            'order_date' => now()->format('Y-m-d'), 'status' => 'draft',
            'subtotal' => 100, 'total_amount' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$mine, $theirs];
    }

    private function search(): array
    {
        return collect($this->getJson('/api/v1/admin/search?q=Zebra')->assertOk()->json('results'))
            ->groupBy('type')->map->all()->all();
    }

    public function test_a_tailor_gets_nothing_back(): void
    {
        $this->world();
        $this->actAs('tailor');

        $this->assertSame([], $this->search(),
            'a tailor holds none of orders/customers/procurement/products.view');
    }

    public function test_a_cashier_sees_her_own_orders_and_the_customer_picker_but_not_procurement(): void
    {
        $clerk = $this->actAs('pos_clerk');
        [$mine, $theirs] = $this->world($clerk);

        $byType = $this->search();

        $orderIds = collect($byType['order'] ?? [])->pluck('id')->all();
        $this->assertContains($mine->id, $orderIds);
        $this->assertNotContains($theirs->id, $orderIds, 'own-scope: another clerk\'s sale must not appear');
        $this->assertArrayHasKey('customer', $byType);
        $this->assertArrayHasKey('product', $byType);
        $this->assertArrayNotHasKey('supplier', $byType);
        $this->assertArrayNotHasKey('purchase_order', $byType);
    }

    public function test_procurement_sees_suppliers_and_purchase_orders_but_not_customers_or_orders(): void
    {
        $this->world();
        $this->actAs('procurement_officer');

        $byType = $this->search();

        $this->assertArrayHasKey('supplier', $byType);
        $this->assertArrayHasKey('purchase_order', $byType);
        $this->assertArrayHasKey('product', $byType);
        $this->assertArrayNotHasKey('customer', $byType);
        $this->assertArrayNotHasKey('order', $byType);
    }

    public function test_admin_sees_every_type(): void
    {
        $this->world();
        $this->actAs('admin');

        $byType = $this->search();

        foreach (['product', 'order', 'customer', 'supplier', 'purchase_order'] as $type) {
            $this->assertArrayHasKey($type, $byType, $type);
        }
        $this->assertCount(2, $byType['order'], 'admin is not narrowed');
    }
}
