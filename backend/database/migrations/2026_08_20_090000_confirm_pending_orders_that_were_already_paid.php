<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Status hygiene for orders that were paid but never confirmed.
 *
 * Revenue is now recognised on human confirmation OR on money arriving. The
 * second arm exists because a customer can pay a payment link before any staff
 * member opens the order, which leaves `status = 'pending'` while the cash is
 * already banked. Reporting handles that correctly on its own, but leaving the
 * row at 'pending' is a lie on every order screen: it reads as an unconfirmed
 * browsing cart when the money is in the account.
 *
 * So this promotes exactly those rows to 'confirmed' — once, idempotently.
 *
 * Scope is deliberately narrow. Only orders that are currently 'pending' AND
 * have a settled payment move. A genuinely abandoned cart (no money) is left
 * alone: it belongs in the pipeline report for a human to work through, and
 * silently confirming those is what caused the KES 1,136,060 overstatement
 * this whole change removes.
 *
 * At the time of writing this affects ZERO rows in production — every one of
 * the 98 pending orders is genuinely unpaid. It is written anyway because the
 * gap between authoring and deploying is exactly when the first one appears,
 * and because a re-run must stay a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('orders as o')
            ->join('payments as p', 'p.order_id', '=', 'o.id')
            ->where('o.status', 'pending')
            ->where('p.status', 'paid')
            ->groupBy('o.id')
            ->havingRaw('SUM(p.amount - COALESCE(p.refund_amount, 0)) > 0')
            ->pluck('o.id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('orders')->whereIn('id', $ids)->update([
            'status'     => 'confirmed',
            'updated_at' => now(),
        ]);
    }

    /**
     * Not reversible by design. 'confirmed' is indistinguishable from a
     * confirmation a human made afterwards, so a down() would demote real
     * staff decisions back to pending and drop that revenue on the floor.
     */
    public function down(): void
    {
    }
};
