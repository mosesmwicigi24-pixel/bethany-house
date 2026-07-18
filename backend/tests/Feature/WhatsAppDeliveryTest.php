<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WhatsApp delivery for the morning digest, over the Meta Cloud API.
 *
 * The service is env-configured (WABA_TOKEN / WABA_PHONE_NUMBER_ID — the
 * same names Neema uses on the VPS) and a safe no-op when unconfigured, so
 * CI never talks to Meta. These tests fake the Graph API and also pin the
 * recipient-string normalization (the settings page stores comma-separated
 * strings, not arrays).
 */
class WhatsAppDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function configureWhatsApp(): void
    {
        config([
            'services.whatsapp.token'           => 'test-token',
            'services.whatsapp.phone_number_id' => '123456789',
            'services.whatsapp.api_version'     => 'v19.0',
        ]);
    }

    private function saveDeliverySettings(array $settings): void
    {
        DB::table('system_settings')->upsert(
            ['key' => 'eod_delivery', 'value' => json_encode($settings), 'updated_at' => now()],
            ['key'], ['value', 'updated_at'],
        );
    }

    private function seedOneFinding(): void
    {
        $product = Product::factory()->create();
        DB::table('production_orders')->insert([
            'order_number' => 'PRD-WA-LATE', 'product_id' => $product->id, 'quantity' => 5,
            'status' => 'in_progress', 'due_date' => now()->subDays(4)->format('Y-m-d'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_unconfigured_service_is_a_safe_noop(): void
    {
        Http::fake();
        config(['services.whatsapp.token' => null, 'services.whatsapp.phone_number_id' => null]);

        $this->assertFalse(WhatsAppService::configured());
        $this->assertFalse(WhatsAppService::send('+254712345678', 'hello'));
        Http::assertNothingSent();
    }

    public function test_digest_sends_whatsapp_to_comma_separated_numbers(): void
    {
        $this->configureWhatsApp();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);

        $this->seedOneFinding();
        $this->saveDeliverySettings([
            'whatsapp_enabled'    => true,
            'whatsapp_recipients' => '+254712345678, +254733000000',
        ]);

        $this->artisan('insights:digest --force')
            ->expectsOutputToContain('WhatsApp sent to +254712345678')
            ->expectsOutputToContain('WhatsApp sent to +254733000000')
            ->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn ($req) =>
            str_contains($req->url(), 'graph.facebook.com/v19.0/123456789/messages')
            && $req['messaging_product'] === 'whatsapp'
            && $req['to'] === '254712345678'
            && str_contains($req['text']['body'], 'production order'));
    }

    public function test_digest_email_recipients_accept_the_settings_pages_string_format(): void
    {
        $this->seedOneFinding();
        $this->saveDeliverySettings([
            'email_enabled'    => true,
            'email_recipients' => 'owner@sonalux.test, manager@sonalux.test',
        ]);

        $this->artisan('insights:digest --force')
            ->expectsOutputToContain('Sent to owner@sonalux.test')
            ->expectsOutputToContain('Sent to manager@sonalux.test')
            ->assertSuccessful();
    }

    public function test_meta_rejection_is_reported_not_swallowed(): void
    {
        $this->configureWhatsApp();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 131047, 'message' => 'Re-engagement message']], 400)]);

        $this->seedOneFinding();
        $this->saveDeliverySettings([
            'whatsapp_enabled'    => true,
            'whatsapp_recipients' => '+254712345678',
        ]);

        $this->artisan('insights:digest --force')
            ->expectsOutputToContain('WhatsApp failed for +254712345678')
            ->assertSuccessful();
    }

    public function test_test_delivery_endpoint_supports_whatsapp(): void
    {
        $this->configureWhatsApp();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);

        $user = \App\Models\User::factory()->create();
        // The route stacks the pos group gate on the per-route permission.
        foreach (['pos.access', 'settings.edit'] as $perm) {
            $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($perm, 'sanctum'));
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->postJson('/api/v1/admin/pos/reports/eod-settings/test', [
            'channel' => 'whatsapp', 'whatsapp_recipients' => '+254712345678',
        ])->assertOk()->assertJsonFragment(['message' => 'Test sent to 1 WhatsApp number.']);

        Http::assertSentCount(1);
    }
}
