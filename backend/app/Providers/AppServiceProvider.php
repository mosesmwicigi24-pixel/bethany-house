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
        // A catalogue edit (price, name, variant) reaches Neema the moment it
        // saves — she busts her quote cache on this event (owner rule: her
        // memory changes when the price changes).
        foreach ([
            \App\Models\Product::class,
            \App\Models\ProductPrice::class,
            \App\Models\ProductVariant::class,
            \App\Models\ProductTranslation::class,
        ] as $catalogModel) {
            $catalogModel::observe(\App\Observers\CatalogObserver::class);
        }
    }
}