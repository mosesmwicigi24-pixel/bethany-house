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

    public function test_an_abandoned_cart_revives_when_the_customer_returns(): void
    {
        // The token lives in the customer's browser until an order rotates it,
        // so coming back weeks later to the same cart is the NORMAL path.
        $this->upsert();
        InterestCart::query()->update(['status' => 'abandoned']);

        $this->upsert(['items' => [['slug' => 'preaching-gown-men', 'quantity' => 1]]])
            ->assertOk()
            ->assertJsonPath('interest_cart.status', 'active_cart');

        $this->assertSame('active_cart', InterestCart::first()->status);
    }

    public function test_a_converted_cart_is_frozen_except_identity_enrichment(): void
    {
        $this->upsert();
        $this->patchJson('/api/v1/storefront/interest-carts/BH-0QVP2358', [
            'status' => 'online_order', 'order_ref' => 'BH-WEBORD01',
        ])->assertOk();

        // A tab opened before the token rotated syncs its stale cart: the
        // bought items must stay as bought, but a late-learned name is true.
        $this->upsert([
            'items'    => [['slug' => 'something-else', 'quantity' => 9]],
            'subtotal' => 1,
            'customer' => ['name' => 'Grace Wanjiru'],
        ])->assertOk()->assertJsonPath('interest_cart.status', 'online_order');

        $cart = InterestCart::first();
        $this->assertSame('preaching-gown-men', $cart->items[0]['slug']);
        $this->assertEquals(9000, (float) $cart->subtotal);
        $this->assertSame('Grace Wanjiru', $cart->name);
    }

    public function test_a_whatsapp_close_stamps_the_channel_that_closed_it(): void
    {
        $this->upsert();
        $this->patchJson('/api/v1/storefront/interest-carts/BH-0QVP2358', ['status' => 'whatsapp_order'])
            ->assertOk();

        $cart = InterestCart::first();
        $this->assertSame('whatsapp', $cart->last_channel);
        $this->assertSame('web', $cart->channel, 'Origin channel must stay where the interest began.');
    }

    public function test_token_lookup_is_case_insensitive(): void
    {
        // Humans re-type tokens off a WhatsApp message; case must not matter.
        $this->upsert();
        $this->getJson('/api/v1/storefront/interest-carts?token=bh-0qvp2358')
            ->assertOk()
            ->assertJsonPath('interest_cart.token', 'BH-0QVP2358');
    }

    public function test_the_sweep_abandons_stale_live_carts_but_never_a_sale(): void
    {
        $this->upsert(['token' => 'BH-STALE001']);
        $this->upsert(['token' => 'BH-FRESH001']);
        $this->upsert(['token' => 'BH-SOLD0001']);
        $this->patchJson('/api/v1/storefront/interest-carts/BH-SOLD0001', ['status' => 'whatsapp_order'])
            ->assertOk();

        // Age the stale cart and the sold cart beyond the window.
        InterestCart::whereIn('token', ['BH-STALE001', 'BH-SOLD0001'])
            ->update(['updated_at' => now()->subDays(20)]);

        $this->artisan('interest-carts:sweep-abandoned', ['--days' => 14])->assertExitCode(0);

        $this->assertSame('abandoned', InterestCart::where('token', 'BH-STALE001')->value('status'));
        $this->assertSame('active_cart', InterestCart::where('token', 'BH-FRESH001')->value('status'));
        $this->assertSame('whatsapp_order', InterestCart::where('token', 'BH-SOLD0001')->value('status'), 'The sweep abandoned a closed sale.');

        // Kept, never deleted — all three rows survive the sweep.
        $this->assertSame(3, InterestCart::count());
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
