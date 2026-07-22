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
        //
    }
}