<?php

namespace App\Console\Commands;

use App\Models\InterestCart;
use Illuminate\Console\Command;

/**
 * php artisan interest-carts:sweep-abandoned {--days=14}
 *
 * The §7c abandonment sweep: live carts (active_cart / checkout_started)
 * untouched for N days are marked `abandoned`. Marked, never deleted —
 * the row IS the interest signal, and the customer keeps their token (it
 * only rotates when an order completes), so a returning customer's next
 * cart sync revives the same row (see InterestCart::statusWouldRegress).
 *
 * Converted carts are excluded by the WHERE, and the model guard makes
 * regression impossible even if this query ever drifts — belt and braces.
 *
 * N=14 is a working default, not a studied policy: long enough that a
 * "pay on Sunday after service" cart is still live the following week,
 * short enough that the ledger's active view stays honest. Change with
 * --days when the business decides otherwise.
 */
class SweepAbandonedInterestCarts extends Command
{
    protected $signature   = 'interest-carts:sweep-abandoned {--days=14 : Days without activity before a live cart counts as abandoned}';
    protected $description = 'Mark live interest carts untouched for N days as abandoned (kept, never deleted)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $swept = InterestCart::whereIn('status', [InterestCart::STATUS_ACTIVE, InterestCart::STATUS_CHECKOUT])
            ->where('updated_at', '<', now()->subDays($days))
            ->update(['status' => 'abandoned']);

        $this->info("Swept {$swept} cart(s) to abandoned (inactive > {$days} days).");

        return self::SUCCESS;
    }
}
