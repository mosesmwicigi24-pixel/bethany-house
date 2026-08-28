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

    private ?Outlet $outlet = null;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\CurrencyPricing::forget();
        \Illuminate\Support\Facades\DB::table('currencies')->updateOrInsert(
            ['code' => 'KES'],
            ['name' => 'KES', 'symbol' => 'KES', 'exchange_rate' => 1,
             'is_base' => true, 'is_active' => true,
             'created_at' => now(), 'updated_at' => now()],
        );
        \App\Services\CurrencyPricing::forget();
    }

    private function outlet(): Outlet
    {
        return $this->outlet ??= Outlet::factory()->create([
            'sales_channel' => 'whatsapp', 'country_code' => 'KE',
        ]);
    }

    /** A clerk the till will accept: the permission AND an outlet assignment. */
    private function clerk(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('pos.access', 'sanctum'));
        $user->outlets()->sync([$this->outlet()->id]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        return $user;
    }

    /** A sellable product: priced, and in stock at the fixture outlet. */
    private function product(): Product
    {
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        \App\Models\ProductPrice::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'currency_code' => 'KES', 'regular_price' => 7000,
        ]);
        \App\Models\InventoryItem::create([
            'product_id' => $product->id, 'product_variant_id' => null,
            'outlet_id' => $this->outlet()->id, 'quantity_on_hand' => 25,
            'quantity_reserved' => 0, 'reorder_point' => 0,
        ]);

        return $product;
    }

    private function push(array $overrides = [])
    {
        $this->clerk();

        return $this->postJson('/api/v1/admin/pos/pending-order', array_merge([
            'outlet_id'         => $this->outlet()->id,
            'channel'           => 'whatsapp',
            'client_request_id' => 'req-' . bin2hex(random_bytes(6)),
            'items'             => [[
                'product_id' => $this->product()->id,
                'quantity'   => 1,
                'unit_price' => 7000,
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
