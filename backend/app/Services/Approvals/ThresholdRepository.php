<?php

namespace App\Services\Approvals;

use App\Models\ApprovalThreshold;
use App\Models\User;
use App\Services\ActivityLogService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ONE reader of approval_thresholds (Phase 3B).
 *
 * An event's bands are a set sharing one effective_from. The set in force at a
 * moment is the one with the latest effective_from at or before it — so a
 * change scheduled for tomorrow does nothing today, and history is never
 * edited: replacing a set inserts a new one.
 */
final class ThresholdRepository
{
    /** Events the engine knows. A threshold set can only be written for one of these. */
    public const EVENTS = [
        'purchase_order', 'stock_adjustment', 'serialized_write_off', 'expense',
        'imprest_topup', 'payment_void', 'payment_reassign', 'stock_transfer',
    ];

    /**
     * The bands in force for an event, in order.
     *
     * @return Collection<int, ApprovalThreshold>
     */
    public function bands(string $event, ?CarbonInterface $at = null): Collection
    {
        $at ??= now();

        $from = ApprovalThreshold::where('event', $event)
            ->where('effective_from', '<=', $at)
            ->max('effective_from');

        if ($from === null) {
            return collect();
        }

        return ApprovalThreshold::where('event', $event)
            ->where('effective_from', $from)
            ->orderBy('band_order')
            ->get();
    }

    /** Every event's set in force now, plus any set scheduled for later. */
    public function overview(): array
    {
        $out = [];
        foreach (self::EVENTS as $event) {
            $current = $this->bands($event);
            $scheduled = ApprovalThreshold::where('event', $event)
                ->where('effective_from', '>', now())
                ->orderBy('effective_from')->orderBy('band_order')
                ->get()
                ->groupBy(fn ($t) => $t->effective_from->toIso8601String())
                ->map(fn ($set, $from) => ['effective_from' => $from, 'bands' => $set->map(fn ($b) => $this->present($b))->values()])
                ->values();

            $out[] = [
                'event'          => $event,
                'effective_from' => $current->first()?->effective_from?->toIso8601String(),
                'bands'          => $current->map(fn ($b) => $this->present($b))->values(),
                'scheduled'      => $scheduled,
            ];
        }

        return $out;
    }

    /**
     * Put a new set in force for an event (super_admin only — the caller
     * checks; this writes and audits). Never retroactive: effective_from may
     * not be in the past.
     *
     * @param  list<array{up_to_kes: float|int|string|null, approver_permission: string}>  $bands
     * @return Collection<int, ApprovalThreshold>
     */
    public function replace(string $event, array $bands, User $by, ?CarbonInterface $effectiveFrom = null): Collection
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw ValidationException::withMessages(['event' => "Unknown approval event: {$event}."]);
        }
        $effectiveFrom ??= now();
        if ($effectiveFrom->lt(now()->subMinute())) {
            throw ValidationException::withMessages(['effective_from' => 'A threshold change takes effect now or later, never in the past.']);
        }
        if ($bands === []) {
            throw ValidationException::withMessages(['bands' => 'An event needs at least one band.']);
        }

        $known = DB::table(config('permission.table_names.permissions'))->where('guard_name', 'sanctum')->pluck('name')->all();
        $previous = null;
        foreach (array_values($bands) as $i => $band) {
            $last = $i === count($bands) - 1;
            $upTo = $band['up_to_kes'] ?? null;
            if (!in_array($band['approver_permission'] ?? '', $known, true)) {
                throw ValidationException::withMessages(["bands.{$i}.approver_permission" => 'Not a permission this system has.']);
            }
            if ($last && $upTo !== null) {
                throw ValidationException::withMessages(["bands.{$i}.up_to_kes" => 'The top band has no ceiling.']);
            }
            if (!$last && $upTo === null) {
                throw ValidationException::withMessages(["bands.{$i}.up_to_kes" => 'Only the top band may be open-ended.']);
            }
            if ($upTo !== null && ((float) $upTo < 0 || ($previous !== null && (float) $upTo <= $previous))) {
                throw ValidationException::withMessages(["bands.{$i}.up_to_kes" => 'Each band\'s ceiling must be above the one before it.']);
            }
            $previous = $upTo === null ? $previous : (float) $upTo;
        }

        // Stored to the second. Two sets for one event cannot share a moment,
        // so a change made in the same second as the last one lands a second later.
        $effectiveFrom = $effectiveFrom->copy()->startOfSecond();
        while (ApprovalThreshold::where('event', $event)->where('effective_from', $effectiveFrom)->exists()) {
            $effectiveFrom = $effectiveFrom->copy()->addSecond();
        }

        $before = $this->bands($event)->map(fn ($b) => $this->present($b))->values()->all();

        $rows = DB::transaction(function () use ($event, $bands, $by, $effectiveFrom) {
            $rows = collect();
            foreach (array_values($bands) as $i => $band) {
                $rows->push(ApprovalThreshold::create([
                    'event'               => $event,
                    'band_order'          => $i + 1,
                    'up_to_kes'           => $band['up_to_kes'] ?? null,
                    'approver_permission' => $band['approver_permission'],
                    'effective_from'      => $effectiveFrom,
                    'created_by'          => $by->id,
                ]));
            }

            return $rows;
        });

        ActivityLogService::log('approval_thresholds_changed', null, [
            'event'          => $event,
            'effective_from' => $effectiveFrom->toIso8601String(),
            'before'         => $before,
            'after'          => $rows->map(fn ($b) => $this->present($b))->values()->all(),
        ], "Approval thresholds changed: {$event} (effective {$effectiveFrom->toDateTimeString()})", $by);

        return $rows;
    }

    public function present(ApprovalThreshold $b): array
    {
        return [
            'band_order'          => $b->band_order,
            'up_to_kes'           => $b->up_to_kes === null ? null : (float) $b->up_to_kes,
            'approver_permission' => $b->approver_permission,
        ];
    }
}
