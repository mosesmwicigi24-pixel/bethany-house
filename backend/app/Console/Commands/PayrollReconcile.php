<?php

namespace App\Console\Commands;

use App\Models\PayrollRun;
use App\Services\People\Payroll;
use Illuminate\Console\Command;

/**
 * People PR 6: prove every payroll run ties. Read-only; safe on production.
 *
 *   php artisan payroll:reconcile            # every run
 *   php artisan payroll:reconcile --run=12   # one run
 *
 * For each run: lines sum to the run; each line's net = gross − deductions,
 * employer cost = gross + employer contributions, payslip items sum to net;
 * an approved run's expense equals its employer cost. Exit 1 on any break.
 * Nothing financial is printed beyond the run reference and a count — the
 * figures stay in the Hub.
 */
class PayrollReconcile extends Command
{
    protected $signature = 'payroll:reconcile {--run= : Only this run id} {--show : Print the breaks with their figures (local use)}';

    protected $description = 'Prove payroll runs tie: lines to run, deductions to net, run to its posted expense';

    public function handle(): int
    {
        $runs = PayrollRun::with(['lines', 'expense'])->when($this->option('run'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get();
        $ok = true;
        foreach ($runs as $run) {
            $breaks = Payroll::reconcile($run);
            $this->line(sprintf('%-16s %-9s %3d line(s)  %s', $run->reference, $run->status, $run->lines->count(), $breaks ? count($breaks) . ' BREAK(S)' : 'TIES'));
            if ($breaks && $this->option('show')) foreach ($breaks as $b) $this->line("   - {$b}");
            $ok = $ok && !$breaks;
        }
        if ($runs->isEmpty()) $this->line('No payroll runs.');
        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
