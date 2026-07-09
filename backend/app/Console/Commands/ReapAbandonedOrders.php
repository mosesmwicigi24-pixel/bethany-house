<?php

namespace App\Console\Commands;

use App\Services\AbandonedOrderReaper;
use Illuminate\Console\Command;

/**
 * Cancels abandoned unpaid POS pending orders and restores the stock they
 * reserved off the shelf — keeping inventory self-healing. Scheduled hourly;
 * only orders older than --hours (default 24) with no money are touched.
 */
class ReapAbandonedOrders extends Command
{
    protected $signature = 'pos:reap-abandoned-orders {--hours=24 : Minimum age in hours before an unpaid pending order is reaped}';

    protected $description = 'Cancel abandoned unpaid POS pending orders and restore their reserved stock';

    public function handle(): int
    {
        $hours  = (int) $this->option('hours');
        $result = AbandonedOrderReaper::reap($hours);

        $this->info("Reaped {$result['cancelled']} abandoned order(s); restored {$result['restored']} unit(s) to stock.");

        return self::SUCCESS;
    }
}
