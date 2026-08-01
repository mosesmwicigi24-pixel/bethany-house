<?php

namespace App\Services\Production;

use App\Models\ProductionOrder;
use App\Models\ProductionWorkflowStage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything one station's screen needs, in one round trip.
 *
 * A phone on workshop wi-fi cannot assemble a screen from six calls, so the
 * grouping and the sorting happen here: rework first because somebody is
 * waiting on a correction, then work that is ready ordered by how close its due
 * date is, then holds that need resolving, then what is coming down the line.
 * The client renders; it does not decide.
 *
 * Two things are derived from the ledger rather than stored, which is the point
 * of having a ledger at all:
 *
 * **The rework banner.** Pieces sent back carry the reason they were sent back,
 * because REWORK movements cannot exist without one. Reading the movements that
 * arrived since this stage last filled gives the current cohort — not every
 * rework this batch has ever suffered.
 *
 * **The handover note.** "Two panels cut slightly wide — trim at the side seam"
 * is just the note on the FORWARD movement that delivered the work, attributed
 * to whoever posted it. No new table, no separate messaging feature; the note
 * travels with the pieces because it was attached to the pieces moving.
 *
 * Visibility follows the existing floor rule (`scopeVisibleTo`): coordinators
 * see everything, a worker sees only orders she is actually part of. The
 * station screen must not become the one place that leaks the whole floor.
 */
class StationQueueService
{
    /** Hours in a shift, for turning a daily target into an hourly one. */
    private const SHIFT_HOURS = 8;

    public function __construct(private readonly ProductionReadModel $read) {}

    /**
     * @return array{station:array, shift:array, workflow:list<array>, groups:array}
     */
    public function forStation(string $stageCode, User $user, ?Carbon $now = null): array
    {
        $now       = $now ?? Carbon::now();
        $stageCode = strtoupper($stageCode);

        $stages = ProductionWorkflowStage::whereRaw('UPPER(code) = ?', [$stageCode])->get();

        if ($stages->isEmpty()) {
            return [
                'station'  => null,
                'shift'    => $this->emptyShift(),
                'workflow' => [],
                'groups'   => ['rework' => [], 'ready' => [], 'held' => [], 'upstream' => []],
            ];
        }

        $stageIds = $stages->pluck('id')->all();
        $rows     = $this->distributionRows($stageIds, $user);
        $jobs     = $this->buildJobs($rows, $stageCode, $now);

        // The same station code exists on every line that runs it — the seeded
        // default and each provisioned workflow all have a SEWING. Station
        // settings (target, icon, checklist) must come from the row this
        // tailor's work is actually standing on, not from whichever was created
        // first, or a supervisor's configuration would silently apply to a line
        // nobody is working.
        $inUse  = $rows->firstWhere('stage_code', $stageCode);
        $sample = ($inUse ? $stages->firstWhere('id', (int) $inUse->stage_id) : null)
            ?? $stages->first();

        $workflow = $this->workflowOf($rows, $sample);

        return [
            'station'  => $this->station($sample, $workflow, $stageCode),
            'shift'    => $this->shift($stageIds, $sample, $user, $now),
            'workflow' => $workflow,
            'groups'   => $this->group($jobs),
        ];
    }

    // ------------------------------------------------------------------
    // Loading
    // ------------------------------------------------------------------

    /**
     * Every stage row of every live batch whose workflow includes this station
     * and whose order this user may see. One query; grouping happens in memory
     * because a batch is a handful of rows.
     */
    private function distributionRows(array $stageIds, User $user): Collection
    {
        $visibleOrderIds = ProductionOrder::visibleTo($user)
            ->whereIn('status', ['pending', 'in_progress', 'on_hold', 'qc_pending', 'qc_passed', 'qc_failed'])
            ->pluck('id');

        if ($visibleOrderIds->isEmpty()) {
            return collect();
        }

        // Batches that run a workflow containing this station.
        $batchIds = DB::table('production_order_batches as b')
            ->join('production_workflow_stages as st', 'st.production_workflow_id', '=', 'b.production_workflow_id')
            ->whereIn('b.production_order_id', $visibleOrderIds)
            ->whereIn('st.id', $stageIds)
            ->distinct()
            ->pluck('b.id');

        if ($batchIds->isEmpty()) {
            return collect();
        }

        return collect(DB::table('v_batch_stage_distribution as d')
            ->join('production_orders as o', 'o.id', '=', 'd.production_order_id')
            ->whereIn('d.batch_id', $batchIds)
            ->orderBy('d.batch_id')->orderBy('d.seq')
            ->get([
                'd.*',
                'o.order_number', 'o.priority', 'o.due_date', 'o.specifications',
                'o.customer_id', 'o.customer_order_id', 'o.product_id',
            ]));
    }

    /**
     * The line itself, so the rail is never hard-coded client-side. Taken from
     * the rows when present, and from the station's own workflow otherwise, so
     * an empty queue still renders a line.
     */
    private function workflowOf(Collection $rows, ProductionWorkflowStage $sample): array
    {
        // Distribution rows name the columns `stage_code`/`stage_name`; the
        // stage table names them `code`/`name`. Normalise rather than making the
        // caller care which source it got.
        $stages = $rows->isNotEmpty()
            ? $rows->where('batch_id', $rows->first()->batch_id)
                ->map(fn ($s) => (object) (['code' => $s->stage_code, 'name' => $s->stage_name] + (array) $s))
            : ProductionWorkflowStage::where('production_workflow_id', $sample->production_workflow_id)
                ->orderBy('seq')->get();

        return $stages->sortBy('seq')->values()->map(fn ($s) => [
            'code'      => $s->code,
            'name'      => $s->name,
            'seq'       => (int) $s->seq,
            'kind'      => $s->kind,
            'icon'      => $s->icon ?? null,
            'color'     => $s->color ?? null,
            'wip_limit' => isset($s->wip_limit) && $s->wip_limit !== null ? (int) $s->wip_limit : null,
        ])->all();
    }

    // ------------------------------------------------------------------
    // Jobs
    // ------------------------------------------------------------------

    private function buildJobs(Collection $rows, string $stageCode, Carbon $now): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $batches = $rows->groupBy('batch_id');

        // Resolve customer and garment names for every order in one pass rather
        // than per batch — the identity chain touches three tables.
        $orders = ProductionOrder::with([
            'product.translations' => fn ($q) => $q->where('language_code', 'en')->select('product_id', 'name'),
            'product:id,sku',
            'customer:id,first_name,last_name,phone',
            'customerOrder:id,order_number,customer_id,customer_first_name,customer_last_name,customer_phone',
            'customerOrder.customer:id,first_name,last_name,phone',
        ])
            ->whereIn('id', $rows->pluck('production_order_id')->unique())
            ->get()
            ->keyBy('id');

        $batchMeta = DB::table('production_order_batches')
            ->whereIn('id', $batches->keys())
            ->get(['id', 'attributes', 'images'])
            ->keyBy('id');

        return $batches->map(function (Collection $stages, $batchId) use ($orders, $stageCode, $now, $batchMeta) {
            $first = $stages->first();
            $mine  = $stages->firstWhere('stage_code', $stageCode);

            if (!$mine) {
                return null;
            }

            $order = $orders->get($first->production_order_id);
            $meta  = $batchMeta->get($batchId);
            $due   = $first->due_at ?? $order?->due_date;

            return [
                'batch_id'      => (int) $batchId,
                'order_id'      => (int) $first->production_order_id,
                'order_ref'     => $first->order_number,
                'customer_name' => $order?->customer_label,
                'garment'       => $this->garmentOf($order),
                'batch_label'   => $first->batch_label,
                'swatch_hex'    => $this->swatchOf($meta),
                'ordered_qty'   => (int) $first->ordered_qty,
                'loaded_qty'    => (int) $first->loaded_qty,
                'due_at'        => $due,
                'days_left'     => $due ? (int) $now->copy()->startOfDay()->diffInDays(Carbon::parse($due)->startOfDay(), false) : null,
                'priority'      => $first->priority,

                'distribution'  => $stages->mapWithKeys(fn ($s) => [$s->stage_code => (int) $s->qty_total])->all(),
                'held'          => $stages->filter(fn ($s) => (int) $s->qty_held > 0)
                                          ->mapWithKeys(fn ($s) => [$s->stage_code => (int) $s->qty_held])->all(),

                'my_stage' => [
                    'stage_id'  => (int) $mine->stage_id,
                    'code'      => $mine->stage_code,
                    'name'      => $mine->stage_name,
                    'kind'      => $mine->kind,
                    'qty_ready' => (int) $mine->qty_ready,
                    'qty_held'  => (int) $mine->qty_held,
                ],

                'rework'        => $this->reworkInto($batchId, $mine),
                'hold'          => $this->holdAt($batchId, $mine),
                'handover_note' => $this->handoverInto($batchId, $mine),

                'spec'            => $this->specOf($first->specifications),
                'checklist'       => $this->checklistOf($mine),
                'spec_image_url'  => $this->imageOf($meta, $order),
            ];
        })->filter()->values();
    }

    /**
     * Pieces sent back to this station, and why.
     *
     * Only the current cohort counts — movements that arrived since this stage
     * last filled up. Without that bound a batch reworked once in June would
     * still be shouting about it in August.
     */
    private function reworkInto($batchId, object $stage): ?array
    {
        if ((int) $stage->qty_ready + (int) $stage->qty_held === 0) {
            return null;
        }

        $since = $stage->entered_at;

        $movement = DB::table('piece_movements as m')
            ->leftJoin('movement_reasons as r', 'r.code', '=', 'm.reason_code')
            ->leftJoin('production_workflow_stages as fs', 'fs.id', '=', 'm.from_stage_id')
            ->where('m.production_order_batch_id', $batchId)
            ->where('m.to_stage_id', $stage->stage_id)
            ->where('m.movement_type', 'REWORK')
            ->when($since, fn ($q) => $q->where('m.moved_at', '>=', $since))
            ->orderByDesc('m.moved_at')->orderByDesc('m.id')
            ->first(['m.quantity', 'm.moved_at', 'r.label', 'r.label_sw', 'fs.name as from_stage']);

        if (!$movement) {
            return null;
        }

        return [
            'qty'          => min((int) $movement->quantity, (int) $stage->qty_ready + (int) $stage->qty_held),
            'reason_label' => $movement->label,
            'reason_sw'    => $movement->label_sw,
            'from_stage'   => $movement->from_stage,
            'at'           => $movement->moved_at,
        ];
    }

    /** Why work is stopped here, taken from the movement that stopped it. */
    private function holdAt($batchId, object $stage): ?array
    {
        if ((int) $stage->qty_held === 0) {
            return null;
        }

        $movement = DB::table('piece_movements as m')
            ->leftJoin('movement_reasons as r', 'r.code', '=', 'm.reason_code')
            ->where('m.production_order_batch_id', $batchId)
            ->where('m.to_stage_id', $stage->stage_id)
            ->where('m.movement_type', 'HOLD')
            ->orderByDesc('m.moved_at')->orderByDesc('m.id')
            ->first(['m.quantity', 'm.moved_at', 'r.code', 'r.label', 'r.label_sw']);

        return [
            'qty'          => (int) $stage->qty_held,
            'reason_code'  => $movement->code ?? null,
            'reason_label' => $movement->label ?? null,
            'reason_sw'    => $movement->label_sw ?? null,
            'at'           => $movement->moved_at ?? null,
        ];
    }

    /**
     * The message that came with the work.
     *
     * It is simply the note on the FORWARD movement that delivered this cohort —
     * the note travels with the pieces because it was attached to the pieces
     * moving, which is why there is no separate messaging feature to keep in
     * sync with where the garments actually are.
     */
    private function handoverInto($batchId, object $stage): ?array
    {
        if ((int) $stage->qty_ready + (int) $stage->qty_held === 0) {
            return null;
        }

        $movement = DB::table('piece_movements as m')
            ->join('users as u', 'u.id', '=', 'm.moved_by')
            ->leftJoin('production_workflow_stages as fs', 'fs.id', '=', 'm.from_stage_id')
            ->where('m.production_order_batch_id', $batchId)
            ->where('m.to_stage_id', $stage->stage_id)
            ->where('m.movement_type', 'FORWARD')
            ->whereNotNull('m.note')
            // Notes the engine writes for itself are plumbing, not a handover.
            ->where('m.note', 'not like', '%stage counter%')
            ->where('m.note', 'not like', 'Migrated%')
            ->when($stage->entered_at, fn ($q) => $q->where('m.moved_at', '>=', $stage->entered_at))
            ->orderByDesc('m.moved_at')->orderByDesc('m.id')
            ->first(['m.note', 'm.moved_at', 'u.first_name', 'u.last_name', 'fs.name as from_stage']);

        if (!$movement) {
            return null;
        }

        return [
            'from_stage' => $movement->from_stage,
            'by_name'    => trim("{$movement->first_name} {$movement->last_name}") ?: null,
            'text'       => $movement->note,
            'at'         => $movement->moved_at,
        ];
    }

    // ------------------------------------------------------------------
    // Grouping
    // ------------------------------------------------------------------

    /**
     * Rework first — somebody is waiting on a correction. Then work that can be
     * done, soonest due first. Then holds, which need a decision rather than a
     * needle. Then what is coming, so an idle station is told why instead of
     * being shown a blank screen.
     */
    private function group(Collection $jobs): array
    {
        $groups = ['rework' => [], 'ready' => [], 'held' => [], 'upstream' => []];

        foreach ($jobs as $job) {
            $ready = $job['my_stage']['qty_ready'];
            $held  = $job['my_stage']['qty_held'];

            if ($ready > 0 && $job['rework']) {
                $groups['rework'][] = $job;
            } elseif ($ready > 0) {
                $groups['ready'][] = $job;
            } elseif ($held > 0) {
                $groups['held'][] = $job;
            } elseif ($this->hasUpstreamWork($job)) {
                $groups['upstream'][] = $job;
            }
        }

        $soonest = function (array $a, array $b) {
            $x = $a['days_left'] ?? PHP_INT_MAX;
            $y = $b['days_left'] ?? PHP_INT_MAX;

            return $x <=> $y;
        };

        usort($groups['rework'], $soonest);
        usort($groups['ready'], $soonest);
        usort($groups['upstream'], $soonest);

        return $groups;
    }

    private function hasUpstreamWork(array $job): bool
    {
        $mine = $job['my_stage']['code'];
        $seen = false;

        foreach ($job['distribution'] as $code => $qty) {
            if ($code === $mine) {
                break;
            }
            if ($qty > 0) {
                $seen = true;
            }
        }

        return $seen;
    }

    // ------------------------------------------------------------------
    // Station and shift
    // ------------------------------------------------------------------

    private function station(ProductionWorkflowStage $stage, array $workflow, string $stageCode): array
    {
        $line = array_values(array_filter($workflow, fn ($s) => $s['kind'] !== ProductionWorkflowStage::KIND_SCRAP));
        $i    = null;

        foreach ($line as $idx => $s) {
            if (strtoupper($s['code']) === $stageCode) {
                $i = $idx;
                break;
            }
        }

        $next = $i !== null && $i < count($line) - 1 ? $line[$i + 1] : null;

        return [
            'stage_code'      => $stage->code,
            'stage_name'      => $stage->name,
            'kind'            => $stage->kind,
            'icon'            => $stage->icon,
            'color'           => $stage->color,
            'next_stage_code' => $next['code'] ?? null,
            'next_stage_name' => $next['name'] ?? null,
            // What this station is allowed to offer. Driven by stage kind, so a
            // new station type gets the right buttons without a new screen.
            'can_hold'   => !in_array($stage->kind, [ProductionWorkflowStage::KIND_DONE, ProductionWorkflowStage::KIND_SCRAP], true),
            'can_rework' => (bool) $stage->allows_rework,
            'can_scrap'  => $stage->kind === ProductionWorkflowStage::KIND_WORK,
            'can_intake' => $i === 1 || $stage->kind === ProductionWorkflowStage::KIND_QUEUE,
        ];
    }

    /**
     * Her numbers, not a league table.
     *
     * The ledger stamps every movement with who made it, which is what settles a
     * wage or count dispute — that is the benefit worth leading with. Published
     * rankings are what make people log dishonestly, so a tailor sees her own
     * totals and nobody else's.
     */
    private function shift(array $stageIds, ProductionWorkflowStage $stage, User $user, Carbon $now): array
    {
        $moved = fn (Carbon $from) => (int) DB::table('piece_movements')
            ->where('moved_by', $user->id)
            ->whereIn('from_stage_id', $stageIds)
            ->where('movement_type', 'FORWARD')
            ->where('moved_at', '>=', $from)
            ->sum('quantity');

        $target = $stage->daily_target !== null ? (int) $stage->daily_target : null;

        return [
            'moved_today'     => $moved($now->copy()->startOfDay()),
            'target'          => $target,
            'moved_this_hour' => $moved($now->copy()->startOfHour()),
            // A daily figure only tells you at 5pm. An hourly one tells you at
            // 11am, while the shift can still be changed.
            'hour_target'     => $target !== null ? (int) max(1, round($target / self::SHIFT_HOURS)) : null,
        ];
    }

    private function emptyShift(): array
    {
        return ['moved_today' => 0, 'target' => null, 'moved_this_hour' => 0, 'hour_target' => null];
    }

    // ------------------------------------------------------------------
    // Small resolvers
    // ------------------------------------------------------------------

    private function garmentOf(?ProductionOrder $order): ?string
    {
        return $order?->product?->translations?->first()?->name
            ?? $order?->product?->sku;
    }

    /** A colour the tailor can recognise across the room, if one was recorded. */
    private function swatchOf(?object $meta): ?string
    {
        $attributes = $meta && $meta->attributes ? json_decode($meta->attributes, true) : null;

        if (!is_array($attributes)) {
            return null;
        }

        foreach (['swatch_hex', 'swatch', 'hex', 'colour', 'color'] as $key) {
            $value = $attributes[$key] ?? null;

            if (is_string($value) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value)) {
                return $value;
            }
        }

        return null;
    }

    private function imageOf(?object $meta, ?ProductionOrder $order): ?string
    {
        $images = $meta && $meta->images ? json_decode($meta->images, true) : null;

        if (is_array($images) && !empty($images[0])) {
            return is_string($images[0]) ? $images[0] : ($images[0]['url'] ?? null);
        }

        return null;
    }

    /**
     * What she is making, as label/value rows.
     *
     * `specifications` is free-form JSON that has been written by several
     * different callers over the life of this system, so both shapes it has
     * taken in the wild are accepted rather than trusting one.
     *
     * @return list<array{label:string, value:string}>
     */
    private function specOf($raw): array
    {
        $spec = is_string($raw) ? json_decode($raw, true) : $raw;

        if (!is_array($spec)) {
            return [];
        }

        $out = [];

        foreach ($spec as $key => $value) {
            if (is_array($value)) {
                $label = $value['label'] ?? (is_string($key) ? $key : null);
                $val   = $value['value'] ?? null;
            } else {
                $label = is_string($key) ? $key : null;
                $val   = $value;
            }

            if ($label === null || $val === null || $val === '') {
                continue;
            }

            $out[] = [
                'label' => ucfirst(str_replace('_', ' ', (string) $label)),
                'value' => is_scalar($val) ? (string) $val : json_encode($val),
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private function checklistOf(object $stage): array
    {
        $list = $stage->checklist ?? null;
        $list = is_string($list) ? json_decode($list, true) : $list;

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
