<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductTranslation;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Three exposures found on 2026-10-02 while grounding the role-hardening plan
 * against production. Both reached data no role is meant to read.
 */
class StaffExposureTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $perms = []): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($u);

        return $u;
    }

    public function test_a_chat_attachment_link_cannot_step_out_of_its_folder(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('channel-attachments/2026/10/9f1c-photo.jpg', 'chat photo');
        Storage::disk('local')->put('payment-proofs/proof-123.pdf', 'a customer payment proof');
        Storage::disk('local')->put('download-archive/export.csv', 'archived export');
        $this->staff();   // any signed-in staff member — the route checks nothing more

        $serve = fn (string $path) => $this->get('/api/v1/admin/channels/attachments/serve?path=' . urlencode($path));

        $serve('channel-attachments/2026/10/9f1c-photo.jpg')->assertOk()->assertSee('chat photo');

        foreach ([
            'channel-attachments/../payment-proofs/proof-123.pdf',
            'channel-attachments/2026/../../download-archive/export.csv',
            'channel-attachments/./../payment-proofs/proof-123.pdf',
            'channel-attachments\\..\\payment-proofs\\proof-123.pdf',
            'channel-attachments//../payment-proofs/proof-123.pdf',
            'payment-proofs/proof-123.pdf',
            '',
        ] as $path) {
            $res = $serve($path);
            $res->assertForbidden();
            $this->assertStringNotContainsString('payment proof', $res->getContent(), $path);
            $this->assertStringNotContainsString('archived export', $res->getContent(), $path);
        }
    }

    public function test_the_settings_endpoint_never_returns_a_credential(): void
    {
        foreach ([
            'app_name' => 'Bethany House', 'mpesa_shortcode' => '174379', 'paystack_public_key' => 'pk_live_x',
            'mpesa_consumer_secret' => 'MPESA-SECRET', 'mpesa_passkey' => 'MPESA-PASSKEY', 'mpesa_consumer_key' => 'MPESA-KEY',
            'paystack_secret_key' => 'sk_live_SECRET', 'ai_api_key' => 'sk-ai-SECRET', 'ai_embeddings_api_key' => 'sk-emb-SECRET',
            'backup_s3_secret' => 'S3-SECRET', 'backup_s3_key' => 'S3-KEY',
        ] as $k => $v) {
            DB::table('settings')->updateOrInsert(['key' => $k], ['value' => $v, 'created_at' => now(), 'updated_at' => now()]);
        }
        Cache::forget('app_settings');

        // settings.view is what an outlet manager holds today.
        $this->staff(['settings.view']);
        $res = $this->getJson('/api/v1/admin/settings')->assertOk();

        $this->assertSame('Bethany House', $res->json('settings.app_name'), 'ordinary settings still come back');
        $this->assertSame('174379', $res->json('settings.mpesa_shortcode'));
        foreach (['MPESA-SECRET', 'MPESA-PASSKEY', 'MPESA-KEY', 'sk_live_SECRET', 'sk-ai-SECRET', 'sk-emb-SECRET', 'S3-SECRET', 'S3-KEY'] as $secret) {
            $this->assertStringNotContainsString($secret, $res->getContent(), "{$secret} leaked");
        }
        $this->assertArrayNotHasKey('mpesa_passkey', $res->json('settings'));
    }

    public function test_no_public_product_response_carries_what_the_product_cost_us(): void
    {
        $product = Product::factory()->create(['status' => 'active', 'published_at' => now()->subDay(), 'is_featured' => true]);
        ProductTranslation::create(['product_id' => $product->id, 'language_code' => 'en', 'name' => 'Chasuble']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SKU-C1', 'variant_name' => 'M',
            'attributes' => ['size' => 'M'], 'is_active' => true]);
        ProductPrice::create(['product_id' => $product->id, 'currency_code' => 'KES', 'regular_price' => 10000, 'cost_price' => 3517]);
        ProductPrice::create(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'currency_code' => 'KES',
            'regular_price' => 10000, 'cost_price' => 3517]);

        // Signed out, as anyone on the internet.
        foreach (['/api/v1/products', "/api/v1/products/{$product->slug}", '/api/v1/products/featured',
                  '/api/v1/products/new-arrivals', "/api/v1/products/{$product->id}/variants"] as $url) {
            $res = $this->getJson($url)->assertOk();
            $this->assertStringNotContainsString('cost_price', $res->getContent(), "{$url} carries cost_price");
            $this->assertStringNotContainsString('3517', $res->getContent(), "{$url} carries the cost figure");
        }
        $this->assertStringContainsString('10000', $this->getJson("/api/v1/products/{$product->slug}")->getContent(), 'the selling price still shows');

        // The admin price editor still gets the cost it edits.
        $this->staff(['products.view']);
        $this->assertStringContainsString('cost_price', $this->getJson("/api/v1/admin/products/{$product->id}")->getContent());
    }
}
