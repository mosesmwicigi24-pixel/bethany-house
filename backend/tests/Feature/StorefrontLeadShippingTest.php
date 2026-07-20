<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The two Neema storefront-bridge endpoints (docs/HUB_CONTRACT.md): lead
 * capture (idempotent) and shipping estimate (data-driven from countries,
 * KES for KE / USD otherwise). Public routes, called server-side by the
 * storefront; the acceptance checklist (§4) is pinned here.
 */
class StorefrontLeadShippingTest extends TestCase
{
    use RefreshDatabase;

    private function country(string $code, string $name, array $extra = []): void
    {
        DB::table('countries')->insert(array_merge([
            'code' => $code, 'name' => $name,
            'is_shipping_enabled' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function leadBody(array $over = []): array
    {
        return array_merge([
            'client_request_id' => '11111111-1111-1111-1111-111111111111',
            'intent'    => 'quote',
            'readiness' => 'high',
            'customer'  => ['name' => 'Rev. Mwangi', 'phone' => '+254727891989'],
            'location'  => ['country_code' => 'KE', 'city' => 'Nakuru'],
            'products'  => ['clergy-cassock'],
            'quantity'  => '20',
            'message'   => 'Purple, Advent ordination',
            'source_path' => '/shop',
        ], $over);
    }

    // ── Leads ────────────────────────────────────────────────────────────────

    public function test_a_lead_is_persisted_and_returns_its_id(): void
    {
        $res = $this->postJson('/api/v1/storefront/leads', $this->leadBody())->assertStatus(201);

        $id = $res->json('lead.id');
        $this->assertNotNull($id);
        $this->assertDatabaseHas('leads', ['id' => $id, 'phone' => '+254727891989', 'intent' => 'quote']);
    }

    public function test_the_same_client_request_id_returns_the_same_lead(): void
    {
        $first  = $this->postJson('/api/v1/storefront/leads', $this->leadBody())->assertStatus(201)->json('lead.id');
        $second = $this->postJson('/api/v1/storefront/leads', $this->leadBody())->assertOk()->json('lead.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Lead::count());
    }

    public function test_only_phone_is_required_missing_name_and_email_are_accepted(): void
    {
        $this->postJson('/api/v1/storefront/leads', $this->leadBody([
            'customer' => ['phone' => '+254700000000'],
        ]))->assertStatus(201);

        // No phone at all → rejected.
        $this->postJson('/api/v1/storefront/leads', $this->leadBody([
            'client_request_id' => '22222222-2222-2222-2222-222222222222',
            'customer' => ['name' => 'No Phone'],
        ]))->assertStatus(422);
    }

    public function test_an_unknown_intent_is_stored_as_other_not_rejected(): void
    {
        $res = $this->postJson('/api/v1/storefront/leads', $this->leadBody([
            'intent' => 'something_new',
        ]))->assertStatus(201);

        $this->assertSame('other', Lead::find($res->json('lead.id'))->intent);
    }

    // ── Shipping ───────────────────────────────────────────────────────────────

    public function test_kenya_estimate_is_in_kes_with_an_options_array(): void
    {
        $this->country('KE', 'Kenya', [
            'standard_shipping_cost' => 300, 'express_shipping_cost' => 800,
            'estimated_delivery_days' => 3,
        ]);

        $res = $this->getJson('/api/v1/storefront/shipping/estimate?country_code=KE&city=Nairobi')->assertOk();

        $this->assertSame('Nairobi, Kenya', $res->json('destination'));
        $this->assertIsArray($res->json('options'));
        $this->assertStringContainsString('KES', $res->json('options.0.cost'));
    }

    public function test_international_estimate_is_in_usd_with_a_duties_note(): void
    {
        $this->country('UG', 'Uganda', [
            'standard_shipping_cost' => 22, 'express_shipping_cost' => 48,
            'estimated_delivery_days' => 8,
        ]);

        $res = $this->getJson('/api/v1/storefront/shipping/estimate?country_code=UG&city=Kampala')->assertOk();

        $this->assertSame('Kampala, Uganda', $res->json('destination'));
        $this->assertStringContainsString('USD', $res->json('options.0.cost'));
        $this->assertNotEmpty($res->json('note'));
    }

    public function test_an_unknown_destination_returns_an_empty_options_array_with_a_note(): void
    {
        $res = $this->getJson('/api/v1/storefront/shipping/estimate?country=Narnia')->assertOk();

        $this->assertIsArray($res->json('options'));
        $this->assertSame([], $res->json('options'));
        $this->assertNotEmpty($res->json('note'));
        $this->assertNotEmpty($res->json('destination'));
    }

    public function test_country_resolves_by_free_text_name_too(): void
    {
        $this->country('UG', 'Uganda', ['standard_shipping_cost' => 22, 'estimated_delivery_days' => 8]);

        $res = $this->getJson('/api/v1/storefront/shipping/estimate?country=Uganda')->assertOk();
        $this->assertSame('Uganda', $res->json('destination'));
        $this->assertStringContainsString('USD', $res->json('options.0.cost'));
    }

    // ── Optional shared-secret gate (§6) ────────────────────────────────────────

    public function test_when_a_key_is_configured_the_header_is_required(): void
    {
        config(['services.storefront.key' => 'sekret']);

        $this->postJson('/api/v1/storefront/leads', $this->leadBody())->assertStatus(401);
        $this->postJson('/api/v1/storefront/leads', $this->leadBody(), ['X-Storefront-Key' => 'wrong'])->assertStatus(401);
        $this->postJson('/api/v1/storefront/leads', $this->leadBody(), ['X-Storefront-Key' => 'sekret'])->assertStatus(201);
    }
}
