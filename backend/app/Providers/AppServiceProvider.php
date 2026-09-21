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

        // Scoped, not singleton: forgotten between queue jobs, so a worker
        // never carries one job's request id into the next.
        $this->app->scoped(\App\Support\Audit\AuditContext::class);
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

        // The audit trail: before-and-after of every write to a business
        // record, whichever controller, job or webhook made it (config/audit.php).
        foreach ((array) config('audit.observed_models', []) as $audited) {
            if (class_exists($audited)) {
                $audited::observe(\App\Observers\AuditObserver::class);
            }
        }
    }
}