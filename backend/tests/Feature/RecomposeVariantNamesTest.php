<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The one-off variants:recompose-names command — renames auto-generated
 * variant names to the colour-led scheme, leaves hand-edited names alone,
 * and honours --dry-run.
 */
class RecomposeVariantNamesTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name): Product
    {
        $product = Product::factory()->create();
        DB::table('product_translations')->insert([
            'product_id' => $product->id, 'language_code' => 'en',
            'name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $product->load('translations');
    }

    private function variant(Product $product, string $name, array $attrs): ProductVariant
    {
        return ProductVariant::create([
            'product_id'   => $product->id,
            'sku'          => 'SKU-' . uniqid(),
            'variant_name' => $name,
            'attributes'   => $attrs,
            'is_active'    => true,
        ]);
    }

    public function test_it_recomposes_legacy_slash_joined_names(): void
    {
        $product = $this->product('Princes Cassock – Blue');
        // Legacy auto-name = attribute values joined with " / ".
        $v = $this->variant($product, 'White / Black / Black / Black', [
            'Colour' => 'White', 'Piping' => 'Black', 'Buttons' => 'Black', 'Pleats' => 'Black',
        ]);

        $this->artisan('variants:recompose-names')
            ->expectsOutputToContain('Renamed 1 variant(s).')
            ->assertSuccessful();

        // Colour leads and all three trim labels appear; their order follows
        // however the DB returns the jsonb attribute keys, which is fine.
        $name = $v->fresh()->variant_name;
        $this->assertStringStartsWith('White Princes Cassock + Black ', $name);
        foreach (['Piping', 'Buttons', 'Pleats'] as $label) {
            $this->assertStringContainsString($label, $name);
        }
    }

    public function test_dry_run_writes_nothing(): void
    {
        $product = $this->product('Princes Cassock');
        $v = $this->variant($product, 'White / Black', ['Colour' => 'White', 'Trim' => 'Black']);

        $this->artisan('variants:recompose-names --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame('White / Black', $v->fresh()->variant_name);
    }

    public function test_hand_edited_names_are_left_alone_by_default(): void
    {
        $product = $this->product('Princes Cassock');
        // Not a slash-join of its attributes → treated as intentional.
        $v = $this->variant($product, 'The Bishop Special', ['Colour' => 'White', 'Trim' => 'Black']);

        $this->artisan('variants:recompose-names')
            ->expectsOutputToContain('Skipped 1 hand-edited')
            ->assertSuccessful();

        $this->assertSame('The Bishop Special', $v->fresh()->variant_name);
    }

    public function test_all_flag_forces_even_hand_edited_names(): void
    {
        $product = $this->product('Princes Cassock');
        $v = $this->variant($product, 'The Bishop Special', ['Colour' => 'White', 'Trim' => 'Black']);

        $this->artisan('variants:recompose-names --all')->assertSuccessful();

        $this->assertSame('White Princes Cassock + Black', $v->fresh()->variant_name);
    }

    public function test_product_scope_limits_the_rename(): void
    {
        $a = $this->product('Princes Cassock');
        $b = $this->product('Deacon Robe');
        $va = $this->variant($a, 'White / Black', ['Colour' => 'White', 'Trim' => 'Black']);
        $vb = $this->variant($b, 'Navy / Gold', ['Colour' => 'Navy', 'Trim' => 'Gold']);

        $this->artisan("variants:recompose-names --product={$a->id}")->assertSuccessful();

        $this->assertSame('White Princes Cassock + Black', $va->fresh()->variant_name);
        $this->assertSame('Navy / Gold', $vb->fresh()->variant_name); // untouched
    }
}
