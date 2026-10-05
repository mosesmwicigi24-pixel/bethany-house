<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionTask;
use App\Models\ProductSerial;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\MtoFulfilment;
use App\Services\ProductSerialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A customer's made-to-order garment: into stock at completion, held for the
 * customer, out of stock when they collect (owner decision 2026-10-05, option
 * a, with decisions 1–4: leaves on "completed", held at the order's outlet,
 * older jobs auto-matched or refused, storefront serials and the sales-order
 * status write fixed).
 *
 * Expected stock figures are written out as numbers from the scenario, never
 * computed with the service's own arithmetic.
 */
class MtoFulfilmentTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $shop;
    private Outlet $otherShop;
    private Product $product;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
        $this->shop      = Outlet::factory()->create();
        $this->otherShop = Outlet::factory()->create();
        $this->product   = Product::factory()->create();
        $this->variant   = ProductVariant::factory()->create(['product_id' => $this->product->id]);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function manager(): User
    {
        $u = User::factory()->create();
        $u->assignRole(Role::findByName('admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u->fresh());

        return $u->fresh();
    }

    /** A paid customer sale at $this->shop with one made-to-order line. */
    private function sale(string $status = 'pending', int $lines = 1, ?string $notes = '__MTO__|Purple cassock'): Order
    {
        $order = Order::factory()->create([
            'order_type' => 'pos', 'status' => $status, 'outlet_id' => $this->shop->id,
            'payment_status' => 'paid', 'subtotal' => 1000, 'total_amount' => 1000,
        ]);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 1000]);
        for ($i = 0; $i < $lines; $i++) {
            OrderItem::create([
                'order_id' => $order->id, 'product_id' => $this->product->id,
                'product_variant_id' => $this->variant->id, 'product_name' => 'Cassock', 'sku' => 'CASSOCK-1',
                'quantity' => 2, 'unit_price' => 500, 'total_price' => 1000, 'notes' => $notes,
            ]);
        }

        return $order->fresh('items');
    }

    /** A customer job that has passed QC, with its serials minted. */
    private function job(?Order $sale, int $qty = 2, array $extra = []): ProductionOrder
    {
        $po = ProductionOrder::create(array_merge([
            'order_number'       => 'PRD-MTO-' . fake()->unique()->numerify('#####'),
            'product_id'         => $this->product->id,
            'product_variant_id' => $this->variant->id,
            'quantity'           => $qty,
            'status'             => 'qc_passed',
            'is_customer_order'  => (bool) $sale,
            'customer_order_id'  => $sale?->id,
            'outlet_id'          => $this->otherShop->id,
        ], $extra));
        $stage = ProductionStage::firstOrCreate(['slug' => 'sew-mto'], ['name' => 'Sewing', 'sort_order' => 1, 'is_active' => true]);
        ProductionTask::withoutViewerScope()->create([
            'production_order_id' => $po->id, 'production_stage_id' => $stage->id, 'sequence' => 1,
            'status' => 'completed', 'quantity_done' => $qty, 'started_at' => now(), 'completed_at' => now(),
        ]);
        ProductSerialService::generateForProductionOrder($po);

        return $po->fresh();
    }

    private function complete(ProductionOrder $po, array $body = [])
    {
        return $this->postJson("/api/v1/admin/production-orders/{$po->id}/complete", $body);
    }

    private function row(?int $outletId): ?InventoryItem
    {
        return InventoryItem::where('product_id', $this->product->id)
            ->where('product_variant_id', $this->variant->id)
            ->where('outlet_id', $outletId)->first();
    }

    private function steps(ProductionOrder $po): array
    {
        return DB::table('inventory_transactions')
            ->where('reference_type', ProductionOrder::class)->where('reference_id', $po->id)
            ->orderBy('id')->pluck('transaction_type')->all();
    }

    private function serialStatuses(ProductionOrder $po): array
    {
        return ProductSerial::where('production_order_id', $po->id)->orderBy('id')->pluck('status')->all();
    }

    // ── completion holds the garment for the customer ───────────────────────

    public function test_completion_holds_the_garment_at_the_orders_outlet(): void
    {
        $sale = $this->sale();
        $po   = $this->job($sale);
        $this->manager();

        // Even if someone picks a different outlet, the customer's garment waits where they ordered.
        $this->complete($po, ['outlet_id' => $this->otherShop->id])->assertOk()
            ->assertJsonFragment(['message' => "Production order {$po->order_number} completed. 2 unit(s) held for {$sale->order_number} until the customer collects."]);

        $row = $this->row($this->shop->id);
        $this->assertNotNull($row, 'stocked at the outlet the order was taken at');
        $this->assertSame(2, (int) $row->quantity_on_hand);
        $this->assertSame(2, (int) $row->quantity_reserved, 'held: counted, not sellable');
        $this->assertSame(0, (int) $row->fresh()->quantity_available);
        $this->assertNull($this->row($this->otherShop->id));

        $this->assertSame(['production', MtoFulfilment::HOLD], $this->steps($po));
        $this->assertSame([ProductSerial::SOLD, ProductSerial::SOLD], $this->serialStatuses($po));
        $this->assertSame([$sale->id, $sale->id], ProductSerial::where('production_order_id', $po->id)->pluck('order_id')->map(fn ($v) => (int) $v)->all());

        // Older job (no link) was matched to its line, both ways.
        $line = $sale->items->first()->fresh();
        $this->assertSame($line->id, (int) $po->fresh()->order_item_id);
        $this->assertSame($po->id, (int) $line->production_order_id);
    }

    public function test_the_sales_order_moves_forward_to_processing_with_history(): void
    {
        $sale = $this->sale('pending');
        $po   = $this->job($sale);
        $this->manager();

        $this->complete($po)->assertOk();

        $this->assertSame('processing', $sale->fresh()->status);
        $this->assertTrue(DB::table('order_status_history')->where('order_id', $sale->id)
            ->where('from_status', 'pending')->where('to_status', 'processing')->exists());
    }

    public function test_a_shipped_sales_order_is_not_pulled_back(): void
    {
        $sale = $this->sale('shipped');
        $po   = $this->job($sale);
        $this->manager();

        $this->complete($po)->assertOk();

        $this->assertSame('shipped', $sale->fresh()->status, 'the raw update used to drag it back to processing');
    }

    // ── collection takes it out ─────────────────────────────────────────────

    public function test_completing_the_order_hands_the_garment_over_once(): void
    {
        $sale = $this->sale('processing');
        $po   = $this->job($sale);
        $this->manager();
        $this->complete($po)->assertOk();

        $this->putJson("/api/v1/admin/orders/{$sale->id}/status", ['status' => 'completed'])->assertOk();

        $row = $this->row($this->shop->id)->fresh();
        $this->assertSame(0, (int) $row->quantity_on_hand, 'the garment left with the customer');
        $this->assertSame(0, (int) $row->quantity_reserved);
        $this->assertSame(['production', MtoFulfilment::HOLD, MtoFulfilment::COLLECTED], $this->steps($po));
        $this->assertSame([ProductSerial::DISPATCHED, ProductSerial::DISPATCHED], $this->serialStatuses($po));

        // Again: nothing moves twice.
        $this->putJson("/api/v1/admin/orders/{$sale->id}/status", ['status' => 'completed'])->assertOk();
        MtoFulfilment::collectForOrder($sale->fresh(), null);
        $this->assertSame(0, (int) $row->fresh()->quantity_on_hand);
        $this->assertSame(1, collect($this->steps($po))->filter(fn ($s) => $s === MtoFulfilment::COLLECTED)->count());
    }

    public function test_pos_dispatch_hands_the_garment_over(): void
    {
        $sale = $this->sale('processing');
        $po   = $this->job($sale);
        $this->manager();
        $this->complete($po)->assertOk();

        $this->postJson("/api/v1/admin/pos/sales/{$sale->id}/dispatch")->assertOk();

        $this->assertSame(0, (int) $this->row($this->shop->id)->fresh()->quantity_on_hand);
        $this->assertContains(MtoFulfilment::COLLECTED, $this->steps($po));
        $this->assertSame([ProductSerial::DISPATCHED, ProductSerial::DISPATCHED], $this->serialStatuses($po));
    }

    public function test_an_order_already_completed_takes_the_garment_straight_out(): void
    {
        // A POS sale can be dispatched before its garment is finished.
        $sale = $this->sale('completed');
        $po   = $this->job($sale);
        $this->manager();

        $this->complete($po)->assertOk();

        $row = $this->row($this->shop->id)->fresh();
        $this->assertSame(0, (int) $row->quantity_on_hand);
        $this->assertSame(0, (int) $row->quantity_reserved);
        $this->assertSame(['production', MtoFulfilment::HOLD, MtoFulfilment::COLLECTED], $this->steps($po));
    }

    // ── cancel / void ───────────────────────────────────────────────────────

    public function test_cancelling_before_collection_turns_the_garment_into_shop_stock(): void
    {
        $sale = $this->sale('processing');
        $po   = $this->job($sale);
        $this->manager();
        $this->complete($po)->assertOk();

        $this->putJson("/api/v1/admin/orders/{$sale->id}/status", ['status' => 'cancelled'])->assertOk();

        $row = $this->row($this->shop->id)->fresh();
        $this->assertSame(2, (int) $row->quantity_on_hand, 'the garment is still physically here');
        $this->assertSame(0, (int) $row->quantity_reserved, 'and now sellable');
        $this->assertSame(['production', MtoFulfilment::HOLD, MtoFulfilment::RELEASED], $this->steps($po));
        $this->assertSame([ProductSerial::IN_STOCK, ProductSerial::IN_STOCK], $this->serialStatuses($po));
        $this->assertSame(0, ProductSerial::where('production_order_id', $po->id)->whereNotNull('order_id')->count());

        // Releasing again does nothing.
        MtoFulfilment::releaseForOrder($sale->fresh(), null);
        $this->assertSame(0, (int) $row->fresh()->quantity_reserved);
        $this->assertSame(1, collect($this->steps($po))->filter(fn ($s) => $s === MtoFulfilment::RELEASED)->count());
    }

    public function test_voiding_after_collection_puts_the_garment_back(): void
    {
        $sale = $this->sale('processing');
        $po   = $this->job($sale);
        $this->manager();
        $this->complete($po)->assertOk();
        $this->putJson("/api/v1/admin/orders/{$sale->id}/status", ['status' => 'completed'])->assertOk();

        // The till's void (an approval flow) ends in PosInventoryService::unwindForOrder.
        $sale->forceFill(['status' => 'voided'])->save();
        \App\Services\PosInventoryService::unwindForOrder($sale->fresh(), null);

        $row = $this->row($this->shop->id)->fresh();
        $this->assertSame(2, (int) $row->quantity_on_hand);
        $this->assertSame(0, (int) $row->quantity_reserved);
        $this->assertSame(['production', MtoFulfilment::HOLD, MtoFulfilment::COLLECTED, MtoFulfilment::RETURNED], $this->steps($po));
        $this->assertSame([ProductSerial::IN_STOCK, ProductSerial::IN_STOCK], $this->serialStatuses($po));
    }

    // ── matching the job to its line ────────────────────────────────────────

    public function test_completion_is_refused_when_the_line_is_ambiguous(): void
    {
        $sale = $this->sale('pending', lines: 2);
        $po   = $this->job($sale);
        $this->manager();

        $this->complete($po)->assertStatus(422)->assertJsonPath('code', 'MTO_LINE_UNRESOLVED');

        $this->assertSame('qc_passed', $po->fresh()->status);
        $this->assertSame([], $this->steps($po), 'nothing went into stock');
    }

    public function test_completion_is_refused_when_no_line_matches(): void
    {
        $sale = $this->sale();
        $po   = $this->job($sale, extra: ['product_id' => Product::factory()->create()->id, 'product_variant_id' => null]);
        $this->manager();

        $this->complete($po)->assertStatus(422)->assertJsonPath('code', 'MTO_LINE_UNRESOLVED');
        $this->assertSame([], $this->steps($po));
    }

    public function test_pos_pairs_two_gowns_for_two_people_one_to_one(): void
    {
        $cashier = User::factory()->create();
        foreach (['pos.access', 'production.view'] as $p) {
            $cashier->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        $cashier->outlets()->syncWithoutDetaching([$this->shop->id]);
        CashRegister::create([
            'register_number' => "REG-{$this->shop->id}", 'outlet_id' => $this->shop->id,
            'register_name' => 'Till', 'status' => 'open', 'currency_code' => 'KES',
            'opening_balance' => 0, 'expected_cash' => 0, 'total_sales' => 0, 'total_cash_sales' => 0,
            'total_card_sales' => 0, 'total_mpesa_sales' => 0, 'total_refunds' => 0, 'transaction_count' => 0,
            'opened_by' => $cashier->id, 'opened_at' => now(),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($cashier->fresh());

        $res = $this->postJson('/api/v1/admin/pos/pending-order', [
            'outlet_id'        => $this->shop->id,
            'production_items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'production_notes' => 'For Mary'],
                ['product_id' => $this->product->id, 'quantity' => 1, 'production_notes' => 'For John'],
            ],
        ])->assertSuccessful();

        $jobs = ProductionOrder::whereIn('order_number', $res->json('production_orders'))->orderBy('id')->get();
        $this->assertCount(2, $jobs);
        $this->assertNotNull($jobs[0]->order_item_id);
        $this->assertNotNull($jobs[1]->order_item_id);
        $this->assertNotSame($jobs[0]->order_item_id, $jobs[1]->order_item_id, 'one line each');
        foreach ($jobs as $job) {
            $this->assertSame($job->id, (int) OrderItem::find($job->order_item_id)->production_order_id);
        }
    }

    // ── shop stock jobs are unchanged in kind ───────────────────────────────

    public function test_a_stock_job_goes_into_its_own_outlet_unheld(): void
    {
        $po = $this->job(null);
        $this->manager();

        $this->complete($po)->assertOk();

        $row = $this->row($this->otherShop->id)->fresh();
        $this->assertSame(2, (int) $row->quantity_on_hand);
        $this->assertSame(0, (int) $row->quantity_reserved, 'shop stock is for sale');
        $this->assertSame(['production'], $this->steps($po));
        $this->assertSame([ProductSerial::IN_STOCK, ProductSerial::IN_STOCK], $this->serialStatuses($po));
    }

    // ── online-shop serials (fix 5) ─────────────────────────────────────────

    public function test_an_online_made_to_order_line_does_not_claim_someone_elses_serial(): void
    {
        // Shop stock serial of the same product, sitting on the shelf.
        $shopJob = $this->job(null, 1);
        $this->manager();
        $this->complete($shopJob)->assertOk();
        $shelfSerial = ProductSerial::where('production_order_id', $shopJob->id)->firstOrFail();

        // An online order whose garment is being made (no __MTO__ prefix).
        $online = $this->sale('pending', 1, 'Measurements: Chest 40');
        $onlineJob = $this->job($online, 2);
        MtoFulfilment::link($onlineJob, $online->items->first());

        ProductSerialService::syncSoldForOrder($online->fresh('items'));
        $this->assertSame(ProductSerial::IN_STOCK, $shelfSerial->fresh()->status, 'the shelf unit is not sold to them');
        $this->assertNull($shelfSerial->fresh()->order_id);

        // Their own garment, once held, is not released by a later sync either.
        $this->complete($onlineJob)->assertOk();
        ProductSerialService::syncSoldForOrder($online->fresh('items'));
        $this->assertSame([ProductSerial::SOLD, ProductSerial::SOLD], $this->serialStatuses($onlineJob));
    }
}
