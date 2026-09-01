<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A LIVE order carrying stock_unwound_at is telling two stories: the goods were
 * given back, yet the order still expects to sell them.
 *
 * Restoring the 12 auto-cancelled till sales would have created exactly that
 * shape. Three readers mishandle it, each in a different direction:
 *   - commitForOrder deducts NOTHING for a previously-committed order (goods
 *     walk out, the count never moves);
 *   - or commits a reservation released weeks ago, stealing units earmarked for
 *     someone else's order;
 *   - and the POS edit path returned the units a SECOND time, because it read
 *     stock_reserved_at/committed_at but not unwound_at, while its sibling
 *     OrderLineEditor::stockMode() always did.
 *
 * These tests pin the guards, not the restore: the shape can arise from any
 * cancel-then-revive, so the protection has to live in the code.
 */
class StaleStockHoldTest extends TestCase
{
    use RefreshDatabase;

    private function tillOrderWithStaleHold(): array
    {
        $outlet  = Outlet::factory()->create(['sales_channel' => 'pos', 'country_code' => 'KE']);
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        $inv = InventoryItem::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'outlet_id' => $outlet->id, 'quantity_on_hand' => 10,
            'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);
        $order = Order::factory()->create([
            'order_type' => 'pos', 'sales_bucket' => 'till', 'outlet_id' => $outlet->id,
            'status' => 'pending', 'payment_status' => 'pending',
            'currency_code' => 'KES', 'total_amount' => 1000,
            // The dangerous shape: live, but its stock was already given back.
            'stock_reserved_at' => now()->subMonth(),
            'stock_unwound_at'  => now()->subMonth(),
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'product_variant_id' => null, 'product_name' => 'Cassock', 'sku' => 'CAS-1',
            'quantity' => 1, 'unit_price' => 1000, 'total_price' => 1000,
        ]);

        $user = User::factory()->create();
        foreach (['pos.access', 'payments.record'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        $user->outlets()->sync([$outlet->id]);
        Sanctum::actingAs($user);

        return [$order, $inv];
    }

    public function test_taking_payment_on_a_stale_hold_is_refused_not_silently_skipped(): void
    {
        [$order, $inv] = $this->tillOrderWithStaleHold();

        $this->postJson("/api/v1/admin/pos/pending-order/{$order->id}/pay", [
            'payments' => [['method' => 'cash', 'amount' => 1000]],
        ])->assertStatus(422)->assertJsonPath('reason', 'stale_stock_hold');

        // The point of refusing: the shelf must not silently go wrong.
        $this->assertSame(10, $inv->fresh()->quantity_on_hand,
            'a refused payment must move no stock at all');
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_a_healthy_order_is_not_caught_by_the_tripwire(): void
    {
        [$order] = $this->tillOrderWithStaleHold();
        // Clear the contradiction — this is what re-saving the order does.
        $order->forceFill(['stock_unwound_at' => null])->save();

        // Asserts the GUARD, not the whole POS payment stack: a full sale also
        // has to satisfy register, totals and rounding rules that have nothing
        // to do with this fix, and pinning those here would make the test fail
        // for reasons that are not about a stale stock hold.
        $this->postJson("/api/v1/admin/pos/pending-order/{$order->id}/pay", [
            'payments' => [['method' => 'cash', 'amount' => 1000]],
        ])->assertJsonMissing(['reason' => 'stale_stock_hold']);
    }

    public function test_a_dead_order_is_not_caught_by_the_tripwire(): void
    {
        // cancelled/voided orders legitimately carry stock_unwound_at; the guard
        // must only fire on LIVE ones.
        [$order] = $this->tillOrderWithStaleHold();
        $order->forceFill(['status' => 'cancelled'])->save();

        $this->postJson("/api/v1/admin/pos/pending-order/{$order->id}/pay", [
            'payments' => [['method' => 'cash', 'amount' => 1000]],
        ])->assertStatus(422)
          ->assertJsonMissing(['reason' => 'stale_stock_hold']);
    }
}
