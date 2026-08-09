<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\InventoryItem;
use App\Models\Material;
use App\Models\Outlet;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: App\Models\Inventory::adjustQuantity (the legacy `inventories`
 * pool used by the admin Livewire flows — POS NewSale, StockTransfers,
 * GoodsReceipt, PosReturns, StockAdjustments) used to insert its ledger row
 * with `inventory_id`, a column inventory_transactions does not have. The key
 * wasn't in InventoryTransaction::$fillable either, so it was silently dropped
 * and the insert died on the inventory_item_id NOT NULL constraint — every
 * admin Livewire stock mutation threw and rolled back its whole transaction.
 *
 * The fix maps the ledger row onto the inventory_items row for the same
 * product/variant/outlet (creating it at zero when absent), so admin flows
 * land on the same COGS ledger as the API controllers.
 */
class LegacyInventoryLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function legacyRow(array $attrs = []): Inventory
    {
        $outlet  = Outlet::factory()->create();
        $product = Product::factory()->create();

        return Inventory::create(array_merge([
            'inventory_type'   => 'product',
            'product_id'       => $product->id,
            'outlet_id'        => $outlet->id,
            'quantity_on_hand' => 50,
            'cost_per_unit'    => 750,
        ], $attrs));
    }

    public function test_adjustment_writes_a_ledger_row_keyed_to_the_matching_inventory_item(): void
    {
        $legacy = $this->legacyRow();

        $legacy->adjustQuantity(-3, 'sale', 'order', 12345);

        $this->assertSame(47, (int) $legacy->fresh()->quantity_on_hand);

        $item = InventoryItem::where('product_id', $legacy->product_id)
            ->whereNull('product_variant_id')
            ->where('outlet_id', $legacy->outlet_id)
            ->first();
        $this->assertNotNull($item, 'the matching inventory_items row should be auto-created');

        $this->assertDatabaseHas('inventory_transactions', [
            'inventory_item_id' => $item->id,
            'transaction_type'  => 'sale',
            'reference_type'    => 'order',
            'reference_id'      => 12345,
            'quantity_change'   => -3,
            'quantity_before'   => 50,
            'quantity_after'    => 47,
        ]);
    }

    public function test_adjustment_reuses_an_existing_inventory_item_row(): void
    {
        $legacy = $this->legacyRow();
        $item   = InventoryItem::factory()->create([
            'product_id' => $legacy->product_id,
            'outlet_id'  => $legacy->outlet_id,
        ]);

        $legacy->adjustQuantity(10, 'purchase', 'grn', 7);

        $this->assertSame(1, InventoryItem::count());
        $this->assertDatabaseHas('inventory_transactions', [
            'inventory_item_id' => $item->id,
            'transaction_type'  => 'purchase',
            'quantity_change'   => 10,
        ]);
    }

    public function test_material_rows_adjust_without_throwing_and_without_a_product_ledger_row(): void
    {
        $outlet   = Outlet::factory()->create();
        $material = Material::create([
            'code'            => 'MAT-TEST-1',
            'name'            => 'Test Cotton',
            'unit_of_measure' => 'meter',
        ]);
        $legacy = Inventory::create([
            'inventory_type'   => 'material',
            'material_id'      => $material->id,
            'outlet_id'        => $outlet->id,
            'quantity_on_hand' => 20,
        ]);

        $legacy->adjustQuantity(-5, 'manual');

        $this->assertSame(15, (int) $legacy->fresh()->quantity_on_hand);
        // No inventory_items counterpart exists for materials, so no
        // inventory_transactions row can be written from this path.
        $this->assertDatabaseCount('inventory_transactions', 0);
    }
}
