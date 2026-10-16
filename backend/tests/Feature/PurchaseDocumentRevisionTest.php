<?php

namespace Tests\Feature;

use App\Models\DocumentRevision;
use App\Models\GoodsReceivedNote;
use App\Models\GrnItem;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Material;
use App\Models\MaterialInventory;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The owner edits issued purchase documents (owner, 2026-10-16). Decided:
 * every edit is a revision (the old version kept, with the reason); goods
 * already received are corrected by the difference, never rewritten; the
 * owner's edit keeps an approved order approved. Every expected figure below
 * is worked out by hand from the fixture.
 */
class PurchaseDocumentRevisionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $approver;
    private int $supplierId;
    private Outlet $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        $this->owner    = $this->person('super_admin');
        $this->approver = $this->person('admin');
        $this->shop     = Outlet::factory()->create();
        $this->supplierId = DB::table('suppliers')->insertGetId([
            'code' => 'SUP-REV', 'name' => 'Mint Fabrics', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($this->owner);
    }

    private function person(string $role): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function material(string $name = 'Royal blue fabric', float $cost = 150): Material
    {
        return Material::create([
            'code' => 'MAT-' . uniqid(), 'name' => $name, 'category' => 'fabric',
            'unit_of_measure' => 'm', 'unit_cost' => $cost, 'is_active' => true,
        ]);
    }

    /** An approved PO (approved by someone else, so the owner may receive it). */
    private function po(array $lines, float $shipping = 0, string $status = 'approved'): PurchaseOrder
    {
        $subtotal = array_sum(array_map(fn ($l) => $l[2] * $l[3], $lines));
        $po = PurchaseOrder::create([
            'po_number' => 'PO-REV-' . uniqid(), 'supplier_id' => $this->supplierId, 'order_date' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(3)->toDateString(), 'status' => $status, 'currency_code' => 'KES',
            'subtotal' => $subtotal, 'shipping_amount' => $shipping, 'tax_amount' => 0, 'total_amount' => $subtotal + $shipping,
            'created_by' => $this->approver->id, 'approved_by' => $status === 'approved' ? $this->approver->id : null,
        ]);
        foreach ($lines as [$type, $id, $qty, $price]) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $po->id, 'item_type' => $type,
                $type === 'product' ? 'product_id' : 'material_id' => $id,
                'description' => $type === 'product' ? "Product {$id}" : "Material {$id}",
                'quantity' => $qty, 'quantity_received' => 0, 'unit_price' => $price, 'total_price' => $qty * $price,
            ]);
        }

        return $po->fresh('items');
    }

    private function receive(PurchaseOrderItem $line, float $qty, ?int $outletId = null, string $quality = 'passed'): GoodsReceivedNote
    {
        $this->postJson("/api/v1/admin/purchase-orders/{$line->purchase_order_id}/receive", array_filter([
            'location_type' => $outletId ? 'outlet' : 'warehouse',
            'outlet_id'     => $outletId,
            'items'         => [['po_item_id' => $line->id, 'quantity_received' => $qty, 'quality_status' => $quality]],
        ]))->assertOk();

        return GoodsReceivedNote::where('purchase_order_id', $line->purchase_order_id)->latest('id')->firstOrFail();
    }

    private function lines(PurchaseOrder $po): array
    {
        return $po->fresh('items')->items->map(fn ($i) => [
            'id' => $i->id, 'type' => $i->item_type, 'item_id' => $i->item_type === 'product' ? $i->product_id : $i->material_id,
            'quantity' => (float) $i->quantity, 'unit_price' => (float) $i->unit_price,
        ])->all();
    }

    public function test_the_owner_edits_lines_and_header_and_the_old_version_is_kept(): void
    {
        $p1 = Product::factory()->create();
        $p2 = Product::factory()->create();
        $m  = $this->material();
        // 30 × 450 + 10 × 200 + shipping 500 = 16,000.
        $po = $this->po([['product', $p1->id, 30, 450], ['material', $m->id, 10, 200]], 500);
        $this->assertSame('16000.00', (string) $po->total_amount);

        $lines = $this->lines($po);
        $lines[0]['quantity'] = 28;
        $lines[0]['unit_price'] = 460;
        unset($lines[1]);                                                    // remove the material
        $lines[] = ['type' => 'product', 'item_id' => $p2->id, 'quantity' => 5, 'unit_price' => 100];

        $res = $this->putJson("/api/v1/admin/purchase-orders/{$po->id}/revise", [
            'reason' => 'Supplier confirmed 28 pieces at 460', 'items' => array_values($lines), 'tax' => 500,
        ])->assertOk();

        // 28 × 460 = 12,880 + 5 × 100 = 500 → 13,380 + shipping 500 + tax 500 = 14,380.
        $po->refresh();
        $this->assertSame('14380.00', (string) $po->total_amount);
        $this->assertSame('13380.00', (string) $po->subtotal);
        $this->assertSame(1, $po->revision);
        $this->assertSame('approved', $po->status, "the owner's edit keeps it approved");
        $this->assertSame($this->approver->id, $po->approved_by);
        $this->assertCount(2, $po->items);
        $this->assertContains('Edited and re-approved by the owner.', $res->json('notes'));

        $rev = DocumentRevision::where('document_type', 'purchase_order')->where('document_id', $po->id)->sole();
        $this->assertSame(0, $rev->revision);
        $this->assertSame('16000.00', (string) $rev->snapshot['total_amount']);
        $this->assertCount(2, $rev->snapshot['items']);
        $this->assertSame('Supplier confirmed 28 pieces at 460', $rev->reason);
        $this->assertContains("Product {$p1->id}: quantity 30 → 28", array_map(fn ($c) => str_replace($p1->sku, "Product {$p1->id}", $c), $rev->changes));
        $this->assertDatabaseHas('activity_log', ['event' => 'po_revised', 'subject_id' => $po->id, 'causer_id' => $this->owner->id]);

        // History is listed, and a revision never changes.
        $this->getJson("/api/v1/admin/purchase-orders/{$po->id}/revisions")->assertOk()
            ->assertJsonPath('current_revision', 1)->assertJsonPath('revisions.0.revision', 0);
        $this->expectException(\LogicException::class);
        $rev->update(['reason' => 'rewritten']);
    }

    public function test_received_lines_are_protected_and_a_price_correction_moves_only_the_latest_cost(): void
    {
        $m  = $this->material('Silk', 150);
        $po = $this->po([['material', $m->id, 20, 200]]);
        $line = $po->items->first();
        $this->receive($line, 10, $this->shop->id);
        $this->assertSame('200.00', (string) $m->fresh()->unit_cost, 'receiving sets the latest cost');
        $this->assertSame('partially_received', $po->fresh()->status);

        $lines = $this->lines($po);
        $lines[0]['quantity'] = 8;
        $this->putJson("/api/v1/admin/purchase-orders/{$po->id}/revise", ['reason' => 'Fewer pieces', 'items' => $lines])
            ->assertStatus(422);
        $this->putJson("/api/v1/admin/purchase-orders/{$po->id}/revise", [
            'reason' => 'Swap', 'items' => [['type' => 'material', 'item_id' => $this->material('Other')->id, 'quantity' => 20, 'unit_price' => 200]],
        ])->assertStatus(422);   // the received line cannot be removed

        // Price 200 → 220 on goods in stock: this line is the latest receipt, so the cost follows.
        $lines[0]['quantity'] = 20;
        $lines[0]['unit_price'] = 220;
        $res = $this->putJson("/api/v1/admin/purchase-orders/{$po->id}/revise", ['reason' => 'Invoice says 220', 'items' => $lines])->assertOk();
        $this->assertSame('220.00', (string) $m->fresh()->unit_cost);
        $this->assertStringContainsString('stock cost corrected to KES 220.00', implode(' ', $res->json('notes')));
        $this->assertSame('partially_received', $po->fresh()->status);

        // A later order receives the same material at 250: correcting the first again leaves 250.
        $later = $this->po([['material', $m->id, 5, 250]]);
        $this->travel(1)->days();
        $this->receive($later->items->first(), 5, $this->shop->id);
        $this->assertSame('250.00', (string) $m->fresh()->unit_cost);
        $lines[0]['unit_price'] = 230;
        $res = $this->putJson("/api/v1/admin/purchase-orders/{$po->id}/revise", ['reason' => 'Credit note 230', 'items' => $lines])->assertOk();
        $this->assertSame('250.00', (string) $m->fresh()->unit_cost, 'a later receipt set the current cost');
        $this->assertStringContainsString('a later receipt set the current cost', implode(' ', $res->json('notes')));
        $this->assertSame(2, $po->fresh()->revision);
    }

    public function test_a_po_waiting_for_approval_goes_back_to_draft_and_a_cancelled_one_is_not_edited(): void
    {
        $p  = Product::factory()->create();
        $po = $this->po([['product', $p->id, 4, 100]], 0, 'pending_approval');
        $lines = $this->lines($po);
        $lines[0]['quantity'] = 6;
        $this->putJson("/api/v1/admin/purchase-orders/{$po->id}/revise", ['reason' => 'Two more needed', 'items' => $lines])->assertOk();
        $this->assertSame('draft', $po->fresh()->status);
        $this->assertSame('600.00', (string) $po->fresh()->total_amount);

        $po->update(['status' => 'cancelled']);
        $this->putJson("/api/v1/admin/purchase-orders/{$po->id}/revise", ['reason' => 'Try again', 'items' => $lines])->assertStatus(422);
        // Nothing changed → refused, no empty revision.
        $po->update(['status' => 'draft']);
        $this->putJson("/api/v1/admin/purchase-orders/{$po->id}/revise", ['reason' => 'No change at all', 'items' => $this->lines($po)])->assertStatus(422);
        $this->assertSame(1, DocumentRevision::where('document_id', $po->id)->count());
    }

    public function test_only_the_owner_revises_or_corrects(): void
    {
        $m  = $this->material();
        $po = $this->po([['material', $m->id, 5, 100]]);
        $grn = $this->receive($po->items->first(), 5, $this->shop->id);

        Sanctum::actingAs($this->approver);   // an admin, with every procurement permission
        $this->putJson("/api/v1/admin/purchase-orders/{$po->id}/revise", ['reason' => 'Not mine to change', 'items' => $this->lines($po)])
            ->assertForbidden();
        $this->postJson("/api/v1/admin/grn/{$grn->id}/corrections", [
            'reason' => 'Not mine to change', 'lines' => [['grn_item_id' => $grn->items()->first()->id, 'quantity_received' => 4]],
        ])->assertForbidden();
        $this->assertSame('5.00', (string) $po->items->first()->fresh()->quantity_received);
    }

    public function test_correcting_a_goods_receipt_moves_stock_and_serials_by_the_difference(): void
    {
        $p  = Product::factory()->create();
        $po = $this->po([['product', $p->id, 30, 450]]);
        $line = $po->items->first();
        $grn  = $this->receive($line, 10);   // warehouse
        $item = $grn->items()->first();
        $stock = fn () => (int) InventoryItem::where(['product_id' => $p->id, 'product_variant_id' => null, 'outlet_id' => null])->value('quantity_on_hand');
        $inStock = fn () => ProductSerial::where('product_id', $p->id)->where('status', ProductSerial::IN_STOCK)->count();
        $this->assertSame(10, $stock());
        $this->assertSame(10, $inStock());

        // Counted twice: 10 → 8. Two units leave stock, two serials are cancelled.
        $this->postJson("/api/v1/admin/grn/{$grn->id}/corrections", [
            'reason' => 'Recount: one box counted twice', 'lines' => [['grn_item_id' => $item->id, 'quantity_received' => 8]],
        ])->assertOk()->assertJsonPath('grn.revision', 1);
        $this->assertSame(8, $stock());
        $this->assertSame(8, $inStock());
        $this->assertSame(2, ProductSerial::where('product_id', $p->id)->where('status', ProductSerial::CANCELLED)->count());
        $this->assertSame('8.00', (string) $line->fresh()->quantity_received);
        $this->assertSame('8.00', (string) $item->fresh()->quantity_received);
        $move = InventoryTransaction::where('transaction_type', 'purchase_correction')->sole();
        $this->assertSame(-2, (int) $move->quantity_change);
        $this->assertSame(GoodsReceivedNote::class, $move->reference_type);
        $this->assertSame($grn->id, (int) $move->reference_id);
        $this->assertSame('10.00', (string) DocumentRevision::where('document_type', 'goods_receipt')->sole()->snapshot['items'][0]['quantity_received']);

        // 8 → 12: four more come in, with four new serials.
        $this->postJson("/api/v1/admin/grn/{$grn->id}/corrections", [
            'reason' => 'Second carton found', 'lines' => [['grn_item_id' => $item->id, 'quantity_received' => 12]],
        ])->assertOk();
        $this->assertSame(12, $stock());
        $this->assertSame(12, $inStock());
        $this->assertSame('partially_received', $po->fresh()->status);

        // Never more than ordered (30).
        $this->postJson("/api/v1/admin/grn/{$grn->id}/corrections", [
            'reason' => 'Way too many', 'lines' => [['grn_item_id' => $item->id, 'quantity_received' => 31]],
        ])->assertStatus(422);

        // Nine sold: only three on the shelf. Taking seven back is refused, nothing moves.
        InventoryItem::where(['product_id' => $p->id, 'outlet_id' => null])->update(['quantity_on_hand' => 3]);
        ProductSerial::where('product_id', $p->id)->where('status', ProductSerial::IN_STOCK)->orderBy('id')->limit(9)->update(['status' => ProductSerial::SOLD]);
        $this->postJson("/api/v1/admin/grn/{$grn->id}/corrections", [
            'reason' => 'Overcounted after all', 'lines' => [['grn_item_id' => $item->id, 'quantity_received' => 5]],
        ])->assertStatus(422);
        $this->assertSame(3, $stock());
        $this->assertSame('12.00', (string) $line->fresh()->quantity_received);
        $this->assertSame(2, $grn->fresh()->revision);
    }

    public function test_correcting_materials_and_rejected_goods(): void
    {
        $m  = $this->material();
        $po = $this->po([['material', $m->id, 12, 100]]);
        $grn = $this->receive($po->items->first(), 10.5, $this->shop->id);
        $onHand = fn () => (float) MaterialInventory::where(['material_id' => $m->id, 'outlet_id' => $this->shop->id])->value('quantity_on_hand');
        $this->assertSame(10.5, $onHand());

        $this->postJson("/api/v1/admin/grn/{$grn->id}/corrections", [
            'reason' => 'Measured again', 'lines' => [['grn_item_id' => $grn->items()->first()->id, 'quantity_received' => 10.25]],
        ])->assertOk();
        $this->assertSame(10.25, $onHand());
        $this->assertSame('partially_received', $po->fresh()->status, '10.25 of 12 received');

        // Rejected goods never entered stock: correcting the count moves nothing.
        $p   = Product::factory()->create();
        $po2 = $this->po([['product', $p->id, 6, 50]]);
        $rej = $this->receive($po2->items->first(), 4, null, 'rejected');
        $this->postJson("/api/v1/admin/grn/{$rej->id}/corrections", [
            'reason' => 'Only 3 were damaged', 'lines' => [['grn_item_id' => $rej->items()->first()->id, 'quantity_received' => 3]],
        ])->assertOk();
        $this->assertSame(0, InventoryTransaction::where('transaction_type', 'purchase_correction')->count());
        $this->assertSame('3.00', (string) GrnItem::where('grn_id', $rej->id)->value('quantity_rejected'));
    }

    public function test_the_suppliers_invoice_is_recorded_at_any_status_but_cancelled(): void
    {
        $m  = $this->material();
        $po = $this->po([['material', $m->id, 5, 100]]);
        $this->receive($po->items->first(), 5, $this->shop->id);
        $this->assertSame('received', $po->fresh()->status);

        // Whoever raises orders records the supplier's invoice.
        Sanctum::actingAs($this->person('procurement_manager'));
        $this->putJson("/api/v1/admin/purchase-orders/{$po->id}", [
            'supplier_invoice_number' => 'MF-2291', 'supplier_invoice_date' => '2026-10-15', 'supplier_invoice_amount' => 520,
        ])->assertOk();
        $po->refresh();
        $this->assertSame('MF-2291', $po->supplier_invoice_number);
        $this->assertSame('520.00', (string) $po->supplier_invoice_amount);

        // Anything else on a received order is still refused; the old field name now lands.
        $this->putJson("/api/v1/admin/purchase-orders/{$po->id}", ['notes' => 'late change'])->assertStatus(422);
        $this->putJson("/api/v1/admin/purchase-orders/{$po->id}", ['invoice_number' => 'MF-2291-A'])->assertOk();
        $this->assertSame('MF-2291-A', $po->fresh()->supplier_invoice_number);

        $po->update(['status' => 'cancelled']);
        $this->putJson("/api/v1/admin/purchase-orders/{$po->id}", ['supplier_invoice_number' => 'X'])->assertStatus(422);
    }

    public function test_documents_show_their_revision_and_the_receipt_pdf_routes_work(): void
    {
        $p  = Product::factory()->create();
        $po = $this->po([['product', $p->id, 2, 100]]);
        $lines = $this->lines($po);
        $lines[0]['quantity'] = 3;
        $this->putJson("/api/v1/admin/purchase-orders/{$po->id}/revise", [
            'reason' => 'One more', 'items' => $lines, 'supplier_invoice_number' => 'INV-77',
        ])->assertOk();

        $html = \App\Services\PdfService::purchaseOrder($po->fresh()->toArray());
        $this->assertStringContainsString('Rev 1', $html);
        $this->assertStringContainsString('INV-77', $html);

        $grn = $this->receive($po->fresh('items')->items->first(), 3);
        // These two routes pointed at methods that never existed.
        $this->get("/api/v1/admin/grn/{$grn->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post("/api/v1/admin/grn/{$grn->id}/print")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
