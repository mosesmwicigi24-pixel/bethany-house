<?php

namespace App\Services\Approvals;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What the engine needs to know about one kind of record (Phase 3B).
 *
 * The engine owns the rules — bands, order, signers, maker ≠ checker,
 * splitting, expiry, versions. A handler owns the record: what it is worth,
 * who made it, what an approval is bound to, and what happens to it when the
 * last band signs, when it is rejected, and when it expires.
 *
 * onApproved / onRejected run INSIDE the engine's transaction: a handler that
 * cannot complete the domain action (stock has gone, the payment was voided
 * meanwhile) throws, and the signature is not recorded either.
 */
abstract class ApprovalHandler
{
    /** The event key, e.g. 'purchase_order' (also the approval_thresholds.event). */
    abstract public function event(): string;

    /** @return class-string<Model> */
    abstract public function modelClass(): string;

    /** The MakerChecker action key, for its message and audit entry. */
    abstract public function makerCheckerAction(): string;

    /** Load the record, outside any viewer scope (the engine checks who may act). */
    public function find(int $id): ?Model
    {
        $class = $this->modelClass();
        $query = method_exists($class, 'withoutViewerScope') ? $class::withoutViewerScope() : $class::query();

        return $query->find($id);
    }

    /**
     * What the record is worth: [amount, currency] — amount null when it
     * cannot be valued (the engine then requires every band). Return null for
     * an event that has no value band at all (a stock transfer).
     *
     * @return array{0: float|null, 1: string}|null
     */
    abstract public function amount(Model $record, ?array $payload = null): ?array;

    /** The anti-splitting key ("supplier:12"), or null when there is none. */
    abstract public function counterparty(Model $record, ?array $payload = null): ?string;

    /** Every user id that originated the record (created, submitted, requested). */
    abstract public function makerIds(Model $record): array;

    /** The maker to record when a pending record is adopted without a submission. */
    public function adoptedMaker(Model $record): ?int
    {
        $ids = array_values(array_filter($this->makerIds($record)));

        return $ids === [] ? null : (int) end($ids);
    }

    /**
     * The fields an approval is bound to. If any of them changes after
     * submission the request is stale and cannot be signed.
     */
    abstract public function fingerprint(Model $record, ?array $payload = null): array;

    /** Is the record in the state that waits for approval? */
    abstract public function isAwaitingApproval(Model $record): bool;

    /** Short, human description for the inbox: title, reference, link, detail lines. */
    abstract public function summary(Model $record, ?array $payload = null): array;

    /** The last band signed: carry out the domain action. */
    abstract public function onApproved(ApprovalRequest $request, Model $record, User $finalSigner): void;

    /** A signer rejected it: put the record back for its maker. */
    abstract public function onRejected(ApprovalRequest $request, Model $record, User $signer, string $reason): void;

    /** 72 hours passed with no decision: back to the maker, never approved. */
    abstract public function onExpired(ApprovalRequest $request, Model $record): void;

    /**
     * Put a rejected / expired record back into the awaiting state so it can
     * be resubmitted as a new version. Throw a ValidationException when the
     * record cannot come back (e.g. it has since been cancelled).
     */
    abstract public function reopen(Model $record, User $maker): void;

    /**
     * Can a signer send this kind of record back to its maker for changes
     * (ApprovalEngine::requestChanges)? Off unless the handler says how the
     * record goes back — onChangesRequested below.
     */
    public function supportsChangeRequests(): bool
    {
        return false;
    }

    /**
     * A signer asked for changes: put the record back with its maker, who
     * edits it and submits it again (a new version). Runs INSIDE the engine's
     * transaction, like onRejected.
     */
    public function onChangesRequested(ApprovalRequest $request, Model $record, User $by, string $note): void
    {
        throw new \LogicException(static::class . ' does not support change requests.');
    }

    /**
     * Where the record sits, for a signer bounded to outlets (Phase 4A data
     * scope): [the capability that governs reading it, the outlet ids it
     * belongs to]. A record at several outlets (a transfer) is in scope when
     * ANY of them is the signer's; a null id is head office, which a bounded
     * signer does not reach. null = not an outlet-bound record.
     *
     * @return array{0: string, 1: list<int|null>}|null
     */
    public function outletScope(Model $record): ?array
    {
        return null;
    }
}
