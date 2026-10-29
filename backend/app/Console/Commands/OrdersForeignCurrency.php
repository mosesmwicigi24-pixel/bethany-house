<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderCurrencySwitch;
use App\Support\SellableCurrency;
use Illuminate\Console\Command;

/**
 * Foreign customers billed in shillings (owner, 2026-10-29).
 *
 * Until 29 Oct the till, chat and WhatsApp orders gave a customer whose own
 * currency can't be sold (Botswana's pula, say) the shop's currency, while
 * Set Country / Currency gave them USD. This lists every order for a customer
 * outside Kenya that is still in KES where the one rule
 * (SellableCurrency::forCustomer) bills them in something else:
 *
 *   switch    unpaid and open — moved to the customer's currency by
 *             OrderCurrencySwitch, the same code as the Set Country button:
 *             lines from the hub's price list for that currency, else
 *             converted at the pricing rate; discounts and shipping carried;
 *             the reporting rate re-stamped
 *   paid      money has moved (paid, part-paid, a proof under review or a
 *             payment in flight) — left exactly as it is: money collected in
 *             shillings is never restated in another currency
 *   kept      someone kept it in KES on purpose, with a reason, at the link
 *   closed    cancelled, voided or refunded — skipped
 *
 * `--country=ZA` narrows to one country and ALSO moves that country's
 * unpaid orders in any other currency (South Africa's USD orders to rand once
 * the country is billed in ZAR, owner 2026-10-29). An order whose currency
 * the customer asked for, or that was kept in KES on purpose, is left alone.
 *
 * Dry run by default; `--apply --actor=<id or email>` switches the "switch"
 * rows, one transaction each, logged in the actor's name with source
 * "repair". Safe to run again: a switched order is no longer in KES. Links
 * are NOT resent — staff check each new price and resend from the order.
 *
 * The output names orders by id and number only: no customer, phone or
 * payment detail (the workflow log is public).
 */
class OrdersForeignCurrency extends Command
{
    protected $signature = 'orders:foreign-currency
        {--apply : Switch the unpaid orders (default is a dry run that changes nothing)}
        {--actor= : The user the switches are made in the name of (id or email); required with --apply}
        {--order= : Only this order id}
        {--country= : Only customers in this country (ISO code); also moves its orders in any currency other than the one it is billed in}';

    protected $description = 'List foreign customers\' orders billed in KES and switch the unpaid ones to the currency their country is billed in';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $actor = null;
        if ($apply) {
            $who = (string) $this->option('actor');
            $actor = $who === '' ? null : (ctype_digit($who) ? User::query()->find((int) $who) : User::query()->where('email', $who)->first());
            if (!$actor || $actor->status !== 'active') {
                $this->error('--apply needs --actor=<user id or email> of an active user; the switches are made in their name.');

                return self::FAILURE;
            }
        }

        $home        = SellableCurrency::home();
        $homeCountry = SellableCurrency::homeCountry();
        $only        = strtoupper(trim((string) $this->option('country')));
        if ($only !== '' && !SellableCurrency::isForeign($only)) {
            $this->error("--country={$only} is the shop's own country; nothing to move.");

            return self::FAILURE;
        }
        $billedIn = $only !== '' ? SellableCurrency::forCustomer($only) : null;
        $orders = Order::query()
            ->whereNotNull('customer_country_code')
            ->whereRaw('UPPER(customer_country_code) <> ?', [$homeCountry])
            ->when($only !== '',
                fn ($q) => $q->whereRaw('UPPER(customer_country_code) = ?', [$only])->whereRaw('UPPER(currency_code) <> ?', [$billedIn]),
                fn ($q) => $q->whereRaw('UPPER(currency_code) = ?', [$home]))
            ->when($this->option('order'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')
            ->get();

        $tally = ['switch' => 0, 'switched' => 0, 'refused' => 0, 'paid' => 0, 'kept' => 0, 'closed' => 0, 'fine' => 0];
        $this->line(($apply ? 'APPLY' : 'DRY RUN') . ' — ' . $orders->count() . ' order(s) '
            . ($only !== '' ? "for customers in {$only} not in {$billedIn}." : "for customers outside {$homeCountry} in {$home}."));

        foreach ($orders as $o) {
            $country   = strtoupper((string) $o->customer_country_code);
            $target    = SellableCurrency::forCustomer($country);
            $head      = "Order #{$o->id} {$o->order_number} {$country} {$o->currency_code} " . number_format((float) $o->total_amount, 2);

            if ($target === strtoupper((string) $o->currency_code)) { $tally['fine']++; continue; }   // already right
            if (!$o->isPayable()) { $tally['closed']++; $this->line("{$head} → closed ({$o->status}), skipped"); continue; }
            if (OrderCurrencySwitch::moneyHasMoved($o)) { $tally['paid']++; $this->line("{$head} → paid or part-paid ({$o->payment_status}), left as is"); continue; }
            if (OrderCurrencySwitch::chosenOnPurpose($o)) { $tally['kept']++; $this->line("{$head} → {$o->currency_code} chosen on purpose, left as is"); continue; }

            $tally['switch']++;
            if (!$apply) {
                $p = OrderCurrencySwitch::preview($o, $country);
                $this->line($p
                    ? "{$head} → would switch to {$p['currency_code']} " . number_format($p['total'], 2)
                    : "{$head} → would switch to {$target}, but cannot be priced in {$target} (needs a price or rate)");
                continue;
            }

            try {
                $r = OrderCurrencySwitch::toCountry($o, $country, $actor, 'repair');
            } catch (\Throwable $e) {
                report($e);
                $r = ['ok' => false, 'message' => 'failed: ' . class_basename($e) . ' (see the application log)'];
            }
            if ($r['ok'] && $r['changed']) {
                $tally['switched']++;
                $this->line("{$head} → switched to {$r['currency_code']} " . number_format((float) $r['new_total'], 2));
            } else {
                $tally['refused']++;
                $this->warn("{$head} → NOT switched: " . ($r['message'] ?? 'no change'));
            }
        }

        $this->newLine();
        $this->info($apply
            ? "Switched {$tally['switched']}, refused {$tally['refused']}; left paid {$tally['paid']}, kept {$tally['kept']}, closed {$tally['closed']}."
            : "Would switch {$tally['switch']}; left paid {$tally['paid']}, kept {$tally['kept']}, closed {$tally['closed']}.");

        return $tally['refused'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
