<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-method approval policy.
 *
 * Until now approval was HARDCODED in three payment paths (createSale,
 * recordPosPay, addPayment) and again on the frontend, all keyed off the coarse
 * `type` column. The only lever was type='cash', which is why I&M Paybill —
 * configured type='cash' so it settles immediately — could never be told apart
 * from actual cash, and why the frontend and backend classified the same method
 * differently (the order-detail screen falsely showed I&M as "awaiting approval").
 *
 * This adds an authoritative, per-method `requires_approval` flag so the policy
 * is configurable in the admin UI instead of living in code:
 *   - true  → the payment is held pending_review until an admin approves it
 *             (cheque, bank transfer, Western Union, MoneyGram, "other").
 *   - false → the payment settles immediately with a notification only
 *             (cash, I&M Paybill, M-Pesa, card — gateway/instant methods).
 *   - null  → un-configured; the code falls back to the legacy type derivation
 *             so behaviour never regresses for rows this backfill didn't touch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('payment_methods', 'requires_approval')) {
            Schema::table('payment_methods', function (Blueprint $table) {
                // Nullable on purpose: NULL means "not configured — derive from type".
                $table->boolean('requires_approval')->nullable()->after('type');
            });
        }

        // Backfill from the existing type so live methods get the right policy.
        // Instant/gateway settlement → no approval.
        DB::table('payment_methods')
            ->whereIn('type', ['cash', 'mobile_money', 'card', 'wallet'])
            ->update(['requires_approval' => false]);

        // Manually-verified rails → approval required.
        DB::table('payment_methods')
            ->where('type', 'bank_transfer')
            ->update(['requires_approval' => true]);

        // Belt-and-braces by code for the manual rails the owner named, in case
        // any were mis-typed (e.g. a cheque method created as type='card').
        DB::table('payment_methods')
            ->whereIn('code', ['bank_transfer', 'cheque', 'check', 'western_union', 'moneygram', 'money_gram', 'other'])
            ->update(['requires_approval' => true]);

        // I&M Paybill (inmpaybill) settles instantly like cash — notification only.
        DB::table('payment_methods')
            ->whereIn('code', ['inmpaybill', 'cash', 'mpesa', 'paystack', 'flutterwave'])
            ->update(['requires_approval' => false]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_methods', 'requires_approval')) {
            Schema::table('payment_methods', function (Blueprint $table) {
                $table->dropColumn('requires_approval');
            });
        }
    }
};
