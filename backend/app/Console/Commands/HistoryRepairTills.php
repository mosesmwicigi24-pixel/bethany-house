<?php

namespace App\Console\Commands;

use App\Models\CashRegister;
use App\Models\CashRegisterBalance;
use App\Models\TillCorrection;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\History\HistoryAudit;
use App\Services\Tills\TillDrawer;
use App\Services\Tills\TillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Currency plan Step 6: tills that took foreign cash as if it were their own.
 *
 * Before Step 2 a USD 40 cash sale on a KES till raised the till's expected
 * shillings by 40. Step 2 stamped every ledger row with its currency and
 * rebuilt the OPEN tills' foreign rows; this command does the history:
 *
 *   • a balance row per foreign currency the till's ledger moved, built from
 *     the ledger (expected = sales + cash in − refunds − voids − paid-outs),
 *     never counted (actual_cash NULL) — history was not counted per currency;
 *   • a till correction on the home currency restating the expected cash from
 *     the home-currency ledger alone, with the variance that follows, the
 *     reason naming the foreign amounts removed;
 *   • for a till not yet finalized, the register's own expected cash (and the
 *     home balance row) is put right in place; a finalized till's money
 *     fields are locked by the database and the correction IS its restatement.
 *
 * Dry run by default. `--apply --actor=<user id or email>` writes, in one
 * transaction per till, and can be run again safely: a row or correction
 * already there is never written twice. Every write is logged.
 *
 *   php artisan history:repair-tills
 *   php artisan history:repair-tills --apply --actor=finance@bethanyhouse.co.ke
 */
class HistoryRepairTills extends Command
{
    public const REASON_PREFIX = 'History repair (currency plan Step 6)';

    protected $signature = 'history:repair-tills
        {--apply : Write the repairs (default is a dry run that changes nothing)}
        {--actor= : The user the corrections are opened by (id or email); required with --apply}
        {--register= : Only this cash register id}';

    protected $description = 'Rebuild per-currency till balances from the ledger and restate home-currency expected cash through corrections';

    public function handle(HistoryAudit $audit, TillService $tills, TillDrawer $drawer): int
    {
        $apply = (bool) $this->option('apply');
        $actor = null;
        if ($apply) {
            $actor = $this->resolveActor((string) $this->option('actor'));
            if (!$actor) {
                $this->error('--apply needs --actor=<user id or email> of an active staff member; the corrections are opened in their name.');

                return self::FAILURE;
            }
        }

        $registerId = $this->option('register') ? (int) $this->option('register') : null;
        $affected   = $audit->tills($registerId);

        $this->line(($apply ? 'APPLY' : 'DRY RUN') . " — {$affected->count()} till(s) with foreign cash in their ledger.");
        if ($affected->isEmpty()) {
            return self::SUCCESS;
        }

        $rowsWritten = $corrections = $inPlace = 0;

        foreach ($affected as $t) {
            $register = CashRegister::query()->findOrFail($t['register_id']);
            $home     = $t['home'];
            $foreign  = implode(', ', array_map(fn ($c) => "{$c['currency']} {$this->money($c['net'])}", $t['currencies']));

            $this->newLine();
            $this->info("Till #{$t['register_id']} {$t['register_name']} ({$t['status']}" . ($t['finalized'] ? ', finalized' : '') . ") — home {$home}");
            foreach ($t['currencies'] as $c) {
                $this->line(sprintf('   %s: sales %s, cash in %s, refunds %s, voids %s, paid-outs %s → net %s %s',
                    $c['currency'], $this->money($c['cash_sales']), $this->money($c['cash_in']), $this->money($c['refunds']),
                    $this->money($c['voids']), $this->money($c['paid_outs']), $this->money($c['net']),
                    $c['has_balance_row'] ? '(row exists — kept)' : '(row to create, uncounted)'));
            }

            $needsCorrection = $t['stored_expected'] !== null && abs($t['difference']) >= 0.005;
            $already         = TillCorrection::where('cash_register_id', $register->id)->where('currency_code', $home)
                ->where('reason', 'like', self::REASON_PREFIX . '%')->exists();

            if ($needsCorrection) {
                $this->line(sprintf('   %s expected: stored %s → ledger %s (difference %s); variance %s → %s%s',
                    $home, $this->money($t['stored_expected']), $this->money($t['ledger_expected']), $this->money($t['difference']),
                    $t['stored_variance'] === null ? 'n/a' : $this->money($t['stored_variance']),
                    $t['restated_variance'] === null ? 'n/a' : $this->money($t['restated_variance']),
                    $already ? ' — correction already recorded, kept' : ''));
            } else {
                $this->line("   {$home} expected matches the ledger; no correction needed.");
            }

            if (!$apply) {
                continue;
            }

            DB::transaction(function () use ($t, $register, $home, $foreign, $needsCorrection, $already, $actor, $tills, $drawer, &$rowsWritten, &$corrections, &$inPlace) {
                foreach ($t['currencies'] as $c) {
                    if ($c['has_balance_row']) {
                        continue;
                    }
                    CashRegisterBalance::create([
                        'cash_register_id'  => $register->id,
                        'currency_code'     => $c['currency'],
                        'is_home'           => false,
                        'opening_balance'   => 0,
                        'expected_cash'     => max(0, $c['net']),
                        'actual_cash'       => null,
                        'total_sales'       => $c['cash_sales'],
                        'total_cash_sales'  => $c['cash_sales'],
                        'total_refunds'     => $c['refunds'],
                        'transaction_count' => $c['sale_count'],
                    ]);
                    $rowsWritten++;
                }

                if ($needsCorrection && !$already) {
                    $actual = $t['actual_cash'];
                    TillCorrection::create([
                        'cash_register_id'        => $register->id,
                        'currency_code'           => $home,
                        'opened_by'               => $actor->id,
                        'reason'                  => self::REASON_PREFIX . ": {$foreign} in cash was added to the {$home} expected as if it were {$home}. "
                            . "Expected {$home} restated from the {$home} ledger alone: {$this->money($t['stored_expected'])} → {$this->money($t['ledger_expected'])}.",
                        'original_actual_cash'    => $actual,
                        'original_expected_cash'  => $t['stored_expected'],
                        'original_variance'       => $t['stored_variance'],
                        'corrected_actual_cash'   => $actual,
                        'corrected_expected_cash' => $t['ledger_expected'],
                        'corrected_variance'      => $t['restated_variance'],
                        'status'                  => 'recorded',
                    ]);
                    $corrections++;

                    // Not finalized: the register's own figures are still open and
                    // are put right in place (the lock only guards finalized tills).
                    if ($register->finalized_at === null) {
                        $update = ['expected_cash' => $t['ledger_expected']];
                        if ($actual !== null) {
                            $update['expected_cash_at_count'] = $t['ledger_expected'];
                            $update['variance']               = $t['restated_variance'];
                            $update['variance_class']         = TillService::classify($t['restated_variance']);
                        }
                        $register->forceFill($update)->save();
                        $drawer->balanceFor($register, $home)->forceFill($update)->save();
                        $inPlace++;
                    }
                }

                ActivityLogService::log('history_till_repaired', $register, [
                    'register_id'        => $register->id,
                    'home'               => $home,
                    'foreign'            => $t['currencies'],
                    'stored_expected'    => $t['stored_expected'],
                    'ledger_expected'    => $t['ledger_expected'],
                    'correction_written' => $needsCorrection && !$already,
                    'applied_in_place'   => $register->finalized_at === null && $needsCorrection && !$already,
                ], "History repair: till #{$register->id} per-currency balances rebuilt from its ledger", $actor);
            });
        }

        $this->newLine();
        $this->line($apply
            ? "Written: {$rowsWritten} balance row(s), {$corrections} correction(s), {$inPlace} till(s) put right in place."
            : 'Dry run: nothing written. Re-run with --apply --actor=<user> to write.');

        return self::SUCCESS;
    }

    private function resolveActor(string $who): ?User
    {
        if ($who === '') {
            return null;
        }
        $user = ctype_digit($who) ? User::query()->find((int) $who) : User::query()->where('email', $who)->first();

        return $user && $user->status === 'active' ? $user : null;
    }

    private function money(float|int|null $v): string
    {
        return $v === null ? 'n/a' : number_format((float) $v, 2);
    }
}
