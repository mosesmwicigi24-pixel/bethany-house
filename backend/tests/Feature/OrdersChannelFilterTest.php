<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The Sales navigation splits orders into POS / Online / WhatsApp. WhatsApp
 * orders are POS orders taken at a WhatsApp-channel outlet, so the split is:
 *   online   → order_type = 'online'
 *   whatsapp → outlet.sales_channel = 'whatsapp'
 *   pos      → order_type = 'pos', excluding WhatsApp outlets
 */
class OrdersChannelFilterTest extends TestCase
{
    use RefreshDatabase;

    private function actAsViewer(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('orders.view', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    public function test_sales_channel_splits_pos_whatsapp_and_online(): void
    {
        $this->actAsViewer();

        $store    = Outlet::factory()->create(['sales_channel' => 'pos']);
        $whatsapp = Outlet::factory()->create(['sales_channel' => 'whatsapp']);

        $posOrder = Order::factory()->create(['order_type' => 'pos', 'outlet_id' => $store->id]);
        $waOrder  = Order::factory()->create(['order_type' => 'pos', 'outlet_id' => $whatsapp->id]);
        $onlineOrder = Order::factory()->create(['order_type' => 'online', 'outlet_id' => null]);

        // POS Orders: the store order only — not the WhatsApp-outlet one.
        $ids = collect($this->getJson('/api/v1/admin/orders?sales_channel=pos')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($posOrder->id));
        $this->assertFalse($ids->contains($waOrder->id));
        $this->assertFalse($ids->contains($onlineOrder->id));

        // WhatsApp Orders: only the WhatsApp-outlet order.
        $ids = collect($this->getJson('/api/v1/admin/orders?sales_channel=whatsapp')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($waOrder->id));
        $this->assertFalse($ids->contains($posOrder->id));
        $this->assertFalse($ids->contains($onlineOrder->id));

        // Online Orders: only the storefront order.
        $ids = collect($this->getJson('/api/v1/admin/orders?sales_channel=online')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($onlineOrder->id));
        $this->assertFalse($ids->contains($posOrder->id));
        $this->assertFalse($ids->contains($waOrder->id));
    }
}
