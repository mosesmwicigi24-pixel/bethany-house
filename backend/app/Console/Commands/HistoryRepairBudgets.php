<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\ExpenseBudget;
use App\Models\User;
use App\Services\History\HistoryAudit;
use App\Support\ReportingCurrency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Currency plan Step 6: expense budgets set in a currency other than KES are
 * converted to shillings at the reporting rate, the original kept in the
 * budget's notes and in the audit log. A budget in a currency with no
 * reporting rate is listed and left alone — never a guessed figure.
 *
 * Dry run by default; `--apply --actor=<user id or email>` writes. Safe to
 * run again: a budget already in KES is not touched.
 *
 *   php artisan history:repair-budgets
 *   php artisan history:repair-budgets --apply --actor=finance@bethanyhouse.co.ke
 */
class HistoryRepairBudgets extends Command
{
    public const ACTION = 'history_budget_converted';

    protected $signature = 'history:repair-budgets
        {--apply : Write the conversions (default is a dry run that changes nothing)}
        {--actor= : The user the conversion is recorded against (id or email); required with --apply}';

    protected $description = 'Convert expense budgets set in a foreign currency to KES at the reporting rate, keeping the original';

    public function handle(HistoryAudit $audit): int
    {
        $apply = (bool) $this->option('apply');
        $actor = null;
        if ($apply) {
            $who   = (string) $this->option('actor');
            $actor = $who === '' ? null
                : (ctype_digit($who) ? User::query()->find((int) $who) : User::query()->where('email', $who)->first());
            if (!$actor || $actor->status !== 'active') {
                $this->error('--apply needs --actor=<user id or email> of an active staff member.');

                return self::FAILURE;
            }
        }

        $budgets = $audit->foreignBudgets();
        $this->line(($apply ? 'APPLY' : 'DRY RUN') . " — {$budgets->count()} budget(s) not in KES.");

        $converted = $skipped = 0;
        foreach ($budgets as $b) {
            $label = "Budget #{$b['budget_id']} {$b['category']} {$b['period']}: {$b['currency']} " . number_format($b['amount'], 2);
            if ($b['rate'] === null) {
                $this->warn("   {$label} — no reporting rate for {$b['currency']}; left as it is (set a rate, then run again).");
                $skipped++;
                continue;
            }
            $this->line("   {$label} → KES " . number_format($b['amount_kes'], 2) . " at {$b['rate']} KES/{$b['currency']}");
            if (!$apply) {
                continue;
            }

            DB::transaction(function () use ($b, $actor) {
                $budget = ExpenseBudget::query()->lockForUpdate()->findOrFail($b['budget_id']);
                if (strtoupper((string) $budget->currency_code) === 'KES') {
                    return;         // converted by an earlier run
                }
                $old  = ['budgeted_amount' => round((float) $budget->budgeted_amount, 2), 'currency_code' => strtoupper((string) $budget->currency_code)];
                $new  = ['budgeted_amount' => $b['amount_kes'], 'currency_code' => 'KES'];
                $note = sprintf('Converted from %s %s at %s KES/%s (history repair, currency plan Step 6, %s).',
                    $old['currency_code'], number_format($old['budgeted_amount'], 2), rtrim(rtrim(number_format($b['rate'], 6, '.', ''), '0'), '.'),
                    $old['currency_code'], now(config('app.timezone'))->toDateString());
                $budget->forceFill($new + ['notes' => trim((string) $budget->notes . "\n" . $note)])->save();

                AuditLog::create([
                    'user_id'     => $actor->id,
                    'action'      => self::ACTION,
                    'entity_type' => ExpenseBudget::class,
                    'entity_id'   => $budget->id,
                    'old_values'  => $old,
                    'new_values'  => $new + ['rate' => $b['rate']],
                ]);
            });
            $converted++;
        }

        $this->newLine();
        $this->line($apply
            ? "Converted {$converted} budget(s); {$skipped} left without a rate."
            : "Dry run: nothing written; {$skipped} would be left without a rate. Re-run with --apply --actor=<user> to write.");

        return self::SUCCESS;
    }
}
