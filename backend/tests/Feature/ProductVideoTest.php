<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\User;
use App\Services\ProductVideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Product video — the one short clip a product carries for the storefront's
 * hover-to-play cards (products.video_url).
 *
 * Pins the contract the storefront relies on: an upload stores the clip on
 * the public disk under products/{id}/ and bakes an absolute URL into the
 * product; the public product payload exposes it as `video_url` (null when
 * there is none); replacing removes the old file; deleting clears both.
 *
 * The END STATE below is unchanged by moving conversion onto the queue — what
 * changed is when it arrives. The request now answers 202 with the clip still
 * converting, so these read the settled url off the PRODUCT rather than the
 * response. QUEUE_CONNECTION is `sync` here, so the job has run by the time
 * the request returns; ProductVideoConversionTest covers the queued behaviour
 * itself.
 *
 * CI has no ffmpeg, so these tests exercise the store-as-is path. When
 * ffmpeg IS present the conversion path is covered by
 * test_ffmpeg_converts_to_a_small_mp4 (skipped otherwise).
 */
class ProductVideoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        // The raw upload is parked here before the job converts it — faked so a
        // test run leaves nothing behind in storage/app.
        Storage::fake('local');
        // Store-as-is path by default: the fake clips below are not real
        // video, so conversion would (rightly) refuse them. The one test that
        // exercises ffmpeg opts back in with a real generated clip.
        config(['video.ffmpeg' => '']);

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('products.view', 'sanctum'));
        $user->givePermissionTo(Permission::findOrCreate('products.edit', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    private function product(): Product
    {
        $product = Product::factory()->create(['status' => 'active', 'published_at' => now()]);
        ProductTranslation::create([
            'product_id' => $product->id,
            'language_code' => 'en',
            'name' => 'Chalice Royale',
        ]);

        return $product;
    }

    private function upload(Product $product, UploadedFile $file): \Illuminate\Testing\TestResponse
    {
        // Multipart (a real file), but asking for JSON so validation failures
        // come back as 422 bodies rather than a redirect.
        return $this->post(
            "/api/v1/admin/products/{$product->id}/video",
            ['video' => $file],
            ['Accept' => 'application/json'],
        );
    }

    /** The url once the (synchronous, in tests) conversion job has run. */
    private function settledUrl(Product $product): string
    {
        $product->refresh();
        $this->assertNull($product->video_status, 'conversion did not settle');
        $this->assertNotNull($product->video_url);

        return $product->video_url;
    }

    /** Storage path of a public-disk URL the way ImageService/ProductVideoService bake them. */
    private function pathOf(string $url): string
    {
        return ltrim(preg_replace('#^/storage/#', '', parse_url($url, PHP_URL_PATH)), '/');
    }

    public function test_upload_stores_the_clip_and_bakes_an_absolute_url_onto_the_product(): void
    {
        $product = $this->product();

        $res = $this->upload($product, UploadedFile::fake()->create('clip.mp4', 512, 'video/mp4'));
        $res->assertStatus(202)->assertJsonStructure(['message', 'video_status', 'video_url']);

        $url = $this->settledUrl($product);
        $this->assertStringStartsWith(rtrim(config('app.url'), '/')."/storage/products/{$product->id}/", $url);
        $this->assertMatchesRegularExpression('#\.(mp4|webm)$#', $url);
        Storage::disk('public')->assertExists($this->pathOf($url));

        $this->assertSame($url, $product->fresh()->video_url);
    }

    public function test_public_product_payload_exposes_video_url_and_null_without_one(): void
    {
        $product = $this->product();

        $this->getJson("/api/v1/products/{$product->slug}")
            ->assertOk()
            ->assertJsonPath('product.video_url', null);

        $this->upload($product, UploadedFile::fake()->create('clip.mp4', 256, 'video/mp4'));
        $url = $this->settledUrl($product);

        $this->getJson("/api/v1/products/{$product->slug}")
            ->assertOk()
            ->assertJsonPath('product.video_url', $url);

        // The listing the storefront actually syncs from is the raw model —
        // the column must ride along there too.
        $list = $this->getJson('/api/v1/products?per_page=200')->assertOk()->json('data');
        $row = collect($list)->firstWhere('id', $product->id);
        $this->assertNotNull($row);
        $this->assertSame($url, $row['video_url']);
    }

    public function test_uploading_again_replaces_the_clip_and_removes_the_old_file(): void
    {
        $product = $this->product();

        $this->upload($product, UploadedFile::fake()->create('one.mp4', 256, 'video/mp4'));
        $first = $this->settledUrl($product);
        $this->upload($product, UploadedFile::fake()->create('two.mp4', 256, 'video/mp4'));
        $second = $this->settledUrl($product);

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($this->pathOf($first));
        Storage::disk('public')->assertExists($this->pathOf($second));
        $this->assertSame($second, $product->fresh()->video_url);
    }

    public function test_delete_clears_the_url_and_removes_the_file(): void
    {
        $product = $this->product();
        $this->upload($product, UploadedFile::fake()->create('clip.mp4', 256, 'video/mp4'));
        $url = $this->settledUrl($product);

        $this->delete("/api/v1/admin/products/{$product->id}/video")->assertOk();

        $this->assertNull($product->fresh()->video_url);
        Storage::disk('public')->assertMissing($this->pathOf($url));

        // Idempotent — deleting again is not an error.
        $this->delete("/api/v1/admin/products/{$product->id}/video")->assertOk();
    }

    public function test_rejects_files_that_are_not_video_clips(): void
    {
        $product = $this->product();

        $this->upload($product, UploadedFile::fake()->create('notes.pdf', 64, 'application/pdf'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['video']);

        $this->upload($product, UploadedFile::fake()->create('shot.jpg', 64, 'image/jpeg'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['video']);

        $this->assertNull($product->fresh()->video_url);
    }

    public function test_rejects_clips_over_the_edge_size_ceiling(): void
    {
        $product = $this->product();

        // 20 MB is nginx's client_max_body_size; the rule must not accept more.
        $this->upload($product, UploadedFile::fake()->create('huge.mp4', 20481, 'video/mp4'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['video']);
    }

    public function test_without_ffmpeg_a_mov_upload_is_refused_with_guidance(): void
    {
        $product = $this->product();

        $this->upload($product, UploadedFile::fake()->create('phone.mov', 256, 'video/quicktime'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['video'])
            ->assertJsonFragment(['video' => ['This server cannot convert .mov clips. Export it as an MP4 (H.264) and upload again.']]);
    }

    public function test_requires_products_edit_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(Permission::findOrCreate('products.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($viewer);

        $product = $this->product();
        $this->upload($product, UploadedFile::fake()->create('clip.mp4', 64, 'video/mp4'))
            ->assertStatus(403);
    }

    public function test_ffmpeg_converts_to_a_small_mp4(): void
    {
        config(['video.ffmpeg' => env('FFMPEG_BIN', 'ffmpeg')]);
        $service = app(ProductVideoService::class);
        if ($service->ffmpegBinary() === null) {
            $this->markTestSkipped('ffmpeg is not installed here; the conversion path runs in the production image.');
        }

        // A real (tiny) clip: 2 s of colour bars at 1280x720 from ffmpeg itself.
        $src = tempnam(sys_get_temp_dir(), 'bhsrc').'.mp4';
        $gen = new \Symfony\Component\Process\Process([
            $service->ffmpegBinary(), '-y', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc=size=1280x720:rate=30',
            '-t', '2', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $src,
        ]);
        $gen->run();
        $this->assertTrue($gen->isSuccessful(), $gen->getErrorOutput());

        $product = $this->product();
        $this->upload($product, new UploadedFile($src, 'phone.mov', 'video/quicktime', null, true))
            ->assertStatus(202);

        $path = $this->pathOf($this->settledUrl($product));
        $this->assertStringEndsWith('.mp4', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertLessThan(filesize($src) + 1, Storage::disk('public')->size($path));
        @unlink($src);
    }
}
