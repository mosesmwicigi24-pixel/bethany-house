<?php

namespace App\Console\Commands;

use App\Services\Approvals\ProposalService;
use Illuminate\Console\Command;

/**
 * php artisan proposals:apply-due
 *
 * Writes every signed, effective-dated change (tax rate, reporting FX, payment
 * settlement) whose effective_from has come (Phase 3C). Never earlier than its
 * date — a change is not retroactive — and nothing unsigned is ever applied.
 * A change that cannot be written is reported and stays scheduled.
 * Scheduled every minute.
 */
class ApplyDueProposals extends Command
{
    protected $signature   = 'proposals:apply-due';
    protected $description = 'Apply signed, effective-dated changes whose effective date has come.';

    public function handle(ProposalService $proposals): int
    {
        $n = $proposals->applyDue();
        $this->info("{$n} scheduled change(s) took effect.");

        return self::SUCCESS;
    }
}
