<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\CurrencyPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A sale price that is not a discount is not a sale price.
 *
 * A sale_price with no window is on sale FOREVER, so one equal to the regular
 * price marks a product permanently on sale at no saving — 180 rows across 61
 * products on production did exactly that. Two rows were ABOVE the regular
 * price: a Cassock listed at 13,000 with a 20,000 "sale". The storefront bills
 * effective_price and the till bills regular_price, so those carried a
 * different price depending on which door the customer used.
 *
 * The guard lives on the MODEL because prices are written from a dozen places —
 * four API endpoints, three Livewire screens, variant bulk-edit. A rule kept at
 * the call site is a rule the next door forgets.
 */
class SalePriceIsADiscountTest extends TestCase
{
    use RefreshDatabase;

    private function price(array $attrs = []): array
    {
        return array_merge([
            'product_id'         => Product::factory()->create()->id,
            'product_variant_id' => null,
            'currency_code'      => 'KES',
            'regular_price'      => 10000,
        ], $attrs);
    }

    public function test_a_real_discount_saves(): void
    {
        $row = ProductPrice::create($this->price(['sale_price' => 8500]));

        $this->assertSame('8500.00', $row->fresh()->sale_price);
    }

    public function test_no_sale_price_at_all_is_always_fine(): void
    {
        $row = ProductPrice::create($this->price(['sale_price' => null]));

        $this->assertNull($row->fresh()->sale_price);
    }

    public function test_a_sale_price_equal_to_the_regular_price_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        ProductPrice::create($this->price(['sale_price' => 10000]));
    }

    public function test_a_sale_price_above_the_regular_price_is_refused(): void
    {
        // The live Cassock: 13,000 list, 20,000 "sale".
        try {
            ProductPrice::create($this->price(['regular_price' => 13000, 'sale_price' => 20000]));
            $this->fail('a sale price above list must not be storable');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('lower than the regular price',
                $e->validator->errors()->first('sale_price'));
        }
    }

    public function test_a_zero_sale_price_reads_as_none_not_as_free(): void
    {
        $row = ProductPrice::create($this->price(['sale_price' => 0]));

        $this->assertNull($row->fresh()->sale_price);
    }

    public function test_the_guard_holds_on_a_later_edit_too(): void
    {
        $row = ProductPrice::create($this->price(['sale_price' => 8500]));

        $this->expectException(ValidationException::class);
        $row->update(['sale_price' => 12000]);
    }

    /** The charged price can never exceed list, whatever the row says. */
    public function test_a_bad_row_that_predates_the_guard_still_cannot_overcharge(): void
    {
        $product = Product::factory()->create();
        // Written past the model, exactly as the production rows were.
        DB::table('product_prices')->insert([
            'product_id' => $product->id, 'product_variant_id' => null,
            'currency_code' => 'KES', 'regular_price' => 13000, 'sale_price' => 20000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $priced = CurrencyPricing::catalogue($product->id, null, 'KES');

        $this->assertSame(13000.0, $priced['effective_price'],
            'the customer is charged list, never the inflated "sale"');
        $this->assertNull($priced['sale_price']);
    }

    /** ── the window ─────────────────────────────────────────────────────── */

    public function test_a_sale_runs_through_the_last_day_named_on_the_label(): void
    {
        $product = Product::factory()->create();
        ProductPrice::create($this->price([
            'product_id'      => $product->id,
            'sale_price'      => 8500,
            'sale_end_date'   => today(),        // "Sale Until <today>"
        ]));

        $this->travelTo(today()->setTime(17, 30));

        $this->assertSame(8500.0, CurrencyPricing::catalogue($product->id, null, 'KES')['effective_price'],
            'the day printed on the label must be a day the shop actually sells on');
    }

    public function test_the_sale_is_over_the_day_after(): void
    {
        $product = Product::factory()->create();
        ProductPrice::create($this->price([
            'product_id'    => $product->id,
            'sale_price'    => 8500,
            'sale_end_date' => today(),
        ]));

        $this->travelTo(today()->addDay()->setTime(9, 0));

        $this->assertSame(10000.0, CurrencyPricing::catalogue($product->id, null, 'KES')['effective_price']);
    }

    public function test_a_future_sale_does_not_start_early(): void
    {
        $product = Product::factory()->create();
        ProductPrice::create($this->price([
            'product_id'      => $product->id,
            'sale_price'      => 8500,
            'sale_start_date' => today()->addDays(3),
        ]));

        $this->assertSame(10000.0, CurrencyPricing::catalogue($product->id, null, 'KES')['effective_price']);
    }

    public function test_a_window_round_trips_as_the_day_it_was_typed(): void
    {
        // Cast as datetime these came back "2026-06-08T18:00:00.000000Z" — a
        // value no <input type="date"> can render, so the Pricing screen showed
        // an EMPTY box for a window that existed, and the UTC shift moved the
        // day by one.
        $row = ProductPrice::create($this->price([
            'sale_price'      => 8500,
            'sale_start_date' => '2026-06-09',
            'sale_end_date'   => '2026-06-30',
        ]))->fresh();

        $json = $row->toArray();

        $this->assertSame('2026-06-09', $json['sale_start_date']);
        $this->assertSame('2026-06-30', $json['sale_end_date']);
    }
}
