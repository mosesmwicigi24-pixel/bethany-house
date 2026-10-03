<?php

namespace App\Services\Approvals;

use App\Models\ApprovalRequest;
use App\Models\ApprovalSignature;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Support\MakerChecker;
use App\Support\ReportingCurrency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The approval engine (Role Hardening Plan §5.1, §16; Phase 3B).
 *
 * One place decides who must sign what, in which order:
 *
 *   - Amounts are stated in KES at currencies.reporting_rate_to_kes. A value
 *     that cannot be stated (no rate, no product cost) needs EVERY band —
 *     never a guess.
 *   - Bands are cumulative and signed in order; band k+1 is needed when the
 *     amount exceeds band k's ceiling. Each band is signed by a different
 *     person.
 *   - Anti-splitting: the band is judged on the rolling 24-hour total of the
 *     same maker's submissions to the same counterparty.
 *   - The maker never signs (App\Support\MakerChecker, on user id — super
 *     admin included). When nobody but the maker (and anyone who already
 *     signed) holds a band, the band escalates to the next one; past the top
 *     band of the set, to the super admin.
 *   - A super admin may sign a lower band in place of its holder only when no
 *     super-admin band follows — otherwise he would use up the one signature
 *     the top band needs from him.
 *   - A pending request expires after 72 hours and goes back to the maker. It
 *     is never approved by time passing.
 *   - A rejected or expired record comes back only as a new version linked to
 *     the old one. An approval is bound to the record id, the version and a
 *     fingerprint of the record: a mismatched id or version is a 422, and a
 *     record changed since submission cannot be signed.
 *   - Every submission, signature, rejection, expiry and cancellation is
 *     written to the audit trail.
 */
final class ApprovalEngine
{
    public const SUPER_PERMISSION = 'approvals.super_sign';

    /** @var array<string, ApprovalHandler> */
    private array $handlers = [];

    public function __construct(private ThresholdRepository $thresholds)
    {
        foreach ([
            Handlers\PurchaseOrderHandler::class,
            Handlers\StockAdjustmentHandler::class,
            Handlers\StockTransferHandler::class,
            Handlers\ExpenseHandler::class,
            Handlers\ImprestTopupHandler::class,
            Handlers\PaymentVoidHandler::class,
            Handlers\PaymentReassignHandler::class,
            // Phase 4B part 2: till voids and refunds.
            Handlers\PosVoidHandler::class,
            Handlers\PosRefundHandler::class,
            // Phase 3C: proposals — a guarded value changes only once signed.
            Handlers\SellingPriceChangeHandler::class,
            Handlers\ProductCostChangeHandler::class,
            Handlers\SupplierCostChangeHandler::class,
            Handlers\TaxRateChangeHandler::class,
            Handlers\ReportingFxChangeHandler::class,
            Handlers\PaymentSettlementChangeHandler::class,
            Handlers\CustomerPricingFxChangeHandler::class,
            Handlers\CustomerCreditHandler::class,
        ] as $class) {
            $handler = app($class);
            $this->handlers[$handler->event()] = $handler;
        }
    }

    public function handler(string $event): ApprovalHandler
    {
        return $this->handlers[$event]
            ?? throw new \InvalidArgumentException("No approval handler for event '{$event}'.");
    }

    /** @return array<string, ApprovalHandler> */
    public function handlers(): array
    {
        return $this->handlers;
    }

    public function thresholds(): ThresholdRepository
    {
        return $this->thresholds;
    }

    // ── money ────────────────────────────────────────────────────────────────

    /** An amount in KES at the reporting rate; null when the currency has none. */
    public static function toKes(?float $amount, ?string $currency): ?float
    {
        if ($amount === null || $currency === null || $currency === '') {
            return null;
        }
        $kes = ReportingCurrency::toKes($amount, $currency);

        return $kes === null ? null : round($kes, 2);
    }

    // ── bands ────────────────────────────────────────────────────────────────

    /**
     * The bands an amount needs under the set in force (no escalation — that
     * is decided at signing time, against who holds each band then).
     *
     * @return array{0: list<array>, 1: list<array>, 2: ?string} [required, ladder, effective_from]
     */
    public function bandsFor(string $event, ?float $basisKes): array
    {
        $set = $this->thresholds->bands($event);
        if ($set->isEmpty()) {
            $this->fail(422, 'NO_THRESHOLDS', "No approval thresholds are configured for {$event}.");
        }

        $ladder = $set->map(fn ($b) => [
            'order'      => (int) $b->band_order,
            'permission' => $b->approver_permission,
            'up_to_kes'  => $b->up_to_kes === null ? null : (float) $b->up_to_kes,
        ])->values()->all();

        $required = [];
        foreach ($ladder as $band) {
            $required[] = $band;
            if ($basisKes !== null && ($band['up_to_kes'] === null || $basisKes <= $band['up_to_kes'])) {
                break;
            }
        }

        return [$required, $ladder, $set->first()->effective_from?->toIso8601String()];
    }

    /**
     * The anti-splitting basis: this amount plus the same maker's other
     * submissions to the same counterparty in the last 24 hours (pending or
     * approved; earlier versions of this same record are not counted twice).
     * $extraKes adds value the caller knows of that never needed a request
     * (stock adjustments applied directly under the procurement band).
     */
    public function rollingBasis(string $event, ?int $makerId, ?string $counterparty, ?float $amountKes, ?Model $record = null, float $extraKes = 0.0): ?float
    {
        if ($amountKes === null) {
            return null;
        }
        if ($makerId === null || $counterparty === null) {
            return round($amountKes + $extraKes, 2);
        }

        $others = ApprovalRequest::where('event', $event)
            ->where('maker_id', $makerId)
            ->where('counterparty', $counterparty)
            ->whereIn('status', [ApprovalRequest::PENDING, ApprovalRequest::APPROVED])
            ->where('created_at', '>=', now()->subDay())
            ->whereNotNull('amount_kes')
            ->when($record, fn ($q) => $q->where(fn ($w) => $w
                ->where('approvable_type', '!=', $record->getMorphClass())
                ->orWhere('approvable_id', '!=', $record->getKey())))
            ->sum('amount_kes');

        return round($amountKes + (float) $others + $extraKes, 2);
    }

    // ── submitting ───────────────────────────────────────────────────────────

    /**
     * Put a record in front of its signers. The handler values it, keys it
     * for anti-splitting and fingerprints it; the bands come from the set in
     * force. A record with an earlier rejected/expired request becomes the
     * next version, linked to it.
     */
    public function submit(string $event, Model $record, ?User $maker, ?array $payload = null, float $extraRollingKes = 0.0): ApprovalRequest
    {
        $handler = $this->handler($event);

        if ($this->openRequest($event, $record)) {
            $this->fail(409, 'APPROVAL_PENDING', 'This is already waiting for approval.');
        }

        $valued = $handler->amount($record, $payload);
        [$amount, $currency] = $valued ?? [null, null];
        $amountKes    = $valued === null ? null : self::toKes($amount, $currency);
        $valueUnknown = $valued !== null && $amountKes === null;

        $counterparty = $handler->counterparty($record, $payload);
        $makerId      = $maker?->id;
        $basis        = $valued === null ? null : $this->rollingBasis($event, $makerId, $counterparty, $amountKes, $record, $extraRollingKes);

        [$required, $ladder, $effectiveFrom] = $this->bandsFor($event, $valued === null ? null : $basis);
        if ($valued === null) {
            $required = [$required[0]];   // no value band: the first band alone
        }

        $previous = ApprovalRequest::where('event', $event)
            ->where('approvable_type', $record->getMorphClass())
            ->where('approvable_id', $record->getKey())
            ->orderByDesc('version')
            ->first();

        $request = ApprovalRequest::create([
            'event'                     => $event,
            'approvable_type'           => $record->getMorphClass(),
            'approvable_id'             => $record->getKey(),
            'version'                   => ($previous?->version ?? 0) + 1,
            'maker_id'                  => $makerId,
            'counterparty'              => $counterparty,
            'amount'                    => $amount,
            'currency_code'             => $currency ? strtoupper($currency) : null,
            'amount_kes'                => $amountKes,
            'basis_kes'                 => $basis,
            'value_unknown'             => $valueUnknown,
            'bands'                     => $required,
            'ladder'                    => $ladder,
            'current_band'              => $required[0]['order'],
            'thresholds_effective_from' => $effectiveFrom,
            'status'                    => ApprovalRequest::PENDING,
            'fingerprint'               => $this->hash($handler->fingerprint($record, $payload)),
            'payload'                   => $payload,
            'expires_at'                => now()->addHours(ApprovalRequest::TTL_HOURS),
            'supersedes_id'             => $previous && $previous->status !== ApprovalRequest::PENDING ? $previous->id : null,
        ]);

        ActivityLogService::log('approval_submitted', $record, [
            'approval_request_id' => $request->id,
            'event'               => $event,
            'version'             => $request->version,
            'amount'              => $amount,
            'currency'            => $request->currency_code,
            'amount_kes'          => $amountKes,
            'basis_kes'           => $basis,
            'value_unknown'       => $valueUnknown,
            'counterparty'        => $counterparty,
            'bands'               => array_column($required, 'permission'),
            'supersedes_id'       => $request->supersedes_id,
        ], "Submitted for approval: {$event} v{$request->version}"
            . ($amountKes !== null ? ' (KES ' . number_format($amountKes, 2) . ')' : ($valueUnknown ? ' (value unknown — every band)' : '')),
            $maker);

        $this->notifySigners($request);

        return $request;
    }

    /** The open (pending) request for a record and event, if any. */
    public function openRequest(string $event, Model $record): ?ApprovalRequest
    {
        return ApprovalRequest::where('event', $event)
            ->where('approvable_type', $record->getMorphClass())
            ->where('approvable_id', $record->getKey())
            ->where('status', ApprovalRequest::PENDING)
            ->first();
    }

    /** The latest request of any status for a record and event. */
    public function latestRequest(string $event, Model $record): ?ApprovalRequest
    {
        return ApprovalRequest::where('event', $event)
            ->where('approvable_type', $record->getMorphClass())
            ->where('approvable_id', $record->getKey())
            ->orderByDesc('version')
            ->first();
    }

    /**
     * For the record-level approve/reject endpoints: the open request, or —
     * for a record that was waiting before the engine existed — one adopted
     * now with the record's own maker. A record whose last request was
     * rejected or expired is not adopted: it went back to its maker.
     */
    public function openOrAdopt(string $event, Model $record): ApprovalRequest
    {
        $handler = $this->handler($event);

        if ($open = $this->openRequest($event, $record)) {
            return $open;
        }
        if (!$handler->isAwaitingApproval($record)) {
            $this->fail(422, 'NOT_AWAITING_APPROVAL', 'This is not waiting for approval.');
        }
        $latest = $this->latestRequest($event, $record);
        if ($latest && in_array($latest->status, [ApprovalRequest::REJECTED, ApprovalRequest::EXPIRED], true)) {
            $this->fail(422, 'APPROVAL_' . strtoupper($latest->status),
                'This went back to the person who raised it. They must resubmit it before anyone can sign.');
        }

        $makerId = $handler->adoptedMaker($record);

        return $this->submit($event, $record, $makerId ? User::find($makerId) : null);
    }

    // ── signing ──────────────────────────────────────────────────────────────

    /**
     * Sign the band a request is waiting on, or reject it.
     *
     * $approvableId / $version, when given, must match the request — the
     * approval is bound to the record and version the signer was shown.
     */
    public function sign(ApprovalRequest $request, User $signer, string $decision, ?string $reason = null, ?int $approvableId = null, ?int $version = null): ApprovalRequest
    {
        if (!in_array($decision, [ApprovalSignature::APPROVED, ApprovalSignature::REJECTED], true)) {
            $this->fail(422, 'BAD_DECISION', 'A decision is approve or reject.');
        }
        if ($decision === ApprovalSignature::REJECTED && trim((string) $reason) === '') {
            $this->fail(422, 'REASON_REQUIRED', 'Say why it is rejected.');
        }

        if ($approvableId !== null && (int) $approvableId !== (int) $request->approvable_id) {
            $this->auditMismatch($request, $signer, 'approvable_id', $approvableId);
            $this->fail(422, 'APPROVAL_MISMATCH', 'This approval belongs to a different record. Reload and try again.');
        }
        if ($version !== null && (int) $version !== (int) $request->version) {
            $this->auditMismatch($request, $signer, 'version', $version);
            $this->fail(422, 'APPROVAL_VERSION_MISMATCH', 'This record has been resubmitted since you opened it. Reload and sign the current version.');
        }

        if (!$request->isPending()) {
            $this->fail(409, 'APPROVAL_DECIDED', "This request is already {$request->status}.");
        }
        if ($request->expires_at->isPast()) {
            $this->expire($request);
            $this->fail(422, 'APPROVAL_EXPIRED', 'This request waited more than 72 hours and went back to the person who raised it.');
        }

        $handler = $this->handler($request->event);
        $record  = $handler->find((int) $request->approvable_id);
        if (!$record) {
            $this->fail(404, 'APPROVABLE_MISSING', 'The record this approval is for no longer exists.');
        }

        // Maker ≠ checker first: a maker is refused as a maker (SELF_APPROVAL),
        // whatever band they might also hold. Before any write, so the audit
        // entry it makes is not rolled back.
        if ($decision === ApprovalSignature::APPROVED) {
            MakerChecker::assertNotMaker($signer, $handler->makerCheckerAction(), $record, ...$this->makerIdsOf($request, $record));
        }

        if ($this->hash($handler->fingerprint($record, $request->payload)) !== $request->fingerprint) {
            $this->fail(422, 'APPROVAL_STALE', 'This record changed after it was submitted. It must be resubmitted before it can be signed.');
        }

        [$target, $covers] = $this->effectiveBand($request, $record);

        if ($decision === ApprovalSignature::APPROVED && $this->signerIds($request)->contains((int) $signer->id)) {
            $this->fail(403, 'ALREADY_SIGNED', 'You signed an earlier band of this request; the next band needs someone else.');
        }
        // The owner may veto at any band: a rejection spends no signature,
        // so the stand-in rule that keeps his top-band signature does not apply.
        $ownerVeto = $decision === ApprovalSignature::REJECTED && $signer->hasRole('super_admin', 'sanctum');
        if (!$ownerVeto && !$this->canSignBand($signer, $request, $target)) {
            $this->fail(403, 'NOT_YOUR_BAND', 'This is waiting for a signature you do not hold.');
        }

        $finished = DB::transaction(function () use ($request, $signer, $decision, $reason, $target, $covers, $handler) {
            /** @var ApprovalRequest $locked */
            $locked = ApprovalRequest::whereKey($request->id)->lockForUpdate()->first();
            if (!$locked->isPending() || (int) $locked->current_band !== (int) $request->current_band) {
                $this->fail(409, 'APPROVAL_DECIDED', 'Someone else acted on this request first. Reload it.');
            }
            $record = $handler->find((int) $locked->approvable_id);

            ApprovalSignature::create([
                'approval_request_id' => $locked->id,
                'band_order'          => $target['order'],
                'signer_id'           => $signer->id,
                'decision'            => $decision,
                'covers'              => $covers,
                'reason'              => $reason,
                'signed_at'           => now(),
            ]);

            if ($decision === ApprovalSignature::REJECTED) {
                $locked->update([
                    'status'          => ApprovalRequest::REJECTED,
                    'rejected_reason' => $reason,
                    'decided_at'      => now(),
                    'decided_by'      => $signer->id,
                ]);
                $handler->onRejected($locked, $record, $signer, (string) $reason);

                return $locked;
            }

            $next = collect($locked->bands)->first(fn ($b) => (int) $b['order'] > max($covers));
            if ($next) {
                $locked->update(['current_band' => $next['order']]);

                return $locked;
            }

            $locked->update([
                'status'     => ApprovalRequest::APPROVED,
                'decided_at' => now(),
                'decided_by' => $signer->id,
            ]);
            $handler->onApproved($locked, $record, $signer);

            return $locked;
        });

        $finished->refresh();
        $escalated = count($covers) > 1 || !collect($finished->bands)->pluck('order')->contains($target['order']);

        ActivityLogService::log(
            $decision === ApprovalSignature::REJECTED ? 'approval_rejected' : ($finished->status === ApprovalRequest::APPROVED ? 'approval_approved' : 'approval_signed'),
            $record,
            [
                'approval_request_id' => $finished->id,
                'event'               => $finished->event,
                'version'             => $finished->version,
                'band'                => $target['order'],
                'band_permission'     => $target['permission'],
                'covers'              => $covers,
                'escalated'           => $escalated,
                'reason'              => $reason,
                'status'              => $finished->status,
            ],
            ucfirst($decision) . " {$finished->event} v{$finished->version} at band {$target['order']}" . ($escalated ? ' (escalated)' : ''),
            $signer,
        );

        if ($finished->isPending()) {
            $this->notifySigners($finished);
        } else {
            $this->notifyMaker($finished);
        }

        return $finished;
    }

    /**
     * The band a signature satisfies now: the current band, or — when nobody
     * but the makers and earlier signers holds it — the band it escalates to.
     *
     * @return array{0: array, 1: list<int>} [target band, band orders covered]
     */
    public function effectiveBand(ApprovalRequest $request, ?Model $record = null): array
    {
        $record ??= $this->handler($request->event)->find((int) $request->approvable_id);
        $excluded = array_values(array_unique(array_merge(
            $record ? $this->makerIdsOf($request, $record) : array_filter([(int) $request->maker_id]),
            $this->signerIds($request)->all(),
        )));

        $target = $request->currentBandDef();
        $covers = [(int) $target['order']];

        while (!$this->hasEligibleSigner($target['permission'], $excluded)) {
            $next = $this->escalationOf($request, $target);
            if ($next === null) {
                break;   // the super-admin band with no other super admin: it waits
            }
            $target   = $next;
            $covers[] = (int) $next['order'];
        }

        return [$target, $covers];
    }

    /** The band a band escalates to: the next in the set, else the super admin. */
    private function escalationOf(ApprovalRequest $request, array $band): ?array
    {
        foreach ($request->ladder ?? [] as $candidate) {
            if ((int) $candidate['order'] > (int) $band['order']) {
                return $candidate;
            }
        }
        if ($band['permission'] !== self::SUPER_PERMISSION) {
            return ['order' => (int) $band['order'] + 1, 'permission' => self::SUPER_PERMISSION, 'up_to_kes' => null];
        }

        return null;
    }

    /** Someone other than $excluded holds this band (explicitly, or as super admin for the top band). */
    public function hasEligibleSigner(string $permission, array $excluded): bool
    {
        return $this->holdersOf($permission)->whereNotIn('id', $excluded)->isNotEmpty();
    }

    /** Active staff who hold a band's key in their own right. */
    public function holdersOf(string $permission): Collection
    {
        if ($permission === self::SUPER_PERMISSION) {
            $supers = User::role('super_admin', 'sanctum')->where('status', 'active')->get();
            try {
                return $supers->merge(User::permission($permission)->where('status', 'active')->get())->unique('id')->values();
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
                return $supers;
            }
        }
        try {
            return User::permission($permission)->where('status', 'active')->get();
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            return collect();
        }
    }

    /**
     * May this person sign this band of this request? The band's key held in
     * their own right; or super admin — always for the top band, and for a
     * lower band only when no super-admin band follows in the request.
     */
    public function canSignBand(User $user, ApprovalRequest $request, array $band): bool
    {
        $isSuper = $user->hasRole('super_admin', 'sanctum');

        if ($band['permission'] === self::SUPER_PERMISSION) {
            return $isSuper || $this->holdsExplicitly($user, self::SUPER_PERMISSION);
        }
        if ($this->holdsExplicitly($user, $band['permission'])) {
            return true;
        }
        if (!$isSuper) {
            return false;
        }

        return !collect($request->bands)->contains(fn ($b) => (int) $b['order'] > (int) $band['order']
            && $b['permission'] === self::SUPER_PERMISSION);
    }

    private function holdsExplicitly(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission, 'sanctum');
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            return false;
        }
    }

    /** Can this person sign this request right now? (The inbox's question.) */
    public function canSignNow(User $user, ApprovalRequest $request): bool
    {
        if (!$request->isPending() || $request->expires_at->isPast()) {
            return false;
        }
        $record = $this->handler($request->event)->find((int) $request->approvable_id);
        if (!$record) {
            return false;
        }
        if (in_array((int) $user->id, $this->makerIdsOf($request, $record), true)
            || $this->signerIds($request)->contains((int) $user->id)) {
            return false;
        }
        [$target] = $this->effectiveBand($request, $record);

        return $this->canSignBand($user, $request, $target);
    }

    // ── cancelling, expiring, resubmitting ──────────────────────────────────

    /** Withdraw an open request (the record was cancelled or changed). */
    public function cancelOpen(string $event, Model $record, ?User $by, string $why): void
    {
        $open = $this->openRequest($event, $record);
        if (!$open) {
            return;
        }
        $open->update(['status' => ApprovalRequest::CANCELLED, 'decided_at' => now(), 'decided_by' => $by?->id, 'rejected_reason' => $why]);
        ActivityLogService::log('approval_cancelled', $record, [
            'approval_request_id' => $open->id, 'event' => $event, 'version' => $open->version, 'why' => $why,
        ], "Approval withdrawn: {$event} v{$open->version} — {$why}", $by);
    }

    /** Past 72 hours: back to the maker. Never approved by time passing. */
    public function expire(ApprovalRequest $request): void
    {
        $handler = $this->handler($request->event);

        $done = DB::transaction(function () use ($request, $handler) {
            $locked = ApprovalRequest::whereKey($request->id)->lockForUpdate()->first();
            if (!$locked || !$locked->isPending()) {
                return null;
            }
            $locked->update(['status' => ApprovalRequest::EXPIRED, 'decided_at' => now()]);
            if ($record = $handler->find((int) $locked->approvable_id)) {
                $handler->onExpired($locked, $record);
            }

            return $locked;
        });

        if ($done) {
            ActivityLogService::log('approval_expired', $handler->find((int) $done->approvable_id), [
                'approval_request_id' => $done->id, 'event' => $done->event, 'version' => $done->version,
                'band'                => $done->current_band,
            ], "Approval expired after " . ApprovalRequest::TTL_HOURS . "h, back to the maker: {$done->event} v{$done->version}", null);
            $this->notifyMaker($done->fresh());
        }
    }

    /** Expire every pending request past its time. Returns how many. */
    public function expireDue(): int
    {
        $n = 0;
        ApprovalRequest::where('status', ApprovalRequest::PENDING)
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->each(function (ApprovalRequest $r) use (&$n) {
                $this->expire($r);
                $n++;
            });

        return $n;
    }

    /**
     * A rejected or expired request comes back as a NEW version linked to
     * it, raised by its maker. The handler puts the record back into the
     * waiting state first.
     */
    public function resubmit(ApprovalRequest $old, User $maker): ApprovalRequest
    {
        if (!in_array($old->status, [ApprovalRequest::REJECTED, ApprovalRequest::EXPIRED], true)) {
            $this->fail(422, 'NOT_RESUBMITTABLE', 'Only a rejected or expired request can be resubmitted.');
        }
        $handler = $this->handler($old->event);
        $record  = $handler->find((int) $old->approvable_id);
        if (!$record) {
            $this->fail(404, 'APPROVABLE_MISSING', 'The record this approval is for no longer exists.');
        }
        $latest = $this->latestRequest($old->event, $record);
        if ($latest && $latest->id !== $old->id) {
            $this->fail(409, 'SUPERSEDED', 'A newer version of this has already been submitted.');
        }
        $makers = $this->makerIdsOf($old, $record);
        if (!in_array((int) $maker->id, $makers, true)) {
            $this->fail(403, 'NOT_THE_MAKER', 'Only the person who raised this can resubmit it.');
        }

        return DB::transaction(function () use ($handler, $record, $maker, $old) {
            $handler->reopen($record, $maker);

            return $this->submit($old->event, $record->fresh(), $maker, $old->payload);
        });
    }

    // ── reading ──────────────────────────────────────────────────────────────

    /** Every pending request this person can sign now, oldest first. */
    public function inbox(User $user): Collection
    {
        return ApprovalRequest::with(['signatures.signer:id,first_name,last_name,email', 'maker:id,first_name,last_name,email'])
            ->where('status', ApprovalRequest::PENDING)
            ->where('expires_at', '>', now())
            ->orderBy('created_at')
            ->get()
            ->filter(fn (ApprovalRequest $r) => $this->canSignNow($user, $r))
            ->values();
    }

    /** What this person has submitted, newest first. */
    public function mine(User $user, int $limit = 100): Collection
    {
        return ApprovalRequest::with(['signatures.signer:id,first_name,last_name,email', 'maker:id,first_name,last_name,email'])
            ->where('maker_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function present(ApprovalRequest $r, ?User $viewer = null): array
    {
        $handler = $this->handler($r->event);
        $record  = $handler->find((int) $r->approvable_id);
        $current = $r->isPending() ? $r->currentBandDef() : null;
        $target  = null;
        if ($r->isPending() && $record) {
            [$target] = $this->effectiveBand($r, $record);
        }
        $name = fn (?User $u) => $u ? (trim("{$u->first_name} {$u->last_name}") ?: $u->email) : null;

        return [
            'id'              => $r->id,
            'event'           => $r->event,
            'approvable_type' => class_basename($r->approvable_type),
            'approvable_id'   => (int) $r->approvable_id,
            'version'         => $r->version,
            'status'          => $r->status,
            'maker'           => $r->maker ? ['id' => $r->maker->id, 'name' => $name($r->maker)] : null,
            'counterparty'    => $r->counterparty,
            'amount'          => $r->amount === null ? null : (float) $r->amount,
            'currency_code'   => $r->currency_code,
            'amount_kes'      => $r->amount_kes === null ? null : (float) $r->amount_kes,
            'basis_kes'       => $r->basis_kes === null ? null : (float) $r->basis_kes,
            'value_unknown'   => $r->value_unknown,
            'bands'           => collect($r->bands)->map(function ($b) use ($r) {
                $sig = $r->signatures->first(fn ($s) => in_array((int) $b['order'], array_map('intval', $s->covers ?? [$s->band_order]), true));

                return $b + [
                    'signed' => $sig && $sig->decision === ApprovalSignature::APPROVED,
                ];
            })->values(),
            'current_band'    => $current,
            'awaiting'        => $target,
            'escalated'       => $current && $target && (int) $target['order'] !== (int) $current['order'],
            'signatures'      => $r->signatures->map(fn ($s) => [
                'band_order' => $s->band_order,
                'covers'     => $s->covers,
                'decision'   => $s->decision,
                'reason'     => $s->reason,
                'signed_at'  => $s->signed_at?->toIso8601String(),
                'signer'     => $s->signer ? ['id' => $s->signer->id, 'name' => $name($s->signer)] : null,
            ])->values(),
            'rejected_reason' => $r->rejected_reason,
            'supersedes_id'   => $r->supersedes_id,
            'expires_at'      => $r->expires_at?->toIso8601String(),
            'decided_at'      => $r->decided_at?->toIso8601String(),
            'created_at'      => $r->created_at?->toIso8601String(),
            'summary'         => $record ? $handler->summary($record, $r->payload) : null,
            'can_sign'        => $viewer ? $this->canSignNow($viewer, $r) : false,
            'can_resubmit'    => $viewer && in_array($r->status, [ApprovalRequest::REJECTED, ApprovalRequest::EXPIRED], true)
                && (int) $r->maker_id === (int) $viewer->id
                && !ApprovalRequest::where('supersedes_id', $r->id)->exists(),
        ];
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** @return list<int> */
    private function makerIdsOf(ApprovalRequest $request, Model $record): array
    {
        $ids = array_merge([$request->maker_id], $this->handler($request->event)->makerIds($record));

        return array_values(array_unique(array_map('intval', array_filter($ids, fn ($id) => $id !== null && $id !== ''))));
    }

    private function signerIds(ApprovalRequest $request): Collection
    {
        return ApprovalSignature::where('approval_request_id', $request->id)
            ->where('decision', ApprovalSignature::APPROVED)
            ->pluck('signer_id')->map(fn ($id) => (int) $id);
    }

    private function hash(array $fields): string
    {
        return hash('sha256', json_encode($this->canonical($fields)));
    }

    private function canonical(mixed $v): mixed
    {
        if (is_array($v)) {
            if (!array_is_list($v)) {
                ksort($v);
            }

            return array_map(fn ($x) => $this->canonical($x), $v);
        }
        if (is_float($v) || (is_string($v) && is_numeric($v) && str_contains($v, '.'))) {
            return number_format((float) $v, 4, '.', '');
        }

        return $v === null ? null : (is_bool($v) ? $v : (string) $v);
    }

    private function auditMismatch(ApprovalRequest $request, User $signer, string $field, int $given): void
    {
        ActivityLogService::log('approval_binding_mismatch', $request, [
            'approval_request_id' => $request->id,
            'field'               => $field,
            'given'               => $given,
            'expected'            => $field === 'version' ? $request->version : (int) $request->approvable_id,
        ], "Approval refused: {$field} did not match request #{$request->id}", $signer);
    }

    private function notifySigners(ApprovalRequest $request): void
    {
        try {
            $record = $this->handler($request->event)->find((int) $request->approvable_id);
            if (!$record) {
                return;
            }
            [$target] = $this->effectiveBand($request, $record);
            $excluded = array_merge($this->makerIdsOf($request, $record), $this->signerIds($request)->all());
            $users = $this->holdersOf($target['permission'])->whereNotIn('id', $excluded)->values();
            $summary = $this->handler($request->event)->summary($record, $request->payload);
            NotificationService::approvalWaiting($users, $request->id, $summary['title'] ?? $request->event, $request->amount_kes === null ? null : (float) $request->amount_kes);
        } catch (\Throwable $e) {
            // A notification never blocks an approval; the inbox is the source of truth.
            report($e);
        }
    }

    private function notifyMaker(ApprovalRequest $request): void
    {
        try {
            if (!$request->maker_id) {
                return;
            }
            $record = $this->handler($request->event)->find((int) $request->approvable_id);
            $summary = $record ? $this->handler($request->event)->summary($record, $request->payload) : [];
            NotificationService::approvalDecided((int) $request->maker_id, $request->id, $summary['title'] ?? $request->event, $request->status, $request->rejected_reason);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
