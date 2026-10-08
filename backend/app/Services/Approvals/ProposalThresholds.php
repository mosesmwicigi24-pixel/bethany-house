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
 * Reading and changing the Phase 3C threshold sets (approval_thresholds).
 *
 * ThresholdRepository (3B) reads any event's set, but its overview() and
 * replace() accept only the 3B events, and 3C must not edit it. This class
 * does the same two things for the 3C events with the same rules — a set per
 * effective_from, never retroactive, history never edited, super admin only
 * (the caller checks), audited as approval_thresholds_changed.
 *
 * Kept apart from ThresholdRepository::EVENTS on purpose: these events carry a
 * unit (percent / kes / none) and a maker's own "_direct" band, which is one
 * band WITH a ceiling — a shape 3B's validator rightly refuses for a signature
 * ladder. Reading still goes through ThresholdRepository::bands().
 */
final class ProposalThresholds
{
    /** event => unit of up_to_kes ('percent' | 'kes' | 'none'). */
    public const EVENTS = [
        'selling_price_change_direct' => 'percent',
        'selling_price_change'        => 'percent',
        'product_cost_change_direct'  => 'percent',
        'product_cost_change'         => 'percent',
        'supplier_cost_change_direct' => 'percent',
        'supplier_cost_change'        => 'percent',
        'customer_credit_direct'      => 'kes',
        'customer_credit'             => 'kes',
        'tax_rate_change'             => 'none',
        'reporting_fx_change'         => 'none',
        'payment_settlement_change'   => 'none',
        'customer_pricing_fx_change'  => 'none',
    ];

    public function __construct(private ThresholdRepository $repo) {}

    public function overview(): array
    {
        $out = [];
        foreach (self::EVENTS as $event => $unit) {
            $current = $this->repo->bands($event);
            $out[] = [
                'event'          => $event,
                'unit'           => $unit,
                'direct'         => str_ends_with($event, '_direct'),
                'effective_from' => $current->first()?->effective_from?->toIso8601String(),
                'bands'          => $current->map(fn ($b) => $this->repo->present($b))->values(),
                'scheduled'      => ApprovalThreshold::where('event', $event)->where('effective_from', '>', now())
                    ->orderBy('effective_from')->orderBy('band_order')->get()
                    ->groupBy(fn ($t) => $t->effective_from->toIso8601String())
                    ->map(fn ($set, $from) => ['effective_from' => $from, 'bands' => $set->map(fn ($b) => $this->repo->present($b))->values()])
                    ->values(),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{up_to_kes: float|int|string|null, approver_permission: string}>  $bands
     * @return Collection<int, ApprovalThreshold>
     */
    public function replace(string $event, array $bands, User $by, ?CarbonInterface $effectiveFrom = null): Collection
    {
        if (!array_key_exists($event, self::EVENTS)) {
            throw ValidationException::withMessages(['event' => "Unknown proposal event: {$event}."]);
        }
        $effectiveFrom ??= now();
        if ($effectiveFrom->lt(now()->subMinute())) {
            throw ValidationException::withMessages(['effective_from' => 'A threshold change takes effect now or later, never in the past.']);
        }
        if ($bands === []) {
            throw ValidationException::withMessages(['bands' => 'An event needs at least one band.']);
        }
        if (str_ends_with($event, '_direct') && count($bands) !== 1) {
            throw ValidationException::withMessages(['bands' => 'A maker\'s own band is one band.']);
        }

        $known = DB::table(config('permission.table_names.permissions'))->where('guard_name', 'sanctum')->pluck('name')->all();
        $previous = null;
        $direct   = str_ends_with($event, '_direct');
        foreach (array_values($bands) as $i => $band) {
            $last = $i === count($bands) - 1;
            $upTo = $band['up_to_kes'] ?? null;
            if (!in_array($band['approver_permission'] ?? '', $known, true)) {
                throw ValidationException::withMessages(["bands.{$i}.approver_permission" => 'Not a permission this system has.']);
            }
            // A direct band's ceiling IS its point; a signature ladder's top is open.
            if (!$direct && $last && $upTo !== null) {
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

        $effectiveFrom = $effectiveFrom->copy()->startOfSecond();
        while (ApprovalThreshold::where('event', $event)->where('effective_from', $effectiveFrom)->exists()) {
            $effectiveFrom = $effectiveFrom->copy()->addSecond();
        }

        $before = $this->repo->bands($event)->map(fn ($b) => $this->repo->present($b))->values()->all();

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
            'unit'           => self::EVENTS[$event],
            'effective_from' => $effectiveFrom->toIso8601String(),
            'before'         => $before,
            'after'          => $rows->map(fn ($b) => $this->repo->present($b))->values()->all(),
        ], "Approval thresholds changed: {$event} (effective {$effectiveFrom->toDateTimeString()})", $by);

        return $rows;
    }
}
