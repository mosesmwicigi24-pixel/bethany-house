<?php

namespace App\Services\Approvals\Handlers;

use App\Models\ApprovalRequest;
use App\Models\ChangeProposal;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Approvals\ApprovalHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * A proposal (Phase 3C): a change to a money-relevant value that takes effect
 * only after the signatures its bands require.
 *
 * The record the engine signs is a ChangeProposal row; the value being changed
 * (a price row, a tax rate, a currency…) is its SUBJECT. The new value travels
 * in the request payload — the approval is bound to it — and is written to the
 * subject only in onApproved (or, for an effective-dated change signed before
 * its date, by `proposals:apply-due` when the date arrives). A rejection or an
 * expiry leaves the live value exactly as it was.
 *
 * Each concrete handler is one event: which fields it guards, who may propose,
 * how a change measures against the bands, and how the new value is written.
 * The engine's rules (bands, maker ≠ checker, escalation, expiry, versions) are
 * the engine's; nothing here changes them.
 *
 * Anti-splitting: the engine's rolling sum adds the same maker's amounts for a
 * counterparty. That is right for money (credit) but wrong for a price, which
 * is an absolute value — three 9 % cuts are a 27 % cut, not 27 "percent-units"
 * summed with whatever else. So counterparty() is null here and each event
 * measures against the value as it stood 24 hours ago (baseline()), or adds
 * its own rolling extra (extraRolling()) where a sum is the right measure.
 */
abstract class ProposalHandler extends ApprovalHandler
{
    // ── what each event defines ─────────────────────────────────────────────

    /** 'product_price', 'material', 'tax_rate', 'currency', 'payment_method', 'order'. */
    abstract public function subjectType(): string;

    /** The guarded fields: field => human label. */
    abstract public function fields(): array;

    /** Any one of these lets a person propose this change. */
    abstract public function makerPermissions(): array;

    /** The live values of the guarded fields, or null when the subject does not exist. */
    abstract public function current(int $subjectId): ?array;

    /** "Cassock (CS-01) · KES" — what the inbox calls the subject. */
    abstract public function label(int $subjectId): string;

    /**
     * Normalise and validate proposed values against the subject (throw a
     * ValidationException). Returns only fields this event guards.
     */
    abstract public function normalise(array $new, int $subjectId): array;

    /**
     * Measure a change: ['direct_basis' => ?float, 'band_basis' => ?float,
     * 'direct_ok' => bool, ...detail]. band_basis null on a valued event means
     * "cannot be stated" — the engine then requires every band.
     */
    abstract public function measure(ChangeProposal $p): array;

    /** Write the new values to the live subject. Runs inside a transaction. */
    abstract public function apply(ChangeProposal $p, array $new, ?User $by): void;

    /** Where the subject is edited in the console. */
    abstract public function link(ChangeProposal $p): ?string;

    /** A short name for the event, for the inbox title. */
    abstract public function title(): string;

    /** Takes a future effective_from (tax, reporting FX, settlement). */
    public function effectiveDated(): bool
    {
        return false;
    }

    /** The approval_thresholds event holding the maker's own band, or null for none. */
    public function directEvent(): ?string
    {
        return null;
    }

    /** What up_to_kes means for this event's bands: 'percent', 'kes' or 'none'. */
    public function unit(): string
    {
        return 'none';
    }

    /** False for an event with no value band (one signature, whatever the change). */
    public function valued(): bool
    {
        return true;
    }

    /** KES the engine adds to the band basis (anti-splitting by sum). */
    public function extraRolling(ChangeProposal $p): float
    {
        return 0.0;
    }

    /** What else the approval is bound to (a cost, an order total) beyond the subject's own fields. */
    public function context(ChangeProposal $p): array
    {
        return [];
    }

    /** One value, for people. */
    public function formatValue(string $field, mixed $value, ChangeProposal $p): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return is_numeric($value) ? number_format((float) $value, 2) : (string) $value;
    }

    /** The measure, in a line, for the inbox. */
    public function measureLine(ChangeProposal $p): ?string
    {
        return null;
    }

    // ── shared measuring ────────────────────────────────────────────────────

    /**
     * A field's value as it stood before this maker's changes in the last 24
     * hours: the old value of their earliest change to it in that window,
     * else its live value. Changes applied, waiting or scheduled all count.
     */
    public function baseline(ChangeProposal $p, string $field, mixed $live): mixed
    {
        if (!$p->maker_id) {
            return $live;
        }
        $earlier = ChangeProposal::where('event', $this->event())
            ->where('subject_type', $this->subjectType())
            ->where('subject_id', $p->subject_id)
            ->where('maker_id', $p->maker_id)
            ->whereIn('status', [ChangeProposal::APPLIED, ChangeProposal::PENDING, ChangeProposal::SCHEDULED])
            ->where('created_at', '>=', now()->subDay())
            ->when($p->exists, fn ($q) => $q->where('id', '!=', $p->id))
            ->orderBy('id')
            ->get()
            ->first(fn (ChangeProposal $e) => array_key_exists($field, $e->changeset ?? []));

        return $earlier ? ($earlier->changeset[$field]['old'] ?? null) : $live;
    }

    /**
     * |new − old| / old, in percent. A first value (old empty) measures 0 —
     * entering a missing figure is not a change to one. Clearing a value
     * (new empty) measures 100.
     */
    public static function changePct(mixed $old, mixed $new): float
    {
        $o = $old === null ? 0.0 : (float) $old;
        $n = $new === null ? 0.0 : (float) $new;
        if ($o <= 0.0) {
            return 0.0;
        }
        if ($n <= 0.0) {
            return 100.0;
        }

        return round(abs($n - $o) / $o * 100, 2);
    }

    // ── ApprovalHandler ─────────────────────────────────────────────────────

    public function modelClass(): string
    {
        return ChangeProposal::class;
    }

    public function makerCheckerAction(): string
    {
        return $this->event() . '.approve';
    }

    public function amount(Model $p, ?array $payload = null): ?array
    {
        if (!$this->valued()) {
            return null;
        }
        $basis = $p->measures['band_basis'] ?? null;

        // KES so the engine's conversion is the identity; for a percentage
        // event the number is a percentage (unit() says so to the console).
        return [$basis === null ? null : (float) $basis, 'KES'];
    }

    public function counterparty(Model $p, ?array $payload = null): ?string
    {
        return null;
    }

    public function makerIds(Model $p): array
    {
        return [$p->maker_id];
    }

    public function fingerprint(Model $p, ?array $payload = null): array
    {
        $changes = $payload['changes'] ?? $p->changeset;
        $live    = $this->current((int) $p->subject_id) ?? [];

        return [
            'id'             => $p->id,
            'event'          => $p->event,
            'subject'        => $p->subject_type . ':' . $p->subject_id,
            'changes'        => $changes,
            'effective_from' => $payload['effective_from'] ?? $p->effective_from?->toIso8601String(),
            // The live values the change was made against: if someone changes
            // them meanwhile, the proposal is stale and cannot be signed.
            'live'           => array_intersect_key($live, $changes ?? []),
            'context'        => $this->context($p),
        ];
    }

    public function isAwaitingApproval(Model $p): bool
    {
        return $p->status === ChangeProposal::PENDING;
    }

    public function summary(Model $p, ?array $payload = null): array
    {
        $changes = $payload['changes'] ?? $p->changeset ?? [];
        $labels  = $this->fields();
        $lines   = [];
        foreach ($changes as $field => $c) {
            $lines[] = ($labels[$field] ?? $field) . ': '
                . $this->formatValue($field, $c['old'] ?? null, $p) . ' → '
                . $this->formatValue($field, $c['new'] ?? null, $p);
        }
        if ($m = $this->measureLine($p)) {
            $lines[] = $m;
        }
        if ($this->effectiveDated()) {
            $from    = $payload['effective_from'] ?? $p->effective_from?->toIso8601String();
            $lines[] = 'Takes effect: ' . ($from ? \Carbon\Carbon::parse($from)->format('j M Y, H:i') : 'when approved');
        }
        if ($p->note) {
            $lines[] = 'Note: ' . $p->note;
        }

        return [
            'title'     => $this->title() . ': ' . ($p->subject_label ?: $this->label((int) $p->subject_id)),
            'reference' => 'CHG-' . $p->id,
            'link'      => $this->link($p),
            'lines'     => $lines,
            'changes'   => $changes,
            'unit'      => $this->unit(),
        ];
    }

    public function onApproved(ApprovalRequest $request, Model $p, User $finalSigner): void
    {
        /** @var ChangeProposal $p */
        $p = ChangeProposal::whereKey($p->id)->lockForUpdate()->first();
        if ($p->status !== ChangeProposal::PENDING) {
            throw new HttpResponseException(response()->json([
                'message' => "This change is {$p->status}, not waiting for approval.",
                'code'    => 'PROPOSAL_NOT_PENDING',
            ], 409));
        }

        // The value signed for is the one in the request, never re-read.
        $new  = array_map(fn ($c) => $c['new'] ?? null, $request->payload['changes'] ?? $p->changeset);
        $from = isset($request->payload['effective_from']) ? \Carbon\Carbon::parse($request->payload['effective_from']) : null;

        if ($from && $from->isFuture()) {
            $p->update(['status' => ChangeProposal::SCHEDULED]);
            ActivityLogService::log('proposal_scheduled', $p, [
                'event' => $p->event, 'approval_request_id' => $request->id,
                'effective_from' => $from->toIso8601String(), 'changes' => $p->changeset,
            ], "{$this->title()} approved, takes effect {$from->toDateTimeString()}: " . ($p->subject_label ?? ''), $finalSigner);

            return;
        }

        $this->apply($p, $new, $finalSigner);
        $p->update(['status' => ChangeProposal::APPLIED, 'applied_at' => now(), 'applied_by' => $finalSigner->id]);
        ActivityLogService::log('proposal_applied', $p, [
            'event' => $p->event, 'approval_request_id' => $request->id, 'changes' => $p->changeset, 'direct' => false,
        ], "{$this->title()} approved and applied: " . ($p->subject_label ?? ''), $finalSigner);
    }

    public function onRejected(ApprovalRequest $request, Model $p, User $signer, string $reason): void
    {
        // The live value was never touched.
        $p->update(['status' => ChangeProposal::REJECTED]);
    }

    public function onExpired(ApprovalRequest $request, Model $p): void
    {
        if ($p->status === ChangeProposal::PENDING) {
            $p->update(['status' => ChangeProposal::EXPIRED]);
        }
    }

    /**
     * A rejected or expired proposal comes back as a new version only while
     * it still describes the live value: if the value moved on meanwhile, or
     * the effective date has passed, the maker raises a new proposal instead.
     */
    public function reopen(Model $p, User $maker): void
    {
        if (!in_array($p->status, [ChangeProposal::REJECTED, ChangeProposal::EXPIRED], true)) {
            throw ValidationException::withMessages(['status' => 'Only a rejected or expired change can be resubmitted.']);
        }
        if (ChangeProposal::where('event', $p->event)->where('subject_type', $p->subject_type)
            ->where('subject_id', $p->subject_id)->whereIn('status', ChangeProposal::OPEN)->exists()) {
            throw ValidationException::withMessages(['status' => 'Another change to this value is already waiting or scheduled.']);
        }
        $live = $this->current((int) $p->subject_id);
        if ($live === null) {
            throw ValidationException::withMessages(['subject' => 'What this change was for no longer exists.']);
        }
        foreach ($p->changeset as $field => $c) {
            if (!self::same($live[$field] ?? null, $c['old'] ?? null)) {
                throw ValidationException::withMessages(['changes' => 'The value has changed since this was proposed. Propose the change again from the current value.']);
            }
        }
        if ($p->effective_from && $p->effective_from->lt(now()->subMinute())) {
            throw ValidationException::withMessages(['effective_from' => 'Its effective date has passed, and a change is never retroactive. Propose it again with a new date.']);
        }

        $p->status   = ChangeProposal::PENDING;
        $p->measures = $this->measure($p) + ['unit' => $this->unit()];
        $p->save();
    }

    /** Two field values are the same value (numbers compared as numbers). */
    public static function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.000001;
        }

        return (string) $a === (string) $b;
    }
}
