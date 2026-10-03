<?php

namespace App\Services\Approvals;

use App\Models\ApprovalRequest;
use App\Models\ChangeProposal;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Approvals\Handlers\ProposalHandler;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Proposals (Role Hardening Plan §5, Phase 3C): every write to a guarded,
 * money-relevant value goes through propose().
 *
 *   - Inside the maker's own band (the event's _direct threshold, for a maker
 *     holding its key) the change applies at once — still a change_proposals
 *     row, audited, so the value's history is whole.
 *   - Otherwise a pending row is submitted to the approval engine with the new
 *     value in the request payload. The live value does not move until the
 *     last band signs; a rejection or an expiry leaves it untouched.
 *   - An effective-dated change (tax, reporting FX, settlement) signed before
 *     its date waits as `scheduled`; apply-due writes it when the date comes.
 *     It is never applied earlier than effective_from, and effective_from is
 *     never in the past.
 *
 * Maker ≠ checker and escalation are the engine's: a super admin's own change
 * that crosses a band still waits — for another holder of the band (production
 * has two super admins), and a super-admin band no one else holds waits.
 */
final class ProposalService
{
    /** Who a band's key belongs to, in words. */
    public const BAND_LABELS = [
        'approvals.finance_sign' => 'Finance',
        'approvals.super_sign'   => 'Super admin',
        'expenses.approve'       => 'Finance',
        'procurement.approve'    => 'Procurement manager',
        'inventory.approve'      => 'Procurement manager',
    ];

    public function __construct(private ApprovalEngine $engine) {}

    public function handler(string $event): ProposalHandler
    {
        $h = $this->engine->handlers()[$event] ?? null;
        if (!$h instanceof ProposalHandler) {
            throw ValidationException::withMessages(['event' => "Not a change that can be proposed: {$event}."]);
        }

        return $h;
    }

    /** @return array<string, ProposalHandler> */
    public function handlers(): array
    {
        return array_filter($this->engine->handlers(), fn ($h) => $h instanceof ProposalHandler);
    }

    public function mayPropose(User $user, ProposalHandler $h): bool
    {
        foreach ($h->makerPermissions() as $permission) {
            if ($this->holds($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** May this person read this event's proposals (a maker, a signer, the owner)? */
    public function maySee(User $user, ProposalHandler $h): bool
    {
        return $this->mayPropose($user, $h)
            || $this->holds($user, 'approvals.finance_sign')
            || $user->hasRole('super_admin', 'sanctum');
    }

    public function holds(User $user, string $permission): bool
    {
        if ($user->hasRole('super_admin', 'sanctum')) {
            return true;   // Gate::before: the owner holds every key
        }
        try {
            return $user->hasPermissionTo($permission, 'sanctum');
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            return false;
        }
    }

    // ── proposing ────────────────────────────────────────────────────────────

    /**
     * Propose new values for a subject's guarded fields. Returns null when
     * nothing guarded actually changes; otherwise the proposal — APPLIED when
     * it went straight through, PENDING when it waits for signatures.
     */
    public function propose(string $event, int $subjectId, array $new, User $maker, ?string $effectiveFrom = null, ?string $note = null): ?ChangeProposal
    {
        $h = $this->handler($event);

        // Nothing guarded changes → nothing to propose, and nothing to refuse:
        // an edit form resends every field, changed or not.
        $p = $this->build($h, $subjectId, $new, $maker, $effectiveFrom, $note);
        if ($p === null) {
            return null;
        }
        if (!$this->mayPropose($maker, $h)) {
            $this->fail(403, 'NOT_A_MAKER', "You cannot propose a {$h->title()} change.");
        }

        if ($open = $this->openFor($event, $h->subjectType(), $subjectId)) {
            $this->fail(409, 'PROPOSAL_PENDING', "A {$h->title()} change to this is already "
                . ($open->status === ChangeProposal::SCHEDULED ? 'approved and scheduled' : 'waiting for approval')
                . " (CHG-{$open->id}). Withdraw it before proposing another.");
        }

        $direct = $this->qualifiesDirect($h, $p, $maker);

        return DB::transaction(function () use ($h, $p, $maker, $direct, $event) {
            if ($direct) {
                $p->fill(['status' => ChangeProposal::APPLIED, 'direct' => true, 'applied_at' => now(), 'applied_by' => $maker->id]);
                $p->save();
                $h->apply($p, $p->newValues(), $maker);
                ActivityLogService::log('proposal_applied', $p, [
                    'event' => $event, 'changes' => $p->changeset, 'measures' => $p->measures, 'direct' => true,
                ], "{$h->title()} changed within the maker's band: {$p->subject_label}", $maker);

                return $p;
            }

            $p->save();
            $this->engine->submit($event, $p, $maker, $this->payload($p), $h->extraRolling($p));

            return $p->fresh();
        });
    }

    /**
     * What a change would need, without saving anything: straight through, or
     * which signatures. For the edit screens' "This change needs approval from …".
     */
    public function preview(string $event, int $subjectId, array $new, User $maker, ?string $effectiveFrom = null): array
    {
        $h = $this->handler($event);
        $p = $this->build($h, $subjectId, $new, $maker, $effectiveFrom, null);
        if ($p === null) {
            return ['changes' => [], 'direct' => true, 'needs' => [], 'message' => 'Nothing to change.'];
        }
        if (!$this->mayPropose($maker, $h)) {
            return ['changes' => $p->changeset, 'direct' => false, 'needs' => [], 'allowed' => false,
                'message' => "You cannot propose a {$h->title()} change."];
        }
        $open = $this->openFor($event, $h->subjectType(), $subjectId);
        if ($this->qualifiesDirect($h, $p, $maker)) {
            return ['changes' => $p->changeset, 'measures' => $p->measures, 'direct' => true, 'needs' => [],
                'open' => $open ? $this->present($open, $maker) : null,
                'message' => 'Within your band: this applies at once.'];
        }

        $needs = $this->needs($h, $p);

        return [
            'changes'  => $p->changeset,
            'measures' => $p->measures,
            'direct'   => false,
            'needs'    => $needs,
            'open'     => $open ? $this->present($open, $maker) : null,
            'message'  => 'This change needs approval from ' . implode(', then ', $needs) . '.',
        ];
    }

    /** The signatures a (not yet submitted) proposal would need, in words. */
    private function needs(ProposalHandler $h, ChangeProposal $p): array
    {
        if (!$h->valued()) {
            [$required] = $this->engine->bandsFor($h->event(), null);
            $required = [$required[0]];
        } else {
            $basis = $p->measures['band_basis'] ?? null;
            [$required] = $this->engine->bandsFor($h->event(), $basis === null ? null : (float) $basis + $h->extraRolling($p));
        }

        return array_values(array_unique(array_map(fn ($b) => self::BAND_LABELS[$b['permission']] ?? $b['permission'], $required)));
    }

    /** An unsaved proposal for the change, measured — or null when nothing guarded changes. */
    private function build(ProposalHandler $h, int $subjectId, array $new, User $maker, ?string $effectiveFrom, ?string $note): ?ChangeProposal
    {
        $live = $h->current($subjectId);
        if ($live === null) {
            $this->fail(404, 'SUBJECT_MISSING', "What this {$h->title()} change is for does not exist.");
        }
        $new = $h->normalise($new, $subjectId);

        $changes = [];
        foreach ($new as $field => $value) {
            if (!ProposalHandler::same($live[$field] ?? null, $value)) {
                $changes[$field] = ['old' => $live[$field] ?? null, 'new' => $value];
            }
        }
        if ($changes === []) {
            return null;
        }

        $p = new ChangeProposal([
            'event'          => $h->event(),
            'subject_type'   => $h->subjectType(),
            'subject_id'     => $subjectId,
            'subject_label'  => mb_substr($h->label($subjectId), 0, 255),
            'changeset'      => $changes,
            'effective_from' => $this->effectiveFrom($h, $effectiveFrom),
            'status'         => ChangeProposal::PENDING,
            'direct'         => false,
            'maker_id'       => $maker->id,
            'note'           => $note,
        ]);
        $p->measures = $h->measure($p) + ['unit' => $h->unit()];

        return $p;
    }

    /** Effective-dated events take a date now or later; the others take effect on approval. */
    private function effectiveFrom(ProposalHandler $h, ?string $given): ?Carbon
    {
        if ($given === null || trim($given) === '') {
            return null;
        }
        if (!$h->effectiveDated()) {
            throw ValidationException::withMessages(['effective_from' => "A {$h->title()} change takes effect when it is approved; it has no effective date."]);
        }
        try {
            $from = Carbon::parse($given);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['effective_from' => 'Not a date.']);
        }
        if ($from->lt(now()->subMinute())) {
            throw ValidationException::withMessages(['effective_from' => 'A change takes effect now or later, never in the past.']);
        }

        return $from->startOfSecond();
    }

    private function qualifiesDirect(ProposalHandler $h, ChangeProposal $p, User $maker): bool
    {
        $event = $h->directEvent();
        if ($event === null || !($p->measures['direct_ok'] ?? false)) {
            return false;
        }
        $basis = $p->measures['direct_basis'] ?? null;
        $band  = $this->engine->thresholds()->bands($event)->first();
        if ($basis === null || $band === null || !$this->holds($maker, $band->approver_permission)) {
            return false;
        }

        return $band->up_to_kes === null || (float) $basis <= (float) $band->up_to_kes;
    }

    private function payload(ChangeProposal $p): array
    {
        return [
            'changes'        => $p->changeset,
            'effective_from' => $p->effective_from?->toIso8601String(),
        ];
    }

    public function openFor(string $event, string $subjectType, int $subjectId): ?ChangeProposal
    {
        return ChangeProposal::where('event', $event)->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)->whereIn('status', ChangeProposal::OPEN)->first();
    }

    // ── withdrawing, applying when due ──────────────────────────────────────

    /** The maker (or the owner) withdraws a waiting or scheduled change. The live value is untouched. */
    public function withdraw(ChangeProposal $p, User $by): ChangeProposal
    {
        if (!$p->isOpen()) {
            $this->fail(422, 'PROPOSAL_CLOSED', "This change is {$p->status}; there is nothing to withdraw.");
        }
        if ((int) $p->maker_id !== (int) $by->id && !$by->hasRole('super_admin', 'sanctum')) {
            $this->fail(403, 'NOT_THE_MAKER', 'Only the person who proposed this can withdraw it.');
        }

        DB::transaction(function () use ($p, $by) {
            if ($p->status === ChangeProposal::PENDING) {
                $this->engine->cancelOpen($p->event, $p, $by, 'Withdrawn by ' . ((int) $p->maker_id === (int) $by->id ? 'the maker' : 'the super admin'));
            }
            $p->update(['status' => ChangeProposal::CANCELLED]);
            ActivityLogService::log('proposal_withdrawn', $p, ['event' => $p->event, 'changes' => $p->changeset],
                "Change withdrawn: {$p->subject_label}", $by);
        });

        return $p->fresh();
    }

    /**
     * Write every signed change whose effective date has come. Never before
     * effective_from. Returns how many were applied; one that cannot be
     * written is reported and left scheduled, never half-applied.
     */
    public function applyDue(): int
    {
        $n = 0;
        ChangeProposal::where('status', ChangeProposal::SCHEDULED)
            ->where('effective_from', '<=', now())
            ->orderBy('effective_from')->orderBy('id')
            ->each(function (ChangeProposal $p) use (&$n) {
                try {
                    DB::transaction(function () use ($p, &$n) {
                        $locked = ChangeProposal::whereKey($p->id)->lockForUpdate()->first();
                        if (!$locked || $locked->status !== ChangeProposal::SCHEDULED || $locked->effective_from->isFuture()) {
                            return;
                        }
                        $request = ApprovalRequest::where('event', $locked->event)
                            ->where('approvable_type', $locked->getMorphClass())->where('approvable_id', $locked->id)
                            ->where('status', ApprovalRequest::APPROVED)->orderByDesc('version')->first();
                        $h   = $this->handler($locked->event);
                        $new = array_map(fn ($c) => $c['new'] ?? null, $request?->payload['changes'] ?? $locked->changeset);
                        $by  = $request?->decided_by ? User::find($request->decided_by) : null;

                        $h->apply($locked, $new, $by);
                        $locked->update(['status' => ChangeProposal::APPLIED, 'applied_at' => now(), 'applied_by' => $by?->id]);
                        ActivityLogService::log('proposal_applied', $locked, [
                            'event' => $locked->event, 'changes' => $locked->changeset, 'direct' => false,
                            'effective_from' => $locked->effective_from->toIso8601String(), 'approval_request_id' => $request?->id,
                        ], "{$h->title()} took effect as scheduled: {$locked->subject_label}", $by);
                        $n++;
                    });
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $n;
    }

    // ── reading ──────────────────────────────────────────────────────────────

    public function present(ChangeProposal $p, ?User $viewer = null): array
    {
        $h       = $this->engine->handlers()[$p->event] ?? null;
        $request = $this->engine->latestRequest($p->event, $p);
        $name    = fn (?User $u) => $u ? (trim("{$u->first_name} {$u->last_name}") ?: $u->email) : null;
        $label   = fn (array $b) => self::BAND_LABELS[$b['permission']] ?? $b['permission'];

        $awaiting = null;
        if ($request && $request->isPending()) {
            [$target] = $this->engine->effectiveBand($request, $p);
            $awaiting = $label($target);
        }

        return [
            'id'             => $p->id,
            'reference'      => 'CHG-' . $p->id,
            'event'          => $p->event,
            'title'          => $h?->title() ?? $p->event,
            'subject_type'   => $p->subject_type,
            'subject_id'     => $p->subject_id,
            'subject_label'  => $p->subject_label,
            'changes'        => collect($p->changeset)->map(fn ($c, $field) => [
                'field' => $field,
                'label' => $h?->fields()[$field] ?? $field,
                'old'   => $c['old'] ?? null,
                'new'   => $c['new'] ?? null,
                'old_display' => $h ? $h->formatValue($field, $c['old'] ?? null, $p) : (string) ($c['old'] ?? ''),
                'new_display' => $h ? $h->formatValue($field, $c['new'] ?? null, $p) : (string) ($c['new'] ?? ''),
            ])->values(),
            'measure'        => $h?->measureLine($p),
            'unit'           => $h?->unit(),
            'effective_from' => $p->effective_from?->toIso8601String(),
            'status'         => $p->status,
            'direct'         => $p->direct,
            'maker'          => $p->maker ? ['id' => $p->maker->id, 'name' => $name($p->maker)] : null,
            'note'           => $p->note,
            'applied_at'     => $p->applied_at?->toIso8601String(),
            'created_at'     => $p->created_at?->toIso8601String(),
            'request'        => $request ? [
                'id'              => $request->id,
                'version'         => $request->version,
                'status'          => $request->status,
                'needs'           => array_values(array_unique(array_map($label, $request->bands ?? []))),
                'awaiting'        => $awaiting,
                'rejected_reason' => $request->rejected_reason,
                'expires_at'      => $request->expires_at?->toIso8601String(),
            ] : null,
            'can_withdraw'   => $viewer && $p->isOpen()
                && ((int) $p->maker_id === (int) $viewer->id || $viewer->hasRole('super_admin', 'sanctum')),
        ];
    }

    /** What to tell the maker after a save. */
    public function message(ChangeProposal $p): string
    {
        $h = $this->handler($p->event);
        if ($p->status === ChangeProposal::APPLIED) {
            return "{$h->title()} changed (within your band).";
        }
        $request = $this->engine->latestRequest($p->event, $p);
        $needs   = array_values(array_unique(array_map(fn ($b) => self::BAND_LABELS[$b['permission']] ?? $b['permission'], $request?->bands ?? [])));

        return "{$h->title()} change sent for approval — it needs " . implode(', then ', $needs)
            . '. The current value stays until it is approved.';
    }

    /** Recent proposals for one subject (its history), newest first. */
    public function forSubject(string $subjectType, int $subjectId, ?array $events = null, int $limit = 50): Collection
    {
        return ChangeProposal::with('maker:id,first_name,last_name,email')
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->when($events, fn ($q) => $q->whereIn('event', $events))
            ->orderByDesc('id')->limit($limit)->get();
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], $status));
    }
}
