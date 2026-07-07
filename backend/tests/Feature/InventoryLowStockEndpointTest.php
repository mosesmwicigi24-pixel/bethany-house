<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GET /inventory/low-stock now reads the live inventory_items ledger (audit
 * INV-1). It previously queried the stale, empty `inventories` table with
 * columns that don't exist there, so the dashboard low-stock widget was broken.
 */
class InventoryLowStockEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function actingWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_low_stock_flags_items_at_or_below_reorder_point_from_inventory_items(): void
    {
        $this->actingWithPermissions(['inventory.view']);

        $low  = InventoryItem::factory()->create(['quantity_on_hand' => 3,  'reorder_point' => 5]);
        $ok   = InventoryItem::factory()->create(['quantity_on_hand' => 50, 'reorder_point' => 5]);
        $zero = InventoryItem::factory()->create(['quantity_on_hand' => 0,  'reorder_point' => 5]);

        $res = $this->getJson('/api/v1/admin/inventory/low-stock');

        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->all();
        $this->assertContains($low->id, $ids);       // below reorder point → low
        $this->assertNotContains($ok->id, $ids);     // healthy stock
        $this->assertNotContains($zero->id, $ids);   // out of stock excluded (quantity_on_hand > 0)
    }

    public function test_low_stock_can_filter_by_outlet(): void
    {
        $this->actingWithPermissions(['inventory.view']);

        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();
        $inA = InventoryItem::factory()->create(['outlet_id' => $outletA->id, 'quantity_on_hand' => 2, 'reorder_point' => 5]);
        $inB = InventoryItem::factory()->create(['outlet_id' => $outletB->id, 'quantity_on_hand' => 2, 'reorder_point' => 5]);

        $res = $this->getJson("/api/v1/admin/inventory/low-stock?outlet_id={$outletA->id}");

        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->all();
        $this->assertContains($inA->id, $ids);
        $this->assertNotContains($inB->id, $ids);
    }
}
