<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\MaterialAllocation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductionOrder;
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
 * Where a production job's materials come from (owner, 2026-11-18/19).
 *
 * Sending a made-to-order line to production, staff choose per material:
 * FROM STORE (reserved for the job) or NEEDS BUYING (onto the supplier's open
 * draft purchase order, one line per job, later jobs joining the same draft).
 * Sales staff never see who the supplier is.
 *
 * The fixture, worked by hand: a shirt takes 2.5 m of fabric and 8 buttons.
 * Two shirts need 5 m and 16 buttons. The store holds 1 m of fabric and 120
 * buttons. Fabric was last paid for at KES 450 a metre.
 */
class MaterialSourcingTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private Product $shirt;
    private Material $fabric;
    private Material $buttons;
    private int $dk;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->outlet = Outlet::factory()->create();
        $this->shirt  = Product::factory()->create(['is_producible' => true]);

        $this->fabric  = $this->material('White cotton fabric', 'm', 400, onHand: 1);
        $this->buttons = $this->material('Shirt buttons', 'pcs', 5, onHand: 120);
        $bom = DB::table('bills_of_materials')->insertGetId(['product_id' => $this->shirt->id, 'version' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bom_items')->insert([
            ['bom_id' => $bom, 'material_id' => $this->fabric->id, 'quantity' => 2.5, 'unit_of_measure' => 'm', 'created_at' => now(), 'updated_at' => now()],
            ['bom_id' => $bom, 'material_id' => $this->buttons->id, 'quantity' => 8, 'unit_of_measure' => 'pcs', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->dk = $this->supplier('DK Textiles');
        $this->fabric->update(['preferred_supplier_id' => $this->dk]);
        // Fabric was last paid for at 450 (its unit cost on file says 400).
        $old = PurchaseOrder::create(['po_number' => 'PO-OLD-1', 'supplier_id' => $this->dk, 'order_date' => now()->subMonth()->toDateString(), 'status' => 'received', 'currency_code' => 'KES', 'subtotal' => 4500, 'total_amount' => 4500]);
        PurchaseOrderItem::create(['purchase_order_id' => $old->id, 'item_type' => 'material', 'material_id' => $this->fabric->id, 'description' => 'Fabric', 'quantity' => 10, 'quantity_received' => 10, 'unit_price' => 450, 'total_price' => 4500]);
    }

    private function material(string $name, string $unit, float $cost, float $onHand): Material
    {
        $m = Material::create(['code' => 'MAT-' . uniqid(), 'name' => $name, 'category' => 'fabric', 'unit_of_measure' => $unit, 'unit_cost' => $cost, 'is_active' => true]);
        DB::table('material_inventory')->insert(['material_id' => $m->id, 'outlet_id' => $this->outlet->id, 'quantity_on_hand' => $onHand, 'quantity_reserved' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return $m;
    }

    private function supplier(string $name): int
    {
        return DB::table('suppliers')->insertGetId(['code' => 'SUP-' . uniqid(), 'name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function actAs(array $permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function sales(): User
    {
        return $this->actAs(['orders.view', 'production.view', 'production.raise_order', 'inventory.view']);
    }

    private function procurement(): User
    {
        return $this->actAs(['procurement.view', 'procurement.create', 'procurement.receive', 'inventory.view', 'inventory.adjust', 'production.view']);
    }

    /** A paid order with one line of $qty shirts. */
    private function line(int $qty = 2): array
    {
        $order = Order::factory()->create(['status' => 'processing', 'payment_status' => 'paid', 'outlet_id' => $this->outlet->id]);
        $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $this->shirt->id, 'product_name' => 'White Shirt', 'sku' => 'SH-1', 'quantity' => $qty, 'unit_price' => 2500, 'total_price' => 2500 * $qty]);

        return [$order, $item];
    }

    private function raise(array $line, array $materials)
    {
        return $this->postJson("/api/v1/admin/orders/{$line[0]->id}/items/{$line[1]->id}/production", ['materials' => $materials]);
    }

    private function free(Material $m): float
    {
        return (float) DB::table('material_inventory')->where('material_id', $m->id)->sum('quantity_available');
    }

    // ── The window ────────────────────────────────────────────────────────────

    public function test_the_window_lists_what_the_line_needs_and_what_is_free_without_naming_a_supplier(): void
    {
        $this->sales();
        [$order, $item] = $this->line(2);

        $res = $this->getJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}/production-materials")->assertOk();

        $res->assertJsonPath('has_bom', true);
        $rows = collect($res->json('materials'))->keyBy('material_id');
        $this->assertCount(2, $rows);
        $this->assertSame(['White cotton fabric', 'm'], [$rows[$this->fabric->id]['name'], $rows[$this->fabric->id]['unit']]);
        $this->assertEqualsWithDelta(5.0, (float) $rows[$this->fabric->id]['required'], 0.001);      // 2 × 2.5 m
        $this->assertEqualsWithDelta(1.0, (float) $rows[$this->fabric->id]['free'], 0.001);
        $this->assertEqualsWithDelta(16.0, (float) $rows[$this->buttons->id]['required'], 0.001);    // 2 × 8
        $this->assertEqualsWithDelta(120.0, (float) $rows[$this->buttons->id]['free'], 0.001);
        $this->assertSame(['material_id', 'name', 'unit', 'required', 'free'], array_keys($rows[$this->fabric->id]));
        $this->assertStringNotContainsString('DK Textiles', $res->getContent());
        $this->assertStringNotContainsString('supplier', $res->getContent());
        $this->assertStringNotContainsString('450', $res->getContent());           // no price either
    }

    public function test_a_product_with_no_bill_of_materials_is_raised_as_before(): void
    {
        $this->sales();
        $plain = Product::factory()->create(['is_producible' => true]);
        $order = Order::factory()->create(['status' => 'processing', 'payment_status' => 'paid']);
        $item  = OrderItem::create(['order_id' => $order->id, 'product_id' => $plain->id, 'product_name' => 'Stole', 'sku' => 'ST-1', 'quantity' => 1, 'unit_price' => 100, 'total_price' => 100]);

        $this->getJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}/production-materials")->assertOk()->assertExactJson(['has_bom' => false, 'materials' => []]);
        $this->postJson("/api/v1/admin/orders/{$order->id}/items/{$item->id}/production", [])->assertOk()->assertJsonPath('materials_status', 'none');
        $this->assertSame(0, PurchaseOrder::where('status', 'draft')->count());
    }

    // ── From store ────────────────────────────────────────────────────────────

    public function test_from_store_reserves_the_quantity_so_a_second_job_cannot_claim_it(): void
    {
        $this->sales();
        // 16 buttons from the store; all 5 m of fabric bought.
        $res = $this->raise($this->line(2), [
            ['material_id' => $this->buttons->id, 'from_store' => 16],
            ['material_id' => $this->fabric->id, 'to_buy' => 5],
        ])->assertOk()->assertJsonPath('materials_status', 'waiting');

        $this->assertEqualsWithDelta(104.0, $this->free($this->buttons), 0.001);       // 120 − 16
        $a = MaterialAllocation::where('production_order_id', $res->json('production_order_id'))->where('material_id', $this->buttons->id)->first();
        $this->assertEqualsWithDelta(16.0, (float) $a->quantity_reserved, 0.001);
        $this->assertNotNull($a->sourced_at);

        // 14 shirts want 112 buttons from the store: only 104 are free.
        $this->raise($this->line(14), [
            ['material_id' => $this->buttons->id, 'from_store' => 112],
            ['material_id' => $this->fabric->id, 'to_buy' => 35],
        ])->assertStatus(422)->assertJsonValidationErrors('materials.0.from_store');
        $this->assertEqualsWithDelta(104.0, $this->free($this->buttons), 0.001, 'a refused job reserves nothing');
        $this->assertSame(1, ProductionOrder::count(), 'and is not raised');
    }

    public function test_the_two_figures_must_add_up_to_what_the_job_needs(): void
    {
        $this->sales();
        $this->raise($this->line(2), [
            ['material_id' => $this->fabric->id, 'from_store' => 1, 'to_buy' => 3],      // 4, not 5
            ['material_id' => $this->buttons->id, 'from_store' => 16],
        ])->assertStatus(422)->assertJsonValidationErrors('materials.0');
        $this->assertSame(0, ProductionOrder::count());
        $this->assertEqualsWithDelta(120.0, $this->free($this->buttons), 0.001);
    }

    // ── Needs buying ──────────────────────────────────────────────────────────

    public function test_what_is_bought_joins_the_suppliers_open_draft_one_line_per_job(): void
    {
        $this->sales();
        // Split: 1 m from the store, 4 m bought.
        $first = $this->raise($this->line(2), [
            ['material_id' => $this->fabric->id, 'from_store' => 1, 'to_buy' => 4],
            ['material_id' => $this->buttons->id, 'from_store' => 16],
        ])->assertOk();
        $this->assertStringNotContainsString('DK', $first->getContent());

        $draft = PurchaseOrder::where('status', 'draft')->sole();
        $this->assertSame($this->dk, (int) $draft->supplier_id);
        $this->assertTrue($draft->collects_production_needs);
        $this->assertSame('KES', $draft->currency_code);
        $line = $draft->items()->sole();
        $this->assertSame($first->json('production_order_id'), (int) $line->production_order_id);
        $this->assertEqualsWithDelta(4.0, (float) $line->quantity, 0.001);
        $this->assertEqualsWithDelta(450.0, (float) $line->unit_price, 0.001);       // last price paid, not the 400 on file
        $this->assertEqualsWithDelta(1800.0, (float) $draft->total_amount, 0.001);   // 4 × 450
        $this->assertEqualsWithDelta(0.0, $this->free($this->fabric), 0.001);         // the 1 m is held

        // A second customer: one shirt, 2.5 m, all bought. Same draft.
        $second = $this->raise($this->line(1), [
            ['material_id' => $this->fabric->id, 'to_buy' => 2.5],
            ['material_id' => $this->buttons->id, 'from_store' => 8],
        ])->assertOk();
        $this->assertSame(1, PurchaseOrder::where('status', 'draft')->count());
        $this->assertSame(2, $draft->items()->count());
        $this->assertEqualsWithDelta(6.5, (float) $draft->items()->sum('quantity'), 0.001);
        $this->assertEqualsWithDelta(2925.0, (float) $draft->fresh()->total_amount, 0.001);   // 6.5 × 450

        // Submitted for approval, the draft is closed: the next need starts a new one.
        $draft->update(['status' => 'pending_approval']);
        $this->raise($this->line(1), [
            ['material_id' => $this->fabric->id, 'to_buy' => 2.5],
            ['material_id' => $this->buttons->id, 'from_store' => 8],
        ])->assertOk();
        $this->assertNotSame($draft->id, PurchaseOrder::where('status', 'draft')->sole()->id);
        $this->assertSame($second->json('production_order_id'), (int) $draft->items()->orderByDesc('id')->first()->production_order_id);
    }

    public function test_a_manual_draft_for_the_same_supplier_is_left_alone(): void
    {
        $this->sales();
        $manual = PurchaseOrder::create(['po_number' => 'PO-MANUAL', 'supplier_id' => $this->dk, 'order_date' => now()->toDateString(), 'status' => 'draft', 'currency_code' => 'KES', 'subtotal' => 0, 'total_amount' => 0]);

        $this->raise($this->line(2), [['material_id' => $this->fabric->id, 'to_buy' => 5], ['material_id' => $this->buttons->id, 'from_store' => 16]])->assertOk();

        $this->assertSame(0, $manual->items()->count());
        $this->assertSame(2, PurchaseOrder::where('status', 'draft')->count());
    }

    public function test_a_material_with_no_supplier_waits_and_is_placed_when_procurement_sets_one(): void
    {
        $this->sales();
        // Buttons have no preferred supplier and no purchase history.
        $this->raise($this->line(2), [['material_id' => $this->fabric->id, 'to_buy' => 5], ['material_id' => $this->buttons->id, 'to_buy' => 16]])->assertOk();
        $this->assertSame(0, PurchaseOrderItem::where('material_id', $this->buttons->id)->count());

        // Sales may not set suppliers; procurement may, and sees what is waiting.
        $haber = $this->supplier('Haberdashery Ltd');
        $this->actAs(['inventory.view', 'inventory.adjust']);
        $this->putJson("/api/v1/admin/inventory/materials/{$this->buttons->id}", ['preferred_supplier_id' => $haber])->assertStatus(403);

        $this->procurement();
        $row = collect($this->getJson('/api/v1/admin/inventory/materials')->assertOk()->json('data'))->firstWhere('id', $this->buttons->id);
        $this->assertEqualsWithDelta(16.0, (float) $row['needs_supplier'], 0.001);
        $this->assertNull($row['preferred_supplier']);

        $this->putJson("/api/v1/admin/inventory/materials/{$this->buttons->id}", ['preferred_supplier_id' => $haber])->assertOk()
            ->assertJsonPath('material.preferred_supplier.name', 'Haberdashery Ltd')
            ->assertJsonPath('material.needs_supplier', 0);
        $line = PurchaseOrderItem::where('material_id', $this->buttons->id)->sole();
        $this->assertSame($haber, (int) $line->purchaseOrder->supplier_id);
        $this->assertEqualsWithDelta(16.0, (float) $line->quantity, 0.001);
        $this->assertEqualsWithDelta(80.0, (float) $line->total_price, 0.001);      // 16 × 5, the unit cost: never bought before
    }

    // ── Who sees the supplier ─────────────────────────────────────────────────

    public function test_sales_staff_never_see_who_a_material_is_bought_from(): void
    {
        $this->sales();
        $job = $this->raise($this->line(2), [['material_id' => $this->fabric->id, 'to_buy' => 5], ['material_id' => $this->buttons->id, 'from_store' => 16]])->assertOk()->json('production_order_id');

        $sourcing = $this->getJson("/api/v1/admin/production-orders/{$job}/material-sourcing")->assertOk();
        $sourcing->assertJsonPath('status', 'waiting');
        $materials = $this->getJson('/api/v1/admin/inventory/materials')->assertOk();
        foreach ([$sourcing, $materials, $this->getJson("/api/v1/admin/inventory/materials/{$this->fabric->id}")->assertOk()] as $res) {
            $this->assertStringNotContainsString('DK Textiles', $res->getContent());
            $this->assertStringNotContainsString('preferred_supplier', $res->getContent());
        }
        // The purchase order itself is behind procurement.view.
        $this->getJson('/api/v1/admin/purchase-orders/' . PurchaseOrder::where('status', 'draft')->value('id'))->assertStatus(403);

        // Procurement sees the supplier, and on the order which job each line is for.
        $this->procurement();
        $row = collect($this->getJson('/api/v1/admin/inventory/materials')->json('data'))->firstWhere('id', $this->fabric->id);
        $this->assertSame('DK Textiles', $row['preferred_supplier']['name']);
        $po = $this->getJson('/api/v1/admin/purchase-orders/' . PurchaseOrder::where('status', 'draft')->value('id'))->assertOk();
        $this->assertSame($job, $po->json('purchase_order.items.0.production_order.id'));
    }

    // ── Afterwards ────────────────────────────────────────────────────────────

    public function test_the_choice_can_be_changed_and_moves_the_reservation_and_the_draft_line(): void
    {
        $this->sales();
        $job = $this->raise($this->line(2), [['material_id' => $this->fabric->id, 'to_buy' => 5], ['material_id' => $this->buttons->id, 'from_store' => 16]])->assertOk()->json('production_order_id');

        // On second thoughts: the 1 m in the store, 4 m bought.
        $this->postJson("/api/v1/admin/production-orders/{$job}/material-sourcing", ['materials' => [['material_id' => $this->fabric->id, 'from_store' => 1, 'to_buy' => 4]]])
            ->assertOk()->assertJsonPath('status', 'waiting');

        $line = PurchaseOrderItem::where('production_order_id', $job)->sole();
        $this->assertEqualsWithDelta(4.0, (float) $line->quantity, 0.001);
        $this->assertEqualsWithDelta(1800.0, (float) $line->purchaseOrder->total_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, $this->free($this->fabric), 0.001);

        // A job raised without the choice (sold at the till) is decided here too.
        $till = ProductionOrder::create(['product_id' => $this->shirt->id, 'quantity' => 1, 'status' => 'draft', 'outlet_id' => $this->outlet->id, 'priority' => 'normal', 'due_date' => now()->addWeek()]);
        \App\Services\Production\MaterialSourcing::ensureAllocations($till);
        $this->getJson("/api/v1/admin/production-orders/{$till->id}/material-sourcing")->assertOk()->assertJsonPath('status', 'unsourced');
        $this->postJson("/api/v1/admin/production-orders/{$till->id}/material-sourcing", ['materials' => [
            ['material_id' => $this->fabric->id, 'to_buy' => 2.5], ['material_id' => $this->buttons->id, 'from_store' => 8],
        ]])->assertOk();
        $this->assertEqualsWithDelta(6.5, (float) PurchaseOrderItem::whereNotNull('production_order_id')->sum('quantity'), 0.001);
    }

    public function test_cancelling_a_job_frees_the_store_and_takes_its_lines_off_a_draft_but_not_off_a_sent_order(): void
    {
        $this->sales();
        $a = $this->raise($this->line(2), [['material_id' => $this->fabric->id, 'from_store' => 1, 'to_buy' => 4], ['material_id' => $this->buttons->id, 'from_store' => 16]])->assertOk()->json('production_order_id');
        $b = $this->raise($this->line(1), [['material_id' => $this->fabric->id, 'to_buy' => 2.5], ['material_id' => $this->buttons->id, 'from_store' => 8]])->assertOk()->json('production_order_id');
        $draft = PurchaseOrder::where('status', 'draft')->sole();

        ProductionOrder::find($a)->update(['status' => 'cancelled']);

        $this->assertEqualsWithDelta(1.0, $this->free($this->fabric), 0.001);
        $this->assertEqualsWithDelta(112.0, $this->free($this->buttons), 0.001);     // 120 − job b's 8
        $this->assertSame([$b], $draft->items()->pluck('production_order_id')->all());
        $this->assertEqualsWithDelta(1125.0, (float) $draft->fresh()->total_amount, 0.001);   // 2.5 × 450

        // Job b's order has gone to the supplier: cancelling b leaves the line, and says so.
        $draft->update(['status' => 'ordered']);
        ProductionOrder::find($b)->update(['status' => 'cancelled']);
        $this->assertSame(1, $draft->items()->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'production_materials_still_on_order', 'subject_id' => $b]);
        $this->assertEqualsWithDelta(120.0, $this->free($this->buttons), 0.001);

        // The last line off a collecting draft removes the empty draft.
        $c = $this->raise($this->line(1), [['material_id' => $this->fabric->id, 'to_buy' => 2.5], ['material_id' => $this->buttons->id, 'from_store' => 8]])->assertOk()->json('production_order_id');
        $this->assertSame(1, PurchaseOrder::where('status', 'draft')->count());
        ProductionOrder::find($c)->update(['status' => 'cancelled']);
        $this->assertSame(0, PurchaseOrder::where('status', 'draft')->count());
    }

    public function test_a_received_delivery_is_held_for_its_job_and_issuing_spends_the_reservation(): void
    {
        $this->sales();
        $job = $this->raise($this->line(2), [['material_id' => $this->fabric->id, 'from_store' => 1, 'to_buy' => 4], ['material_id' => $this->buttons->id, 'from_store' => 16]])->assertOk()->json('production_order_id');
        $line = PurchaseOrderItem::where('production_order_id', $job)->sole();
        $line->purchaseOrder->update(['status' => 'approved']);

        $this->procurement();
        $this->postJson("/api/v1/admin/purchase-orders/{$line->purchase_order_id}/receive", [
            'location_type' => 'outlet', 'outlet_id' => $this->outlet->id,
            'items' => [['po_item_id' => $line->id, 'quantity_received' => 4]],
        ])->assertSuccessful();

        // 1 + 4 = 5 m in the store, all 5 held for the job: none free for anyone else.
        $this->assertEqualsWithDelta(5.0, (float) DB::table('material_inventory')->where('material_id', $this->fabric->id)->sum('quantity_on_hand'), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->free($this->fabric), 0.001);
        $this->getJson("/api/v1/admin/production-orders/{$job}/material-sourcing")->assertOk()->assertJsonPath('status', 'ready');

        // Issued to the floor: the stock leaves and the hold with it.
        $this->actAs(['production.view', 'production.manage_assignees']);
        ProductionOrder::find($job)->update(['status' => 'pending']);
        $allocation = MaterialAllocation::where('production_order_id', $job)->where('material_id', $this->fabric->id)->first();
        $this->postJson("/api/v1/admin/production-orders/{$job}/materials", ['allocations' => [['allocation_id' => $allocation->id, 'quantity' => 5]]])->assertOk();
        $inv = DB::table('material_inventory')->where('material_id', $this->fabric->id)->first();
        $this->assertEqualsWithDelta(0.0, (float) $inv->quantity_on_hand, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $inv->quantity_reserved, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $allocation->fresh()->quantity_reserved, 0.001);
    }

    public function test_procurement_moves_a_line_to_another_supplier_only_while_the_order_is_a_draft(): void
    {
        $this->sales();
        $job = $this->raise($this->line(2), [['material_id' => $this->fabric->id, 'to_buy' => 5], ['material_id' => $this->buttons->id, 'from_store' => 16]])->assertOk()->json('production_order_id');
        $line  = PurchaseOrderItem::where('production_order_id', $job)->sole();
        $other = $this->supplier('Rivatex');
        $url   = fn () => "/api/v1/admin/purchase-orders/{$line->fresh()->purchase_order_id}/items/{$line->id}/move";

        $this->postJson($url(), ['supplier_id' => $other])->assertStatus(403);          // sales: no

        $this->procurement();
        $this->postJson($url(), ['supplier_id' => $other])->assertOk();
        $moved = $line->fresh()->purchaseOrder;
        $this->assertSame($other, (int) $moved->supplier_id);
        $this->assertEqualsWithDelta(2250.0, (float) $moved->total_amount, 0.001);       // 5 × 450
        $this->assertSame(1, PurchaseOrder::where('status', 'draft')->count(), 'the emptied draft is removed');

        // Once approved the supplier is fixed.
        $moved->update(['status' => 'approved']);
        $this->postJson($url(), ['supplier_id' => $this->dk])->assertStatus(422)->assertJsonValidationErrors('supplier_id');
    }
}
