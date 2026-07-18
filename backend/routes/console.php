<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Intelligence checks — nightly at 07:00 ───────────────────────────────────
// Runs: budget warnings, churn risk summary, material shortage summary.
// Manual run: php artisan intelligence:run
// Dry run:    php artisan intelligence:run --dry-run

Schedule::command(\App\Console\Commands\SendInsightsDigest::class)
    ->dailyAt('07:30')
    ->timezone('Africa/Nairobi');

Schedule::command(\App\Console\Commands\RunIntelligenceChecks::class)
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[Intelligence] Nightly checks failed.');
    });
// ── Reap abandoned POS orders — hourly ───────────────────────────────────────
// Cancels unpaid pending POS orders older than 24h and restores their reserved
// stock, so abandoned carts never silently drain the shelf count.
// Manual run: php artisan pos:reap-abandoned-orders
Schedule::command(\App\Console\Commands\ReapAbandonedOrders::class)
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('[POS] Abandoned-order reap failed.');
    });

// ── Stock aging check — daily at 08:00 ───────────────────────────────────────
// Notifies procurement/owners when tracked units have sat unsold too long.
// Manual run: php artisan serials:check-aging
Schedule::command(\App\Console\Commands\CheckStockAging::class)
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();
