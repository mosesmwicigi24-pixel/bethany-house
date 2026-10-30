<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\OrderCurrencySwitch;
use App\Services\OrderRepricer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Unpaid orders still billed with cents (owner, 2026-10-30: "All customer
 * numbers should be rounded off").
 *
 * New orders are billed in whole units (App\Support\CustomerMoney). This lists
 * the open orders written before that whose customer figures still carry
 * cents — a unit price, a discount, shipping, VAT added on top, or the total —
 * and what each would come to:
 *
 *   round     unpaid and open — rebilled by OrderRepricer in its own
 *             currency: each price, discount and shipping fee to a whole
 *             unit, VAT worked out again, the total following
 *   paid      money has moved (paid, part-paid, a proof under review or a
 *             payment in flight) — left exactly as it is
 *   closed    cancelled, voided or refunded — skipped
 *
 * Dry run by default; `--apply --actor=<id or email>` rebills the "round"
 * rows, one transaction each, logged in the actor's name. Safe to run again:
 * a rebilled order has no cents left. Links are NOT resent.
 *
 * The output names orders by id and number only: no customer, phone or
 * payment detail (the workflow log is public).
 */
class OrdersWholeAmounts extends Command
{
    protected $signature = 'orders:whole-amounts
        {--apply : Rebill the unpaid orders (default is a dry run that changes nothing)}
        {--actor= : The user the changes are made in the name of (id or email); required with --apply}
        {--order= : Only this order id}';

    protected $description = 'List open orders still billed with cents and rebill the unpaid ones in whole units';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $actor = null;
        if ($apply) {
            $who   = (string) $this->option('actor');
            $actor = $who === '' ? null : (ctype_digit($who) ? User::query()->find((int) $who) : User::query()->where('email', $who)->first());
            if (!$actor || $actor->status !== 'active') {
                $this->error('--apply needs --actor=<user id or email> of an active user; the changes are made in their name.');

                return self::FAILURE;
            }
        }

        $cents = fn (string $col) => "{$col} <> ROUND({$col})";
        $orders = Order::query()
            ->with(['items'])
            ->where(function ($q) use ($cents) {
                $q->whereRaw($cents('total_amount'))
                  ->orWhereRaw($cents('subtotal'))
                  ->orWhereRaw($cents('discount_amount'))
                  ->orWhereRaw($cents('shipping_amount'))
                  ->orWhereRaw('(prices_include_tax = false AND ' . $cents('tax_amount') . ')')
                  ->orWhereExists(fn ($i) => $i->from('order_items')->whereColumn('order_items.order_id', 'orders.id')
                      ->where(fn ($w) => $w->whereRaw($cents('order_items.unit_price'))->orWhereRaw($cents('order_items.discount_amount'))));
            })
            ->when($this->option('order'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')
            ->get();

        $tally = ['round' => 0, 'rounded' => 0, 'refused' => 0, 'paid' => 0, 'closed' => 0];
        $this->line(($apply ? 'APPLY' : 'DRY RUN') . ' — ' . $orders->count() . ' order(s) with cents in a customer figure.');

        foreach ($orders as $o) {
            $currency = strtoupper((string) $o->currency_code);
            $head     = "Order #{$o->id} {$o->order_number} {$currency} " . number_format((float) $o->total_amount, 2);

            if (!$o->isPayable()) { $tally['closed']++; $this->line("{$head} → closed ({$o->status}), skipped"); continue; }
            if (OrderCurrencySwitch::moneyHasMoved($o)) { $tally['paid']++; $this->line("{$head} → paid or part-paid ({$o->payment_status}), left as is"); continue; }

            $plan = OrderRepricer::plan($o, [], $currency, $currency);
            $tally['round']++;
            if (!$apply) {
                $this->line("{$head} → would be {$currency} " . number_format($plan['total'], 2));
                continue;
            }

            $oldTotal = (float) $o->total_amount;
            $late = DB::transaction(function () use ($o, $plan) {
                $locked = Order::whereKey($o->id)->lockForUpdate()->first();
                if ($locked && ($why = OrderCurrencySwitch::moneyHasMoved($locked))) {
                    return $why;
                }
                OrderRepricer::apply($o, $plan);

                return null;
            });
            if ($late !== null) {
                $tally['refused']++;
                $this->warn("{$head} → NOT changed: {$late}");
                continue;
            }

            ActivityLogService::log('amounts_rounded', $o, [
                'currency'  => $currency,
                'old_total' => $oldTotal,
                'new_total' => $plan['total'],
                'source'    => 'repair',
                'actor_id'  => $actor->id,
            ], "{$actor->first_name} rounded {$o->order_number} to whole {$currency}: "
                . number_format($oldTotal, 2) . ' → ' . number_format($plan['total'], 2), $actor);

            $tally['rounded']++;
            $this->line("{$head} → now {$currency} " . number_format($plan['total'], 2));
        }

        $this->newLine();
        $this->info($apply
            ? "Rounded {$tally['rounded']}, refused {$tally['refused']}; left paid {$tally['paid']}, closed {$tally['closed']}."
            : "Would round {$tally['round']}; left paid {$tally['paid']}, closed {$tally['closed']}.");

        return $tally['refused'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
