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

// ── Cross-channel engagement — pull Neema's message rollup nightly ───────────
Schedule::command(\App\Console\Commands\SyncChannelTouchpoints::class)
    ->dailyAt('06:30')
    ->timezone('Africa/Nairobi')
    ->withoutOverlapping()
    ->runInBackground();
// ── Replenishment pings — daily at 10:00 Nairobi ─────────────────────────────
// WhatsApp reorder reminders for customers the Replenishment Radar says are
// due. Marketing sends are a CONSCIOUS OPT-IN: the closure no-ops silently
// until REPLENISHMENT_PINGS_ENABLED=true is set (config/services.php).
// Manual run: php artisan replenishment:send-pings --dry-run
Schedule::call(function () {
    if (!config('services.replenishment.pings_enabled')) {
        return; // disabled by default — see config/services.php
    }
    Artisan::call(\App\Console\Commands\SendReplenishmentPings::class);
})
    ->name('replenishment-send-pings')
    ->dailyAt('10:00')
    ->timezone('Africa/Nairobi')
    ->withoutOverlapping();

// ── Abandoned POS orders — NO LONGER CANCELLED AUTOMATICALLY ────────────────
// This used to run hourly, cancelling unpaid pending POS orders older than 24h.
// It cancelled 12 real orders worth KES 170,400 between July and August —
// including a KES 49,500 sale — each of them a customer a human had served and
// might still have closed.
//
// Owner's rule (2026-09-01): "The system should not cancel the receipts, only
// human should." So nothing here cancels anything. The signal the reaper
// existed for — which carts have gone stale — is better served by the Pending
// Queue (/sales/pending-queue), where a person sees the order, the customer and
// the age, and decides.
//
// The command survives for a human to run deliberately, and now refuses to
// cancel without --force. DO NOT re-add a schedule for it.

// ── Stock aging check — daily at 08:00 ───────────────────────────────────────
// Notifies procurement/owners when tracked units have sat unsold too long.
// Manual run: php artisan serials:check-aging
Schedule::command(\App\Console\Commands\CheckStockAging::class)
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();
