<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A chat order must remember WHICH APP the customer used.
 *
 * The hub has always accepted source_channel (whatsapp|messenger|instagram)
 * and Neema never sent it, so every conversational order arrived labelled
 * WhatsApp. A real Messenger buyer's order therefore offered a WhatsApp button
 * that could not reach her: her thread is keyed by a 17-digit page-scoped id,
 * while the order carried a Central African Republic phone that keys nothing.
 *
 * These tests pin the contract Neema now relies on.
 */
class ChatOrderChannelTest extends TestCase
{
    use RefreshDatabase;

    private function clerk(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'sanctum'));
        foreach (['orders.create', 'orders.view', 'pos.access'] as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }

        return $user;
    }

    private function push(array $overrides = [])
    {
        $outlet  = Outlet::factory()->create();
        $product = Product::factory()->create();

        return $this->actingAs($this->clerk(), 'sanctum')
            ->postJson('/api/v1/admin/pos/pending-order', array_merge([
                'outlet_id'         => $outlet->id,
                'channel'           => 'whatsapp',
                'client_request_id' => 'req-' . bin2hex(random_bytes(6)),
                'new_customer'      => ['first_name' => 'Stella', 'phone' => '+23672582495'],
                'items'             => [[
                    'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1000,
                ]],
            ], $overrides));
    }

    public function test_a_messenger_order_is_remembered_as_messenger(): void
    {
        $res = $this->push(['source_channel' => 'messenger'])->assertStatus(201);

        $order = Order::find($res->json('order_id'));

        $this->assertSame('messenger', $order->source_channel,
            'the app the customer used must survive the push');
        $this->assertSame('chat', $order->sales_bucket,
            'it is still a chat sale — the bucket is the queue, the channel is the app');
    }

    public function test_an_instagram_order_is_remembered_as_instagram(): void
    {
        $res = $this->push(['source_channel' => 'instagram'])->assertStatus(201);

        $this->assertSame('instagram', Order::find($res->json('order_id'))->source_channel);
    }

    public function test_a_push_without_a_channel_still_defaults_to_whatsapp(): void
    {
        // Backwards compatibility: an older Neema that does not send the field
        // must keep working exactly as before.
        $res = $this->push()->assertStatus(201);

        $this->assertSame('whatsapp', Order::find($res->json('order_id'))->source_channel);
    }

    public function test_the_push_hands_back_the_durable_customer_link(): void
    {
        // Neema stores this and sends it to the buyer; without it she falls back
        // to a 72-hour pay session that dies in three days.
        $res = $this->push(['source_channel' => 'messenger'])->assertStatus(201);

        $order = Order::find($res->json('order_id'));

        $this->assertSame($order->public_token, $res->json('public_token'));
        $this->assertStringContainsString("/order/{$order->public_token}", $res->json('public_url'));
    }
}
