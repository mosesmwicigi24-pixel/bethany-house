<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Pos\NewSale;
use App\Models\CashRegister;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use App\Support\DiscountRule;
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
 * The owner's rule, 2026-10-03: "Our products are exotic and exclusive. I did
 * not want discounts given. The max is 5%. Unless the super admin set the %
 * discount, we can only give the discount up to 5%."
 *
 * So every staff path that takes a discount refuses anything above 5% of what
 * it applies to — for every role, whatever permissions it holds. In particular
 * `pos.discount_override`, which used to lift the ceiling for outlet managers
 * and admins, no longer does. Only a super_admin may go further.
 *
 * The refusal is a 422 that names the field and says, in one sentence, why.
 */
class DiscountMaximumTest extends TestCase
{
    use RefreshDatabase;

    private const SENTENCE = 'The most anyone can give is 5%. Larger discounts are set by the owner.';

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['pos.discount_cap_percent' => 5.0]);
        $this->outlet = Outlet::factory()->create();
    }

    /** Every human role that can reach a discount field. */
    public static function staffRoles(): array
    {
        return [
            'clerk'          => ['pos_clerk'],
            'outlet manager' => ['outlet_manager'],
            'admin'          => ['admin'],
            'finance'        => ['finance_manager'],
        ];
    }

    /**
     * A member of $role holding every permission that touches a discount —
     * including pos.discount_override, which must no longer lift anything.
     */
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
            'outlet_id'       => $this->outlet->id,
            'register_name'   => 'Till ' . $user->id,
            'status'          => 'open',
            'currency_code'   => 'KES',
            'opening_balance' => 1000,
            'expected_cash'   => 1000,
            'opened_by'       => $user->id,
            'opened_at'       => now(),
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
            'product_id' => $product->id, 'language_code' => 'en', 'name' => 'Chasuble',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_prices')->insert([
            'product_id' => $product->id, 'product_variant_id' => null,
            'currency_code' => 'KES', 'regular_price' => $price, 'cost_price' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        InventoryItem::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'outlet_id' => $this->outlet->id, 'quantity_on_hand' => 100,
            'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);

        return $product;
    }

    /** One 1,000 line, optionally discounted. */
    private function line(Product $product, string $type = 'none', float $value = 0): array
    {
        return [
            'product_id'     => $product->id,
            'quantity'       => 1,
            'unit_price'     => 1000,
            'discount_type'  => $type,
            'discount_value' => $value,
        ];
    }

    private function sale(array $items, array $extra = [])
    {
        return $this->postJson('/api/v1/admin/pos/sales', array_merge([
            'outlet_id'      => $this->outlet->id,
            'payment_method' => 'cash',
            'cash_received'  => 5000,
            'items'          => $items,
        ], $extra));
    }

    private function pendingOrder(array $items, array $extra = [])
    {
        return $this->postJson('/api/v1/admin/pos/pending-order', array_merge([
            'outlet_id' => $this->outlet->id,
            'items'     => $items,
        ], $extra));
    }

    private function assertRefused($response, string $field): void
    {
        // The field names carry dots (items.0.discount_value), so they are read
        // as keys, not as a JSON path.
        $response->assertStatus(422)->assertJsonPath('message', self::SENTENCE);
        $this->assertSame([self::SENTENCE], $response->json('errors')[$field] ?? null,
            "refused on {$field}: " . $response->getContent());
    }

    // ── POS: a sale ───────────────────────────────────────────────────────────

    #[DataProvider('staffRoles')]
    public function test_a_pos_sale_refuses_a_6_percent_line_discount(string $role): void
    {
        $this->actAs($role);
        $p = $this->product();

        $this->assertRefused($this->sale([$this->line($p, 'percent', 6)]), 'items.0.discount_value');
        $this->assertSame(0, Order::count(), 'nothing was rung up');
    }

    #[DataProvider('staffRoles')]
    public function test_a_pos_sale_accepts_a_5_percent_line_discount(string $role): void
    {
        $this->actAs($role);
        $p = $this->product();

        $this->sale([$this->line($p, 'percent', 5)])->assertSuccessful();
        $this->assertSame(950.0, (float) Order::first()->total_amount);
    }

    #[DataProvider('staffRoles')]
    public function test_a_pos_sale_refuses_a_6_percent_cart_discount(string $role): void
    {
        $this->actAs($role);
        $p = $this->product();

        $this->assertRefused(
            $this->sale([$this->line($p)], ['cart_discount_type' => 'percent', 'cart_discount_value' => 6]),
            'cart_discount_value',
        );
    }

    #[DataProvider('staffRoles')]
    public function test_a_pos_sale_accepts_a_5_percent_cart_discount(string $role): void
    {
        $this->actAs($role);
        $p = $this->product();

        $this->sale([$this->line($p)], ['cart_discount_type' => 'percent', 'cart_discount_value' => 5])
            ->assertSuccessful();
    }

    /** 51 off a 1,000 line is 5.1% however it was typed. */
    public function test_a_flat_discount_over_5_percent_of_the_line_is_refused(): void
    {
        $this->actAs('outlet_manager');
        $p = $this->product();

        $this->assertRefused($this->sale([$this->line($p, 'flat', 51)]), 'items.0.discount_value');
        $this->sale([$this->line($p, 'flat', 50)])->assertSuccessful();
    }

    public function test_the_field_names_the_line_that_broke_the_rule(): void
    {
        $this->actAs('pos_clerk');
        $p = $this->product();

        $this->assertRefused(
            $this->sale([$this->line($p, 'percent', 5), $this->line($p, 'percent', 7)]),
            'items.1.discount_value',
        );
    }

    // ── POS: a pending order, created and then edited ─────────────────────────

    #[DataProvider('staffRoles')]
    public function test_a_pending_order_refuses_6_and_accepts_5(string $role): void
    {
        $this->actAs($role);
        $p = $this->product();

        $this->assertRefused($this->pendingOrder([$this->line($p, 'percent', 6)]), 'items.0.discount_value');
        $this->assertRefused(
            $this->pendingOrder([$this->line($p)], ['cart_discount_type' => 'percent', 'cart_discount_value' => 6]),
            'cart_discount_value',
        );
        $this->pendingOrder([$this->line($p, 'percent', 5)])->assertSuccessful();
    }

    #[DataProvider('staffRoles')]
    public function test_editing_a_pending_order_refuses_6_and_accepts_5(string $role): void
    {
        $this->actAs($role);
        $p  = $this->product();
        $id = $this->pendingOrder([$this->line($p)])->assertSuccessful()->json('order_id');

        $this->assertRefused(
            $this->patchJson("/api/v1/admin/pos/pending-order/{$id}", ['items' => [$this->line($p, 'percent', 6)]]),
            'items.0.discount_value',
        );
        $this->assertRefused(
            $this->patchJson("/api/v1/admin/pos/pending-order/{$id}", [
                'items' => [$this->line($p)], 'cart_discount_type' => 'flat', 'cart_discount_value' => 60,
            ]),
            'cart_discount_value',
        );
        $this->assertSame(1000.0, (float) Order::find($id)->total_amount, 'the refused edits changed nothing');

        $this->patchJson("/api/v1/admin/pos/pending-order/{$id}", ['items' => [$this->line($p, 'percent', 5)]])
            ->assertSuccessful();
        $this->assertSame(950.0, (float) Order::find($id)->total_amount);
    }

    // ── Quotations ────────────────────────────────────────────────────────────

    private function quotation(float $discount)
    {
        return $this->postJson('/api/v1/admin/quotations', [
            'customer_first_name' => 'Jane',
            'items' => [
                ['product_name' => 'Cope', 'quantity' => 2, 'unit_price' => 1000, 'discount_amount' => $discount],
            ],
        ]);
    }

    #[DataProvider('staffRoles')]
    public function test_a_quotation_refuses_6_and_accepts_5(string $role): void
    {
        $this->actAs($role);

        // The line is 2 × 1,000 = 2,000, so 5% is 100.
        $this->assertRefused($this->quotation(120), 'items.0.discount_amount');
        $this->assertSame(0, Quotation::count());

        $id = $this->quotation(100)->assertCreated()->json('quotation.id');

        $this->assertRefused($this->putJson("/api/v1/admin/quotations/{$id}", [
            'items' => [['product_name' => 'Cope', 'quantity' => 2, 'unit_price' => 1000, 'discount_amount' => 101]],
        ]), 'items.0.discount_amount');
        $this->assertSame(100.0, (float) Quotation::find($id)->discount_amount);
    }

    public function test_a_super_admin_may_quote_30_percent(): void
    {
        $this->actAsSuperAdmin();

        $id = $this->quotation(600)->assertCreated()->json('quotation.id');
        $this->assertSame(600.0, (float) Quotation::find($id)->discount_amount);
    }

    // ── Editing the lines of an existing order ───────────────────────────────

    private function order(Product $product, float $lineDiscount = 0): array
    {
        $order = Order::factory()->create([
            'order_type' => 'pos', 'status' => 'processing', 'payment_status' => 'pending',
            'outlet_id' => $this->outlet->id, 'currency_code' => 'KES',
            'subtotal' => 2000, 'discount_amount' => 0, 'tax_amount' => 0,
            'prices_include_tax' => false, 'shipping_amount' => 0,
            'total_amount' => 2000 - $lineDiscount,
        ]);
        $line = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Chasuble',
            'sku' => $product->sku, 'quantity' => 2, 'unit_price' => 1000,
            'discount_amount' => $lineDiscount, 'tax_amount' => 0, 'total_price' => 2000 - $lineDiscount,
            'cost_price' => 100, 'cost_source' => 'product_price',
            'inventory_item_id' => InventoryItem::where('product_id', $product->id)->value('id'),
        ]);

        return [$order, $line];
    }

    #[DataProvider('staffRoles')]
    public function test_an_order_line_edit_refuses_6_and_accepts_5(string $role): void
    {
        $this->actAs($role);
        $p = $this->product();
        [$order, $line] = $this->order($p);

        $this->assertRefused($this->putJson("/api/v1/admin/orders/{$order->id}/items", [
            'items' => [['id' => $line->id, 'quantity' => 2, 'unit_price' => 1000, 'discount_amount' => 101]],
        ]), 'items.0.discount_amount');
        $this->assertSame(0.0, (float) $line->fresh()->discount_amount);

        $this->putJson("/api/v1/admin/orders/{$order->id}/items", [
            'items' => [['id' => $line->id, 'quantity' => 2, 'unit_price' => 1000, 'discount_amount' => 100]],
        ])->assertOk();
        $this->assertSame(100.0, (float) $line->fresh()->discount_amount);
    }

    public function test_a_new_order_line_is_capped_too(): void
    {
        $this->actAs('admin');
        $p = $this->product();
        [$order, $line] = $this->order($p);

        $this->assertRefused($this->putJson("/api/v1/admin/orders/{$order->id}/items", [
            'items' => [
                ['id' => $line->id, 'quantity' => 2, 'unit_price' => 1000],
                ['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 1000, 'discount_amount' => 60],
            ],
        ]), 'items.1.discount_amount');
    }

    /**
     * A flat discount is a fixed sum, so shrinking the quantity under it makes
     * it a bigger share of the line — 100 off 2,000 is 5%, off 1,000 it is 10%.
     */
    public function test_cutting_the_quantity_under_a_flat_discount_cannot_walk_past_the_maximum(): void
    {
        $this->actAs('outlet_manager');
        $p = $this->product();
        [$order, $line] = $this->order($p, lineDiscount: 100);

        $this->assertRefused($this->putJson("/api/v1/admin/orders/{$order->id}/items", [
            'items' => [['id' => $line->id, 'quantity' => 1]],
        ]), 'items.0.discount_amount');
    }

    public function test_a_super_admin_may_give_30_percent_on_an_order_line(): void
    {
        $this->actAsSuperAdmin();
        $p = $this->product();
        [$order, $line] = $this->order($p);

        $this->putJson("/api/v1/admin/orders/{$order->id}/items", [
            'items' => [['id' => $line->id, 'quantity' => 2, 'unit_price' => 1000, 'discount_amount' => 600]],
        ])->assertOk();
        $this->assertSame(600.0, (float) $line->fresh()->discount_amount);
    }

    // ── The super_admin and the till ─────────────────────────────────────────

    /**
     * The owner never works the till (role hardening 1B), so a super_admin's
     * 30% cannot be shown through a POS endpoint — it is refused there for a
     * different reason before any discount is read. The rule itself is what
     * every POS path calls, and it lets the super_admin through.
     */
    public function test_the_rule_lets_a_super_admin_exceed_the_maximum_and_nobody_else(): void
    {
        $owner = $this->actAsSuperAdmin();
        $this->assertNull(DiscountRule::refusal($owner, 300, 1000));

        foreach (array_keys(self::staffRoles()) as $label) {
            $role = self::staffRoles()[$label][0];
            $staff = $this->actAs($role);
            $this->assertSame(self::SENTENCE, DiscountRule::refusal($staff, 60, 1000), $label);
            $this->assertNull(DiscountRule::refusal($staff, 50, 1000), $label);
        }
    }

    public function test_a_super_admin_at_the_till_is_refused_by_the_owner_rule_not_the_discount(): void
    {
        $this->actAsSuperAdmin();
        $p = $this->product();

        $this->sale([$this->line($p, 'percent', 30)])
            ->assertStatus(403)
            ->assertJsonPath('code', 'OWNER_DOES_NOT_TRANSACT');
    }

    // ── The older Livewire till, which is still routed ───────────────────────

    public function test_the_legacy_livewire_till_is_capped_too(): void
    {
        $this->actingAs($this->actAs('outlet_manager'));
        $p    = $this->product();
        $line = [
            'product_id' => $p->id, 'variant_id' => null, 'name' => 'Chasuble', 'variant_name' => null,
            'sku' => $p->sku, 'unit_price' => 1000.0, 'qty' => 1, 'discount' => 60.0, 'subtotal' => 940.0,
        ];

        Livewire::test(NewSale::class)
            ->set('cart', [$line])->set('cashReceived', '5000')
            ->call('processPayment')
            ->assertHasErrors(['cart.0.discount']);

        Livewire::test(NewSale::class)
            ->set('cart', [array_merge($line, ['discount' => 0.0, 'subtotal' => 1000.0])])
            ->set('orderDiscount', 60.0)->set('cashReceived', '5000')
            ->call('processPayment')
            ->assertHasErrors(['orderDiscount']);

        $this->assertSame(0, Order::count());
    }

    // ── The console is told the maximum ──────────────────────────────────────

    public function test_the_signed_in_user_carries_their_discount_maximum(): void
    {
        $this->actAs('outlet_manager');
        $this->getJson('/api/v1/admin/auth/me')->assertOk()->assertJsonPath('user.discount_cap_percent', 5);

        $this->actAsSuperAdmin();
        $this->getJson('/api/v1/admin/auth/me')->assertOk()->assertJsonPath('user.discount_cap_percent', null);
    }

    // ── Nothing already on the books moves ───────────────────────────────────

    public function test_orders_already_carrying_a_large_discount_are_left_alone(): void
    {
        $p = $this->product();
        [$order, $line] = $this->order($p, lineDiscount: 400);   // 20%, from before the rule
        $before = [$order->fresh()->toArray(), $line->fresh()->toArray()];

        // Ordinary work goes on around it.
        $this->actAs('pos_clerk');
        $this->sale([$this->line($p, 'percent', 5)])->assertSuccessful();

        $this->assertEquals($before, [$order->fresh()->toArray(), $line->fresh()->toArray()]);
    }

    public function test_editing_something_else_on_an_old_order_does_not_strip_its_discount(): void
    {
        // A 20% line from before the rule may still have its quantity raised:
        // the rule stops a discount getting bigger, it does not reprice history.
        $this->actAs('admin');
        $p = $this->product();
        [$order, $line] = $this->order($p, lineDiscount: 400);

        $this->putJson("/api/v1/admin/orders/{$order->id}/items", [
            'items' => [['id' => $line->id, 'quantity' => 3]],
        ])->assertOk();
        $this->assertSame(400.0, (float) $line->fresh()->discount_amount);
    }
}
