<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A sale price is a discount the shop puts on the shelf.
 *
 * With every till, quotation and campaign held to 5%, a product's sale_price
 * was the one discount left open: anyone with products.edit could mark a
 * 20,000 cope down to 10,000, and the storefront, Neema and the till would all
 * honour it. So a sale price more than 5% under its regular price is the
 * super_admin's to save — enforced on the price record itself, whichever
 * screen or endpoint writes it. Prices already on the books are not touched,
 * and an edit that does not make an existing markdown deeper still saves.
 */
class SalePriceMaximumTest extends TestCase
{
    use RefreshDatabase;

    private const SENTENCE = 'The most anyone can give is 5%. Larger discounts are set by the owner.';

    protected function setUp(): void
    {
        parent::setUp();
        config(['pos.discount_cap_percent' => 5.0]);
        DB::table('currencies')->updateOrInsert(
            ['code' => 'KES'],
            ['name' => 'KES', 'symbol' => 'KES', 'exchange_rate' => 1, 'is_base' => true, 'is_active' => true,
             'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function editor(): User
    {
        Role::findOrCreate('admin', 'sanctum');
        $user = User::factory()->create();
        $user->assignRole('admin');
        foreach (['products.view', 'products.edit', 'products.create'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function owner(): User
    {
        Role::findOrCreate('super_admin', 'sanctum');
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /** Written as existing data — before anybody signs in. */
    private function product(float $regular = 1000, ?float $sale = null): Product
    {
        $product = Product::factory()->create();
        ProductPrice::create([
            'product_id' => $product->id, 'product_variant_id' => null, 'currency_code' => 'KES',
            'regular_price' => $regular, 'sale_price' => $sale,
        ]);

        return $product;
    }

    private function putPrice(Product $p, float $regular, ?float $sale)
    {
        return $this->putJson("/api/v1/admin/products/{$p->id}", [
            'prices' => [['currency_code' => 'KES', 'regular_price' => $regular, 'sale_price' => $sale]],
        ]);
    }

    private function assertRefused($response): void
    {
        $response->assertStatus(422)->assertJsonPath('message', self::SENTENCE);
    }

    private function salePrice(Product $p): ?float
    {
        $v = ProductPrice::where('product_id', $p->id)->whereNull('product_variant_id')->value('sale_price');

        return $v === null ? null : (float) $v;
    }

    // ── The product's own price ──────────────────────────────────────────────

    public function test_an_editor_cannot_mark_a_product_down_more_than_5_percent(): void
    {
        $p = $this->product();
        $this->editor();

        $this->assertRefused($this->putPrice($p, 1000, 940));
        $this->assertNull($this->salePrice($p));

        $this->putPrice($p, 1000, 950)->assertOk();
        $this->assertSame(950.0, $this->salePrice($p));
    }

    public function test_the_owner_may_mark_a_product_down_20_percent(): void
    {
        $p = $this->product();
        $this->owner();

        $this->putPrice($p, 1000, 800)->assertOk();
        $this->assertSame(800.0, $this->salePrice($p));
    }

    public function test_raising_the_regular_price_under_a_sale_price_is_a_deeper_markdown(): void
    {
        $p = $this->product(1000, 960);
        $this->editor();

        $this->assertRefused($this->putPrice($p, 1100, 960));
    }

    // ── Variants ─────────────────────────────────────────────────────────────

    public function test_a_variant_sale_price_is_held_to_the_same_rule(): void
    {
        $p = $this->product();
        $this->editor();

        $payload = fn (float $sale) => [
            'sku' => 'ALB-' . uniqid(), 'variant_name' => 'Large',
            'prices' => [['currency_code' => 'KES', 'regular_price' => 1000, 'sale_price' => $sale]],
        ];
        $this->assertRefused($this->postJson("/api/v1/admin/products/{$p->id}/variants", $payload(900)));
        $this->assertSame(0, ProductVariant::where('product_id', $p->id)->count());

        $vid = $this->postJson("/api/v1/admin/products/{$p->id}/variants", $payload(950))
            ->assertSuccessful()->json('variant.id');

        $this->assertRefused($this->putJson("/api/v1/admin/products/{$p->id}/variants/{$vid}", [
            'prices' => [['currency_code' => 'KES', 'regular_price' => 1000, 'sale_price' => 900]],
        ]));
        $this->assertSame(950.0, (float) ProductPrice::where('product_variant_id', $vid)->value('sale_price'));
    }

    // ── What is already on the books ─────────────────────────────────────────

    public function test_an_existing_deep_markdown_is_left_alone_and_survives_an_unrelated_edit(): void
    {
        $p      = $this->product(1000, 800);   // 20%, from before the rule
        $before = ProductPrice::where('product_id', $p->id)->first()->toArray();
        $this->editor();

        // The product form sends its prices back unchanged with every save.
        $this->putPrice($p, 1000, 800)->assertOk();
        $this->putJson("/api/v1/admin/products/{$p->id}", ['brand' => 'Bethany'])->assertOk();

        $this->assertEquals($before, ProductPrice::where('product_id', $p->id)->first()->toArray());

        // Taking it off, or making it shallower, is always fine.
        $this->putPrice($p, 1000, 900)->assertOk();
        $this->putPrice($p, 1000, null)->assertOk();
        $this->assertNull($this->salePrice($p));
    }
}
