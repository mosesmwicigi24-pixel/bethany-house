<?php

namespace App\Providers;

use App\Services\Neema\HttpNeemaAnalyticsClient;
use App\Services\Neema\NeemaAnalyticsClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NeemaAnalyticsClient::class, HttpNeemaAnalyticsClient::class);
    }

    public function boot(): void
    {
        // Order lifecycle events → Neema (one hook for every writer: admin UI,
        // POS, payment webhooks). Inert until NEEMA_EVENTS_SECRET is set.
        \App\Models\Order::observe(\App\Observers\OrderObserver::class);
        // A catalogue edit (price, name, variant, image) reaches Neema and the
        // storefront the moment it saves — Neema busts her quote cache on the
        // event (owner rule: her memory changes when the price changes), and
        // the storefront drops its ISR copy so the change is visible now.
        foreach ([
            \App\Models\Product::class,
            \App\Models\ProductPrice::class,
            \App\Models\ProductVariant::class,
            \App\Models\ProductTranslation::class,
            \App\Models\ProductImage::class,
        ] as $catalogModel) {
            $catalogModel::observe(\App\Observers\CatalogObserver::class);
        }
    }
}