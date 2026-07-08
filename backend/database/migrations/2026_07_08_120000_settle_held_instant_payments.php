<?php

use App\Support\SettleHeldInstantPayments;
use Illuminate\Database\Migrations\Migration;

/**
 * One-time cleanup for POS orders (e.g. I&M Paybill) that were wrongly held in
 * "Processing"/pending_approval despite being fully paid. Settles the held
 * payments for no-approval methods and re-marks their orders paid/confirmed.
 * See App\Support\SettleHeldInstantPayments. Idempotent and forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        SettleHeldInstantPayments::run();
    }

    public function down(): void
    {
        // One-time data correction — not reversible.
    }
};
