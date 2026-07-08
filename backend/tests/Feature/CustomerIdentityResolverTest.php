<?php

namespace Tests\Feature;

use App\Enums\IdentityProvider;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\User;
use App\Services\CustomerIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The multichannel-identity resolver is the core of the Neema epic: any inbound
 * Meta contact (WhatsApp / Instagram / Messenger) must map to exactly one
 * customer, reusing an existing customer when a shared phone proves identity and
 * never colliding with the wrong one.
 */
class CustomerIdentityResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): CustomerIdentityResolver
    {
        return app(CustomerIdentityResolver::class);
    }

    private function actAs(string ...$permissions): void
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    public function test_same_whatsapp_contact_resolves_to_the_same_customer(): void
    {
        $first = $this->resolver()->resolve([
            'provider'     => 'whatsapp',
            'provider_uid' => '254712345678',
            'name'         => 'Amina Otieno',
        ]);

        $second = $this->resolver()->resolve([
            'provider'     => 'whatsapp',
            'provider_uid' => '254712345678',
        ]);

        $this->assertTrue($first['customer_created']);
        $this->assertTrue($first['identity_created']);
        $this->assertFalse($second['customer_created']);
        $this->assertFalse($second['identity_created']);

        $this->assertSame($first['customer']->id, $second['customer']->id);
        $this->assertSame($first['identity']->id, $second['identity']->id);
        $this->assertSame(1, CustomerIdentity::count());

        // WhatsApp wa_id doubles as the phone and is captured on the customer.
        $this->assertSame('+254712345678', $first['customer']->phone);
        // Meta channels are platform-verified.
        $this->assertTrue($first['identity']->isVerified());
    }

    public function test_new_channel_merges_onto_existing_phone_customer(): void
    {
        // A customer already known by a locally-formatted phone.
        $existing = Customer::factory()->create(['phone' => '0712345678']);

        $result = $this->resolver()->resolve([
            'provider'     => 'whatsapp',
            'provider_uid' => '254712345678', // same number as a wa_id
            'name'         => 'Amina Otieno',
        ]);

        $this->assertFalse($result['customer_created']);
        $this->assertTrue($result['identity_created']);
        $this->assertSame($existing->id, $result['customer']->id);
        $this->assertSame($existing->id, $result['identity']->customer_id);
    }

    public function test_unknown_instagram_contact_creates_a_verified_identity(): void
    {
        $result = $this->resolver()->resolve([
            'provider'     => 'instagram',
            'provider_uid' => 'IGSID_9988',
            'username'     => 'amina.style',
            'name'         => 'Amina Otieno',
        ]);

        $this->assertTrue($result['customer_created']);
        $this->assertSame('Amina', $result['customer']->first_name);
        $this->assertSame('Otieno', $result['customer']->last_name);
        $this->assertNull($result['customer']->phone);

        $identity = $result['identity'];
        $this->assertSame(IdentityProvider::INSTAGRAM, $identity->provider);
        $this->assertSame('amina.style', $identity->username);
        $this->assertTrue($identity->isVerified());
    }

    public function test_same_uid_on_different_providers_are_independent(): void
    {
        $wa = $this->resolver()->resolve([
            'provider'     => 'whatsapp',
            'provider_uid' => '254700000001',
        ]);

        // A Messenger PSID that happens to be the same string — different person,
        // must not collapse into the WhatsApp customer (no shared phone signal).
        $fb = $this->resolver()->resolve([
            'provider'     => 'messenger',
            'provider_uid' => '254700000001',
        ]);

        $this->assertNotSame($wa['customer']->id, $fb['customer']->id);
        $this->assertSame(2, CustomerIdentity::count());
    }

    public function test_resolve_endpoint_returns_customer_and_identity(): void
    {
        $this->actAs('customers.create');

        $response = $this->postJson('/api/v1/admin/neema/identities/resolve', [
            'provider'     => 'whatsapp',
            'provider_uid' => '254722000111',
            'name'         => 'Baraka Mwangi',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('customer_created', true)
            ->assertJsonPath('identity_created', true)
            ->assertJsonPath('identity.provider', 'whatsapp')
            ->assertJsonPath('customer.phone', '+254722000111');

        $customerId = $response->json('customer.id');

        // Second call for the same contact is a no-op resolution (200, not 201).
        $this->postJson('/api/v1/admin/neema/identities/resolve', [
            'provider'     => 'whatsapp',
            'provider_uid' => '254722000111',
        ])->assertStatus(200)->assertJsonPath('customer.id', $customerId);
    }

    public function test_resolve_endpoint_rejects_unknown_provider(): void
    {
        $this->actAs('customers.create');

        $this->postJson('/api/v1/admin/neema/identities/resolve', [
            'provider'     => 'telegram',
            'provider_uid' => 'abc',
        ])->assertStatus(422);
    }

    public function test_identities_endpoint_lists_a_customers_channels(): void
    {
        $resolved = $this->resolver()->resolve([
            'provider'     => 'whatsapp',
            'provider_uid' => '254733444555',
            'name'         => 'Neema Test',
        ]);
        $customerId = $resolved['customer']->id;

        // A second channel linked to the same customer.
        CustomerIdentity::create([
            'customer_id'  => $customerId,
            'provider'     => IdentityProvider::INSTAGRAM->value,
            'provider_uid' => 'IGSID_555',
            'username'     => 'neema.test',
        ]);

        $this->actAs('customers.view');

        $this->getJson("/api/v1/admin/customers/{$customerId}/identities")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
