<?php

namespace Tests\Feature;

use App\Models\InterestCart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The interest ledger (HUB_CONTRACT §7) — the Hub half the storefront has
 * been calling into since launch. Pins the contract's acceptance checklist:
 * upsert on token without duplicates, lookup by token and by phone, forward-
 * only status transitions, never-deleted rows, the X-Storefront-Key gate,
 * and the staff lookup being permission-gated pipeline data.
 */
class InterestCartLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function upsert(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/storefront/interest-carts', array_merge([
            'client_request_id' => 'req-' . uniqid(),
            'token'             => 'BH-0QVP2358',
            'channel'           => 'web',
            'items'             => [['slug' => 'preaching-gown-men', 'quantity' => 2, 'size' => 'L']],
            'subtotal'          => 9000,
            'currency'          => 'KES',
        ], $overrides));
    }

    public function test_post_upserts_on_token_without_duplicating(): void
    {
        $this->upsert()->assertStatus(201);

        // The customer edits their cart: same token, new items — one row.
        $this->upsert([
            'items'    => [['slug' => 'preaching-gown-men', 'quantity' => 3]],
            'subtotal' => 13500,
        ])->assertStatus(200);

        $this->assertSame(1, InterestCart::count());
        $cart = InterestCart::first();
        $this->assertSame('BH-0QVP2358', $cart->token);
        $this->assertSame(3, $cart->items[0]['quantity']);
        $this->assertEquals(13500, (float) $cart->subtotal);
    }

    public function test_lookup_by_token_and_by_phone_in_any_format(): void
    {
        $this->upsert(['customer' => ['name' => 'Grace Wanjiru', 'phone' => '0712345678']]);
        $this->upsert([
            'token' => 'BH-SECOND01',
            'customer' => ['phone' => '+254712345678'],
        ]);

        $one = $this->getJson('/api/v1/storefront/interest-carts?token=BH-0QVP2358')
            ->assertOk()->json('interest_cart');
        $this->assertSame('BH-0QVP2358', $one['token']);
        $this->assertSame('preaching-gown-men', $one['items'][0]['slug']);

        // The same person across formats: 0712… and +254712… are one phone.
        $mine = $this->getJson('/api/v1/storefront/interest-carts?phone=254712345678')
            ->assertOk()->json('interest_carts');
        $this->assertCount(2, $mine);

        $this->getJson('/api/v1/storefront/interest-carts?token=BH-NOSUCH00')->assertStatus(404);
        $this->getJson('/api/v1/storefront/interest-carts')->assertStatus(422);
    }

    public function test_status_never_regresses_and_abandoned_never_undoes_a_sale(): void
    {
        $this->upsert();

        // Converted on WhatsApp.
        $this->patchJson('/api/v1/storefront/interest-carts/BH-0QVP2358', [
            'status'    => 'whatsapp_order',
            'order_ref' => 'BH-AB12CD34',
        ])->assertOk();

        $cart = InterestCart::first();
        $this->assertSame('whatsapp_order', $cart->status);
        $this->assertSame('BH-AB12CD34', $cart->order_ref);
        $this->assertNotNull($cart->converted_at);

        // A stale browser tab still syncing its cart must not reopen it…
        $this->upsert(['status' => 'active_cart'])->assertOk();
        $this->assertSame('whatsapp_order', InterestCart::first()->status);

        // …and a later abandonment sweep must not erase the conversion.
        $this->patchJson('/api/v1/storefront/interest-carts/BH-0QVP2358', ['status' => 'abandoned'])
            ->assertOk();
        $this->assertSame('whatsapp_order', InterestCart::first()->status);

        // Abandoned is a status, not a delete: the row is still there.
        $this->assertSame(1, InterestCart::count());
    }

    public function test_the_storefront_key_gates_every_bridge_endpoint_when_set(): void
    {
        config(['services.storefront.key' => 'secret-key']);

        $this->upsert()->assertStatus(401);
        $this->getJson('/api/v1/storefront/interest-carts?token=BH-0QVP2358')->assertStatus(401);
        $this->patchJson('/api/v1/storefront/interest-carts/BH-0QVP2358', ['status' => 'abandoned'])
            ->assertStatus(401);

        $this->withHeader('X-Storefront-Key', 'secret-key')->upsert()->assertStatus(201);
    }

    public function test_staff_lookup_requires_orders_view_and_finds_a_pasted_token(): void
    {
        $this->upsert(['customer' => ['name' => 'Grace Wanjiru', 'phone' => '0712345678']]);

        // No permission → no pipeline data.
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/interest-carts?q=0QVP2358')->assertStatus(403);

        $clerk = User::factory()->create();
        $clerk->givePermissionTo(Permission::findOrCreate('orders.view', 'sanctum'));
        Sanctum::actingAs($clerk->fresh());

        // The realistic paste: the tail of the token from the WhatsApp message.
        $rows = $this->getJson('/api/v1/admin/interest-carts?q=0QVP2358')
            ->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('BH-0QVP2358', $rows[0]['token']);
        $this->assertSame('Grace Wanjiru', $rows[0]['name']);

        // And by phone, in a different format than it was stored.
        $byPhone = $this->getJson('/api/v1/admin/interest-carts?q=%2B254712345678')
            ->assertOk()->json('data');
        $this->assertCount(1, $byPhone);
    }
}
