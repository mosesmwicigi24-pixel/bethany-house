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
    }
}