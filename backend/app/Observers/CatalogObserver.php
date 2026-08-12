<?php

namespace App\Observers;

use App\Services\Neema\NeemaEventEmitter;
use Illuminate\Support\Facades\Cache;

/**
 * When the catalogue changes, Neema must know NOW — she quotes customers from
 * a cached copy of it, and the owner's rule is that her memory changes when a
 * price changes. One observer covers every model whose edit alters what a
 * customer should be quoted: Product, ProductPrice, ProductVariant,
 * ProductTranslation (names/aliases live there).
 *
 * Debounced through the cache (10s): a product save touches several of these
 * rows in one request, and one catalog.updated per burst is plenty — Neema
 * only busts her cache with it.
 *
 * Registered in AppServiceProvider::boot(). Best-effort by construction —
 * the emitter never throws.
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
        if (! Cache::add('neema:catalog-updated:debounce', 1, 10)) {
            return;                       // a sibling row already announced this burst
        }
        NeemaEventEmitter::emitRaw([
            'id'   => 'hub:catalog:' . now()->timestamp,
            'type' => 'catalog.updated',
        ]);
    }
}
