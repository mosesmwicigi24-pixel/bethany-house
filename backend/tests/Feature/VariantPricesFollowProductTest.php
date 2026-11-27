<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ChangeProposal;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CurrencyPricing;
use App\Support\ReportingCurrency;
use App\Support\VariantPriceSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A product's variants are sold at the product's price (owner, 2026-11-26).
 *
 * A variant line is priced from the variant's own rows, and those were a
 * copy taken when the variant was made: a dress raised to KES 13,000 on its
 * Pricing tab kept selling at 10,000 in every colour and size. Hand-set
 * fixture: one dress, cost 6,000, product KES 10,000; variants RED-M and
 * WHI-S both at 10,000 with their own costs (6,000 and 6,400).
 */
class VariantPricesFollowProductTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private User $finance;
    private Product $dress;
    private ProductPrice $kes;
    private ProductVariant $red;
    private ProductVariant $white;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        foreach ([['KES', 1, 1, true], ['USD', 0.01, 128, false]] as [$code, $rate, $reporting, $base]) {
            DB::table('currencies')->updateOrInsert(['code' => $code], ['name' => $code, 'symbol' => $code,
                'exchange_rate' => $rate, 'reporting_rate_to_kes' => $reporting, 'is_base' => $base, 'is_active' => true]);
        }
        ReportingCurrency::forget();

        $this->owner   = $this->person('super_admin');
        $this->admin   = $this->person('admin');
        $this->finance = $this->person('finance_manager');

        $this->dress = Product::factory()->create();
        $this->kes   = $this->row(null, 'KES', 10000, null, 6000);
        $this->red   = $this->variant('T-DRESS-RED-M');
        $this->white = $this->variant('T-DRESS-WHI-S');
        $this->row($this->red->id, 'KES', 10000, null, 6000);
        $this->row($this->white->id, 'KES', 10000, null, 6400);
    }

    private function person(string $role): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    private function variant(string $sku): ProductVariant
    {
        return ProductVariant::create(['product_id' => $this->dress->id, 'sku' => $sku, 'variant_name' => $sku, 'attributes' => [], 'is_active' => true]);
    }

    private function row(?int $variantId, string $currency, float $regular, ?float $sale = null, ?float $cost = null): ProductPrice
    {
        return ProductPrice::create([
            'product_id' => $this->dress->id, 'product_variant_id' => $variantId, 'currency_code' => $currency,
            'regular_price' => $regular, 'sale_price' => $sale, 'cost_price' => $cost,
        ]);
    }

    private function save(User $as, array $price)
    {
        Sanctum::actingAs($as);

        return $this->putJson("/api/v1/admin/products/{$this->dress->id}", [
            'prices' => [$price + ['currency_code' => 'KES', 'regular_price' => 10000]],
        ]);
    }

    private function of(ProductVariant $v, string $currency = 'KES'): ?ProductPrice
    {
        return ProductPrice::where('product_variant_id', $v->id)->where('currency_code', $currency)->first();
    }

    public function test_a_price_change_inside_the_band_reaches_every_variant_at_once(): void
    {
        // 10,000 → 10,500 is 5%: applies directly.
        $this->save($this->admin, ['regular_price' => 10500])->assertOk();

        $this->assertEquals(10500.00, (float) $this->kes->fresh()->regular_price);
        foreach ([$this->red, $this->white] as $v) {
            $this->assertEquals(10500.00, (float) $this->of($v)->regular_price);
            // What a sale of that variant charges, read the way an order line reads it.
            $this->assertEquals(10500.00, CurrencyPricing::catalogue($this->dress->id, $v->id, 'KES')['effective_price']);
        }
        // Each variant's cost is its own.
        $this->assertEquals(6000.00, (float) $this->of($this->red)->cost_price);
        $this->assertEquals(6400.00, (float) $this->of($this->white)->cost_price);
    }

    public function test_a_change_that_waits_for_approval_moves_nothing_until_it_is_signed(): void
    {
        // 10,000 → 13,000 is 30%: finance must sign.
        $this->save($this->admin, ['regular_price' => 13000])->assertOk();

        $this->assertEquals(10000.00, (float) $this->kes->fresh()->regular_price, 'still waiting');
        $this->assertEquals(10000.00, (float) $this->of($this->red)->regular_price, 'variants do not jump ahead');

        $p = ChangeProposal::where('event', 'selling_price_change')->latest('id')->firstOrFail();
        $r = ApprovalRequest::where('event', $p->event)->where('approvable_id', $p->id)->orderByDesc('version')->firstOrFail();
        Sanctum::actingAs($this->finance);
        $this->postJson("/api/v1/admin/approvals/{$r->id}/sign", ['approvable_id' => $r->approvable_id, 'version' => $r->version])->assertOk();

        $this->assertEquals(13000.00, (float) $this->kes->fresh()->regular_price);
        $this->assertEquals(13000.00, (float) $this->of($this->red)->regular_price);
        $this->assertEquals(13000.00, (float) $this->of($this->white)->regular_price);
    }

    public function test_the_sale_price_and_its_window_are_the_products_and_end_with_it(): void
    {
        // 10,000 with a sale of 9,600 (4% off) for a week.
        $this->save($this->owner, ['sale_price' => 9600, 'sale_start_date' => now()->subDay()->toDateString(), 'sale_end_date' => now()->addDays(6)->toDateString()])->assertOk();

        $red = $this->of($this->red);
        $this->assertEquals(9600.00, (float) $red->sale_price);
        $this->assertSame(now()->addDays(6)->toDateString(), (string) $red->sale_end_date->format('Y-m-d'));
        $this->assertEquals(9600.00, CurrencyPricing::catalogue($this->dress->id, $this->red->id, 'KES')['effective_price'], 'the sale price when one is set');

        // The sale ends: back to the regular price, on the variants too.
        $this->save($this->owner, ['sale_price' => null])->assertOk();
        $this->assertNull($this->of($this->white)->sale_price);
        $this->assertEquals(10000.00, CurrencyPricing::catalogue($this->dress->id, $this->white->id, 'KES')['effective_price'], 'otherwise the regular price');
    }

    public function test_a_variant_form_cannot_set_its_own_selling_price_but_keeps_its_own_cost(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson("/api/v1/admin/products/{$this->dress->id}/variants/{$this->red->id}", [
            'prices' => [['currency_code' => 'KES', 'regular_price' => 7500, 'sale_price' => 7000, 'cost_price' => 6100]],
        ])->assertOk();

        $red = $this->of($this->red);
        $this->assertEquals(10000.00, (float) $red->regular_price, "the product's price, not the form's");
        $this->assertNull($red->sale_price);
        $this->assertEquals(6100.00, (float) $red->cost_price, 'its cost is its own');
        $this->assertSame(0, ChangeProposal::where('event', 'selling_price_change')->count(), 'no price proposal for a variant');
    }

    public function test_a_new_variant_and_a_new_currency_take_the_products_price(): void
    {
        Sanctum::actingAs($this->owner);

        // A new variant sent with some other price.
        $this->postJson("/api/v1/admin/products/{$this->dress->id}/variants", [
            'sku' => 'T-DRESS-PUR-L', 'variant_name' => 'Purple L',
            'prices' => [['currency_code' => 'KES', 'regular_price' => 4321]],
        ])->assertStatus(201);
        $purple = ProductVariant::where('sku', 'T-DRESS-PUR-L')->firstOrFail();
        $this->assertEquals(10000.00, (float) $this->of($purple)->regular_price);

        // The product gains a USD price: every variant has it.
        $this->putJson("/api/v1/admin/products/{$this->dress->id}", [
            'prices' => [['currency_code' => 'KES', 'regular_price' => 10000], ['currency_code' => 'USD', 'regular_price' => 100]],
        ])->assertOk();
        foreach ([$this->red, $this->white, $purple] as $v) {
            $this->assertEquals(100.00, (float) $this->of($v, 'USD')->regular_price);
            $this->assertNull($this->of($v, 'USD')->cost_price, 'cost stays on the KES row');
        }
    }

    public function test_a_product_without_variants_and_past_orders_are_untouched(): void
    {
        $plain = Product::factory()->create();
        $row   = ProductPrice::create(['product_id' => $plain->id, 'currency_code' => 'KES', 'regular_price' => 500]);
        $this->assertSame(0, VariantPriceSync::follow($row));
        $this->assertSame(1, ProductPrice::where('product_id', $plain->id)->count());

        // A line already sold keeps the price it was sold at.
        $order = \App\Models\Order::withoutViewerScope()->create(['order_number' => 'T-VAR-1', 'status' => 'confirmed',
            'payment_status' => 'paid', 'currency_code' => 'KES', 'subtotal' => 10000, 'total_amount' => 10000]);
        \App\Models\OrderItem::create(['order_id' => $order->id, 'product_id' => $this->dress->id, 'product_variant_id' => $this->red->id,
            'product_name' => 'Dress', 'sku' => 'T-DRESS-RED-M', 'quantity' => 1, 'unit_price' => 10000, 'total_price' => 10000]);

        $this->save($this->admin, ['regular_price' => 10500])->assertOk();

        $this->assertEquals(10000.00, (float) DB::table('order_items')->where('order_id', $order->id)->value('unit_price'));
        $this->assertEquals(10000.00, (float) $order->fresh()->total_amount);
    }

    public function test_the_alignment_counts_first_then_aligns_once_and_leaves_cost_alone(): void
    {
        // Drift as it is on the live hub: the product moved, the variants did not.
        DB::table('product_prices')->where('id', $this->kes->id)->update(['regular_price' => 13000]);
        // And one variant somebody had priced lower, with a sale on it.
        DB::table('product_prices')->where('product_variant_id', $this->white->id)->update(['regular_price' => 15000, 'sale_price' => 14000]);

        Artisan::call('prices:align-variants');
        $out = Artisan::output();
        $this->assertStringContainsString('Products with a variant out of step: 1', $out);
        $this->assertStringContainsString('Variant prices out of step: 2', $out);
        $this->assertStringContainsString('Would go up: 1', $out);      // red 10,000 → 13,000
        $this->assertStringContainsString('Would go down: 1', $out);    // white 14,000 (sale) → 13,000
        $this->assertStringContainsString('Dry run: nothing changed.', $out);
        $this->assertStringNotContainsString('T-DRESS', $out, 'counts only');
        $this->assertEquals(10000.00, (float) $this->of($this->red)->regular_price, 'a dry run changes nothing');

        // Refused without a super admin.
        $this->assertSame(1, Artisan::call('prices:align-variants', ['--apply' => true, '--actor' => $this->admin->id]));
        $this->assertEquals(10000.00, (float) $this->of($this->red)->regular_price);

        $this->assertSame(0, Artisan::call('prices:align-variants', ['--apply' => true, '--actor' => $this->owner->id]));
        $out = Artisan::output();
        $this->assertStringContainsString('Aligned now: 2', $out);
        $this->assertStringContainsString('Still out of step: 0', $out);

        $this->assertEquals(13000.00, (float) $this->of($this->red)->regular_price);
        $this->assertEquals(13000.00, (float) $this->of($this->white)->regular_price);
        $this->assertNull($this->of($this->white)->sale_price);
        $this->assertEquals(6000.00, (float) $this->of($this->red)->cost_price);
        $this->assertEquals(6400.00, (float) $this->of($this->white)->cost_price);

        // The old figures are on the trail — how it is undone.
        $log = DB::table('activity_log')->where('action', 'variant_prices_followed_product')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('10000.00', (string) $log->properties);
        $this->assertStringContainsString('15000.00', (string) $log->properties);

        // Again: nothing to do, nothing more on the trail.
        $before = DB::table('activity_log')->where('action', 'variant_prices_followed_product')->count();
        Artisan::call('prices:align-variants', ['--apply' => true, '--actor' => $this->owner->id]);
        $this->assertStringContainsString('Aligned now: 0', Artisan::output());
        $this->assertSame($before, DB::table('activity_log')->where('action', 'variant_prices_followed_product')->count());
    }

    public function test_the_list_goes_to_the_owner_by_email_and_the_log_shows_counts_only(): void
    {
        Mail::fake();
        config(['audit.owner_email' => 'owner@example.test']);
        DB::table('product_prices')->where('id', $this->kes->id)->update(['regular_price' => 13000]);

        Artisan::call('prices:align-variants', ['--mail' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString('List emailed to the owner.', $out);
        $this->assertStringNotContainsString('T-DRESS', $out);
        $this->assertStringNotContainsString('13,000', $out);
        $this->assertEquals(10000.00, (float) $this->of($this->red)->regular_price, 'emailing changes nothing');
    }
}
