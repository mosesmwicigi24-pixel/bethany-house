<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderCurrencySwitch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Orders with a payment in another currency and nothing saying what it counts
 * for on the order (owner, 2026-11-05 — order #1158: USD 25 counted as ZAR 25).
 *
 *   orders:payment-currency                     list (read-only, the default)
 *   orders:payment-currency --counts-only       just the totals (public CI logs)
 *   orders:payment-currency --mail              email the list to the owner
 *                                               (audit.owner_email); print the count only
 *   orders:payment-currency --apply --order=ID --actor=EMAIL
 *                                               stamp ONE order, once the owner
 *                                               has seen it and said yes
 *
 * Stamping keeps every payment as paid (amount, currency, reporting rate);
 * it records what the payment counts for in the order's currency, at the
 * price list (OrderCurrencySwitch::stampStrayPayments), and re-reads the
 * order's payment status.
 */
class OrdersPaymentCurrency extends Command
{
    protected $signature = 'orders:payment-currency {--counts-only} {--mail} {--apply} {--order=} {--actor=}';
    protected $description = 'List (or, one at a time, fix) orders whose payments are in another currency with no counted amount';

    public function handle(): int
    {
        $ids = DB::table('payments as p')->join('orders as o', 'o.id', '=', 'p.order_id')
            ->whereRaw('UPPER(p.currency_code) <> UPPER(o.currency_code)')->whereNull('p.order_currency_code')
            ->whereNotIn('p.status', ['failed', 'cancelled', 'voided', 'void', 'reversed'])
            ->distinct()->orderBy('p.order_id')->pluck('p.order_id');

        if ($this->option('apply')) {
            $id = (int) $this->option('order');
            $actor = User::where('email', (string) $this->option('actor'))->first();
            if (!$id || !$actor) {
                $this->error('--apply needs --order=ID and --actor=EMAIL (one order at a time, after the owner has seen the list).');
                return self::FAILURE;
            }
            if (!$ids->contains($id)) {
                $this->info("Order {$id} has no payment to stamp.");
                return self::SUCCESS;
            }
            $order = Order::with('items')->findOrFail($id);
            $done = OrderCurrencySwitch::stampStrayPayments($order, $actor);
            foreach ($done as $d) {
                $this->line("{$order->order_number}: {$d['payment']} paid {$d['paid']} now counts {$order->currency_code} " . number_format($d['counts_now'], 2));
            }
            $this->info($done ? "Stamped {$order->order_number}: " . $order->fresh()->payment_status . ', balance ' . number_format($order->fresh()->outstandingBalance(), 2) : 'Nothing could be priced; left for a person.');

            return self::SUCCESS;
        }

        $this->line('Orders with a payment in another currency and no counted amount: ' . $ids->count());
        if ($this->option('counts-only') || $ids->isEmpty()) {
            return self::SUCCESS;
        }
        $lines = [];
        foreach (Order::with('items')->whereIn('id', $ids)->orderBy('id')->get() as $o) {
            $pays = DB::table('payments')->where('order_id', $o->id)->whereRaw('UPPER(currency_code) <> ?', [strtoupper($o->currency_code)])
                ->whereNull('order_currency_code')->get(['payment_number', 'amount', 'currency_code', 'status', 'approval_status']);
            $lines[] = ("#{$o->id} {$o->order_number} {$o->currency_code} " . number_format((float) $o->total_amount, 2)
                . " (balance now reads " . number_format($o->outstandingBalance(), 2) . ')');
            foreach ($pays as $p) {
                $there = OrderCurrencySwitch::totalIn($o, $p->currency_code);
                $would = $there ? round((float) $p->amount * (float) $o->total_amount / $there, 2) : null;
                $lines[] = ("   {$p->payment_number} {$p->currency_code} " . number_format((float) $p->amount, 2) . " [{$p->status}" . ($p->approval_status ? "/{$p->approval_status}" : '') . ']'
                    . ($would !== null ? " → would count {$o->currency_code} " . number_format($would, 2) : ' → cannot be priced; needs a person'));
            }
        }

        if ($this->option('mail')) {
            $to = trim((string) config('audit.owner_email'));
            if ($to === '') {
                $this->error('No owner email configured (AUDIT_OWNER_EMAIL).');
                return self::FAILURE;
            }
            $body = "Orders with a payment in another currency and nothing saying what it counts for on the order.\n"
                . "Nothing has been changed. To fix one, after checking it: orders:payment-currency --apply --order=ID --actor=EMAIL\n\n"
                . implode("\n", $lines) . "\n";
            \Illuminate\Support\Facades\Mail::raw($body, fn ($m) => $m->to($to)->subject('Bethany Hub — ' . $ids->count() . ' orders with payments in another currency'));
            $this->info('List emailed to the owner.');

            return self::SUCCESS;
        }
        foreach ($lines as $l) {
            $this->line($l);
        }

        return self::SUCCESS;
    }
}
