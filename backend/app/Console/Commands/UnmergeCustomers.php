<?php

namespace App\Console\Commands;

use App\Services\CustomerMergeService;
use Illuminate\Console\Command;

/** Reverse one customer merge, by the id of its 'customer_merged' audit entry. */
class UnmergeCustomers extends Command
{
    protected $signature = 'customers:unmerge {entry : the customer_merged audit entry id} {--causer= : the user id the audit trail records}';

    protected $description = 'Reverse one customer merge from its audit entry.';

    public function handle(CustomerMergeService $merges): int
    {
        try {
            $merges->unmerge((int) $this->argument('entry'), $this->option('causer') ? (int) $this->option('causer') : null);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->info('Reversed.');

        return self::SUCCESS;
    }
}
