<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\InventoryItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `pos.discount` is enforced, and capped.
 *
 * Until now the permission was granted to pos_clerk and outlet_manager, shown
 * on the Roles screen, and checked nowhere — all three POS entry points took
 * `discount_type` / `discount_value` with `min:0` and no upper bound. A cashier
 * could discount any sale by any amount.
 *
 * The cap is checked against the RESOLVED discount amount rather than the input,
 * so a flat discount cannot walk around a percentage ceiling. Both facts are
 * asserted below, at both the line and cart level, on every entry point.
 */
class PosDiscountPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outlet = Outlet::factory()->create();
    }

    /** @param list<string> $extra */
    private function actingAsCashier(array $extra = []): User
    {
        $user = User::factory()->create();
        foreach (array_merge(['pos.access'], $extra) as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        $user->outlets()->attach($this->outlet->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        // createSale refuses before it reaches any discount if the drawer is
        // shut, so every case needs one open.
        CashRegister::create([
            'outlet_id'       => $this->outlet->id,
            'register_name'   => 'Till',
            'status'          => 'open',
            'currency_code'   => 'KES',
            'opening_balance' => 1000,
            'expected_cash'   => 1000,
            'opened_by'       => $user->id,
            'opened_at'       => now(),
        ]);

        return $user;
    }

    private function sellableProduct(float $price = 1000.0): Product
    {
        $product = Product::factory()->create();
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

    /** @param array<string,mixed> $overrides */
    private function salePayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'outlet_id'      => $this->outlet->id,
            'payment_method' => 'cash',
            // A cash sale is refused unless the drawer was handed enough to
            // cover it; comfortably over the largest total any case here makes.
            'cash_received'  => 5000,
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'unit_price' => 1000,
            ]],
        ], $overrides);
    }

    private function postSale(array $payload)
    {
        return $this->postJson('/api/v1/admin/pos/sales', $payload);
    }

    // ── The permission is enforced at all ────────────────────────────────────

    public function test_a_cashier_without_pos_discount_cannot_discount_at_all(): void
    {
        $this->actingAsCashier();                     // no pos.discount
        $product = $this->sellableProduct();

        $payload = $this->salePayload($product);
        $payload['items'][0]['discount_type']  = 'percent';
        $payload['items'][0]['discount_value'] = 1;   // even 1% is refused

        $this->postSale($payload)
            ->assertForbidden()
            ->assertJsonFragment(['message' => 'You are not permitted to apply discounts.']);
    }

    public function test_a_sale_with_no_discount_is_unaffected(): void
    {
        // The ordinary sale must not acquire a permission requirement.
        $this->actingAsCashier();
        $product = $this->sellableProduct();

        $this->postSale($this->salePayload($product))->assertSuccessful();
    }

    // ── The ceiling ──────────────────────────────────────────────────────────

    public function test_a_discount_at_the_ceiling_is_allowed(): void
    {
        $this->actingAsCashier(['pos.discount']);
        $product = $this->sellableProduct();

        $payload = $this->salePayload($product);
        $payload['items'][0]['discount_type']  = 'percent';
        $payload['items'][0]['discount_value'] = 5;   // exactly the cap

        $this->postSale($payload)->assertSuccessful();
    }

    public function test_a_discount_above_the_ceiling_is_refused(): void
    {
        $this->actingAsCashier(['pos.discount']);
        $product = $this->sellableProduct();

        $payload = $this->salePayload($product);
        $payload['items'][0]['discount_type']  = 'percent';
        $payload['items'][0]['discount_value'] = 6;

        $this->postSale($payload)
            ->assertForbidden()
            ->assertJsonPath('message', fn ($m) => str_contains($m, '5% ceiling'));
    }

    /**
     * The reason the cap is applied to the resolved amount and not the input:
     * 500 off a 1,000 line is 50% however it was typed.
     */
    public function test_a_flat_discount_cannot_walk_around_the_percentage_ceiling(): void
    {
        $this->actingAsCashier(['pos.discount']);
        $product = $this->sellableProduct();

        $payload = $this->salePayload($product);
        $payload['items'][0]['discount_type']  = 'flat';
        $payload['items'][0]['discount_value'] = 500;   // 50% of a 1,000 line

        $this->postSale($payload)->assertForbidden();
    }

    public function test_a_flat_discount_within_the_ceiling_is_allowed(): void
    {
        $this->actingAsCashier(['pos.discount']);
        $product = $this->sellableProduct();

        $payload = $this->salePayload($product);
        $payload['items'][0]['discount_type']  = 'flat';
        $payload['items'][0]['discount_value'] = 50;    // exactly 5% of 1,000

        $this->postSale($payload)->assertSuccessful();
    }

    // ── The cart level is capped too ─────────────────────────────────────────

    public function test_the_cart_discount_is_capped_as_well_as_the_line(): void
    {
        $this->actingAsCashier(['pos.discount']);
        $product = $this->sellableProduct();

        $payload = $this->salePayload($product, [
            'cart_discount_type'  => 'percent',
            'cart_discount_value' => 20,
        ]);

        $this->postSale($payload)
            ->assertForbidden()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'cart discount'));
    }

    // ── The override ─────────────────────────────────────────────────────────

    public function test_an_override_holder_may_exceed_the_ceiling(): void
    {
        $this->actingAsCashier(['pos.discount', 'pos.discount_override']);
        $product = $this->sellableProduct();

        $payload = $this->salePayload($product);
        $payload['items'][0]['discount_type']  = 'percent';
        $payload['items'][0]['discount_value'] = 40;

        $this->postSale($payload)->assertSuccessful();
    }

    // ── The other two entry points ───────────────────────────────────────────

    public function test_the_pending_order_path_is_capped_too(): void
    {
        // createPendingOrder is a separate method with its own copy of the
        // discount arithmetic — the cap has to reach all three.
        $this->actingAsCashier(['pos.discount']);
        $product = $this->sellableProduct();

        $this->postJson('/api/v1/admin/pos/pending-order', [
            'outlet_id' => $this->outlet->id,
            'items'     => [[
                'product_id'     => $product->id,
                'quantity'       => 1,
                'unit_price'     => 1000,
                'discount_type'  => 'percent',
                'discount_value' => 30,
            ]],
        ])->assertForbidden();
    }
}
