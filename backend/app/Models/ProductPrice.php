<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'currency_code',
        'regular_price',
        'sale_price',
        'cost_price',
        'sale_start_date',
        'sale_end_date',
    ];

    protected $casts = [
        'regular_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        // A sale WINDOW is a pair of days, not instants. Cast as datetime these
        // serialised as "2026-06-08T18:00:00.000000Z" — a value no <input
        // type="date"> can render, so the Pricing screen showed an EMPTY box
        // for a window that existed, and the UTC shift moved the day by one.
        // Date-only round-trips exactly as typed, in any timezone.
        'sale_start_date' => 'date:Y-m-d',
        'sale_end_date' => 'date:Y-m-d',
    ];

    /**
     * A sale price that is not a discount is not a sale price.
     *
     * This lives on the MODEL and not in a controller because prices are
     * written from a dozen places — four API endpoints, three Livewire screens,
     * variant bulk-edit — and a rule kept at the call site is a rule the next
     * door forgets. On production this had already produced 180 rows whose sale
     * price equalled the regular price (a permanent "sale" at no discount,
     * since a window-less sale_price is on sale forever) and two that were
     * ABOVE it: a Cassock listed at 13,000 with a 20,000 "sale", which the
     * storefront would have charged because it bills effective_price while the
     * till bills regular_price. Same product, two prices, depending on the door.
     *
     * ValidationException rather than a bare exception, so the API answers 422
     * with a usable message and Livewire shows it against the field.
     */
    protected static function booted(): void
    {
        // A product's variants are sold at the product's price (owner,
        // 2026-11-26; App\Support\VariantPriceSync). On the MODEL for the
        // reason given below: prices are written from a dozen places, and a
        // rule kept at the call site is one the next door forgets.
        //
        //   a VARIANT row takes its product's selling price and sale window
        //   whatever was sent (its cost stays its own);
        //   a PRODUCT row that changes carries every variant with it.
        static::saving(function (self $price) {
            if ($price->product_variant_id === null) {
                return;
            }
            $master = static::query()->where('product_id', $price->product_id)->whereNull('product_variant_id')
                ->where('currency_code', $price->currency_code)->first();
            if ($master) {
                $price->regular_price   = $master->regular_price;
                $price->sale_price      = $master->sale_price;
                $price->sale_start_date = $master->sale_start_date;
                $price->sale_end_date   = $master->sale_end_date;
            }
        });

        static::saved(function (self $price) {
            // A variant row brought to its product's price by the rule above
            // (somebody saved the variant — its cost, say — while it was out
            // of step): a price change, so it goes on the trail with the old
            // figures, like every other.
            if ($price->product_variant_id !== null && !$price->wasRecentlyCreated
                && $price->wasChanged(['regular_price', 'sale_price'])) {
                \App\Services\ActivityLogService::log('variant_prices_followed_product', $price->product, [
                    'product_id' => (int) $price->product_id,
                    'currency'   => (string) $price->currency_code,
                    'via'        => 'variant_row_saved',
                    'variants'   => [[
                        'variant_id' => (int) $price->product_variant_id,
                        'before'     => ['regular_price' => $price->getOriginal('regular_price'), 'sale_price' => $price->getOriginal('sale_price')],
                        'after'      => ['regular_price' => $price->regular_price, 'sale_price' => $price->sale_price],
                    ]],
                ], "1 variant price(s) set to the product's {$price->currency_code} price", auth()->user());
            }

            if ($price->product_variant_id === null
                && ($price->wasRecentlyCreated || $price->wasChanged(['regular_price', 'sale_price', 'sale_start_date', 'sale_end_date']))) {
                \App\Support\VariantPriceSync::follow($price, auth()->user(),
                    $price->wasRecentlyCreated ? 'price_row_created' : 'price_change', createMissing: false);
            }
        });

        static::saving(function (self $price) {
            // Saved to the cent in every currency (CustomerMoney::listPrice,
            // owner 2026-11-16) — here, on the model, for the same reason as
            // the sale rule below.
            foreach (['regular_price', 'sale_price'] as $col) {
                if ($price->{$col} !== null && $price->isDirty($col)) {
                    $price->{$col} = \App\Support\CustomerMoney::listPrice((float) $price->{$col}, (string) $price->currency_code);
                }
            }

            if ($price->sale_price === null) {
                return;                       // no sale is always allowed
            }

            $sale    = (float) $price->sale_price;
            $regular = (float) $price->regular_price;

            if ($sale <= 0.0) {
                $price->sale_price = null;    // 0 means "none", not "free"
                return;
            }

            if ($sale >= $regular) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'sale_price' => sprintf(
                        'The %s sale price (%s) must be lower than the regular price (%s). Leave it empty when the item is not on sale.',
                        $price->currency_code,
                        number_format($sale, 2),
                        number_format($regular, 2),
                    ),
                ]);
            }

            // The owner's 5% rule (App\Support\DiscountRule): a markdown deeper
            // than 5% is the super_admin's to save. Here, on the model, for the
            // same reason as the rule above — every screen, endpoint and import
            // that writes a price passes through it. Only a signed-in person's
            // save is judged: a console seed or migration is not staff
            // discretion. An existing row may be saved again, or changed, so
            // long as its markdown does not get deeper — nothing is repriced.
            // (DiscountRule::actor(): the signed-in user, or the maker of a
            // signed proposal being applied — Phase 3C.)
            $actor = \App\Support\DiscountRule::actor();
            if ($actor instanceof User) {
                $oldRegular = $price->exists ? (float) $price->getOriginal('regular_price') : null;
                $oldSale    = $price->exists && $price->getOriginal('sale_price') !== null
                    ? (float) $price->getOriginal('sale_price') : null;

                if ($message = \App\Support\DiscountRule::salePriceRefusal($actor, $regular, $sale, $oldRegular, $oldSale)) {
                    throw new \App\Exceptions\DiscountAboveMaximum('sale_price', $message);
                }
            }
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * The selling price, published with every price row.
     *
     * Neema read `sale_price or regular_price` off this feed while the hub's
     * order path charged `regular_price` — so she quoted the Preaching Gown at
     * 18,000 and the hub billed 20,000, on 60 products. Publishing ONE number,
     * computed here, is what stops the quote and the charge being two different
     * calculations that can drift apart again.
     *
     * Window-aware, and never above the regular price.
     */
    protected $appends = ['effective_price'];

    public function getEffectivePriceAttribute(): float
    {
        $regular = (float) $this->regular_price;
        $sale    = $this->sale_price !== null ? (float) $this->sale_price : null;

        if ($sale === null || $sale <= 0 || $sale >= $regular) {
            return round($regular, 2);
        }

        return round($this->isOnSale() ? $sale : $regular, 2);
    }

    public function getEffectivePrice()
    {
        if ($this->sale_price && $this->isOnSale()) {
            return $this->sale_price;
        }
        return $this->regular_price;
    }

    public function isOnSale()
    {
        $now = now();

        if (!$this->sale_price) {
            return false;
        }

        if ($this->sale_start_date && $now->lt($this->sale_start_date->startOfDay())) {
            return false;
        }

        // "Sale Until 5 Sept" includes the 5th. The end date is stored at
        // midnight, so comparing against it raw ended the sale at the START of
        // the day named on the label — the shop advertised a last day it never
        // actually sold on.
        if ($this->sale_end_date && $now->gt($this->sale_end_date->endOfDay())) {
            return false;
        }

        return true;
    }
}
