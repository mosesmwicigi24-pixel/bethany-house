<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\InventoryItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * New Purchase Order — "Add all variants" (owner, 2026-10-15).
 *
 * The form now orders a variable product by variant: one PO line per variant
 * with a quantity, priced from the variant's KES book cost. Pins: six variant
 * lines in one request are six lines carrying their variant, with the right
 * subtotal; a variant from another product is refused; the same variant twice
 * is merged at one price and refused at two; the approval band is judged on
 * the whole PO total, as before; receiving a variant line puts the stock on
 * that variant; the pre-fill endpoint gives the variant's KES cost (else the
 * product's), the cost in a foreign PO's currency at the reporting rate, and
 * nothing to a viewer who may not see cost.
 */
class PurchaseOrderVariantLinesTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;   // raises POs (procurement.create, products.view_cost)
    private User $pm;        // procurement manager — first PO band
    private User $fm;        // finance manager — second PO band

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        ReportingCurrency::forget();

        $this->officer = $this->user('procurement_officer');
        $this->pm      = $this->user('procurement_manager');
        $this->fm      = $this->user('finance_manager');
    }

    private function user(string $role): User
    {
        $u = User::factory()->create(['status' => 'active']);
        $u->assignRole(Role::findOrCreate($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function supplierId(): int
    {
        return DB::table('suppliers')->insertGetId([
            'code' => 'SUP-' . uniqid(), 'name' => 'Stole Makers',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A stole in six colours, as CLE-S-009. @return array{0: Product, 1: list<ProductVariant>} */
    private function stole(): array
    {
        $product = Product::factory()->create();
        $variants = [];
        foreach (['Black', 'White', 'Navy', 'Red', 'Green', 'Grey'] as $colour) {
            $variants[] = ProductVariant::factory()->create([
                'product_id' => $product->id,
                'variant_name' => "Single Sided Stole + {$colour} Embroidery and Plain",
                'attributes' => ['Colour' => $colour, 'Features' => 'Embroidery and Plain'],
                'is_active' => true,
            ]);
        }

        return [$product, $variants];
    }

    private function createPo(array $items, string $currency = 'KES')
    {
        Sanctum::actingAs($this->officer);

        return $this->postJson('/api/v1/admin/purchase-orders', [
            'supplier_id'            => $this->supplierId(),
            'expected_delivery_date' => now()->addWeek()->toDateString(),
            'currency'               => $currency,
            'items'                  => $items,
        ]);
    }

    private function line(Product $p, ?ProductVariant $v, int $qty, float $price): array
    {
        return ['type' => 'product', 'item_id' => $p->id, 'product_variant_id' => $v?->id, 'quantity' => $qty, 'unit_price' => $price];
    }

    public function test_six_variant_lines_in_one_request_are_six_lines_with_the_right_total(): void
    {
        [$product, $variants] = $this->stole();
        $qtys = [10, 8, 6, 4, 2, 1];
        $items = array_map(fn ($v, $q) => $this->line($product, $v, $q, 1100), $variants, $qtys);

        $id = $this->createPo($items)->assertCreated()->json('purchase_order.id');

        $lines = PurchaseOrderItem::where('purchase_order_id', $id)->orderBy('id')->get();
        $this->assertCount(6, $lines);
        foreach ($variants as $i => $v) {
            $this->assertSame($v->id, (int) $lines[$i]->product_variant_id);
            $this->assertSame($product->id, (int) $lines[$i]->product_id);
            $this->assertEquals($qtys[$i], (float) $lines[$i]->quantity);
            $this->assertEquals($qtys[$i] * 1100, (float) $lines[$i]->total_price);
            $this->assertStringStartsWith($v->sku, $lines[$i]->description);
        }

        // Independent arithmetic: (10+8+6+4+2+1) × 1,100 = 31 × 1,100 = 34,100.
        $po = PurchaseOrder::findOrFail($id);
        $this->assertEquals(34100.00, (float) $po->subtotal);
        $this->assertEquals(34100.00, (float) $po->total_amount);
    }

    public function test_the_same_variant_twice_at_one_price_is_one_line_with_the_quantities_added(): void
    {
        [$product, $variants] = $this->stole();

        $id = $this->createPo([
            $this->line($product, $variants[0], 3, 1100),
            $this->line($product, $variants[1], 2, 1100),
            $this->line($product, $variants[0], 4, 1100),
        ])->assertCreated()->json('purchase_order.id');

        $lines = PurchaseOrderItem::where('purchase_order_id', $id)->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame($variants[0]->id, (int) $lines[0]->product_variant_id);
        $this->assertEquals(7, (float) $lines[0]->quantity);
        $this->assertEquals(7700, (float) $lines[0]->total_price);
        // 7 × 1,100 + 2 × 1,100 = 9,900.
        $this->assertEquals(9900.00, (float) PurchaseOrder::findOrFail($id)->subtotal);
    }

    public function test_the_same_variant_at_two_prices_is_refused_and_nothing_is_stored(): void
    {
        [$product, $variants] = $this->stole();

        $this->createPo([
            $this->line($product, $variants[0], 3, 1100),
            $this->line($product, $variants[0], 4, 1200),
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'twice at different unit prices'));

        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame(0, PurchaseOrderItem::count());
    }

    public function test_a_variant_of_another_product_is_refused(): void
    {
        [$product] = $this->stole();
        [, $otherVariants] = $this->stole();

        $this->createPo([$this->line($product, $otherVariants[0], 1, 1100)])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'is not a variant of'));

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_a_material_line_cannot_name_a_variant(): void
    {
        [, $variants] = $this->stole();
        $materialId = DB::table('materials')->insertGetId([
            'code' => 'MAT-' . uniqid(), 'name' => 'Satin', 'unit_of_measure' => 'm',
            'unit_cost' => 280, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->createPo([['type' => 'material', 'item_id' => $materialId, 'product_variant_id' => $variants[0]->id, 'quantity' => 1, 'unit_price' => 280]])
            ->assertStatus(422);
    }

    public function test_the_approval_band_is_judged_on_the_whole_po_total(): void
    {
        [$product, $variants] = $this->stole();
        // Each line alone is 50,000 — under the procurement manager's 100,000.
        // The PO is 6 × 50,000 = 300,000: procurement manager + finance.
        $items = array_map(fn ($v) => $this->line($product, $v, 50, 1000), $variants);
        $id = $this->createPo($items)->assertCreated()->json('purchase_order.id');

        $this->postJson("/api/v1/admin/purchase-orders/{$id}/submit")->assertOk();

        $r = ApprovalRequest::where('event', 'purchase_order')->where('approvable_id', $id)->sole();
        $this->assertEquals(300000, (float) $r->amount_kes);
        $this->assertSame(['procurement.approve', 'approvals.finance_sign'], array_column($r->bands, 'permission'));
    }

    public function test_receiving_a_variant_line_puts_the_stock_on_that_variant(): void
    {
        [$product, $variants] = $this->stole();
        $outlet = Outlet::factory()->create();
        $id = $this->createPo([
            $this->line($product, $variants[2], 5, 1100),
            $this->line($product, $variants[3], 2, 1100),
        ])->assertCreated()->json('purchase_order.id');
        // Approval is not under test here.
        PurchaseOrder::whereKey($id)->update(['status' => 'approved', 'approved_by' => $this->pm->id]);
        $navy = PurchaseOrderItem::where('purchase_order_id', $id)->where('product_variant_id', $variants[2]->id)->sole();

        Sanctum::actingAs($this->user('procurement_officer'));   // not the approver
        $this->postJson("/api/v1/admin/purchase-orders/{$id}/receive", [
            'location_type' => 'outlet',
            'outlet_id'     => $outlet->id,
            'items'         => [['po_item_id' => $navy->id, 'quantity_received' => 5]],
        ])->assertOk();

        $stock = InventoryItem::where('product_variant_id', $variants[2]->id)->where('outlet_id', $outlet->id)->sole();
        $this->assertSame(5, (int) $stock->quantity_on_hand);
        $this->assertSame($product->id, (int) $stock->product_id);
        $this->assertFalse(InventoryItem::where('product_variant_id', $variants[3]->id)->exists(), 'the red line was not received');
        $this->assertFalse(InventoryItem::where('product_id', $product->id)->whereNull('product_variant_id')->exists(), 'nothing landed on the bare product');
        $this->assertSame('partially_received', PurchaseOrder::findOrFail($id)->status);
    }

    public function test_the_prefill_gives_each_variant_its_kes_cost_else_the_products(): void
    {
        [$product, $variants] = $this->stole();
        ProductPrice::create(['product_id' => $product->id, 'product_variant_id' => null, 'currency_code' => 'KES', 'regular_price' => 2500, 'cost_price' => 1100]);
        ProductPrice::create(['product_id' => $product->id, 'product_variant_id' => $variants[0]->id, 'currency_code' => 'KES', 'regular_price' => 2500, 'cost_price' => 1280]);
        // A variant KES row with no cost does not shadow the product's cost.
        ProductPrice::create(['product_id' => $product->id, 'product_variant_id' => $variants[1]->id, 'currency_code' => 'KES', 'regular_price' => 2500, 'cost_price' => null]);
        // A cost on a USD row is never read.
        ProductPrice::create(['product_id' => $product->id, 'product_variant_id' => $variants[1]->id, 'currency_code' => 'USD', 'regular_price' => 30, 'cost_price' => 99]);
        Sanctum::actingAs($this->officer);

        // JSON drops a whole float's ".0", so money is compared by value.
        $kes = $this->getJson("/api/v1/admin/purchase-orders/item-costs/{$product->id}?currency=KES")->assertOk()
            ->assertJsonPath('cost_visible', true)->assertJsonCount(6, 'variants')
            ->assertJsonPath('variants.5.attributes.Colour', 'Grey');
        $this->assertEquals(1100, $kes->json('product.kes_cost'));
        $this->assertEquals(1100, $kes->json('product.unit_price'));
        $this->assertEquals(1280, $kes->json('variants.0.kes_cost'));
        $this->assertEquals(1280, $kes->json('variants.0.unit_price'));
        $this->assertEquals(1100, $kes->json('variants.1.kes_cost'), 'a variant row with no cost falls back to the product');
        $this->assertEquals(1100, $kes->json('variants.5.kes_cost'));

        // USD at the reporting rate (128 KES): 1,280 / 128 = 10.00; 1,100 / 128 = 8.59.
        // That is the rate the approval engine reads the PO back into KES with.
        $usd = $this->getJson("/api/v1/admin/purchase-orders/item-costs/{$product->id}?currency=USD")->assertOk();
        $this->assertEquals(128, $usd->json('rate_to_kes'));
        $this->assertEquals(10.00, $usd->json('variants.0.unit_price'));
        $this->assertEquals(8.59, $usd->json('variants.1.unit_price'));

        // EUR has no reporting rate here: no figure, never a guess.
        $eur = $this->getJson("/api/v1/admin/purchase-orders/item-costs/{$product->id}?currency=EUR")->assertOk()
            ->assertJsonPath('rate_missing', true)
            ->assertJsonPath('variants.0.unit_price', null);
        $this->assertEquals(1280, $eur->json('variants.0.kes_cost'));
    }

    public function test_the_prefill_gives_no_cost_to_a_viewer_who_may_not_see_cost(): void
    {
        [$product] = $this->stole();
        ProductPrice::create(['product_id' => $product->id, 'product_variant_id' => null, 'currency_code' => 'KES', 'regular_price' => 2500, 'cost_price' => 1100]);

        $creator = User::factory()->create(['status' => 'active']);
        foreach (['procurement.view', 'procurement.create'] as $perm) {
            $creator->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($perm, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($creator->fresh());

        $this->getJson("/api/v1/admin/purchase-orders/item-costs/{$product->id}?currency=KES")->assertOk()
            ->assertJsonPath('cost_visible', false)
            ->assertJsonPath('product.kes_cost', null)
            ->assertJsonPath('variants.0.kes_cost', null)
            ->assertJsonPath('variants.0.unit_price', null);
    }
}
