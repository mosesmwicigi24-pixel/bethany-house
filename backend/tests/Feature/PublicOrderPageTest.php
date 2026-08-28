<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * /order/{public_token} — the customer's durable view of one order.
 *
 * The security contract this file exists to defend: the TOKEN is the
 * authorisation. An order id or an order number must never yield customer
 * data, because both travel through invoices, WhatsApp threads and staff
 * screens. Everything else here pins the receipt actually being a receipt.
 */
class PublicOrderPageTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $attrs = []): Order
    {
        return Order::factory()->create($attrs + [
            'status'         => 'confirmed',
            'payment_status' => 'pending',
            'currency_code'  => 'KES',
            'subtotal'       => 10000,
            'total_amount'   => 10000,
        ]);
    }

    public function test_every_order_gets_a_public_token_at_creation(): void
    {
        $order = $this->order();

        $this->assertNotEmpty($order->public_token, 'the model hook must mint one for every writer');
        $this->assertSame(48, strlen($order->public_token), '24 random bytes, hex encoded');
        $this->assertNotNull($order->public_token_issued_at);
    }

    public function test_tokens_are_unique_across_orders(): void
    {
        $a = $this->order();
        $b = $this->order();

        $this->assertNotSame($a->public_token, $b->public_token);
    }

    public function test_the_page_shows_the_order_without_any_login(): void
    {
        $order = $this->order(['customer_first_name' => 'Esther', 'customer_phone' => '+254715783436']);
        OrderItem::create([
            'order_id'           => $order->id,
            'product_id'         => null,
            'product_variant_id' => null,
            'product_name'       => 'Communion Cups',
            'sku'                => 'CUP-200',
            'quantity'           => 200,
            'unit_price'         => 10,
            'total_price'        => 2000,
        ]);

        $body = $this->getJson("/api/v1/order/{$order->public_token}")
            ->assertOk()
            ->assertJsonPath('order_number', $order->order_number)
            ->assertJsonPath('amount_due', 10000.0)
            ->json();

        // The receipt must itemise. The old paid screen showed a green tick and
        // an order number — that is what made "the link is wrong" true even
        // when the link resolved.
        $this->assertCount(1, $body['items']);
        $this->assertSame('Communion Cups', $body['items'][0]['name']);
        $this->assertSame(200, $body['items'][0]['quantity']);
        $this->assertNotNull($body['ordered_at']);
        $this->assertSame('Esther', $body['customer_name']);
    }

    public function test_a_paid_order_reads_as_a_receipt(): void
    {
        $order = $this->order(['payment_status' => 'paid']);
        Payment::factory()->create([
            'order_id' => $order->id, 'status' => 'paid', 'amount' => 10000,
            'payment_method' => 'mpesa', 'provider_reference' => 'QWE123',
        ]);

        $body = $this->getJson("/api/v1/order/{$order->public_token}")->assertOk()->json();

        $this->assertSame(10000.0, $body['amount_paid']);
        $this->assertSame(0.0, $body['amount_due']);
        $this->assertCount(1, $body['payments']);
        $this->assertSame('QWE123', $body['payments'][0]['provider_reference']);
        $this->assertSame([], $body['available_methods'], 'nothing left to pay, nothing to offer');
    }

    // ── The security contract ────────────────────────────────────────────────

    public function test_an_unknown_token_is_404_never_403(): void
    {
        // 403 would confirm the order exists. 404 for both cases or the route
        // becomes an existence oracle.
        $this->getJson('/api/v1/order/' . str_repeat('a', 48))
            ->assertStatus(404)
            ->assertJsonPath('message', 'Order not found.');
    }

    public function test_an_order_number_is_not_a_key(): void
    {
        $order = $this->order();

        $this->getJson("/api/v1/order/{$order->order_number}")->assertStatus(404);
        $this->getJson("/api/v1/order/{$order->id}")->assertStatus(404);
    }

    public function test_the_token_never_leaks_through_the_admin_api(): void
    {
        $order = $this->order();
        $staff = User::factory()->create();
        $staff->assignRole(Role::findOrCreate('admin', 'sanctum'));
        $staff->givePermissionTo(Permission::findOrCreate('orders.view', 'sanctum'));

        // Anyone holding the token can read the customer's name, phone and
        // total — so it must not ride along in a list, a detail payload, or
        // anything that gets logged or forwarded.
        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/admin/orders')
            ->assertOk()->assertDontSee($order->public_token);
        $this->actingAs($staff, 'sanctum')->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()->assertDontSee($order->public_token);
    }

    public function test_the_public_route_survives_a_narrowed_viewer_scope(): void
    {
        // Order carries a global viewer scope; an unauthenticated request has no
        // viewer, so without withoutViewerScope() this 404s. The scope is inert
        // today (every role ships data_scope 'all'), which is exactly why this
        // test matters — the bug would ship silently and detonate the first time
        // anyone narrows a role.
        $order = $this->order();
        DB::table('roles')->where('name', 'pos_clerk')->update(['data_scope' => 'own']);

        $this->getJson("/api/v1/order/{$order->public_token}")->assertOk();
    }

    // ── The dead-link fix ────────────────────────────────────────────────────

    public function test_pay_session_mints_a_fresh_token_for_an_expired_link(): void
    {
        $order = $this->order([
            'payment_token'            => 'stale-token-value',
            'payment_token_expires_at' => now()->subDays(5),
        ]);

        $body = $this->postJson("/api/v1/order/{$order->public_token}/pay-session")
            ->assertOk()->json();

        $this->assertNotSame('stale-token-value', $body['payment_token'],
            'an expired session must be renewed, not handed back — every one of the 88 links Neema sent died this way');
        $this->assertTrue(now()->addHours(71)->lt($order->fresh()->payment_token_expires_at));

        // And the renewed session actually opens the real payment page.
        $this->getJson("/api/v1/pay/{$body['payment_token']}")->assertOk();
    }

    public function test_pay_session_refuses_a_paid_order(): void
    {
        $order = $this->order(['payment_status' => 'paid']);
        Payment::factory()->create(['order_id' => $order->id, 'status' => 'paid', 'amount' => 10000]);

        $this->postJson("/api/v1/order/{$order->public_token}/pay-session")->assertStatus(409);
    }

    public function test_the_pay_page_hands_the_customer_their_receipt_url(): void
    {
        $order = $this->order([
            'payment_token'            => bin2hex(random_bytes(16)),
            'payment_token_expires_at' => now()->addHours(72),
        ]);

        $this->getJson("/api/v1/pay/{$order->payment_token}")
            ->assertOk()
            ->assertJsonPath('public_url', config('app.frontend_url') . "/order/{$order->public_token}");
    }
}
