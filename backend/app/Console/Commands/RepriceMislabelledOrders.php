<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\ActivityLogService;
use App\Services\CurrencyPricing;
use App\Services\OrderRepricer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repair orders written before the currency fix: a foreign-currency order
 * carrying the KES figure under its own label — WA-260813-XFPIR billed
 * "USD 4,500.00" for a tray the hub sells at KES 4,500.
 *
 * Only lines it can PROVE are mislabelled are touched. The fingerprint is
 * specific: the stored unit price is not the hub's price for the order's
 * currency, but it does match, to the cent, one of that product's price rows
 * in a DIFFERENT currency. That is the label moving without the number. A
 * price that matches nothing is a discount or a hand-set figure — a human
 * decision, reported and left alone.
 *
 * Orders that have taken money are never rewritten. Repricing a paid order
 * desyncs it from the cash actually collected; those are listed at the end so
 * the shop can refund, credit or invoice the difference deliberately.
 *
 * Dry-run by default — it prints the table and writes nothing until --apply.
 *
 *   php artisan orders:reprice-currency                 # what would change
 *   php artisan orders:reprice-currency --apply         # write it
 *   php artisan orders:reprice-currency --order=494     # one order
 *   php artisan orders:reprice-currency --channel=whatsapp
 */
class RepriceMislabelledOrders extends Command
{
    protected $signature = 'orders:reprice-currency
                            {--apply     : Write the corrections. Without this nothing is saved.}
                            {--order=    : Limit to one order id.}
                            {--channel=  : Limit to a sales channel (pos|online|whatsapp).}';

    protected $description = "Reprice orders billing another currency's numbers under their own label";

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        CurrencyPricing::forget();

        $orders = Order::with('items')
            ->whereNotIn('status', ['cancelled', 'voided', 'refunded'])
            ->when($this->option('order'), fn ($q) => $q->where('id', (int) $this->option('order')))
            ->when($this->option('channel'), fn ($q) => $q->salesChannel($this->option('channel')))
            ->orderBy('id')
            ->get();

        $rows       = [];   // corrections we can make
        $locked     = [];   // mislabelled, but money has moved
        $blocked    = [];   // mislabelled, but the hub cannot price it
        $flagged    = [];   // corrected orders carrying order-level money to eyeball

        foreach ($orders as $order) {
            $currency  = strtoupper((string) $order->currency_code);
            $prices    = [];
            $orderRows = [];

            foreach ($order->items as $item) {
                $found = $this->mislabelledPrice($item, $currency);

                if ($found === null) {
                    continue;
                }

                [$correct, $quotedIn] = $found;

                if ($correct === null) {
                    $blocked[] = [
                        $order->order_number,
                        $item->product_name,
                        "{$currency} " . number_format((float) $item->unit_price, 2),
                        "looks like a {$quotedIn} figure",
                        CurrencyPricing::hasRate($currency) ? "no {$currency} price set" : "no {$currency} rate set",
                    ];
                    continue;
                }

                $prices[$item->id] = $correct;

                $orderRows[] = [
                    $order->order_number,
                    $item->product_name,
                    "{$currency} " . number_format((float) $item->unit_price, 2),
                    "→ {$currency} " . number_format($correct, 2),
                    "was the {$quotedIn} price",
                ];
            }

            if (empty($prices)) {
                continue;
            }

            // Money already collected: report, never rewrite.
            if ($order->payment_status !== 'pending' || $order->totalPaid() > 0) {
                foreach ($orderRows as $row) {
                    $locked[] = [$row[0], $order->payment_status, $row[1], $row[2], $row[3]];
                }
                continue;
            }

            $rows = array_merge($rows, $orderRows);

            // Same currency in and out, so nothing order-level has to convert.
            $plan = OrderRepricer::plan($order, $prices, $currency, $currency);

            if ((float) $order->discount_amount > 0 || (float) $order->shipping_amount > 0) {
                $flagged[] = [
                    $order->order_number,
                    $currency . ' ' . number_format((float) $order->discount_amount, 2),
                    $currency . ' ' . number_format((float) $order->shipping_amount, 2),
                ];
            }

            if (!$apply) {
                continue;
            }

            DB::transaction(function () use ($order, $plan, $currency) {
                $before = (float) $order->total_amount;
                OrderRepricer::apply($order, $plan);

                ActivityLogService::log('order_currency_repriced', $order, [
                    'currency'   => $currency,
                    'old_total'  => $before,
                    'new_total'  => $plan['total'],
                    'reason'     => 'backfill: line prices were another currency\'s figures',
                ]);
            });
        }

        $this->report($rows, $locked, $blocked, $flagged, $apply);

        return self::SUCCESS;
    }

    /**
     * Is this line billing another currency's number?
     *
     * @return array{0: float|null, 1: string}|null  [correct price (null when the
     *         hub cannot price it), the currency the stored figure came from];
     *         null when the line is fine or its price is a human's decision.
     */
    private function mislabelledPrice(object $item, string $currency): ?array
    {
        $rows = CurrencyPricing::rowsFor($item->product_id, $item->product_variant_id);

        if ($rows->isEmpty()) {
            return null;   // nothing to compare against
        }

        $stored = round((float) $item->unit_price, 2);
        $priced = CurrencyPricing::priceIn($rows, $currency);

        if ($priced && abs($priced['regular_price'] - $stored) < 0.01) {
            return null;   // already the hub's price for this currency
        }

        // The tell: the stored figure IS a price row, just not this currency's.
        $source = $rows->first(fn ($r) => strtoupper((string) $r->currency_code) !== $currency
            && abs(round((float) $r->regular_price, 2) - $stored) < 0.01);

        if (!$source) {
            return null;   // a discount or a hand-set price — leave it to a human
        }

        return [$priced['regular_price'] ?? null, strtoupper((string) $source->currency_code)];
    }

    private function report(array $rows, array $locked, array $blocked, array $flagged, bool $apply): void
    {
        if (empty($rows) && empty($locked) && empty($blocked)) {
            $this->info('No mislabelled prices found. Every order bills its own currency.');

            return;
        }

        if (!empty($rows)) {
            $this->newLine();
            $this->info($apply ? 'Repriced:' : 'Would reprice (run with --apply to write):');
            $this->table(['Order', 'Item', 'Billed', 'Correct', 'Why'], $rows);
        }

        if (!empty($flagged)) {
            $this->newLine();
            $this->warn('Check by hand — these repriced orders also carry order-level money that may be in the old currency:');
            $this->table(['Order', 'Discount', 'Shipping'], $flagged);
        }

        if (!empty($blocked)) {
            $this->newLine();
            $this->warn('Cannot fix — the hub has no price for the order\'s currency:');
            $this->table(['Order', 'Item', 'Billed', 'Diagnosis', 'Fix'], $blocked);
        }

        if (!empty($locked)) {
            $this->newLine();
            $this->error('Money already taken — NOT rewritten. Refund, credit or re-invoice these deliberately:');
            $this->table(['Order', 'Payment', 'Item', 'Billed', 'Should be'], $locked);
        }

        if (!$apply && !empty($rows)) {
            $this->newLine();
            $this->comment('Nothing was written. Re-run with --apply.');
        }
    }
}
