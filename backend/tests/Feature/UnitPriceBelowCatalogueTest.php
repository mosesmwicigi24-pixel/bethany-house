<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Quotation;
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
 * A typed price below the catalogue is a discount by another name.
 *
 * Every path that caps the discount field also lets the operator type the
 * unit price — the till, pending orders, order line edits, quotations. With
 * the field capped at 5% and the price free, "940 for the 1,000 stole" was a
 * 6% discount nobody's rule saw. So a unit price under the catalogue's selling
 * price (in the document's currency) counts toward the same 5%, together with
 * any discount on the line.
 *
 * Only where there IS a catalogue price to measure against: an ad-hoc line, or
 * a product with no price the hub can express in that currency, has nothing
 * to be "below" and is left to the operator.
 */
class UnitPriceBelowCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private const SENTENCE = 'The most anyone can give is 5%. Larger discounts are set by the owner.';

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['pos.discount_cap_percent' => 5.0]);
        \App\Services\CurrencyPricing::forget();
        DB::table('currencies')->updateOrInsert(
            ['code' => 'KES'],
            ['name' => 'KES', 'symbol' => 'KES', 'exchange_rate' => 1, 'is_base' => true, 'is_active' => true,
             'created_at' => now(), 'updated_at' => now()],
        );
        \App\Services\CurrencyPricing::forget();
        $this->outlet = Outlet::factory()->create();
    }

    private function actAs(string $role = 'outlet_manager'): User
    {
        Role::findOrCreate($role, 'sanctum');
        $user = User::factory()->create();
        $user->assignRole($role);
        foreach ([
            'pos.access', 'pos.discount', 'pos.discount_override',
            'quotations.view', 'quotations.create',
            'orders.view', 'orders.edit', 'orders.edit_items', 'products.view',
        ] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        $user->outlets()->attach($this->outlet->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
        CashRegister::create([
            'outlet_id' => $this->outlet->id, 'register_name' => 'Till ' . $user->id, 'status' => 'open',
            'currency_code' => 'KES', 'opening_balance' => 1000, 'expected_cash' => 1000,
            'opened_by' => $user->id, 'opened_at' => now(),
        ]);

        return $user;
    }

    private function actAsSuperAdmin(): User
    {
        Role::findOrCreate('super_admin', 'sanctum');
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $user->outlets()->attach($this->outlet->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function product(?float $regular = 1000.0, ?float $sale = null): Product
    {
        $product = Product::factory()->create();
        DB::table('product_translations')->insert([
            'product_id' => $product->id, 'language_code' => 'en', 'name' => 'Stole',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($regular !== null) {
            DB::table('product_prices')->insert([
                'product_id' => $product->id, 'product_variant_id' => null, 'currency_code' => 'KES',
                'regular_price' => $regular, 'sale_price' => $sale, 'cost_price' => 100,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        InventoryItem::create([
            'product_id' => $product->id, 'product_variant_id' => null, 'outlet_id' => $this->outlet->id,
            'quantity_on_hand' => 100, 'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);

        return $product;
    }

    private function sale(Product $p, float $unitPrice, string $discType = 'none', float $discVal = 0)
    {
        return $this->postJson('/api/v1/admin/pos/sales', [
            'outlet_id' => $this->outlet->id, 'payment_method' => 'cash', 'cash_received' => 5000,
            'items' => [[
                'product_id' => $p->id, 'quantity' => 1, 'unit_price' => $unitPrice,
                'discount_type' => $discType, 'discount_value' => $discVal,
            ]],
        ]);
    }

    private function assertRefused($response, string $field): void
    {
        $response->assertStatus(422)->assertJsonPath('message', self::SENTENCE);
        $this->assertSame([self::SENTENCE], $response->json('errors')[$field] ?? null, $response->getContent());
    }

    // ── The till ──────────────────────────────────────────────────────────────

    public function test_a_price_typed_6_percent_under_the_catalogue_is_refused_at_the_till(): void
    {
        $this->actAs('pos_clerk');
        $p = $this->product(1000);

        $this->assertRefused($this->sale($p, 940), 'items.0.unit_price');
        $this->assertSame(0, Order::count());
        $this->sale($p, 950)->assertSuccessful();
    }

    public function test_a_lower_price_and_a_discount_count_together(): void
    {
        $this->actAs('admin');
        $p = $this->product(1000);

        // 50 off the price, then 1% more off the line: 59.50 of a 1,000 item.
        $this->assertRefused($this->sale($p, 950, 'percent', 1), 'items.0.unit_price');
    }

    public function test_a_price_above_the_catalogue_is_the_operators_business(): void
    {
        $this->actAs('pos_clerk');
        $p = $this->product(1000);

        $this->sale($p, 1100, 'percent', 5)->assertSuccessful();
    }

    public function test_the_catalogue_price_is_the_selling_price_when_the_item_is_on_sale(): void
    {
        $this->actAs('pos_clerk');
        $p = $this->product(1000, 900);

        $this->sale($p, 855)->assertSuccessful();      // 5% under 900
        $this->assertRefused($this->sale($p, 850), 'items.0.unit_price');
    }

    public function test_an_item_with_no_catalogue_price_has_nothing_to_be_below(): void
    {
        $this->actAs('pos_clerk');
        $p = $this->product(null);

        $this->sale($p, 300)->assertSuccessful();
    }

    // ── Pending orders, created and edited ───────────────────────────────────

    public function test_pending_orders_measure_the_typed_price_too(): void
    {
        $this->actAs('outlet_manager');
        $p = $this->product(1000);
        $line = fn (float $price) => ['product_id' => $p->id, 'quantity' => 1, 'unit_price' => $price];

        $this->assertRefused(
            $this->postJson('/api/v1/admin/pos/pending-order', ['outlet_id' => $this->outlet->id, 'items' => [$line(940)]]),
            'items.0.unit_price',
        );

        $id = $this->postJson('/api/v1/admin/pos/pending-order', ['outlet_id' => $this->outlet->id, 'items' => [$line(1000)]])
            ->assertSuccessful()->json('order_id');

        $this->assertRefused(
            $this->patchJson("/api/v1/admin/pos/pending-order/{$id}", ['items' => [$line(940)]]),
            'items.0.unit_price',
        );
        $this->patchJson("/api/v1/admin/pos/pending-order/{$id}", ['items' => [$line(950)]])->assertSuccessful();
    }

    public function test_a_made_to_order_line_is_measured_against_its_catalogue_price(): void
    {
        $this->actAs('outlet_manager');
        $p = $this->product(1000);

        $this->assertRefused($this->postJson('/api/v1/admin/pos/pending-order', [
            'outlet_id' => $this->outlet->id,
            'production_items' => [['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 900]],
        ]), 'production_items.0.unit_price');
    }

    // ── Editing an order's lines ──────────────────────────────────────────────

    private function order(Product $p, float $soldAt = 1000): array
    {
        $order = Order::factory()->create([
            'order_type' => 'pos', 'status' => 'processing', 'payment_status' => 'pending',
            'outlet_id' => $this->outlet->id, 'currency_code' => 'KES',
            'subtotal' => $soldAt * 2, 'discount_amount' => 0, 'tax_amount' => 0,
            'prices_include_tax' => false, 'shipping_amount' => 0, 'total_amount' => $soldAt * 2,
        ]);
        $line = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $p->id, 'product_name' => 'Stole', 'sku' => $p->sku,
            'quantity' => 2, 'unit_price' => $soldAt, 'discount_amount' => 0, 'tax_amount' => 0,
            'total_price' => $soldAt * 2, 'cost_price' => 100, 'cost_source' => 'product_price',
            'inventory_item_id' => InventoryItem::where('product_id', $p->id)->value('id'),
        ]);

        return [$order, $line];
    }

    public function test_lowering_a_lines_price_on_an_order_is_measured(): void
    {
        $this->actAs('admin');
        $p = $this->product(1000);
        [$order, $line] = $this->order($p);

        $this->assertRefused($this->putJson("/api/v1/admin/orders/{$order->id}/items", [
            'items' => [['id' => $line->id, 'quantity' => 2, 'unit_price' => 940]],
        ]), 'items.0.unit_price');
        $this->assertRefused($this->putJson("/api/v1/admin/orders/{$order->id}/items", [
            'items' => [['id' => $line->id, 'quantity' => 2], ['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 940]],
        ]), 'items.1.unit_price');

        $this->putJson("/api/v1/admin/orders/{$order->id}/items", [
            'items' => [['id' => $line->id, 'quantity' => 2, 'unit_price' => 950]],
        ])->assertOk();
    }

    public function test_an_old_line_sold_cheap_can_still_have_its_quantity_changed(): void
    {
        // Sold at 800 before the rule. Its price is history; raising the
        // quantity does not make the reduction any bigger a share.
        $this->actAs('admin');
        $p = $this->product(1000);
        [$order, $line] = $this->order($p, soldAt: 800);

        $this->putJson("/api/v1/admin/orders/{$order->id}/items", [
            'items' => [['id' => $line->id, 'quantity' => 3]],
        ])->assertOk();
        $this->assertSame(800.0, (float) $line->fresh()->unit_price);
    }

    // ── Quotations ────────────────────────────────────────────────────────────

    public function test_a_quoted_catalogue_line_is_measured_and_an_ad_hoc_one_is_not(): void
    {
        $this->actAs('pos_clerk');
        $p = $this->product(1000);

        $this->assertRefused($this->postJson('/api/v1/admin/quotations', ['items' => [
            ['product_id' => $p->id, 'product_name' => 'Stole', 'quantity' => 1, 'unit_price' => 940],
        ]]), 'items.0.unit_price');

        $this->postJson('/api/v1/admin/quotations', ['items' => [
            ['product_id' => $p->id, 'product_name' => 'Stole', 'quantity' => 1, 'unit_price' => 950],
            ['product_name' => 'Embroidery, bespoke', 'quantity' => 1, 'unit_price' => 10],
        ]])->assertCreated();
        $this->assertSame(1, Quotation::count());
    }

    public function test_the_owner_may_quote_any_price(): void
    {
        $this->actAsSuperAdmin();
        $p = $this->product(1000);

        $this->postJson('/api/v1/admin/quotations', ['items' => [
            ['product_id' => $p->id, 'product_name' => 'Stole', 'quantity' => 1, 'unit_price' => 700],
        ]])->assertCreated();
    }
}
