<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Variant images — each colourway showcases its own look on the storefront.
 *
 * The product_images table already carried product_variant_id; these tests
 * pin the semantics we added on top of it: images stamp to the variant,
 * primary + gallery are scoped PER VARIANT (a variant's hero is independent
 * of the product's), and the product-level gallery never shows variant
 * photos — so a blue cassock shows blue, not the generic set.
 */
class VariantImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('products.view', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('products.edit', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    private function variant(Product $product, string $sku): ProductVariant
    {
        return ProductVariant::create([
            'product_id'   => $product->id,
            'sku'          => $sku,
            'variant_name' => $sku,
            'attributes'   => ['colour' => 'Blue'],
            'is_active'    => true,
        ]);
    }

    private function upload(Product $product, ?int $variantId, int $count = 1): \Illuminate\Testing\TestResponse
    {
        $files = [];
        for ($i = 0; $i < $count; $i++) {
            $files[] = UploadedFile::fake()->image("shot{$i}.jpg", 600, 400);
        }
        $payload = ['images' => $files];
        if ($variantId !== null) {
            $payload['variant_id'] = $variantId;
        }
        return $this->post("/api/v1/admin/products/{$product->id}/images", $payload);
    }

    public function test_images_stamp_to_the_variant_and_first_is_its_primary(): void
    {
        $product = Product::factory()->create();
        $variant = $this->variant($product, 'CLE-PCB-001-BLU');

        $this->upload($product, $variant->id, 2)->assertOk();

        $images = ProductImage::where('product_variant_id', $variant->id)->orderBy('sort_order')->get();
        $this->assertCount(2, $images);
        $this->assertTrue((bool) $images[0]->is_primary);
        $this->assertFalse((bool) $images[1]->is_primary);
    }

    public function test_primary_is_scoped_per_variant(): void
    {
        $product = Product::factory()->create();
        $blue = $this->variant($product, 'CLE-PCB-001-BLU');
        $red  = $this->variant($product, 'CLE-PCB-001-RED');

        // Two product-level images, plus one image each for blue and red.
        $this->upload($product, null, 2)->assertOk();
        $this->upload($product, $blue->id, 1)->assertOk();
        $this->upload($product, $red->id, 1)->assertOk();

        // Each scope has exactly one primary — three heroes, not one.
        $this->assertSame(1, ProductImage::whereNull('product_variant_id')->where('is_primary', true)->count());
        $this->assertSame(1, ProductImage::where('product_variant_id', $blue->id)->where('is_primary', true)->count());
        $this->assertSame(1, ProductImage::where('product_variant_id', $red->id)->where('is_primary', true)->count());

        // Re-heroing the blue variant's second image doesn't touch red or product-level.
        $blueSecond = $this->upload($product, $blue->id, 1)->json('images.0.id');
        $this->putJson("/api/v1/admin/products/{$product->id}/images/{$blueSecond}/primary")->assertOk();

        $this->assertTrue((bool) ProductImage::find($blueSecond)->is_primary);
        $this->assertSame(1, ProductImage::where('product_variant_id', $blue->id)->where('is_primary', true)->count());
        $this->assertSame(1, ProductImage::whereNull('product_variant_id')->where('is_primary', true)->count());
    }

    public function test_product_gallery_excludes_variant_images_but_variant_carries_its_own(): void
    {
        $product = Product::factory()->create();
        $blue = $this->variant($product, 'CLE-PCB-001-BLU');

        $this->upload($product, null, 2)->assertOk();     // product-level gallery
        $this->upload($product, $blue->id, 3)->assertOk(); // blue's own gallery

        $res = $this->getJson("/api/v1/admin/products/{$product->id}")->assertOk();

        // The main gallery shows only the generic shots…
        $this->assertCount(2, $res->json('product.images'));
        // …while the variant showcases its three colourway photos.
        $blueVariant = collect($res->json('product.variants'))->firstWhere('sku', 'CLE-PCB-001-BLU');
        $this->assertCount(3, $blueVariant['images']);
    }

    public function test_deleting_a_variant_hero_promotes_another_of_its_own_images(): void
    {
        $product = Product::factory()->create();
        $blue = $this->variant($product, 'CLE-PCB-001-BLU');
        $this->upload($product, null, 1)->assertOk();
        $this->upload($product, $blue->id, 2)->assertOk();

        $heroId = ProductImage::where('product_variant_id', $blue->id)->where('is_primary', true)->value('id');
        $this->deleteJson("/api/v1/admin/products/{$product->id}/images/{$heroId}")->assertOk();

        // The remaining blue image becomes the new blue hero — not a product-level one.
        $newHero = ProductImage::where('product_variant_id', $blue->id)->where('is_primary', true)->first();
        $this->assertNotNull($newHero);
        $this->assertSame(1, ProductImage::whereNull('product_variant_id')->where('is_primary', true)->count());
    }

    public function test_a_variant_from_another_product_is_rejected(): void
    {
        $product = Product::factory()->create();
        $other   = Product::factory()->create();
        $foreign = $this->variant($other, 'OTH-001-BLU');

        $this->upload($product, $foreign->id, 1)->assertStatus(422);
    }
}
