<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Pos\NewSale;
use App\Models\CashRegister;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "The max is 5%" — for the ORDER, not for each discount on it.
 *
 * Capping the line at 5% and the cart at 5% separately let one sale go for
 * nearly 9.75% less. Now everything taken off an order — line discounts, the
 * cart discount, and any shortfall of a typed price under the catalogue — is
 * at most 5% of the order's gross before any discount, for everyone but a
 * super_admin. The refusal names the field that tipped it over.
 */
class DiscountCombinedMaximumTest extends TestCase
{
    use RefreshDatabase;

    private const SENTENCE = 'Your discount limit is 5% — a super admin can give more.';

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['pos.discount_cap_percent' => 5.0]);
        // This suite pins the GLOBAL rule: with no per-role limits every role
        // is held to pos.discount_cap_percent. The seeded per-role limits
        // (pos_clerk 10, admin 15) are covered by RoleDiscountLimitsTest.
        DB::table('role_discount_caps')->delete();
        \App\Support\RoleDiscountCaps::forget();
        \App\Services\CurrencyPricing::forget();
        DB::table('currencies')->updateOrInsert(
            ['code' => 'KES'],
            ['name' => 'KES', 'symbol' => 'KES', 'exchange_rate' => 1, 'is_base' => true, 'is_active' => true,
             'created_at' => now(), 'updated_at' => now()],
        );
        \App\Services\CurrencyPricing::forget();
        $this->outlet = Outlet::factory()->create();
    }

    public static function staffRoles(): array
    {
        return [
            'clerk'          => ['pos_clerk'],
            'outlet manager' => ['outlet_manager'],
            'admin'          => ['admin'],
            'finance'        => ['finance_manager'],
        ];
    }

    private function actAs(string $role): User
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

    private function product(float $price = 1000.0): Product
    {
        $product = Product::factory()->create();
        DB::table('product_translations')->insert([
            'product_id' => $product->id, 'language_code' => 'en', 'name' => 'Alb',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_prices')->insert([
            'product_id' => $product->id, 'product_variant_id' => null, 'currency_code' => 'KES',
            'regular_price' => $price, 'cost_price' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
        InventoryItem::create([
            'product_id' => $product->id, 'product_variant_id' => null, 'outlet_id' => $this->outlet->id,
            'quantity_on_hand' => 100, 'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);

        return $product;
    }

    private function line(Product $p, float $percent = 0, float $unitPrice = 1000): array
    {
        return [
            'product_id' => $p->id, 'quantity' => 1, 'unit_price' => $unitPrice,
            'discount_type' => $percent > 0 ? 'percent' : 'none', 'discount_value' => $percent,
        ];
    }

    private function cart(float $percent): array
    {
        return ['cart_discount_type' => 'percent', 'cart_discount_value' => $percent];
    }

    private function sale(array $items, array $extra = [])
    {
        return $this->postJson('/api/v1/admin/pos/sales', array_merge([
            'outlet_id' => $this->outlet->id, 'payment_method' => 'cash', 'cash_received' => 5000, 'items' => $items,
        ], $extra));
    }

    private function assertRefused($response, string $field): void
    {
        $response->assertStatus(422)->assertJsonPath('message', self::SENTENCE);
        $this->assertSame([self::SENTENCE], $response->json('errors')[$field] ?? null, $response->getContent());
    }

    // ── The till ──────────────────────────────────────────────────────────────

    #[DataProvider('staffRoles')]
    public function test_a_5_percent_line_and_a_1_percent_cart_together_are_refused(string $role): void
    {
        $this->actAs($role);
        $p = $this->product();

        $this->assertRefused($this->sale([$this->line($p, 5)], $this->cart(1)), 'cart_discount_value');
        $this->assertSame(0, Order::count());
    }

    public function test_the_whole_order_may_share_its_5_percent_however_it_is_split(): void
    {
        $this->actAs('outlet_manager');
        $p = $this->product();

        // 50 off one line, nothing off the other: 50 of 2,000 used, 50 left.
        // 2.5% of the 1,950 subtotal is 48.75 — 98.75 in all, inside 100.
        $this->sale([$this->line($p, 5), $this->line($p)], $this->cart(2.5))->assertSuccessful();
        // 3% is 58.50 — 108.50 in all.
        $this->assertRefused($this->sale([$this->line($p, 5), $this->line($p)], $this->cart(3)), 'cart_discount_value');
    }

    public function test_a_price_typed_under_the_catalogue_counts_toward_the_order_total(): void
    {
        $this->actAs('pos_clerk');
        $p = $this->product();

        // 40 short of the catalogue + 1% of 960 (9.60) = 49.60 of 1,000: inside.
        $this->sale([$this->line($p, 0, 960)], $this->cart(1))->assertSuccessful();
        // + 2% (19.20) = 59.20: over.
        $this->assertRefused($this->sale([$this->line($p, 0, 960)], $this->cart(2)), 'cart_discount_value');
    }

    #[DataProvider('staffRoles')]
    public function test_pending_orders_created_and_edited_are_measured_as_a_whole(string $role): void
    {
        $this->actAs($role);
        $p = $this->product();

        $this->assertRefused(
            $this->postJson('/api/v1/admin/pos/pending-order', ['outlet_id' => $this->outlet->id, 'items' => [$this->line($p, 5)]] + $this->cart(1)),
            'cart_discount_value',
        );

        $id = $this->postJson('/api/v1/admin/pos/pending-order', ['outlet_id' => $this->outlet->id, 'items' => [$this->line($p, 5)]])
            ->assertSuccessful()->json('order_id');

        $this->assertRefused(
            $this->patchJson("/api/v1/admin/pos/pending-order/{$id}", ['items' => [$this->line($p, 5)]] + $this->cart(1)),
            'cart_discount_value',
        );
    }

    // ── Editing an order's lines ──────────────────────────────────────────────

    /** An order of two 1,000 lines, carrying an order-level discount. */
    private function order(Product $p, float $orderDiscount): array
    {
        $order = Order::factory()->create([
            'order_type' => 'pos', 'status' => 'processing', 'payment_status' => 'pending',
            'outlet_id' => $this->outlet->id, 'currency_code' => 'KES',
            'subtotal' => 2000, 'discount_amount' => $orderDiscount, 'tax_amount' => 0,
            'prices_include_tax' => false, 'shipping_amount' => 0, 'total_amount' => 2000 - $orderDiscount,
        ]);
        $line = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $p->id, 'product_name' => 'Alb', 'sku' => $p->sku,
            'quantity' => 2, 'unit_price' => 1000, 'discount_amount' => 0, 'tax_amount' => 0,
            'total_price' => 2000, 'cost_price' => 100, 'cost_source' => 'product_price',
            'inventory_item_id' => InventoryItem::where('product_id', $p->id)->value('id'),
        ]);

        return [$order, $line];
    }

    #[DataProvider('staffRoles')]
    public function test_a_line_discount_on_top_of_an_order_discount_is_measured_together(string $role): void
    {
        $this->actAs($role);
        $p = $this->product();
        [$order, $line] = $this->order($p, orderDiscount: 60);   // 3% of 2,000

        // 40 more on the line: 100 of 2,000 — exactly 5%.
        $this->putJson("/api/v1/admin/orders/{$order->id}/items", [
            'items' => [['id' => $line->id, 'quantity' => 2, 'unit_price' => 1000, 'discount_amount' => 40]],
        ])->assertOk();

        // 41 is the line that tips it.
        $this->assertRefused($this->putJson("/api/v1/admin/orders/{$order->id}/items", [
            'items' => [['id' => $line->id, 'quantity' => 2, 'unit_price' => 1000, 'discount_amount' => 41]],
        ]), 'items.0.discount_amount');
    }

    public function test_a_super_admin_may_give_more_on_an_order(): void
    {
        $this->actAsSuperAdmin();
        $p = $this->product();
        [$order, $line] = $this->order($p, orderDiscount: 100);

        $this->putJson("/api/v1/admin/orders/{$order->id}/items", [
            'items' => [['id' => $line->id, 'quantity' => 2, 'unit_price' => 1000, 'discount_amount' => 500]],
        ])->assertOk();
    }

    // ── Quotations ────────────────────────────────────────────────────────────

    public function test_a_quotation_is_measured_as_a_whole(): void
    {
        $this->actAs('pos_clerk');
        $p = $this->product();

        // 50 off one catalogue line and 50 short on the other: 100 of 2,000.
        $this->postJson('/api/v1/admin/quotations', ['items' => [
            ['product_id' => $p->id, 'product_name' => 'Alb', 'quantity' => 1, 'unit_price' => 1000, 'discount_amount' => 50],
            ['product_id' => $p->id, 'product_name' => 'Alb', 'quantity' => 1, 'unit_price' => 950],
        ]])->assertCreated();

        $this->assertRefused($this->postJson('/api/v1/admin/quotations', ['items' => [
            ['product_id' => $p->id, 'product_name' => 'Alb', 'quantity' => 1, 'unit_price' => 1000, 'discount_amount' => 50],
            ['product_id' => $p->id, 'product_name' => 'Alb', 'quantity' => 1, 'unit_price' => 949],
        ]]), 'items.1.unit_price');
    }

    // ── The older Livewire till ──────────────────────────────────────────────

    public function test_the_legacy_livewire_till_measures_lines_and_order_discount_together(): void
    {
        $this->actingAs($this->actAs('outlet_manager'));
        $p = $this->product();

        Livewire::test(NewSale::class)
            ->set('cart', [[
                'product_id' => $p->id, 'variant_id' => null, 'name' => 'Alb', 'variant_name' => null,
                'sku' => $p->sku, 'unit_price' => 1000.0, 'qty' => 1, 'discount' => 50.0, 'subtotal' => 950.0,
            ]])
            ->set('orderDiscount', 10.0)->set('cashReceived', '5000')
            ->call('processPayment')
            ->assertHasErrors(['orderDiscount']);

        $this->assertSame(0, Order::count());
    }
}
