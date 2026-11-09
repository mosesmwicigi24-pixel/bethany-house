<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\CurrencyPricing;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A till prices its own lines, so on a sale in a currency other than the
 * outlet's every line must state the currency its price is in (2026-11-05).
 *
 * Found on the native apps: they priced the cart in shillings and sent no
 * currency, so a KES 2,500 dress sold to a customer in the US was billed
 * USD 2,500. The right figure is USD 25 (pricing rate 0.01). Worked by hand
 * for this fixture: KES 2,500 at 0.01 = USD 25.
 */
class TillPriceCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;
    private Product $dress;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        DB::table('settings')->updateOrInsert(['key' => 'app_country'], ['value' => 'KE']);
        $this->outlet = Outlet::factory()->create(['country_code' => 'KE']);

        foreach ([['KES', 1, 1, true], ['USD', 0.01, 128, false]] as [$code, $pricing, $reporting, $base]) {
            DB::table('currencies')->updateOrInsert(['code' => $code], ['name' => $code, 'symbol' => $code,
                'exchange_rate' => $pricing, 'reporting_rate_to_kes' => $reporting, 'is_base' => $base, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([['KE', 'Kenya', 'KES'], ['US', 'United States', 'USD']] as [$code, $name, $cur]) {
            DB::table('countries')->updateOrInsert(['code' => $code], ['name' => $name, 'default_currency_code' => $cur,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        ReportingCurrency::forget();
        CurrencyPricing::forget();

        $this->dress = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        ProductPrice::create(['product_id' => $this->dress->id, 'product_variant_id' => null, 'currency_code' => 'KES', 'regular_price' => 2500]);
        InventoryItem::create(['product_id' => $this->dress->id, 'product_variant_id' => null, 'outlet_id' => $this->outlet->id,
            'quantity_on_hand' => 20, 'quantity_reserved' => 0, 'reorder_point' => 0]);

        $cashier = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $cashier->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('pos.access', 'sanctum'));
        $cashier->outlets()->sync([$this->outlet->id]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($cashier);
    }

    private function line(float $price, ?string $priceCurrency): array
    {
        return array_filter(['product_id' => $this->dress->id, 'quantity' => 1, 'unit_price' => $price,
            'price_currency' => $priceCurrency], fn ($v) => $v !== null);
    }

    private function sale(?string $country, array $line, string $url = '/api/v1/admin/pos/pending-order')
    {
        return $this->postJson($url, array_filter([
            'outlet_id' => $this->outlet->id, 'customer_country_code' => $country, 'items' => [$line],
        ], fn ($v) => $v !== null));
    }

    public function test_a_dollar_sale_priced_in_shillings_is_refused_and_nothing_is_written(): void
    {
        // Exactly what the apps sent: the shilling price, no currency stated.
        $this->sale('US', $this->line(2500, null))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'prices_not_in_sale_currency')
            ->assertJsonPath('currency_code', 'USD');
        // A line that says it is in shillings is refused the same way.
        $this->sale('US', $this->line(2500, 'KES'))->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, (int) InventoryItem::first()->quantity_reserved, 'no stock was held');
    }

    public function test_a_dollar_sale_priced_in_dollars_goes_through_at_the_dollar_price(): void
    {
        $id = $this->sale('US', $this->line(25, 'USD'))->assertSuccessful()->json('order_id');
        $order = Order::findOrFail($id);

        $this->assertSame('USD', $order->currency_code);
        $this->assertEqualsWithDelta(25.00, (float) $order->total_amount, 0.001);
    }

    public function test_a_shilling_sale_needs_no_stated_currency_so_older_clients_keep_working(): void
    {
        $id = $this->sale(null, $this->line(2500, null))->assertSuccessful()->json('order_id');
        $this->assertSame('KES', Order::findOrFail($id)->currency_code);
        $this->assertEqualsWithDelta(2500.00, (float) Order::findOrFail($id)->total_amount, 0.001);

        $ke = $this->sale('KE', $this->line(2500, null))->assertSuccessful()->json('order_id');
        $this->assertSame('KES', Order::findOrFail($ke)->currency_code);
    }

    public function test_editing_a_sale_to_a_customer_abroad_needs_the_prices_in_their_currency(): void
    {
        $id = $this->sale(null, $this->line(2500, null))->assertSuccessful()->json('order_id');
        $patch = fn (array $line) => $this->patchJson("/api/v1/admin/pos/pending-order/{$id}", [
            'outlet_id' => $this->outlet->id, 'customer_country_code' => 'US', 'items' => [$line],
        ]);

        $patch($this->line(2500, null))->assertStatus(422)->assertJsonPath('reason', 'prices_not_in_sale_currency');
        $unchanged = Order::findOrFail($id);
        $this->assertSame('KES', $unchanged->currency_code, 'a refused edit leaves the order as it was');
        $this->assertEqualsWithDelta(2500.00, (float) $unchanged->total_amount, 0.001);

        $patch($this->line(25, 'USD'))->assertSuccessful();
        $this->assertSame('USD', Order::findOrFail($id)->currency_code);
        $this->assertEqualsWithDelta(25.00, (float) Order::findOrFail($id)->total_amount, 0.001);
    }

    public function test_the_one_step_till_sale_follows_the_same_rule(): void
    {
        // The one-step sale asks for an open till first.
        \App\Models\CashRegister::create(['outlet_id' => $this->outlet->id, 'register_name' => 'Till 1', 'status' => 'open',
            'currency_code' => 'KES', 'opening_balance' => 0, 'expected_cash' => 0, 'opened_by' => auth()->id(), 'opened_at' => now()]);

        $this->sale('US', $this->line(2500, null), '/api/v1/admin/pos/sales')
            ->assertStatus(422)->assertJsonPath('reason', 'prices_not_in_sale_currency');
        $this->assertSame(0, Order::count());
    }

    public function test_an_agent_taken_order_is_priced_by_the_hub_and_needs_no_stated_currency(): void
    {
        $id = $this->postJson('/api/v1/admin/pos/pending-order', [
            'outlet_id' => $this->outlet->id, 'channel' => 'whatsapp', 'customer_country_code' => 'US',
            'customer_first_name' => 'Ada', 'items' => [$this->line(2500, null)],
        ])->assertSuccessful()->json('order_id');

        $this->assertEqualsWithDelta(25.00, (float) Order::findOrFail($id)->total_amount, 0.001, 'the hub priced it, not the device');
    }
}
