<?php

namespace App\Support;

use App\Models\ProductionTask;
use Illuminate\Support\Collection;

/**
 * THE progress of a production order — one figure for every surface.
 *
 * Production UI Cycle 1 (coherence): My Tasks showed "53%", the order page
 * header "33% complete" (finished stages ÷ stages) beside its own "6
 * finished" tile, and a tailor's view of the same order "0%" (her own stages
 * only). Progress is now pieces, counted across the WHOLE pipeline:
 *
 *   percent  = pieces passed across all stages ÷ (quantity × stages), rounded
 *   finished = pieces through the last stage
 *
 * Each stage's pieces come from the canonical rule ProductionTask::
 * effectivePassed (a completed / skipped stage has passed every piece), and
 * ProductionOrder::getCompletionPercentage() delegates here — one definition.
 * Stages are read past the viewer's own-task scope, so a tailor sees the
 * order's real progress, as numbers only, never another bench's task or person.
 */
final class OrderProgress
{
    /**
     * @param  iterable<\App\Models\ProductionOrder>  $orders  needs id + quantity
     * @return array<int, array{percent:int, finished:int, stages:int}|null>  keyed by order id
     */
    public static function for(iterable $orders): array
    {
        $orders = collect($orders)->filter()->keyBy('id');
        if ($orders->isEmpty()) return [];

        $pipelines = ProductionTask::withoutViewerScope()
            ->whereIn('production_order_id', $orders->keys())
            ->get(['production_order_id', 'sequence', 'status', 'quantity_done'])
            ->groupBy('production_order_id');

        return $orders->map(fn ($order, $id) => self::compute(
            max(1, (int) ($order->quantity ?? 1)),
            $pipelines[$id] ?? collect(),
        ))->all();
    }

    /** @param Collection<int, ProductionTask> $stages */
    private static function compute(int $qty, Collection $stages): ?array
    {
        if ($stages->isEmpty()) return null;

        $passed = fn (ProductionTask $t) => $t->effectivePassed($qty);
        // The last stage in the pipeline (by sequence; legacy tasks without
        // one sort first) holds the finished pieces.
        $last = $stages->sortBy(fn ($t) => $t->sequence ?? -1)->last();

        return [
            'percent'  => (int) round($stages->sum($passed) * 100 / ($qty * $stages->count())),
            'finished' => $passed($last),
            'stages'   => $stages->count(),
        ];
    }
}
