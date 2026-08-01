<?php

namespace App\Services\Production;

use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\ProductionWorkflow;
use App\Models\ProductionWorkflowStage;
use Illuminate\Support\Collection;

/**
 * Finds — or mints — the line an order actually runs.
 *
 * The legacy pipeline was one global list of stages, narrowed per product by
 * `product.production_stage_ids`. So two orders in this system already run
 * different lines: a cassock might go Cutting → Stitching → Finishing → QC
 * while a stole goes Cutting → Stitching. That is precisely what a workflow is,
 * and it is why a single seeded default is not enough.
 *
 * The order's own tasks are the ground truth. They were created from the
 * product's template in sequence order, so the ordered list of legacy stage ids
 * behind them *is* the line — no guessing, no second source of truth to drift.
 *
 * Workflows are then deduplicated by that signature: every order running
 * Cutting → Stitching shares one workflow row rather than minting a near-
 * duplicate each time, so the workflow list stays readable and comparing two
 * orders on the same line is a foreign-key comparison.
 *
 * Immutability is preserved. A workflow is only ever amended in place while no
 * batch is pinned to it. The moment one is, the workflow is frozen and a
 * changed line becomes a new version — an order halfway down the floor can
 * never have its stages re-sequenced under it.
 */
class WorkflowProvisioner
{
    /** Terminal stages exist on every line; they are structure, not stations. */
    private const ENTRY_SEQ = 10;
    private const STEP      = 10;
    private const SCRAP_SEQ = 9999;

    /**
     * The workflow this order's batches should pin, creating it if this
     * combination of stages has never been seen before.
     */
    public function forOrder(ProductionOrder $order): ProductionWorkflow
    {
        $stages = $this->lineStagesFor($order);

        // An order with no tasks has no line of its own; the seeded default is
        // the honest answer, and it already carries the standard garment line.
        if ($stages->isEmpty()) {
            return ProductionWorkflow::default()
                ?? $this->create(collect(), 'STANDARD', 'Standard production line');
        }

        $signature = $stages->pluck('id')->all();

        if ($existing = $this->findBySignature($signature)) {
            return $existing;
        }

        return $this->create(
            $stages,
            'AUTO_'.substr(md5(implode(',', $signature)), 0, 16),
            $this->nameFor($stages),
        );
    }

    /**
     * The legacy stages this order runs, in the order it runs them.
     *
     * `sequence` is what the task system gates on, so it is what orders the
     * line. Tasks without one sort last but keep a stable position rather than
     * disappearing — a missing sequence is a data problem, not a reason to
     * silently drop a station from the line.
     *
     * @return Collection<int, ProductionStage>
     */
    private function lineStagesFor(ProductionOrder $order): Collection
    {
        $ordered = $order->tasks()
            ->whereNotNull('production_stage_id')
            ->orderByRaw('COALESCE(sequence, 2147483647)')
            ->orderBy('id')
            ->pluck('production_stage_id')
            ->unique()
            ->values();

        if ($ordered->isEmpty()) {
            return collect();
        }

        $byId = ProductionStage::whereIn('id', $ordered)->get()->keyBy('id');

        return $ordered
            ->map(fn ($id) => $byId->get($id))
            ->filter()
            ->values();
    }

    /**
     * An active workflow whose working stages are exactly this sequence of
     * legacy stages. Terminals are ignored — every line has them.
     */
    private function findBySignature(array $signature): ?ProductionWorkflow
    {
        return ProductionWorkflow::active()
            ->with('stages')
            ->get()
            ->first(function (ProductionWorkflow $workflow) use ($signature) {
                $theirs = $workflow->stages
                    ->whereIn('kind', [ProductionWorkflowStage::KIND_WORK, ProductionWorkflowStage::KIND_INSPECT])
                    ->sortBy('seq')
                    ->pluck('production_stage_id')
                    ->filter()
                    ->values()
                    ->all();

                return $theirs === $signature;
            });
    }

    /**
     * Lay down a new line: entry queue, the working stations in order, then the
     * good and bad terminals.
     *
     * @param Collection<int, ProductionStage> $stages
     */
    private function create(Collection $stages, string $code, string $name): ProductionWorkflow
    {
        $workflow = ProductionWorkflow::create([
            'code'        => $code,
            'name'        => mb_substr($name, 0, 150),
            'description' => 'Provisioned from the stage template this order runs.',
            'version'     => $this->nextVersion($code),
            'is_active'   => true,
            'is_default'  => false,
        ]);

        $rows = [[
            'code' => 'NOT_CUT', 'name' => 'Not Cut', 'seq' => self::ENTRY_SEQ,
            'kind' => ProductionWorkflowStage::KIND_QUEUE,
            'allows_rework' => false, 'default_role' => 'cutter',
            'color' => '#94a3b8', 'production_stage_id' => null,
        ]];

        $seq = self::ENTRY_SEQ;

        foreach ($stages as $stage) {
            $seq += self::STEP;
            $isGate = $this->looksLikeQc($stage);

            $rows[] = [
                'code' => $this->codeFor($stage, $seq),
                'name' => $stage->name,
                'seq'  => $seq,
                'kind' => $isGate
                    ? ProductionWorkflowStage::KIND_INSPECT
                    : ProductionWorkflowStage::KIND_WORK,
                'allows_rework'       => $isGate,
                'default_role'        => $isGate ? 'supervisor' : null,
                'color'               => $stage->color,
                'production_stage_id' => $stage->id,
            ];
        }

        $rows[] = [
            'code' => 'FINISHED', 'name' => 'Finished', 'seq' => $seq + self::STEP,
            'kind' => ProductionWorkflowStage::KIND_DONE,
            'allows_rework' => false, 'default_role' => null,
            'color' => '#22c55e', 'production_stage_id' => null,
        ];
        $rows[] = [
            'code' => 'SCRAPPED', 'name' => 'Scrapped', 'seq' => self::SCRAP_SEQ,
            'kind' => ProductionWorkflowStage::KIND_SCRAP,
            'allows_rework' => false, 'default_role' => null,
            'color' => '#dc2626', 'production_stage_id' => null,
        ];

        foreach ($rows as $row) {
            $workflow->stages()->create($row);
        }

        return $workflow->load('stages');
    }

    private function nextVersion(string $code): int
    {
        return (int) ProductionWorkflow::where('code', $code)->max('version') + 1;
    }

    /** "Cutting → Stitching → QC", trimmed to something a person can read. */
    private function nameFor(Collection $stages): string
    {
        return $stages->pluck('name')->implode(' → ');
    }

    /** QC goes by many names on a shop floor; all of them mean "gate". */
    private function looksLikeQc(ProductionStage $stage): bool
    {
        $haystack = strtolower($stage->slug.' '.$stage->name);

        foreach (['quality', 'qc', 'inspect', 'check'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stage codes are unique per workflow. Slugs are unique globally, so they
     * normally suffice; the sequence is appended only if a collision somehow
     * remains, which keeps the common case readable.
     */
    private function codeFor(ProductionStage $stage, int $seq): string
    {
        $code = strtoupper(preg_replace('/[^a-z0-9]+/i', '_', (string) $stage->slug) ?? '');
        $code = trim($code, '_') ?: 'STAGE';
        $code = mb_substr($code, 0, 50);

        return in_array($code, ['NOT_CUT', 'FINISHED', 'SCRAPPED'], true)
            ? $code.'_'.$seq
            : $code;
    }
}
