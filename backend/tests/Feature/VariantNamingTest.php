<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Variant naming — colour leads, the rest explains.
 *
 * Merchandising names read "{Colour} {Garment} + {trim}": the colour is the
 * headline, attributes sharing a value group into plain language, and a lone
 * trim shows just its value. ProductVariant::composeName is the single source
 * of truth; addVariant applies it when the generator asks (auto_name).
 */
class VariantNamingTest extends TestCase
{
    use RefreshDatabase;

    // ── The naming rule, unit-level ──────────────────────────────────────────

    public function test_colour_leads_and_a_single_trim_shows_just_its_value(): void
    {
        $this->assertSame(
            'White Princes Cassock + Black',
            ProductVariant::composeName('Princes Cassock', ['Colour' => 'White', 'Trim' => 'Black']),
        );
    }

    public function test_attributes_sharing_a_value_group_into_plain_language(): void
    {
        $this->assertSame(
            'White Princes Cassock + Black Piping, Buttons and Pleats',
            ProductVariant::composeName('Princes Cassock', [
                'Colour' => 'White', 'Piping' => 'Black', 'Buttons' => 'Black', 'Pleats' => 'Black',
            ]),
        );
    }

    public function test_different_trim_values_are_labelled_for_disambiguation(): void
    {
        $this->assertSame(
            'White Princes Cassock + Black Piping, Gold Buttons',
            ProductVariant::composeName('Princes Cassock', [
                'Colour' => 'White', 'Piping' => 'Black', 'Buttons' => 'Gold',
            ]),
        );
    }

    public function test_the_products_own_colour_suffix_is_stripped_so_the_variant_colour_leads(): void
    {
        // Product entered as "Princes Cassock – Blue"; the variant is White-bodied.
        $this->assertSame(
            'White Princes Cassock + Red',
            ProductVariant::composeName('Princes Cassock – Blue', ['Colour' => 'White', 'Trim' => 'Red']),
        );
    }

    public function test_a_hyphenated_word_in_the_name_is_not_mistaken_for_a_colour_suffix(): void
    {
        $this->assertSame(
            'Navy T-Shirt',
            ProductVariant::composeName('T-Shirt', ['Colour' => 'Navy']),
        );
    }

    public function test_colour_only_variant_has_no_suffix(): void
    {
        $this->assertSame(
            'White Princes Cassock',
            ProductVariant::composeName('Princes Cassock', ['Colour' => 'White']),
        );
    }

    public function test_first_attribute_leads_when_no_colour_attribute_exists(): void
    {
        $this->assertSame(
            'Large Robe + Cotton',
            ProductVariant::composeName('Robe', ['Size' => 'Large', 'Fabric' => 'Cotton']),
        );
    }

    // ── Through the endpoint ─────────────────────────────────────────────────

    public function test_generator_auto_names_the_variant_server_side(): void
    {
        DB::table('currencies')->insert([
            'code' => 'KES', 'name' => 'Kenyan Shilling', 'symbol' => 'KSh',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('products.view', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('products.edit', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        $product = Product::factory()->create();
        DB::table('product_translations')->insert([
            'product_id' => $product->id, 'language_code' => 'en',
            'name' => 'Princes Cassock – Blue',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // No variant_name sent — auto_name asks the server to compose it.
        $res = $this->postJson("/api/v1/admin/products/{$product->id}/variants", [
            'sku'        => 'CLE-PCB-001-WHI-BLA',
            'auto_name'  => true,
            'attributes' => ['Colour' => 'White', 'Piping' => 'Black', 'Buttons' => 'Black', 'Pleats' => 'Black'],
            'prices'     => [['currency_code' => 'KES', 'regular_price' => 13000]],
        ])->assertStatus(201);

        $res->assertJsonPath('variant.variant_name', 'White Princes Cassock + Black Piping, Buttons and Pleats');
    }
}
