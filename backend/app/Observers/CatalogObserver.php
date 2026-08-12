<?php

namespace App\Observers;

use App\Services\Neema\NeemaEventEmitter;
use App\Services\Storefront\StorefrontRevalidator;
use Illuminate\Support\Facades\Cache;

/**
 * When the catalogue changes, both downstream readers must know NOW:
 *
 *  - Neema quotes customers from a cached copy of it, and the owner's rule is
 *    that her memory changes when a price changes.
 *  - The storefront renders it over ISR with a 300s window, so without a ping
 *    a replaced product photo appears not to have saved at all.
 *
 * One observer covers every model whose edit alters what a customer should be
 * quoted or shown: Product, ProductPrice, ProductVariant, ProductTranslation
 * (names/aliases live there) and ProductImage.
 *
 * ProductImage was missing from that list until this change, which meant an
 * image upload — the single most visible catalogue edit there is — announced
 * itself to nobody.
 *
 * Both are debounced through the cache, on separate windows, because they are
 * paying for different things:
 *
 *  - Neema, 10s: a product save touches several of these rows in one request
 *    and one catalog.updated per burst is plenty — she only busts a cache.
 *  - Storefront, 2s: still collapses the row-burst of a single save (those
 *    land within milliseconds), but a deliberate second edit a few seconds
 *    later is a change the shopper should see. On a 10s window that edit would
 *    be swallowed and then wait out the full 300s ISR — the exact "my upload
 *    did nothing" symptom this whole path exists to remove.
 *
 * Registered in AppServiceProvider::boot(). Best-effort by construction —
 * neither call throws.
 */
class CatalogObserver
{
    public function saved($model): void
    {
        $this->ping();
    }

    public function deleted($model): void
    {
        $this->ping();
    }

    private function ping(): void
    {
        if (Cache::add('neema:catalog-updated:debounce', 1, 10)) {
            NeemaEventEmitter::emitRaw([
                'id'   => 'hub:catalog:' . now()->timestamp,
                'type' => 'catalog.updated',
            ]);
        }

        if (Cache::add('storefront:catalog-updated:debounce', 1, 2)) {
            StorefrontRevalidator::catalogChanged();
        }
    }
}
