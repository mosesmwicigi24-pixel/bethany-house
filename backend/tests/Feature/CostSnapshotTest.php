<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * COGS phase 1 — the cost snapshot on order lines.
 *
 * CostResolver is the single place that answers "what does this item cost us"
 * (KES product_prices, variant row preferred, product row fallback), and the
 * P&L computes real COGS from the snapshot with a live-lookup fallback for
 * historical lines.
 */
class CostSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function priceRow(int $productId, ?int $variantId, ?float $cost, string $currency = 'KES'): void
    {
        DB::table('product_prices')->insert([
            'product_id' => $productId, 'product_variant_id' => $variantId,
            'currency_code' => $currency, 'regular_price' => 100,
            'cost_price' => $cost,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_variant_cost_wins_over_product_cost(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->priceRow($product->id, null, 40.0);
        $this->priceRow($product->id, $variant->id, 55.0);

        $resolved = app(CostResolver::class)->resolve($product->id, $variant->id);

        $this->assertNotNull($resolved);
        $this->assertSame(55.0, $resolved['cost_price']);
        $this->assertSame(CostResolver::SOURCE_PRODUCT_PRICE, $resolved['cost_source']);
    }

    public function test_product_level_cost_is_the_fallback(): void
    {
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->priceRow($product->id, null, 40.0);

        $resolved = app(CostResolver::class)->resolve($product->id, $variant->id);

        $this->assertSame(40.0, $resolved['cost_price']);
    }

    public function test_unpriced_product_resolves_null_not_zero(): void
    {
        $product = Product::factory()->create();
        // A retail price row with NO cost, and a foreign-currency cost row —
        // neither may masquerade as a KES cost.
        $this->priceRow($product->id, null, null);
        $this->priceRow($product->id, null, 30.0, 'USD');

        $this->assertNull(app(CostResolver::class)->resolve($product->id, null));

        $cols = app(CostResolver::class)->columns($product->id, null);
        $this->assertNull($cols['cost_price']);
        $this->assertNull($cols['cost_source']);
    }

    public function test_profit_loss_uses_snapshot_and_falls_back_for_legacy_lines(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.view', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('reports.financial', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $product = Product::factory()->create();
        // Current catalogue cost is 70 — but the snapshot below says 60, and
        // the snapshot must win for the line that has one.
        $this->priceRow($product->id, null, 70.0);

        $order = Order::factory()->create([
            'order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => 500, 'currency_code' => 'KES', 'created_at' => now(),
        ]);
        // Line 1: snapshotted at sale time (2 × 60 = 120).
        DB::table('order_items')->insert([
            'order_id' => $order->id, 'product_id' => $product->id, 'sku' => 'S1',
            'product_name' => 'Snapshotted', 'quantity' => 2, 'unit_price' => 100,
            'cost_price' => 60.0, 'cost_source' => 'product_price',
            'total_price' => 200, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Line 2: legacy line with no snapshot → falls back to current cost (1 × 70).
        DB::table('order_items')->insert([
            'order_id' => $order->id, 'product_id' => $product->id, 'sku' => 'S2',
            'product_name' => 'Legacy', 'quantity' => 1, 'unit_price' => 300,
            'total_price' => 300, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->getJson('/api/v1/admin/reports/financial/profit-loss?start_date=' . now()->subDay()->toDateString() . '&end_date=' . now()->addDay()->toDateString());

        $res->assertOk();
        $this->assertEqualsWithDelta(190.0, $res->json('cost_of_goods_sold'), 0.01);
        $this->assertSame(0, $res->json('unpriced_lines'));
        $this->assertEqualsWithDelta(500 - 190.0, $res->json('gross_profit'), 0.01);
    }

    public function test_pos_sale_snapshots_cost_on_created_lines(): void
    {
        // The capture path itself: creating a line through the model with the
        // resolver's columns persists cost_price + cost_source.
        $product = Product::factory()->create();
        $this->priceRow($product->id, null, 25.5);

        $order = Order::factory()->create([
            'order_type' => 'pos', 'status' => 'confirmed', 'payment_status' => 'paid',
            'total_amount' => 100, 'currency_code' => 'KES',
        ]);
        \App\Models\OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'sku' => 'S3',
            'product_name' => 'Captured', 'quantity' => 1, 'unit_price' => 100,
            ...app(CostResolver::class)->columns($product->id, null),
            'total_price' => 100,
        ]);

        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'cost_price' => 25.5,
            'cost_source' => 'product_price',
        ]);
    }
}
