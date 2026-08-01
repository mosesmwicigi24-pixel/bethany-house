<?php

namespace App\Services\Production;

use App\Models\ProductionWorkflowStage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the card, the worker sheet and the manager view need, derived from
 * the stage distribution and nothing else.
 *
 * No writes, no side effects. Feed it rows from `v_batch_stage_distribution`
 * and it produces the headline numbers, the chips, the move sheet and the four
 * judgements a line manager makes by walking the floor.
 */
class ProductionReadModel
{
    /** A stage is "stalled" when nothing has left it in this many hours. */
    public const STALLED_AFTER_HOURS = 8;

    // ------------------------------------------------------------------
    // Loading
    // ------------------------------------------------------------------

    /**
     * Stage rows for one order, grouped into batches.
     *
     * @return Collection<int, array>
     */
    public function batchesForOrder(int $orderId): Collection
    {
        return DB::table('v_batch_stage_distribution')
            ->where('production_order_id', $orderId)
            ->orderBy('batch_id')
            ->orderBy('seq')
            ->get()
            ->groupBy('batch_id')
            ->map(fn (Collection $rows) => [
                'batch_id'    => (int) $rows->first()->batch_id,
                'label'       => $rows->first()->batch_label,
                'ordered_qty' => (int) $rows->first()->ordered_qty,
                'loaded_qty'  => (int) $rows->first()->loaded_qty,
                'due_at'      => $rows->first()->due_at,
                'stages'      => $rows->map(fn ($r) => $this->stageRow($r))->values()->all(),
            ])
            ->values();
    }

    private function stageRow(object $r): array
    {
        return [
            'stage_id'       => (int) $r->stage_id,
            'stage_code'     => $r->stage_code,
            'stage_name'     => $r->stage_name,
            'seq'            => (int) $r->seq,
            'kind'           => $r->kind,
            'wip_limit'      => $r->wip_limit !== null ? (int) $r->wip_limit : null,
            'target_minutes' => $r->target_minutes !== null ? (int) $r->target_minutes : null,
            'allows_rework'  => (bool) $r->allows_rework,
            'color'          => $r->color,
            'qty_ready'      => (int) $r->qty_ready,
            'qty_held'       => (int) $r->qty_held,
            'qty_total'      => (int) $r->qty_total,
            'entered_at'     => $r->entered_at,
            'last_out_at'    => $r->last_out_at,
        ];
    }

    // ------------------------------------------------------------------
    // Headline numbers
    // ------------------------------------------------------------------

    /**
     * "100 Total · 70 Not Started · 24 In Production · 6 Finished".
     *
     * `deliverable` is what can still be shipped from what was loaded;
     * `shortfall` is how many must be re-cut to fulfil the order. A 100-piece
     * order that scraps 3 shows a shortfall of 3 until somebody loads
     * replacements, so nobody discovers the gap at packing time.
     */
    public function snapshot(array $stages, int $orderedQty): array
    {
        $sum = fn (callable $filter, string $key = 'qty_total') => array_sum(
            array_map(fn ($s) => $filter($s) ? $s[$key] : 0, $stages)
        );

        $total       = $sum(fn () => true);
        $notStarted  = $sum(fn ($s) => $s['kind'] === ProductionWorkflowStage::KIND_QUEUE);
        $inProd      = $sum(fn ($s) => in_array($s['kind'], [
            ProductionWorkflowStage::KIND_WORK,
            ProductionWorkflowStage::KIND_INSPECT,
        ], true));
        $finished    = $sum(fn ($s) => $s['kind'] === ProductionWorkflowStage::KIND_DONE);
        $scrapped    = $sum(fn ($s) => $s['kind'] === ProductionWorkflowStage::KIND_SCRAP);
        $held        = $sum(fn () => true, 'qty_held');
        $deliverable = $total - $scrapped;

        return [
            'total'            => $total,
            'ordered'          => $orderedQty,
            'not_started'      => $notStarted,
            'in_production'    => $inProd,
            'finished'         => $finished,
            'scrapped'         => $scrapped,
            'held'             => $held,
            'deliverable'      => $deliverable,
            'shortfall'        => max(0, $orderedQty - $deliverable),
            'percent_complete' => $orderedQty > 0 ? (int) round($finished / $orderedQty * 100) : 0,
        ];
    }

    /** Roll several batches into one order-level snapshot. */
    public function snapshotOfOrder(Collection $batches): array
    {
        $parts = $batches->map(fn ($b) => $this->snapshot($b['stages'], $b['ordered_qty']));

        $add = fn (string $key) => (int) $parts->sum($key);

        $ordered     = $add('ordered');
        $finished    = $add('finished');
        $deliverable = $add('deliverable');

        return [
            'total'            => $add('total'),
            'ordered'          => $ordered,
            'not_started'      => $add('not_started'),
            'in_production'    => $add('in_production'),
            'finished'         => $finished,
            'scrapped'         => $add('scrapped'),
            'held'             => $add('held'),
            'deliverable'      => $deliverable,
            'shortfall'        => max(0, $ordered - $deliverable),
            'percent_complete' => $ordered > 0 ? (int) round($finished / $ordered * 100) : 0,
        ];
    }

    /**
     * "Not Cut 70 · Cutting 10 · Sewing 2 · QC 2 · Finished 6".
     * Scrap only appears when it is non-zero — visible when it matters, not
     * noise when it does not.
     */
    public function distributionChips(array $stages, bool $hideEmpty = false): array
    {
        return array_values(array_map(
            fn ($s) => [
                'stage_id' => $s['stage_id'],
                'label'    => $s['stage_name'],
                'qty'      => $s['qty_total'],
                'held'     => $s['qty_held'],
                'color'    => $s['color'],
                'over_wip' => $s['wip_limit'] !== null && $s['qty_total'] > $s['wip_limit'],
                'dimmed'   => $s['qty_total'] === 0,
            ],
            array_filter(
                $stages,
                fn ($s) => ($s['kind'] !== ProductionWorkflowStage::KIND_SCRAP || $s['qty_total'] > 0)
                    && (!$hideEmpty || $s['qty_total'] > 0),
            )
        ));
    }

    // ------------------------------------------------------------------
    // Worker move sheet
    // ------------------------------------------------------------------

    /**
     * The 1 · 5 · 10 · Custom sheet.
     *
     * Chips a worker cannot honour are never offered: a live "10" on a stage
     * holding 6 is how impossible totals get typed in. Only READY pieces are
     * movable, which is why a stage whose work is entirely on hold says so
     * rather than looking simply empty.
     */
    public function moveSheet(array $stages, int $stageId, array $presets = [1, 5, 10]): array
    {
        $line = array_values(array_filter(
            $stages,
            fn ($s) => $s['kind'] !== ProductionWorkflowStage::KIND_SCRAP,
        ));
        usort($line, fn ($a, $b) => $a['seq'] <=> $b['seq']);

        $i       = null;
        foreach ($line as $idx => $s) {
            if ($s['stage_id'] === $stageId) {
                $i = $idx;
                break;
            }
        }

        $current = $i !== null ? $line[$i] : null;
        $next    = $i !== null && $i < count($line) - 1 ? $line[$i + 1] : null;

        $available = $current['qty_ready'] ?? 0;
        $held      = $current['qty_held'] ?? 0;

        $blocked = match (true) {
            !$current => 'Stage not found in this workflow.',
            $current['kind'] === ProductionWorkflowStage::KIND_DONE => 'These pieces are finished.',
            !$next => 'No next stage.',
            $available === 0 && $held > 0 => "All {$held} piece(s) here are on hold. Release them first.",
            $available === 0 => 'Nothing at this stage yet.',
            default => null,
        };

        $chips = array_map(fn ($v) => [
            'value'   => $v,
            'enabled' => $blocked === null && $v <= $available,
            'label'   => (string) $v,
        ], $presets);

        $allChip = ($blocked === null && $available > 0 && !in_array($available, $presets, true))
            ? ['value' => $available, 'enabled' => true, 'label' => "All {$available}"]
            : null;

        return [
            'stage_id'        => $stageId,
            'stage_name'      => $current['stage_name'] ?? '',
            'available'       => $available,
            'held'            => $held,
            'next_stage_id'   => $next['stage_id'] ?? null,
            'next_stage_name' => $next['stage_name'] ?? null,
            'chips'           => $chips,
            'custom_min'      => $available > 0 ? 1 : 0,
            'custom_max'      => $available,
            'can_move'        => $blocked === null,
            'blocked_reason'  => $blocked,
            'all_chip'        => $allChip,
        ];
    }

    /** Clamp anything typed into Custom. Never let it post out of range. */
    public function clampMove(mixed $n, int $available): int
    {
        $v = (int) floor((float) $n);

        return $v < 1 ? 0 : min($v, $available);
    }

    // ------------------------------------------------------------------
    // Line-manager intelligence
    // ------------------------------------------------------------------

    /**
     * The things a line manager notices walking the floor:
     *
     *   HELD       — work is stopped for a reason somebody must resolve
     *   OVER_WIP   — a station is above its declared limit
     *   STALLED    — pieces are sitting and nothing has left in hours
     *   BOTTLENECK — work is piling up faster than it drains
     *   STARVED    — a downstream station is idle while upstream is loaded
     */
    public function stageAlerts(array $stages, ?Carbon $now = null): array
    {
        $now  = $now ?? Carbon::now();
        $line = array_values(array_filter(
            $stages,
            fn ($s) => $s['kind'] !== ProductionWorkflowStage::KIND_SCRAP,
        ));
        usort($line, fn ($a, $b) => $a['seq'] <=> $b['seq']);

        $alerts = [];

        $work = array_values(array_filter($line, fn ($s) => in_array($s['kind'], [
            ProductionWorkflowStage::KIND_WORK,
            ProductionWorkflowStage::KIND_INSPECT,
        ], true)));

        $busiest = null;
        foreach ($work as $s) {
            if (!$busiest || $s['qty_total'] > $busiest['qty_total']) {
                $busiest = $s;
            }
        }

        foreach ($line as $s) {
            if ($s['wip_limit'] !== null && $s['qty_total'] > $s['wip_limit']) {
                $alerts[] = $this->alert($s, 'warn', 'OVER_WIP',
                    "{$s['stage_name']} is holding {$s['qty_total']} against a limit of {$s['wip_limit']}.");
            }

            if ($s['qty_held'] > 0) {
                $alerts[] = $this->alert($s, 'critical', 'HELD',
                    "{$s['qty_held']} piece(s) stopped at {$s['stage_name']}.");
            }

            $idleFrom = $s['last_out_at'] ?? $s['entered_at'] ?? null;

            if ($s['qty_total'] > 0
                && !in_array($s['kind'], [ProductionWorkflowStage::KIND_DONE, ProductionWorkflowStage::KIND_QUEUE], true)
                && $idleFrom !== null) {
                $hours = Carbon::parse($idleFrom)->diffInHours($now);

                if ($hours >= self::STALLED_AFTER_HOURS) {
                    $alerts[] = $this->alert($s, 'warn', 'STALLED',
                        "Nothing has left {$s['stage_name']} in ".round($hours).'h.');
                }
            }
        }

        if ($busiest && $busiest['qty_total'] >= 2) {
            $others = array_values(array_filter($work, fn ($s) => $s['stage_id'] !== $busiest['stage_id']));
            $avg    = count($others) ? array_sum(array_column($others, 'qty_total')) / count($others) : 0;

            if ($busiest['qty_total'] > max(3, $avg * 2)) {
                $alerts[] = $this->alert($busiest, 'warn', 'BOTTLENECK',
                    "{$busiest['stage_name']} is the constraint — {$busiest['qty_total']} piece(s) queued behind it.");
            }

            foreach ($work as $s) {
                if ($s['qty_total'] === 0 && $s['seq'] > $busiest['seq']) {
                    $alerts[] = $this->alert($s, 'info', 'STARVED',
                        "{$s['stage_name']} is idle while {$busiest['stage_name']} is loaded.");
                }
            }
        }

        return $alerts;
    }

    private function alert(array $stage, string $severity, string $kind, string $message): array
    {
        return [
            'stage_id'   => $stage['stage_id'],
            'stage_name' => $stage['stage_name'],
            'severity'   => $severity,
            'kind'       => $kind,
            'message'    => $message,
        ];
    }

    /**
     * For each piece, the standard minutes of every stage it has not yet
     * passed. This is what tells you on Tuesday whether Friday is real.
     */
    public function remainingStandardMinutes(array $stages): int
    {
        $line = array_values(array_filter($stages, fn ($s) => !in_array($s['kind'], [
            ProductionWorkflowStage::KIND_SCRAP,
            ProductionWorkflowStage::KIND_DONE,
        ], true)));
        usort($line, fn ($a, $b) => $a['seq'] <=> $b['seq']);

        $total = 0;

        foreach ($line as $i => $stage) {
            $downstream = array_sum(array_map(
                fn ($s) => $s['target_minutes'] ?? 0,
                array_slice($line, $i),
            ));
            $total += $stage['qty_total'] * $downstream;
        }

        return $total;
    }

    /** Is the due date real? Remaining standard minutes against daily capacity. */
    public function completionRisk(
        array $stages,
        ?string $dueAt,
        float $capacityMinutesPerDay,
        ?Carbon $now = null,
    ): ?array {
        if (!$dueAt || $capacityMinutesPerDay <= 0) {
            return null;
        }

        $now           = $now ?? Carbon::now();
        $requiredDays  = $this->remainingStandardMinutes($stages) / $capacityMinutesPerDay;
        $availableDays = $now->diffInDays(Carbon::parse($dueAt), false);

        return [
            'required_days'  => round($requiredDays, 2),
            'available_days' => $availableDays,
            'at_risk'        => $requiredDays > $availableDays,
        ];
    }
}
