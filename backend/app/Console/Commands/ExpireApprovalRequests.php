<?php

namespace App\Console\Commands;

use App\Services\Approvals\ApprovalEngine;
use Illuminate\Console\Command;

/**
 * php artisan approvals:expire
 *
 * Pending approval requests older than 72 hours go back to their maker
 * (Phase 3B, plan §5.1). Nothing is ever approved by time passing: an expired
 * request returns the record to its maker's hands (a PO to draft, an expense
 * to draft, an adjustment or transfer to "returned", a top-up to "expired"),
 * and the maker resubmits it as a new version. Scheduled hourly.
 */
class ExpireApprovalRequests extends Command
{
    protected $signature   = 'approvals:expire';
    protected $description = 'Return approval requests pending more than 72 hours to their makers (never auto-approve).';

    public function handle(ApprovalEngine $engine): int
    {
        $n = $engine->expireDue();
        $this->info("{$n} approval request(s) expired and returned to their makers.");

        return self::SUCCESS;
    }
}
