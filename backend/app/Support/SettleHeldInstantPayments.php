<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time correction: settle POS payments that were wrongly held for approval.
 *
 * Before the fix, the POS payment modal forced a proof-of-payment upload for any
 * method that wasn't exactly cash/M-Pesa/card, which made recordPosPay hold the
 * payment (status='pending', requires_approval=true, approval_status='pending_review')
 * and the order (payment_status='pending_approval', status='processing') — even for
 * I&M Paybill, which settles instantly and needs no approval. Those orders show a
 * payment link and never complete despite being fully paid.
 *
 * This settles held payments whose method is NOT configured to require approval
 * (I&M, and anything an admin set to no-approval), then re-marks the affected
 * orders paid/confirmed when the money on file covers the total. Genuine approval
 * methods (cheque / bank transfer / Western Union / MoneyGram / "other") are left
 * untouched, as are closed orders. Idempotent — re-running finds nothing.
 */
class SettleHeldInstantPayments
{
    /**
     * @return array{payments:int, orders:int}
     */
    public static function run(): array
    {
        $result = ['payments' => 0, 'orders' => 0];

        if (!Schema::hasTable('payments') || !Schema::hasTable('orders')) {
            return $result;
        }

        // Methods that settle instantly (no approval): the built-in instant rails
        // plus any payment_methods row explicitly configured requires_approval=false.
        $instantCodes = collect(['cash', 'mpesa', 'card', 'card_paystack', 'card_flutterwave', 'paystack', 'flutterwave']);
        if (Schema::hasTable('payment_methods') && Schema::hasColumn('payment_methods', 'requires_approval')) {
            $instantCodes = $instantCodes->merge(
                DB::table('payment_methods')->where('requires_approval', false)->pluck('code')
            );
        }
        $instantCodes = $instantCodes->filter()->unique()->values()->all();
        if (empty($instantCodes)) {
            return $result;
        }

        // Payments wrongly held for approval on an instant method.
        $held = DB::table('payments')
            ->where('status', 'pending')
            ->where('requires_approval', true)
            ->where('approval_status', 'pending_review')
            ->whereIn('payment_method', $instantCodes)
            ->get(['id', 'order_id']);

        if ($held->isEmpty()) {
            return $result;
        }

        // Settle the payments — treat them as the instant, no-approval payments
        // they always should have been. (Cash drawers are unaffected: these
        // methods are not cash, so no register reconciliation changes.)
        $result['payments'] = DB::table('payments')
            ->whereIn('id', $held->pluck('id'))
            ->update([
                'status'            => 'paid',
                'requires_approval' => false,
                'approval_status'   => null,
                'paid_at'           => DB::raw('COALESCE(paid_at, NOW())'),
                'updated_at'        => now(),
            ]);

        // Re-settle each affected order from the money actually on file.
        foreach ($held->pluck('order_id')->unique()->filter() as $orderId) {
            $order = DB::table('orders')->where('id', $orderId)->first();
            if (!$order) {
                continue;
            }
            // Never touch a closed order.
            if (in_array($order->status, ['cancelled', 'voided', 'refunded', 'completed'])) {
                continue;
            }

            // If the order still has a genuinely-pending approval payment (e.g. a
            // split with a cheque), leave it on hold.
            $stillHeld = DB::table('payments')
                ->where('order_id', $orderId)
                ->where('status', 'pending')
                ->where('requires_approval', true)
                ->where('approval_status', 'pending_review')
                ->exists();
            if ($stillHeld) {
                continue;
            }

            $paid  = (float) DB::table('payments')
                ->where('order_id', $orderId)
                ->where('status', 'paid')
                ->sum('amount');
            $total = (float) $order->total_amount;

            $update = ['updated_at' => now()];
            if ($paid >= $total - 0.01) {
                $update['payment_status'] = 'paid';
                if ($order->status === 'processing') {
                    $update['status'] = 'confirmed';
                }
            } elseif ($paid > 0.01) {
                $update['payment_status'] = 'partial';
            }

            DB::table('orders')->where('id', $orderId)->update($update);
            $result['orders']++;
        }

        return $result;
    }
}
