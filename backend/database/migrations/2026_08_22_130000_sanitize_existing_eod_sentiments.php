<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * One-time backfill: sanitise sentiments already stored in
 * cash_register_eod_reports before the write path started sanitising. Those
 * rows still carry raw WYSIWYG HTML and are the live stored-XSS payloads, so
 * cleaning them is part of the same fix — new writes alone would leave the
 * existing ones dangerous.
 *
 * GUARDED on Schema::hasTable because this repo mixes YYYY_MM_DD and YYYY_DD_MM
 * migration names, so on a fresh run this file sorts BEFORE
 * 2026_16_06_000002_create_cash_register_eod_reports (which is really June 16).
 * On a fresh database there are no rows to backfill, so skipping is correct;
 * in production the table has existed for months, so the backfill runs.
 * Idempotent: re-sanitising already-clean HTML returns the same value.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_register_eod_reports')) {
            return;
        }

        // The work lives in eod:sanitize-sentiments so it can be run AGAIN. A
        // migration gets one attempt — marked as run whether it did the work or
        // stood down — and this one has a reason to stand down: it will not
        // rewrite months-old notes with the plain-text fallback when the
        // purifier package is missing from the container, because down()
        // restores nothing and that formatting cannot be typed back. When that
        // happens the command says so and stays available to re-run; the deploy
        // is not held up either way, which is the whole point.
        Artisan::call('eod:sanitize-sentiments');
    }

    public function down(): void
    {
        // One-way data cleanup: the pre-sanitisation HTML was an XSS vector and
        // is deliberately not recoverable, so there is nothing safe to restore.
    }
};
