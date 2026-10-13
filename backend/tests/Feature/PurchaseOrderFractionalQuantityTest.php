<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\MaterialInventory;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Materials are bought by the metre — 0.25 m, 0.5 m, 0.75 m — and every
 * quantity column on the material path is decimal(12,2). Products are
 * counted in pieces and their stock column is an integer.
 *
 * Pins: a 0.25 m line is ordered, priced, received and returned at 0.25 m
 * exactly (the admin form used to send parseInt(qty), i.e. 0); quantities
 * beyond 2 decimals or below 0.01 are refused (0.001 used to be accepted and
 * stored as 0.00 — a priced zero-quantity line); fractional PRODUCT lines are
 * refused (the PO recorded 2.5 while stock got round(2.5) = 3); and the
 * remaining-balance check survives float noise (ordered 0.30 less received
 * 0.10 is 0.19999999999999998 as a float, which refused the final 0.20 m).
 */
class PurchaseOrderFractionalQuantityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $user = User::factory()->create();
        foreach (['procurement.view', 'procurement.create', 'procurement.receive'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    private function supplierId(): int
    {
        return DB::table('suppliers')->insertGetId([
            'code' => 'SUP-' . uniqid(), 'name' => 'Mint Fabrics',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function material(): Material
    {
        return Material::create([
            'code' => 'MAT-' . uniqid(), 'name' => 'Off-white mint material',
            'category' => 'fabric', 'unit_of_measure' => 'm',
            'unit_cost' => 280, 'is_active' => true,
        ]);
    }

    private function createPo(array $items)
    {
        return $this->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id'            => $this->supplierId(),
            'expected_delivery_date' => now()->addWeek()->toDateString(),
            'currency'               => 'KES',
            'items'                  => $items,
        ]);
    }

    /** An approved PO with one line, built directly (skips approval flow). */
    private function approvedPo(string $type, int $itemId, float $qty, float $received = 0): PurchaseOrderItem
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-FRAC-' . uniqid(), 'supplier_id' => $this->supplierId(),
            'order_date' => now()->toDateString(), 'status' => 'approved',
            'currency_code' => 'KES', 'subtotal' => 100, 'total_amount' => 100,
        ]);

        return PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_type'         => $type,
            $type === 'product' ? 'product_id' : 'material_id' => $itemId,
            'description'       => 'Line',
            'quantity'          => $qty,
            'quantity_received' => $received,
            'unit_price'        => 280,
            'total_price'       => 280 * $qty,
        ]);
    }

    private function receive(PurchaseOrderItem $line, float $qty, int $outletId)
    {
        return $this->postJson("/api/v1/admin/purchase-orders/{$line->purchase_order_id}/receive", [
            'location_type' => 'outlet',
            'outlet_id'     => $outletId,
            'items'         => [['po_item_id' => $line->id, 'quantity_received' => $qty]],
        ]);
    }

    // ── Ordering ──────────────────────────────────────────────────────────────

    public function test_a_quarter_metre_material_line_is_stored_and_priced_exactly(): void
    {
        $mat = $this->material();

        $this->createPo([
            ['type' => 'material', 'item_id' => $mat->id, 'quantity' => 0.25, 'unit_price' => 280],
            ['type' => 'material', 'item_id' => $mat->id, 'quantity' => 1.75, 'unit_price' => 200],
        ])->assertSuccessful();

        $lines = PurchaseOrderItem::orderBy('id')->get();
        $this->assertSame('0.25', (string) $lines[0]->quantity);
        $this->assertSame('1.75', (string) $lines[1]->quantity);

        // Independent arithmetic: 0.25 × 280 + 1.75 × 200 = 70 + 350 = 420.
        $this->assertEquals(420.00, (float) PurchaseOrder::first()->subtotal);
    }

    public function test_quantities_past_two_decimals_or_below_a_hundredth_are_refused(): void
    {
        $mat = $this->material();

        foreach ([0.001, 0.005, 0.255, 0] as $bad) {
            $this->createPo([
                ['type' => 'material', 'item_id' => $mat->id, 'quantity' => $bad, 'unit_price' => 280],
            ])->assertStatus(422);
        }
        $this->assertSame(0, PurchaseOrderItem::count(), 'A zero or truncated line was stored.');
    }

    public function test_a_fractional_product_line_is_refused(): void
    {
        $product = Product::factory()->create();

        $this->createPo([
            ['type' => 'product', 'item_id' => $product->id, 'quantity' => 2.5, 'unit_price' => 100],
        ])->assertStatus(422);

        // Whole pieces are fine — 2.00 is still 2.
        $this->createPo([
            ['type' => 'product', 'item_id' => $product->id, 'quantity' => 2, 'unit_price' => 100],
        ])->assertSuccessful();
    }

    // ── Receiving ─────────────────────────────────────────────────────────────

    public function test_metres_received_in_parts_land_in_material_stock_to_the_hundredth(): void
    {
        $mat    = $this->material();
        $outlet = Outlet::factory()->create();
        $line   = $this->approvedPo('material', $mat->id, 0.30);

        // 0.10 then 0.20: the second used to be refused, because 0.30 − 0.10
        // is 0.19999999999999998 in floating point.
        $this->receive($line, 0.10, $outlet->id)->assertOk();
        $this->receive($line->fresh(), 0.20, $outlet->id)->assertOk();

        $this->assertSame('0.30', (string) $line->fresh()->quantity_received);
        $this->assertEquals(0.30, (float) MaterialInventory::where('material_id', $mat->id)->value('quantity_on_hand'));
        $this->assertSame('received', PurchaseOrder::find($line->purchase_order_id)->status);

        // And nothing beyond what was ordered.
        $this->receive($line->fresh(), 0.01, $outlet->id)->assertStatus(422);
    }

    public function test_a_fractional_product_receipt_is_refused_before_touching_stock(): void
    {
        $product = Product::factory()->create();
        $outlet  = Outlet::factory()->create();
        $line    = $this->approvedPo('product', $product->id, 5);

        $this->receive($line, 2.5, $outlet->id)->assertStatus(422);

        $this->assertSame('0.00', (string) $line->fresh()->quantity_received);
        $this->assertSame(0, DB::table('inventory_items')->where('product_id', $product->id)->sum('quantity_on_hand'));
    }

    // ── Returning ─────────────────────────────────────────────────────────────

    public function test_a_quarter_metre_is_returned_and_leaves_material_stock(): void
    {
        $mat    = $this->material();
        $outlet = Outlet::factory()->create();
        $line   = $this->approvedPo('material', $mat->id, 0.50);
        $this->receive($line, 0.50, $outlet->id)->assertOk();

        $this->postJson("/api/v1/admin/purchase-orders/{$line->purchase_order_id}/return", [
            'items' => [['po_item_id' => $line->id, 'quantity' => 0.25, 'reason' => 'Flawed weave']],
        ])->assertSuccessful();

        $this->assertEquals(0.25, (float) MaterialInventory::where('material_id', $mat->id)->value('quantity_on_hand'));

        // More than was received is still refused.
        $this->postJson("/api/v1/admin/purchase-orders/{$line->purchase_order_id}/return", [
            'items' => [['po_item_id' => $line->id, 'quantity' => 0.51, 'reason' => 'x']],
        ])->assertStatus(422);
    }

    public function test_a_fractional_product_return_is_refused(): void
    {
        $product = Product::factory()->create();
        $line    = $this->approvedPo('product', $product->id, 5, 5);

        $this->postJson("/api/v1/admin/purchase-orders/{$line->purchase_order_id}/return", [
            'items' => [['po_item_id' => $line->id, 'quantity' => 1.5, 'reason' => 'Damaged']],
        ])->assertStatus(422);
    }
}
