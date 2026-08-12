<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * PUT /admin/orders/{id}/items — adding, editing and removing the lines of an
 * order that already exists, and may already be paid, shipped or in production.
 *
 * Every assertion here is really the same assertion: after the edit, the
 * receipt, the payments and the shelf still agree with each other.
 */
class OrderLineEditingTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet = Outlet::factory()->create();
        Cache::flush();   // TaxCalculationService caches the rate + inclusive flag
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** A caller who may edit lines. */
    private function actAsEditor(): User
    {
        $user = User::factory()->create();
        foreach (['orders.view', 'orders.edit', 'orders.edit_items', 'products.view'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /** A named product with a KES price + cost, and stock at the outlet. */
    private function product(string $name, float $price, ?float $cost = null, int $onHand = 100): Product
    {
        $product = Product::factory()->create();
        DB::table('product_translations')->insert([
            'product_id' => $product->id, 'language_code' => 'en', 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_prices')->insert([
            'product_id' => $product->id, 'product_variant_id' => null,
            'currency_code' => 'KES', 'regular_price' => $price, 'cost_price' => $cost,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        InventoryItem::create([
            'product_id'        => $product->id,
            'product_variant_id' => null,
            'outlet_id'         => $this->outlet->id,
            'quantity_on_hand'  => $onHand,
            'quantity_reserved' => 0,
            'reorder_point'     => 0,
        ]);

        return $product;
    }

    private function inventoryFor(Product $product): InventoryItem
    {
        return InventoryItem::where('product_id', $product->id)
            ->where('outlet_id', $this->outlet->id)
            ->firstOrFail();
    }

    /** A 16% VAT applied to everything (no product-specific rate = global default). */
    private function defaultVat(float $percent = 16.0): void
    {
        DB::table('tax_rates')->insert([
            'name' => 'VAT', 'rate' => $percent, 'tax_type' => 'vat',
            'is_active' => true, 'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Cache::flush();
    }

    private function taxInclusive(bool $on): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'tax_inclusive'],
            ['value' => $on ? '1' : '0', 'updated_at' => now(), 'created_at' => now()],
        );
        Cache::flush();
    }

    /**
     * An order carrying one line, with the totals a real writer would have left.
     *
     * @return array{0:Order,1:OrderItem}
     */
    private function orderWithLine(
        Product $product,
        int $qty,
        float $unitPrice,
        array $orderOverrides = [],
        array $lineOverrides = [],
    ): array {
        $taxInclusive = (bool) ($orderOverrides['prices_include_tax'] ?? false);
        $lineTax      = round($unitPrice * $qty * 0.16, 2);
        $hasVat       = DB::table('tax_rates')->where('is_active', true)->exists();
        $lineTax      = $hasVat
            ? ($taxInclusive
                ? round($unitPrice * $qty * 0.16 / 1.16, 2)
                : $lineTax)
            : 0.0;
        $subtotal = $unitPrice * $qty;

        $order = Order::factory()->create(array_merge([
            'order_type'         => 'pos',
            'status'             => 'processing',
            'payment_status'     => 'pending',
            'outlet_id'          => $this->outlet->id,
            'currency_code'      => 'KES',
            'subtotal'           => $subtotal,
            'discount_amount'    => 0,
            'tax_amount'         => $lineTax,
            'prices_include_tax' => false,
            'shipping_amount'    => 0,
            'total_amount'       => $taxInclusive ? $subtotal : $subtotal + $lineTax,
        ], $orderOverrides));

        $line = OrderItem::create(array_merge([
            'order_id'          => $order->id,
            'product_id'        => $product->id,
            'product_name'      => 'Line',
            'sku'               => $product->sku,
            'quantity'          => $qty,
            'unit_price'        => $unitPrice,
            'discount_amount'   => 0,
            'tax_amount'        => $lineTax,
            'total_price'       => $taxInclusive ? $subtotal : $subtotal + $lineTax,
            'cost_price'        => 400.0,
            'cost_source'       => 'product_price',
            'inventory_item_id' => $this->inventoryFor($product)->id,
        ], $lineOverrides));

        return [$order->fresh(), $line->fresh()];
    }

    private function edit(Order $order, array $payload)
    {
        return $this->putJson("/api/v1/admin/orders/{$order->id}/items", $payload);
    }

    // ── Recalculation ─────────────────────────────────────────────────────────

    public function test_adding_a_line_recalculates_subtotal_tax_and_total(): void
    {
        $this->actAsEditor();
        $this->defaultVat();
        $shirt = $this->product('Cassock', 1000, 400);
        $stole = $this->product('Stole', 500, 200);
        [$order, $line] = $this->orderWithLine($shirt, 2, 1000);

        $res = $this->edit($order, ['items' => [
            ['id' => $line->id, 'quantity' => 2, 'unit_price' => 1000],
            ['product_id' => $stole->id, 'quantity' => 3, 'unit_price' => 500],
        ]]);

        $res->assertOk();
        // 2×1000 + 3×500 = 3500 net, +16% = 4060 gross.
        $this->assertEqualsWithDelta(3500.0, $res->json('subtotal'), 0.01);
        $this->assertEqualsWithDelta(560.0, $res->json('tax_amount'), 0.01);
        $this->assertEqualsWithDelta(4060.0, $res->json('total_amount'), 0.01);

        $fresh = $order->fresh();
        $this->assertEqualsWithDelta(3500.0, (float) $fresh->subtotal, 0.01);
        $this->assertEqualsWithDelta(4060.0, (float) $fresh->total_amount, 0.01);
        $this->assertSame(2, $fresh->items()->count());
    }

    public function test_editing_a_quantity_recalculates_and_keeps_the_cost_snapshot(): void
    {
        $this->actAsEditor();
        $this->defaultVat();
        // Catalogue cost has since moved to 900 — the snapshot must not follow it.
        $shirt = $this->product('Cassock', 1000, 900);
        [$order, $line] = $this->orderWithLine($shirt, 2, 1000);

        $res = $this->edit($order, ['items' => [
            ['id' => $line->id, 'quantity' => 5, 'unit_price' => 1000],
        ]]);

        $res->assertOk();
        $this->assertEqualsWithDelta(5000.0, $res->json('subtotal'), 0.01);
        $this->assertEqualsWithDelta(800.0, $res->json('tax_amount'), 0.01);
        $this->assertEqualsWithDelta(5800.0, $res->json('total_amount'), 0.01);

        $fresh = $line->fresh();
        $this->assertSame(5, (int) $fresh->quantity);
        $this->assertEqualsWithDelta(400.0, (float) $fresh->cost_price, 0.01);   // untouched
        $this->assertSame('product_price', $fresh->cost_source);
    }

    public function test_removing_a_line_recalculates_totals(): void
    {
        $this->actAsEditor();
        $this->defaultVat();
        $shirt = $this->product('Cassock', 1000, 400);
        $stole = $this->product('Stole', 500, 200);
        [$order, $line] = $this->orderWithLine($shirt, 2, 1000);
        $extra = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $stole->id, 'product_name' => 'Stole',
            'sku' => $stole->sku,
            'quantity' => 1, 'unit_price' => 500, 'discount_amount' => 0,
            'tax_amount' => 80, 'total_price' => 580,
            'inventory_item_id' => $this->inventoryFor($stole)->id,
        ]);

        $res = $this->edit($order, ['items' => [
            ['id' => $extra->id, 'quantity' => 1, 'unit_price' => 500],
        ]]);

        $res->assertOk();
        $this->assertEqualsWithDelta(500.0, $res->json('subtotal'), 0.01);
        $this->assertEqualsWithDelta(80.0, $res->json('tax_amount'), 0.01);
        $this->assertEqualsWithDelta(580.0, $res->json('total_amount'), 0.01);
        $this->assertDatabaseMissing('order_items', ['id' => $line->id]);
    }

    public function test_a_tax_inclusive_order_keeps_the_total_at_the_line_subtotal(): void
    {
        $this->actAsEditor();
        $this->defaultVat();
        $this->taxInclusive(true);
        $shirt = $this->product('Cassock', 1160, 400);
        [$order, $line] = $this->orderWithLine($shirt, 1, 1160, ['prices_include_tax' => true]);

        $res = $this->edit($order, ['items' => [
            ['id' => $line->id, 'quantity' => 2, 'unit_price' => 1160],
        ]]);

        $res->assertOk();
        // Inclusive: the customer pays 2320; the 320 VAT is extracted, not added.
        $this->assertEqualsWithDelta(2320.0, $res->json('subtotal'), 0.01);
        $this->assertEqualsWithDelta(320.0, $res->json('tax_amount'), 0.01);
        $this->assertEqualsWithDelta(2320.0, $res->json('total_amount'), 0.01);
    }

    public function test_the_orders_own_inclusive_flag_wins_over_the_current_setting(): void
    {
        // The shop has since switched to tax-exclusive pricing. An old
        // tax-inclusive receipt must not silently gross itself up on edit.
        $this->actAsEditor();
        $this->defaultVat();
        $this->taxInclusive(false);
        $shirt = $this->product('Cassock', 1160, 400);
        [$order, $line] = $this->orderWithLine($shirt, 1, 1160, ['prices_include_tax' => true]);

        $res = $this->edit($order, ['items' => [
            ['id' => $line->id, 'quantity' => 1, 'unit_price' => 1160],
        ]]);

        $res->assertOk();
        $this->assertEqualsWithDelta(1160.0, $res->json('total_amount'), 0.01);
        $this->assertTrue((bool) $order->fresh()->prices_include_tax);
    }

    // ── Payments ──────────────────────────────────────────────────────────────

    public function test_a_paid_order_whose_total_rises_flips_to_partial(): void
    {
        $this->actAsEditor();
        $shirt = $this->product('Cassock', 1000, 400);
        [$order, $line] = $this->orderWithLine($shirt, 2, 1000, [
            'payment_status' => 'paid', 'status' => 'processing',
        ]);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 2000, 'status' => 'paid']);

        $res = $this->edit($order, [
            'items' => [['id' => $line->id, 'quantity' => 3, 'unit_price' => 1000]],
            'confirm_paid_change' => true,
        ]);

        $res->assertOk();
        $res->assertJsonPath('previous_payment_status', 'paid');
        $res->assertJsonPath('payment_status', 'partial');
        $this->assertEqualsWithDelta(3000.0, $res->json('total_amount'), 0.01);
        $this->assertEqualsWithDelta(2000.0, $res->json('amount_paid'), 0.01);
        $this->assertEqualsWithDelta(1000.0, $res->json('balance'), 0.01);
        $this->assertEqualsWithDelta(0.0, $res->json('overpaid'), 0.01);

        // The money already collected is untouched.
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        $this->assertSame('partial', $order->fresh()->payment_status);
    }

    public function test_dropping_a_paid_orders_total_flags_a_refund_rather_than_hiding_it(): void
    {
        $this->actAsEditor();
        $shirt = $this->product('Cassock', 1000, 400);
        [$order, $line] = $this->orderWithLine($shirt, 3, 1000, [
            'payment_status' => 'paid', 'status' => 'processing',
        ]);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 3000, 'status' => 'paid']);

        $res = $this->edit($order, [
            'items' => [['id' => $line->id, 'quantity' => 1, 'unit_price' => 1000]],
            'confirm_paid_change' => true,
        ]);

        $res->assertOk();
        $res->assertJsonPath('payment_status', 'paid');       // nothing outstanding…
        $this->assertEqualsWithDelta(2000.0, $res->json('overpaid'), 0.01);   // …but 2000 is owed back
        $this->assertEqualsWithDelta(0.0, $res->json('balance'), 0.01);

        // And it is written where staff will actually see it.
        $this->assertStringContainsString('REFUND DUE', (string) $order->fresh()->notes);
    }

    public function test_editing_a_paid_order_requires_an_explicit_confirmation(): void
    {
        $this->actAsEditor();
        $shirt = $this->product('Cassock', 1000, 400);
        [$order, $line] = $this->orderWithLine($shirt, 2, 1000, ['payment_status' => 'paid']);
        Payment::factory()->create(['order_id' => $order->id, 'amount' => 2000, 'status' => 'paid']);

        $this->edit($order, ['items' => [['id' => $line->id, 'quantity' => 5]]])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'confirm_paid_change');

        $this->assertSame(2, (int) $line->fresh()->quantity);   // nothing moved
    }

    public function test_editing_a_shipped_order_requires_an_explicit_confirmation(): void
    {
        $this->actAsEditor();
        $shirt = $this->product('Cassock', 1000, 400);
        [$order, $line] = $this->orderWithLine($shirt, 2, 1000);
        DB::table('order_shipments')->insert([
            'order_id' => $order->id, 'shipment_number' => 'SHP-1', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->edit($order, ['items' => [['id' => $line->id, 'quantity' => 5]]])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'confirm_shipped_change');

        $this->edit($order, [
            'items' => [['id' => $line->id, 'quantity' => 5]],
            'confirm_shipped_change' => true,
        ])->assertOk();
    }

    // ── Stock ─────────────────────────────────────────────────────────────────

    public function test_committed_stock_comes_back_when_a_line_is_removed_and_is_drawn_when_one_is_added(): void
    {
        $this->actAsEditor();
        $shirt = $this->product('Cassock', 1000, 400, onHand: 50);
        $stole = $this->product('Stole', 500, 200, onHand: 50);
        [$order, $line] = $this->orderWithLine($shirt, 4, 1000, [
            'stock_committed_at' => now(),
        ]);
        // The 4 cassocks physically left the shelf when the sale committed.
        $shirtInv = $this->inventoryFor($shirt);
        $shirtInv->update(['quantity_on_hand' => 46]);

        $res = $this->edit($order, ['items' => [
            ['product_id' => $stole->id, 'quantity' => 2, 'unit_price' => 500],
        ]]);

        $res->assertOk();
        // Cassocks come back on the shelf; stoles leave it.
        $this->assertSame(50, (int) $shirtInv->fresh()->quantity_on_hand);
        $this->assertSame(48, (int) $this->inventoryFor($stole)->quantity_on_hand);

        // Both movements are in the inventory ledger against this order.
        $this->assertDatabaseHas('inventory_transactions', [
            'inventory_item_id' => $shirtInv->id, 'transaction_type' => 'void_return',
            'reference_id' => $order->id, 'quantity_change' => 4,
        ]);
        $this->assertDatabaseHas('inventory_transactions', [
            'inventory_item_id' => $this->inventoryFor($stole)->id, 'transaction_type' => 'sale',
            'reference_id' => $order->id, 'quantity_change' => -2,
        ]);
    }

    public function test_a_quantity_change_moves_only_the_delta(): void
    {
        $this->actAsEditor();
        $shirt = $this->product('Cassock', 1000, 400, onHand: 50);
        [$order, $line] = $this->orderWithLine($shirt, 4, 1000, ['stock_committed_at' => now()]);
        $inv = $this->inventoryFor($shirt);
        $inv->update(['quantity_on_hand' => 46]);

        $this->edit($order, ['items' => [['id' => $line->id, 'quantity' => 6]]])->assertOk();

        $this->assertSame(44, (int) $inv->fresh()->quantity_on_hand);   // 2 more left, not 6
    }

    public function test_a_reserved_order_moves_the_reservation_not_the_physical_count(): void
    {
        $this->actAsEditor();
        $shirt = $this->product('Cassock', 1000, 400, onHand: 50);
        [$order, $line] = $this->orderWithLine($shirt, 4, 1000, ['stock_reserved_at' => now()]);
        $inv = $this->inventoryFor($shirt);
        $inv->update(['quantity_reserved' => 4]);

        $this->edit($order, ['items' => [['id' => $line->id, 'quantity' => 7]]])->assertOk();

        $inv->refresh();
        $this->assertSame(50, (int) $inv->quantity_on_hand);     // untouched
        $this->assertSame(7, (int) $inv->quantity_reserved);
    }

    public function test_an_order_that_never_drew_stock_leaves_the_shelf_alone(): void
    {
        $this->actAsEditor();
        $shirt = $this->product('Cassock', 1000, 400, onHand: 50);
        [$order, $line] = $this->orderWithLine($shirt, 4, 1000);   // no stock flags, no ledger

        $this->edit($order, ['items' => [['id' => $line->id, 'quantity' => 9]]])->assertOk();

        $inv = $this->inventoryFor($shirt)->fresh();
        $this->assertSame(50, (int) $inv->quantity_on_hand);
        $this->assertSame(0, (int) $inv->quantity_reserved);
    }

    public function test_insufficient_stock_is_refused_and_nothing_changes(): void
    {
        $this->actAsEditor();
        $shirt = $this->product('Cassock', 1000, 400, onHand: 5);
        [$order, $line] = $this->orderWithLine($shirt, 2, 1000, ['stock_committed_at' => now()]);
        $inv = $this->inventoryFor($shirt);
        $inv->update(['quantity_on_hand' => 3]);

        $this->edit($order, ['items' => [['id' => $line->id, 'quantity' => 40]]])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'insufficient_stock');

        // Whole transaction rolled back — the line and the shelf are as they were.
        $this->assertSame(2, (int) $line->fresh()->quantity);
        $this->assertSame(3, (int) $inv->fresh()->quantity_on_hand);
        $this->assertEqualsWithDelta(2000.0, (float) $order->fresh()->subtotal, 0.01);
    }

    // ── COGS ──────────────────────────────────────────────────────────────────

    public function test_a_new_line_snapshots_its_cost_from_the_cost_resolver(): void
    {
        $this->actAsEditor();
        $shirt = $this->product('Cassock', 1000, 400);
        $stole = $this->product('Stole', 500, 175.5);
        [$order, $line] = $this->orderWithLine($shirt, 1, 1000);

        $this->edit($order, ['items' => [
            ['id' => $line->id, 'quantity' => 1],
            ['product_id' => $stole->id, 'quantity' => 1, 'unit_price' => 500],
        ]])->assertOk();

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id, 'product_id' => $stole->id,
            'cost_price' => 175.5, 'cost_source' => 'product_price',
        ]);
    }

    // ── Blocked cases ─────────────────────────────────────────────────────────

    public function test_a_voided_order_is_blocked(): void
    {
        $this->actAsEditor();
        $shirt = $this->product('Cassock', 1000, 400);
        [$order, $line] = $this->orderWithLine($shirt, 2, 1000, ['status' => 'voided']);

        $this->edit($order, ['items' => [['id' => $line->id, 'quantity' => 3]]])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'order_closed');

        $this->assertSame(2, (int) $line->fresh()->quantity);
    }

    public function test_a_line_in_production_can_be_neither_removed_nor_requantified(): void
    {
        $this->actAsEditor();
        $shirt = $this->product('Cassock', 1000, 400);
        $stole = $this->product('Stole', 500, 200);
        [$order, $line] = $this->orderWithLine($shirt, 2, 1000);
        $poId = DB::table('production_orders')->insertGetId([
            'order_number' => 'PO-1', 'product_id' => $shirt->id, 'quantity' => 2,
            'priority' => 'normal', 'status' => 'draft', 'outlet_id' => $this->outlet->id,
            'customer_order_id' => $order->id, 'order_item_id' => $line->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $line->update(['production_order_id' => $poId]);

        // Removed → refused.
        $this->edit($order, ['items' => [
            ['product_id' => $stole->id, 'quantity' => 1, 'unit_price' => 500],
        ]])->assertStatus(422)->assertJsonPath('reason', 'line_in_production');

        // Re-quantified → refused.
        $this->edit($order, ['items' => [['id' => $line->id, 'quantity' => 4]]])
            ->assertStatus(422)->assertJsonPath('reason', 'line_in_production');

        // But its price may still be corrected.
        $this->edit($order, ['items' => [['id' => $line->id, 'quantity' => 2, 'unit_price' => 1200]]])
            ->assertOk();
        $this->assertEqualsWithDelta(1200.0, (float) $line->fresh()->unit_price, 0.01);
    }

    public function test_a_returned_line_cannot_be_removed(): void
    {
        $this->actAsEditor();
        $shirt = $this->product('Cassock', 1000, 400);
        $stole = $this->product('Stole', 500, 200);
        [$order, $line] = $this->orderWithLine($shirt, 2, 1000);
        $returnId = DB::table('order_returns')->insertGetId([
            'order_id' => $order->id, 'return_number' => 'RET-1', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('return_items')->insert([
            'return_id' => $returnId, 'order_item_id' => $line->id, 'quantity' => 1,
            'created_at' => now(),
        ]);

        $this->edit($order, ['items' => [
            ['product_id' => $stole->id, 'quantity' => 1, 'unit_price' => 500],
        ]])->assertStatus(422)->assertJsonPath('reason', 'line_returned');

        $this->assertDatabaseHas('order_items', ['id' => $line->id]);
    }

    public function test_an_order_cannot_be_emptied(): void
    {
        $this->actAsEditor();
        $shirt = $this->product('Cassock', 1000, 400);
        [$order] = $this->orderWithLine($shirt, 2, 1000);

        $this->edit($order, ['items' => []])->assertStatus(422);
    }

    // ── Audit + permissions ───────────────────────────────────────────────────

    public function test_every_mutation_is_written_to_the_audit_trail(): void
    {
        $editor = $this->actAsEditor();
        $shirt  = $this->product('Cassock', 1000, 400);
        $stole  = $this->product('Stole', 500, 200);
        [$order, $line] = $this->orderWithLine($shirt, 2, 1000);

        $this->edit($order, [
            'items'  => [
                ['id' => $line->id, 'quantity' => 4, 'unit_price' => 1100],
                ['product_id' => $stole->id, 'quantity' => 1, 'unit_price' => 500],
            ],
            'reason' => 'Customer upsized and added a stole',
        ])->assertOk();

        $log = DB::table('activity_log')
            ->where('event', 'order_items_edited')
            ->where('subject_id', $order->id)
            ->first();

        $this->assertNotNull($log, 'no audit row written');
        $this->assertSame($editor->id, (int) $log->causer_id);

        $props = json_decode($log->properties, true);
        $this->assertSame('Customer upsized and added a stole', $props['reason']);
        $this->assertCount(1, $props['added']);
        $this->assertCount(1, $props['updated']);
        $this->assertSame(2, $props['updated'][0]['from']['quantity']);
        $this->assertSame(4, $props['updated'][0]['to']['quantity']);
        $this->assertEqualsWithDelta(1000.0, $props['updated'][0]['from']['unit_price'], 0.01);
        $this->assertEqualsWithDelta(1100.0, $props['updated'][0]['to']['unit_price'], 0.01);
        $this->assertEqualsWithDelta(2000.0, $props['old_subtotal'], 0.01);
        $this->assertEqualsWithDelta(4900.0, $props['new_subtotal'], 0.01);
    }

    public function test_the_endpoint_is_gated_by_orders_edit_items(): void
    {
        // A caller with the everyday order permissions but not this one.
        $user = User::factory()->create();
        foreach (['orders.view', 'orders.edit', 'products.view'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        Permission::findOrCreate('orders.edit_items', 'sanctum');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $shirt = $this->product('Cassock', 1000, 400);
        [$order, $line] = $this->orderWithLine($shirt, 2, 1000);

        $this->edit($order, ['items' => [['id' => $line->id, 'quantity' => 9]]])
            ->assertStatus(403);

        $this->assertSame(2, (int) $line->fresh()->quantity);
    }

    public function test_permission_sync_creates_the_slug_and_keeps_it_off_outlet_manager(): void
    {
        // The gate decision itself, asserted rather than assumed: this is an
        // administrator permission. An outlet manager holds orders.edit for
        // day-to-day status and notes, and must NOT inherit line editing from it.
        $this->artisan('permission:sync')->assertExitCode(0);

        $this->assertDatabaseHas('permissions', [
            'name' => 'orders.edit_items', 'guard_name' => 'sanctum',
        ]);

        $manager = Role::where('name', 'outlet_manager')->where('guard_name', 'sanctum')->first();
        $this->assertNotNull($manager);
        $this->assertTrue($manager->hasPermissionTo('orders.edit'));
        $this->assertFalse($manager->hasPermissionTo('orders.edit_items'));

        $admin = Role::where('name', 'admin')->where('guard_name', 'sanctum')->first();
        $this->assertTrue($admin->hasPermissionTo('orders.edit_items'));
    }

    public function test_an_outlet_scoped_manager_cannot_edit_another_outlets_order(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('outlet_manager', 'sanctum'));
        $user->outlets()->attach(Outlet::factory()->create()->id);
        foreach (['orders.view', 'orders.edit', 'orders.edit_items', 'products.view'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $shirt = $this->product('Cassock', 1000, 400);
        [$order, $line] = $this->orderWithLine($shirt, 2, 1000);   // a DIFFERENT outlet

        $this->edit($order, ['items' => [['id' => $line->id, 'quantity' => 9]]])
            ->assertStatus(403);

        $this->assertSame(2, (int) $line->fresh()->quantity);
    }

    public function test_a_line_from_another_order_is_rejected(): void
    {
        $this->actAsEditor();
        $shirt = $this->product('Cassock', 1000, 400);
        [$order, $line]   = $this->orderWithLine($shirt, 2, 1000);
        [$other, $otherLine] = $this->orderWithLine($shirt, 1, 1000);

        $this->edit($order, ['items' => [['id' => $otherLine->id, 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'unknown_line');

        $this->assertDatabaseHas('order_items', ['id' => $otherLine->id, 'order_id' => $other->id]);
    }
}
