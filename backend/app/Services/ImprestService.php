<?php

namespace App\Services;

use App\Mail\ImprestAlertMail;
use App\Models\Expense;
use App\Models\ImprestAccount;
use App\Models\ImprestCashCount;
use App\Models\ImprestTopupRequest;
use App\Models\ImprestTransaction;
use App\Models\User;
use App\Notifications\ImprestNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every shilling that enters or leaves an imprest goes through here — nowhere
 * else writes imprest_transactions or imprest_accounts.balance.
 *
 * Each movement: lock the account row (FOR UPDATE), compute in integer cents,
 * append one ledger row carrying the balance after it, update the cached
 * balance — one transaction. Two clerks recording expenses at the same moment
 * queue on the lock; neither can spend the same shilling. The database adds
 * one debit per expense and one credit per top-up (partial unique indexes),
 * so a double-pressed Save cannot deduct twice.
 *
 * Owner decisions (2026-09-22): deduct when the expense is recorded; anyone
 * who can create expenses may spend; the balance rises only when the custodian
 * confirms what arrived; low-balance alert below 20% of the float.
 */
class ImprestService
{
    // ── reading ───────────────────────────────────────────────────────────────

    /** The float an expense at this outlet is paid from: the outlet's own, else the single shared one. */
    public function activeFor(?int $outletId = null): ?ImprestAccount
    {
        $active = ImprestAccount::where('is_active', true);
        if ($outletId) {
            $own = (clone $active)->where('outlet_id', $outletId)->first();
            if ($own) {
                return $own;
            }
        }
        $shared = (clone $active)->whereNull('outlet_id')->get();
        if ($shared->count() === 1) {
            return $shared->first();
        }
        $all = $active->get();
        return $all->count() === 1 ? $all->first() : null;
    }

    public function anyActive(): bool
    {
        return ImprestAccount::where('is_active', true)->exists();
    }

    /** Imprest expenses since the last top-up the custodian confirmed — what a top-up replaces. */
    public function unreplenished(ImprestAccount $account): Collection
    {
        $since = ImprestTransaction::where('imprest_account_id', $account->id)
            ->whereIn('type', [ImprestTransaction::TOP_UP, ImprestTransaction::OPENING])
            ->max('id') ?? 0;

        return Expense::withoutViewerScope()
            ->with(['category:id,name', 'createdBy:id,first_name,last_name'])
            ->whereIn('id', ImprestTransaction::where('imprest_account_id', $account->id)
                ->where('type', ImprestTransaction::EXPENSE)->where('id', '>', $since)->pluck('expense_id'))
            ->orderBy('expense_date')
            ->get();
    }

    // ── opening ───────────────────────────────────────────────────────────────

    public function open(array $data, User $by): ImprestAccount
    {
        return DB::transaction(function () use ($data, $by) {
            $account = ImprestAccount::create([
                'name'                => $data['name'],
                'outlet_id'           => $data['outlet_id'] ?? null,
                'custodian_id'        => $data['custodian_id'],
                'currency_code'       => 'KES',
                'float_amount'        => $data['float_amount'],
                'low_balance_percent' => $data['low_balance_percent'] ?? 20,
                'opened_by'           => $by->id,
            ]);
            $opening = self::cents($data['opening_balance'] ?? 0);
            $this->append($account->fresh(), ImprestTransaction::OPENING, $opening, $by,
                'Opening balance — cash counted when the imprest was set up', lock: false);

            ActivityLogService::log('imprest_opened', $account, [
                'float' => $account->float_amount, 'opening_balance' => self::money($opening),
                'custodian_id' => $account->custodian_id,
            ], "Imprest opened: {$account->name} (float KES {$account->float_amount})", $by);

            return $account->fresh();
        });
    }

    // ── spending ──────────────────────────────────────────────────────────────

    /**
     * Take an expense off the imprest. Called inside the expense's own
     * transaction, so the expense and its debit exist together or not at all.
     */
    public function debitForExpense(Expense $expense, ImprestAccount $account, User $by): ImprestTransaction
    {
        $amount = self::cents($expense->amount_kes);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'An imprest expense needs an amount.']);
        }

        $tx = $this->append($account, ImprestTransaction::EXPENSE, -$amount, $by,
            "{$expense->reference_number} — {$expense->title}", expenseId: $expense->id, refuseIfShort: true);

        $expense->forceFill(['imprest_account_id' => $account->id])->saveQuietly();

        DB::afterCommit(fn () => $this->checkLowBalance($account->fresh()));

        return $tx;
    }

    /** What an expense has taken from the imprest in total (debit + adjustments − returns). */
    public function takenBy(Expense $expense): int
    {
        return -(int) round(ImprestTransaction::where('expense_id', $expense->id)->sum(DB::raw('amount * 100')));
    }

    /** A rejected/cancelled imprest expense: the cash is gone until someone says otherwise. */
    public function flagForResolution(Expense $expense, User $by, string $why): void
    {
        if (!$expense->isImprest() || $expense->imprest_resolution !== null) {
            return;
        }
        $expense->forceFill(['imprest_resolution' => 'pending'])->saveQuietly();

        ActivityLogService::log('imprest_expense_unresolved', $expense, ['why' => $why, 'amount' => $expense->amount_kes],
            "Imprest expense {$expense->reference_number} {$why} — was the cash returned to the box?", $by);

        DB::afterCommit(fn () => $this->tellSuperAdmins(
            "Imprest expense {$why}: was the cash returned?",
            "{$expense->reference_number} — {$expense->title} (KES {$expense->amount_kes}) left the imprest and has been {$why}. Mark it as cash returned or written off.",
            "/expenses/{$expense->id}",
            mail: false,
        ));
    }

    /** Cash back in the box (credited) — or written off (recorded, not credited). */
    public function resolve(Expense $expense, string $resolution, User $by, ?string $note = null): void
    {
        if ($expense->imprest_resolution !== 'pending') {
            throw ValidationException::withMessages(['resolution' => 'This imprest expense has nothing waiting to be resolved.']);
        }

        DB::transaction(function () use ($expense, $resolution, $by, $note) {
            if ($resolution === 'returned') {
                $taken = $this->takenBy($expense);
                if ($taken > 0) {
                    $this->append(ImprestAccount::findOrFail($expense->imprest_account_id), ImprestTransaction::CASH_RETURNED,
                        $taken, $by, trim("Cash returned for {$expense->reference_number}. {$note}"), expenseId: $expense->id);
                }
            }
            $expense->forceFill([
                'imprest_resolution'  => $resolution,
                'imprest_resolved_by' => $by->id,
                'imprest_resolved_at' => now(),
            ])->saveQuietly();

            ActivityLogService::log($resolution === 'returned' ? 'imprest_cash_returned' : 'imprest_written_off', $expense,
                ['amount' => $expense->amount_kes, 'note' => $note],
                "Imprest expense {$expense->reference_number}: " . ($resolution === 'returned' ? 'cash returned to the box' : 'written off'), $by);
        });
    }

    // ── topping up ────────────────────────────────────────────────────────────

    public function requestTopup(ImprestAccount $account, User $by, ?string $amount, ?string $reason): ImprestTopupRequest
    {
        if (ImprestTopupRequest::where('imprest_account_id', $account->id)
            ->whereIn('status', [ImprestTopupRequest::PENDING, ImprestTopupRequest::SENT])->exists()) {
            throw ValidationException::withMessages(['amount' => 'A top-up is already on its way or waiting for a decision.']);
        }

        $suggested = max(0, self::cents($account->float_amount) - self::cents($account->balance));
        $asked = $amount !== null ? self::cents($amount) : $suggested;
        if ($asked <= 0) {
            throw ValidationException::withMessages(['amount' => 'The imprest is already at its float.']);
        }

        $req = ImprestTopupRequest::create([
            'uuid'               => (string) Str::uuid(),
            'imprest_account_id' => $account->id,
            'status'             => ImprestTopupRequest::PENDING,
            'requested_by'       => $by->id,
            'requested_amount'   => self::money($asked),
            'balance_at_request' => $account->balance,
            'reason'             => $reason,
        ]);

        ActivityLogService::log('imprest_topup_requested', $req, [
            'amount' => $req->requested_amount, 'balance' => $account->balance, 'reason' => $reason,
        ], "Imprest top-up requested: KES {$req->requested_amount}", $by);

        $who = trim("{$by->first_name} {$by->last_name}") ?: $by->email;
        $this->tellSuperAdmins(
            "Imprest top-up requested — KES {$req->requested_amount}",
            "{$who} asked for KES {$req->requested_amount}. The imprest has KES {$account->balance} of its KES {$account->float_amount} float.",
            '/expenses/imprest', mail: true,
        );

        return $req;
    }

    /** The super admin says the money went (to an existing request, or a direct load). */
    public function markSent(ImprestAccount $account, ?ImprestTopupRequest $req, array $data, User $by): ImprestTopupRequest
    {
        return DB::transaction(function () use ($account, $req, $data, $by) {
            if ($req && $req->status !== ImprestTopupRequest::PENDING) {
                throw ValidationException::withMessages(['status' => "This request is already {$req->status}."]);
            }
            $req ??= ImprestTopupRequest::create([
                'uuid'               => (string) Str::uuid(),
                'imprest_account_id' => $account->id,
                'status'             => ImprestTopupRequest::PENDING,
                'balance_at_request' => $account->balance,
                'reason'             => 'Loaded directly by the super admin',
            ]);

            $req->forceFill([
                'status'         => ImprestTopupRequest::SENT,
                'sent_by'        => $by->id,
                'sent_amount'    => self::money(self::cents($data['amount'])),
                'sent_method'    => $data['method'],
                'sent_reference' => $data['reference'] ?? null,
                'sent_at'        => $data['sent_at'] ?? now(),
                'sent_note'      => $data['note'] ?? null,
            ])->save();

            ActivityLogService::log('imprest_topup_sent', $req, [
                'amount' => $req->sent_amount, 'method' => $req->sent_method, 'reference' => $req->sent_reference,
            ], "Imprest top-up sent: KES {$req->sent_amount} by {$req->sent_method}", $by);

            DB::afterCommit(function () use ($account, $req) {
                $account->custodian?->notify(new ImprestNotification(
                    "Imprest top-up on its way — KES {$req->sent_amount}",
                    "Sent by {$req->sent_method}" . ($req->sent_reference ? " (ref {$req->sent_reference})" : '')
                        . '. Confirm when it reaches you — the balance goes up only then.',
                    '/expenses/imprest',
                ));
            });

            return $req->fresh();
        });
    }

    /** The custodian confirms what actually arrived. This is when the balance goes up. */
    public function confirmReceived(ImprestTopupRequest $req, string $amount, User $by, ?string $note = null): ImprestTopupRequest
    {
        $account = ImprestAccount::findOrFail($req->imprest_account_id);
        if ((int) $account->custodian_id !== (int) $by->id) {
            throw ValidationException::withMessages(['custodian' => 'Only the imprest custodian can confirm what arrived.']);
        }
        if ($req->status !== ImprestTopupRequest::SENT) {
            throw ValidationException::withMessages(['status' => "This top-up is {$req->status}, not on its way."]);
        }
        $got = self::cents($amount);
        if ($got <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount that arrived.']);
        }

        return DB::transaction(function () use ($req, $account, $got, $by, $note) {
            $this->append($account, ImprestTransaction::TOP_UP, $got, $by,
                trim("Top-up received ({$req->sent_method}" . ($req->sent_reference ? " {$req->sent_reference}" : '') . "). {$note}"),
                topupRequestId: $req->id);

            $req->forceFill([
                'status'          => ImprestTopupRequest::RECEIVED,
                'received_by'     => $by->id,
                'received_amount' => self::money($got),
                'received_at'     => now(),
                'receipt_note'    => $note,
            ])->save();

            $account->refresh();
            if (!$account->isLow()) {
                $account->forceFill(['low_balance_notified_at' => null])->save();   // alert again next time it runs low
            }

            $variance = self::cents($req->sent_amount) - $got;
            ActivityLogService::log('imprest_topup_received', $req, [
                'sent' => $req->sent_amount, 'received' => self::money($got), 'difference' => self::money($variance),
            ], "Imprest top-up received: KES " . self::money($got) . ($variance !== 0 ? ' (KES ' . self::money($variance) . ' short of what was sent)' : ''), $by);

            DB::afterCommit(fn () => $this->tellSuperAdmins(
                $variance === 0 ? 'Imprest top-up received' : 'Imprest top-up received — amount differs',
                'The custodian confirmed KES ' . self::money($got) . " (sent KES {$req->sent_amount}). Imprest balance: KES {$account->balance}.",
                '/expenses/imprest', mail: $variance !== 0,
            ));

            return $req->fresh();
        });
    }

    public function decline(ImprestTopupRequest $req, User $by, string $reason): void
    {
        if ($req->status !== ImprestTopupRequest::PENDING) {
            throw ValidationException::withMessages(['status' => "This request is already {$req->status}."]);
        }
        $req->forceFill(['status' => ImprestTopupRequest::DECLINED, 'declined_by' => $by->id, 'declined_at' => now(), 'decline_reason' => $reason])->save();
        ActivityLogService::log('imprest_topup_declined', $req, ['reason' => $reason], 'Imprest top-up declined', $by);
        $req->requester?->notify(new ImprestNotification('Imprest top-up declined', $reason, '/expenses/imprest'));
    }

    public function cancel(ImprestTopupRequest $req, User $by): void
    {
        if ($req->status !== ImprestTopupRequest::PENDING || (int) $req->requested_by !== (int) $by->id) {
            throw ValidationException::withMessages(['status' => 'Only your own request, before it is sent, can be cancelled.']);
        }
        $req->forceFill(['status' => ImprestTopupRequest::CANCELLED])->save();
        ActivityLogService::log('imprest_topup_cancelled', $req, [], 'Imprest top-up request cancelled', $by);
    }

    // ── counting ──────────────────────────────────────────────────────────────

    public function recordCount(ImprestAccount $account, string $counted, User $by, ?string $note): ImprestCashCount
    {
        $book = self::cents($account->fresh()->balance);
        $c = self::cents($counted);
        $count = ImprestCashCount::create([
            'imprest_account_id' => $account->id,
            'counted_amount'     => self::money($c),
            'book_balance'       => self::money($book),
            'variance'           => self::money($c - $book),
            'note'               => $note,
            'counted_by'         => $by->id,
            'status'             => $c === $book ? ImprestCashCount::APPROVED : ImprestCashCount::PENDING,
            'decided_at'         => $c === $book ? now() : null,
        ]);

        ActivityLogService::log('imprest_cash_counted', $count, [
            'counted' => $count->counted_amount, 'book' => $count->book_balance, 'variance' => $count->variance,
        ], "Imprest cash counted: KES {$count->counted_amount} against KES {$count->book_balance} on the books", $by);

        if ($c !== $book) {
            $this->tellSuperAdmins("Imprest cash count differs by KES " . self::money($c - $book),
                "Counted KES {$count->counted_amount}; the books say KES {$count->book_balance}. Approve the adjustment or reject it.",
                '/expenses/imprest', mail: true);
        }
        return $count;
    }

    public function decideCount(ImprestCashCount $count, bool $approve, User $by, ?string $note): void
    {
        if ($count->status !== ImprestCashCount::PENDING) {
            throw ValidationException::withMessages(['status' => "This count is already {$count->status}."]);
        }
        DB::transaction(function () use ($count, $approve, $by, $note) {
            if ($approve) {
                $this->append(ImprestAccount::findOrFail($count->imprest_account_id), ImprestTransaction::COUNT_ADJUSTMENT,
                    self::cents($count->variance), $by,
                    trim("Cash count adjustment (counted KES {$count->counted_amount}, books KES {$count->book_balance}). {$note}"),
                    cashCountId: $count->id);
            }
            $count->forceFill([
                'status'        => $approve ? ImprestCashCount::APPROVED : ImprestCashCount::REJECTED,
                'decided_by'    => $by->id,
                'decided_at'    => now(),
                'decision_note' => $note,
            ])->save();
            ActivityLogService::log($approve ? 'imprest_count_approved' : 'imprest_count_rejected', $count,
                ['variance' => $count->variance, 'note' => $note], 'Imprest cash count ' . ($approve ? 'adjustment approved' : 'rejected'), $by);
        });
    }

    // ── internals ─────────────────────────────────────────────────────────────

    /**
     * The one INSERT into the ledger. Locks the account, refuses to overdraw
     * when asked, writes the row and the cached balance together.
     */
    private function append(
        ImprestAccount $account, string $type, int $cents, User $by, ?string $note,
        ?int $expenseId = null, ?int $topupRequestId = null, ?int $cashCountId = null,
        bool $refuseIfShort = false, bool $lock = true,
    ): ImprestTransaction {
        return DB::transaction(function () use ($account, $type, $cents, $by, $note, $expenseId, $topupRequestId, $cashCountId, $refuseIfShort, $lock) {
            $locked = $lock ? ImprestAccount::whereKey($account->id)->lockForUpdate()->firstOrFail() : $account;
            if (!$locked->is_active) {
                throw ValidationException::withMessages(['imprest' => 'This imprest is closed.']);
            }
            $after = self::cents($locked->balance) + $cents;
            if ($refuseIfShort && $after < 0) {
                throw ValidationException::withMessages([
                    'use_imprest' => 'The imprest has only KES ' . self::money(self::cents($locked->balance))
                        . ' left. Ask for a top-up, or pay this another way.',
                ]);
            }

            $tx = ImprestTransaction::create([
                'imprest_account_id' => $locked->id,
                'type'               => $type,
                'amount'             => self::money($cents),
                'balance_after'      => self::money($after),
                'expense_id'         => $expenseId,
                'topup_request_id'   => $topupRequestId,
                'cash_count_id'      => $cashCountId,
                'note'               => $note ? mb_substr($note, 0, 1000) : null,
                'created_by'         => $by->id,
                'created_at'         => now(),
            ]);
            $locked->forceFill(['balance' => self::money($after)])->save();
            $account->setRawAttributes($locked->getAttributes(), true);

            return $tx;
        });
    }

    /** Alert once when the balance falls below the threshold; re-armed by a received top-up. */
    public function checkLowBalance(ImprestAccount $account): void
    {
        if (!$account->isLow() || $account->low_balance_notified_at) {
            return;
        }
        $account->forceFill(['low_balance_notified_at' => now()])->save();

        $title = "Imprest low — KES {$account->balance} left";
        $body  = "{$account->name} is below " . $account->low_balance_percent . "% of its KES {$account->float_amount} float. Ask for a top-up.";
        try {
            $account->custodian?->notify(new ImprestNotification($title, $body, '/expenses/imprest'));
        } catch (\Throwable $e) {
            Log::warning('imprest low-balance notification failed', ['error' => $e->getMessage()]);
        }
        $this->tellSuperAdmins($title, $body, '/expenses/imprest', mail: false);
    }

    private function tellSuperAdmins(string $title, string $body, string $url, bool $mail): void
    {
        try {
            User::role('super_admin', 'sanctum')->where('status', 'active')->get()
                ->each(fn (User $u) => $u->notify(new ImprestNotification($title, $body, $url)));
        } catch (\Throwable $e) {
            Log::warning('imprest notification failed', ['error' => $e->getMessage()]);
        }
        $to = (string) config('audit.owner_email');
        if ($mail && $to !== '') {
            try {
                Mail::to($to)->queue(new ImprestAlertMail($title, $body, rtrim((string) config('audit.console_url'), '/') . $url));
            } catch (\Throwable $e) {
                Log::warning('imprest email failed', ['error' => $e->getMessage()]);
            }
        }
    }

    public static function cents($value): int
    {
        return (int) round(((float) $value) * 100);
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
