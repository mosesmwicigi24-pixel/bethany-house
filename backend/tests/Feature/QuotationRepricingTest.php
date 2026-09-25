<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\CurrencyPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Changing a quotation's currency must move the FIGURES, not just the label.
 *
 * Until 2026-09-24 the select relabelled the document and left every price
 * where it was: pick ZMW and KES 17,000 read as "ZMW 17,000" — about seven
 * times the real price, a hundred times over in USD. Owner asked for the
 * numbers to follow the currency.
 *
 * The rules are CurrencyPricing's, shared with every order and POS sale, and
 * the third one is the point: when the hub cannot price a line honestly it
 * says so instead of inventing a figure.
 */
class QuotationRepricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CurrencyPricing::forget();
    }

    private function actor(array $perms = ['quotations.view', 'quotations.create']): void
    {
        $user = User::factory()->create();
        foreach ($perms as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);
    }

    private function currency(string $code, float $rate = 1.0, bool $isBase = false): void
    {
        DB::table('currencies')->updateOrInsert(
            ['code' => $code],
            ['name' => $code, 'symbol' => $code, 'exchange_rate' => $rate,
             'is_base' => $isBase, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        );
        CurrencyPricing::forget();
    }

    /** A product with the given price rows, e.g. ['KES' => 17000, 'ZMW' => 2400]. */
    private function product(array $prices): Product
    {
        $product = Product::factory()->create(['status' => Product::STATUS_ACTIVE]);
        foreach ($prices as $code => $amount) {
            ProductPrice::create([
                'product_id' => $product->id, 'product_variant_id' => null,
                'currency_code' => $code, 'regular_price' => $amount,
            ]);
        }

        return $product;
    }

    private function reprice(array $lines, string $from = 'KES', string $to = 'ZMW')
    {
        return $this->postJson('/api/v1/admin/quotations/reprice', compact('from', 'to') + ['lines' => $lines]);
    }

    // ── the shop's own price wins ───────────────────────────────────────────

    public function test_a_catalogue_line_takes_the_shops_own_price_for_that_currency(): void
    {
        $this->actor();
        $this->currency('KES', 1.0, true);
        $this->currency('ZMW', 0.14);
        // The kwacha price is NOT 17,000 × 0.14 — the shop positions it on purpose,
        // and a figure a human typed is used exactly as typed.
        $gown = $this->product(['KES' => 17000, 'ZMW' => 2500]);

        $res = $this->reprice([
            ['key' => 'r1', 'product_id' => $gown->id, 'unit_price' => 17000, 'name' => 'Ordination Gown'],
        ])->assertOk();

        $res->assertJsonPath('lines.0.unit_price', 2500)
            ->assertJsonPath('lines.0.source', 'catalogue')
            ->assertJsonPath('summary.catalogue', 1)
            ->assertJsonPath('warnings', []);
    }

    public function test_without_a_price_for_that_currency_the_line_is_converted(): void
    {
        $this->actor();
        $this->currency('KES', 1.0, true);
        $this->currency('ZMW', 0.14);
        $bell = $this->product(['KES' => 2000]);          // no kwacha row

        $this->reprice([['key' => 'r1', 'product_id' => $bell->id, 'unit_price' => 2000, 'name' => 'Bell']])
            ->assertOk()
            ->assertJsonPath('lines.0.unit_price', 280)     // 2000 × 0.14
            ->assertJsonPath('lines.0.source', 'converted');
    }

    public function test_an_ad_hoc_line_carries_across_at_the_configured_rate(): void
    {
        $this->actor();
        $this->currency('KES', 1.0, true);
        $this->currency('USD', 0.01);

        $this->reprice([['key' => 'r1', 'product_id' => null, 'unit_price' => 5000, 'name' => 'Engraving']], 'KES', 'USD')
            ->assertOk()
            ->assertJsonPath('lines.0.unit_price', 50)
            ->assertJsonPath('lines.0.source', 'converted');
    }

    // ── what it refuses to guess ────────────────────────────────────────────

    public function test_a_row_at_zero_is_not_a_price_and_does_not_become_one(): void
    {
        $this->actor();
        $this->currency('KES', 1.0, true);
        $this->currency('USD', 0.01);
        // A USD row created and never filled in. Reading it as a real price is
        // what once billed live orders USD 0.00 for a tray.
        $tray = $this->product(['KES' => 4500, 'USD' => 0]);

        $this->reprice([['key' => 'r1', 'product_id' => $tray->id, 'unit_price' => 4500, 'name' => 'Tray']], 'KES', 'USD')
            ->assertOk()
            ->assertJsonPath('lines.0.unit_price', 45)      // converted, not 0.00
            ->assertJsonPath('lines.0.source', 'converted');
    }

    public function test_an_unconfigured_rate_leaves_the_figure_alone_and_says_why(): void
    {
        $this->actor();
        $this->currency('KES', 1.0, true);
        // ZMW at the schema default of 1.0 is an unconfigured row, not a peg to
        // the shilling. Multiplying by it is how a KES 4,500 tray became a
        // USD 4,500 order — a 130x overcharge that reads as normal on screen.
        $this->currency('ZMW', 1.0);
        $gown = $this->product(['KES' => 17000]);

        $res = $this->reprice([['key' => 'r1', 'product_id' => $gown->id, 'unit_price' => 17000, 'name' => 'Ordination Gown']])
            ->assertOk();

        $res->assertJsonPath('lines.0.unit_price', 17000)   // untouched
            ->assertJsonPath('lines.0.source', 'kept')
            ->assertJsonPath('summary.kept', 1);
        $this->assertStringContainsString('Ordination Gown', $res->json('warnings.0'));
        $this->assertStringContainsString('ZMW', $res->json('warnings.0'));
    }

    // ── the mixed basket, which is the real one ─────────────────────────────

    public function test_a_mixed_basket_reports_each_line_for_what_it_is(): void
    {
        $this->actor();
        $this->currency('KES', 1.0, true);
        $this->currency('ZMW', 0.14);
        $priced    = $this->product(['KES' => 17000, 'ZMW' => 2500]);
        $unpriced  = $this->product(['KES' => 2000]);

        $res = $this->reprice([
            ['key' => 'a', 'product_id' => $priced->id,   'unit_price' => 17000, 'name' => 'Ordination Gown'],
            ['key' => 'b', 'product_id' => $unpriced->id, 'unit_price' => 2000,  'name' => 'Bell'],
            ['key' => 'c', 'product_id' => null,          'unit_price' => 500,   'name' => 'Engraving'],
        ])->assertOk();

        $res->assertJsonPath('summary.catalogue', 1)
            ->assertJsonPath('summary.converted', 2)
            ->assertJsonPath('summary.kept', 0);
        $this->assertSame(['a', 'b', 'c'], array_column($res->json('lines'), 'key'), 'lines come back in order');
    }

    public function test_the_same_currency_changes_nothing(): void
    {
        $this->actor();
        $this->currency('KES', 1.0, true);
        $gown = $this->product(['KES' => 17000]);

        $this->reprice([['key' => 'r1', 'product_id' => $gown->id, 'unit_price' => 17000, 'name' => 'Gown']], 'KES', 'KES')
            ->assertOk()
            ->assertJsonPath('lines.0.unit_price', 17000);
    }

    // ── who may ask ─────────────────────────────────────────────────────────

    public function test_pricing_is_for_staff_who_may_draft_a_quotation(): void
    {
        $this->actor(['quotations.view']);   // may read, may not draft
        $this->currency('KES', 1.0, true);
        $this->currency('ZMW', 0.14);

        $this->reprice([['key' => 'r1', 'product_id' => null, 'unit_price' => 100, 'name' => 'X']])
            ->assertStatus(403);
    }

    public function test_the_request_is_validated(): void
    {
        $this->actor();

        $this->postJson('/api/v1/admin/quotations/reprice', ['to' => 'ZMW', 'from' => 'KES'])
            ->assertStatus(422)->assertJsonValidationErrors(['lines']);

        $this->reprice([['key' => 'r1', 'product_id' => 999999, 'unit_price' => 100, 'name' => 'Ghost']])
            ->assertStatus(422)->assertJsonValidationErrors(['lines.0.product_id']);
    }
}
