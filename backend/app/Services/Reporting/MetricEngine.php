<?php

namespace App\Services\Reporting;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * MetricEngine — the single place canonical business metrics are computed.
 *
 * docs/REPORTS_SPEC.md is the contract this class implements:
 *
 *  - THREE TRUTHS, NEVER MIXED. Sales truth reads orders (non-voided,
 *    non-cancelled). Money truth reads settled payments net of refunds.
 *    Drawer truth stays in the registers module. Every metric here belongs
 *    to exactly one truth and says so in its name.
 *  - ONE CLOCK. app.timezone is Africa/Nairobi, so every DB timestamp is
 *    already stored in Nairobi wall-clock time. Period boundaries therefore
 *    stay in Nairobi time end to end — converting to UTC here would shift
 *    every window three hours early (a bug CI caught on day one).
 *  - SCOPE IN THE QUERY. A non-admin with assigned outlets has every query
 *    filtered to those outlets — this engine will not return a row the
 *    caller isn't allowed to aggregate.
 *  - DERIVED, NEVER STORED. Everything is computed from source rows at
 *    read time; this class writes nothing.
 *
 * Money sums are KES-only by design for now: adding KES to USD produces a
 * number that means nothing. Multi-currency consolidation is a later phase
 * (exchange-rate table + report-time conversion), not a silent SUM.
 */
class MetricEngine
{
    private const TZ = 'Africa/Nairobi';

    /** Sales truth: an order that exists commercially. */
    private const SALE_EXCLUDED_STATUSES = ['voided', 'cancelled'];

    /** @var int[]|null Outlet ids the caller may see; null = unrestricted. */
    private ?array $outletIds;

    private function __construct(?array $outletIds)
    {
        $this->outletIds = $outletIds;
    }

    /**
     * Build an engine scoped to what this user is allowed to aggregate.
     * Admins (or users with no outlet assignments) see everything; a user
     * with outlet assignments sees only those outlets. An explicit
     * $requestedOutletId narrows further but can never escape the scope.
     */
    public static function for(User $user, ?int $requestedOutletId = null): self
    {
        $isAdmin  = $user->hasAnyRole(['admin', 'super_admin']);
        $assigned = $isAdmin ? collect() : $user->outlets()->pluck('outlets.id');

        if ($assigned->isEmpty()) {
            $scope = $requestedOutletId ? [$requestedOutletId] : null;
        } elseif ($requestedOutletId) {
            abort_unless($assigned->contains($requestedOutletId), 403, 'You do not have access to this outlet.');
            $scope = [$requestedOutletId];
        } else {
            $scope = $assigned->all();
        }

        return new self($scope);
    }

    // ── Periods ───────────────────────────────────────────────────────────────

    /**
     * Resolve a period key to [start, end, prevStart, prevEnd] — all UTC
     * Carbon instances on Nairobi day boundaries. The previous period is the
     * equivalent preceding one: yesterday for today, last month for this
     * month, same-length window immediately before a custom range.
     */
    public static function resolvePeriod(string $key, ?string $from = null, ?string $to = null): array
    {
        $now = CarbonImmutable::now(self::TZ);

        [$start, $end, $prevStart, $prevEnd] = match ($key) {
            'today' => [
                $now->startOfDay(), $now->endOfDay(),
                $now->subDay()->startOfDay(), $now->subDay()->endOfDay(),
            ],
            'yesterday' => [
                $now->subDay()->startOfDay(), $now->subDay()->endOfDay(),
                $now->subDays(2)->startOfDay(), $now->subDays(2)->endOfDay(),
            ],
            'last_7' => [
                $now->subDays(6)->startOfDay(), $now->endOfDay(),
                $now->subDays(13)->startOfDay(), $now->subDays(7)->endOfDay(),
            ],
            'last_30' => [
                $now->subDays(29)->startOfDay(), $now->endOfDay(),
                $now->subDays(59)->startOfDay(), $now->subDays(30)->endOfDay(),
            ],
            'this_month' => [
                $now->startOfMonth(), $now->endOfDay(),
                $now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth(),
            ],
            'last_month' => [
                $now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth(),
                $now->subMonthsNoOverflow(2)->startOfMonth(), $now->subMonthsNoOverflow(2)->endOfMonth(),
            ],
            'this_quarter' => [
                $now->startOfQuarter(), $now->endOfDay(),
                $now->subQuarter()->startOfQuarter(), $now->subQuarter()->endOfQuarter(),
            ],
            'this_year' => [
                $now->startOfYear(), $now->endOfDay(),
                $now->subYear()->startOfYear(), $now->subYear()->endOfYear(),
            ],
            'custom' => (function () use ($from, $to, $now) {
                $s = CarbonImmutable::parse($from ?: $now->format('Y-m-d'), self::TZ)->startOfDay();
                $e = CarbonImmutable::parse($to   ?: $now->format('Y-m-d'), self::TZ)->endOfDay();
                abort_if($e->lessThan($s), 422, 'Invalid date range.');
                $days = (int) $s->diffInDays($e) + 1;
                return [$s, $e, $s->subDays($days), $s->subDay()->endOfDay()];
            })(),
            default => abort(422, "Unknown period '{$key}'."),
        };

        // Timestamps are stored in Nairobi time (app.timezone) — no UTC shift.
        return [
            $start->toMutable(), $end->toMutable(),
            $prevStart->toMutable(), $prevEnd->toMutable(),
        ];
    }

    // ── Query scaffolding ─────────────────────────────────────────────────────

    /** Sales-truth base: commercial orders, KES, scoped. */
    private function salesBase()
    {
        return DB::table('orders')
            ->whereNotIn('status', self::SALE_EXCLUDED_STATUSES)
            ->whereRaw("UPPER(currency_code) = 'KES'")
            ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds));
    }

    /** Money-truth base: settled payments net of refunds, KES, scoped via order. */
    private function moneyBase()
    {
        return DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.status', 'paid')
            ->whereRaw("UPPER(p.currency_code) = 'KES'")
            ->when($this->outletIds, fn ($q) => $q->whereIn('o.outlet_id', $this->outletIds));
    }

    /** The settle timestamp: paid_at when present, else the row's creation. */
    private const PAID_AT = 'COALESCE(p.paid_at, p.created_at)';

    /** Day bucket — timestamps are already Nairobi wall-clock (app.timezone). */
    private static function eatDay(string $col): string
    {
        return "DATE({$col})";
    }

    /**
     * A flow metric: current value, previous-equivalent value, and a daily
     * series for the current window (sparkline material).
     */
    private function flow(callable $baseFor, string $tsExpr, string $valueExpr, Carbon $s, Carbon $e, Carbon $ps, Carbon $pe): array
    {
        $current  = (float) $baseFor()->whereBetween(DB::raw($tsExpr), [$s, $e])->selectRaw("{$valueExpr} AS v")->value('v');
        $previous = (float) $baseFor()->whereBetween(DB::raw($tsExpr), [$ps, $pe])->selectRaw("{$valueExpr} AS v")->value('v');
        $day      = self::eatDay($tsExpr);
        $series   = $baseFor()->whereBetween(DB::raw($tsExpr), [$s, $e])
            ->selectRaw("{$day} AS d, {$valueExpr} AS v")
            ->groupBy(DB::raw($day))->orderBy('d')
            ->pluck('v', 'd');

        return [
            'current'  => round($current, 2),
            'previous' => round($previous, 2),
            'series'   => $series,
        ];
    }

    // ── Flow metrics (period-based) ───────────────────────────────────────────

    /** Sales truth: what we sold (order totals, voids/cancels excluded). */
    public function revenue(Carbon $s, Carbon $e, Carbon $ps, Carbon $pe): array
    {
        return $this->flow(fn () => $this->salesBase(), 'orders.created_at', 'COALESCE(SUM(total_amount),0)', $s, $e, $ps, $pe);
    }

    public function ordersCount(Carbon $s, Carbon $e, Carbon $ps, Carbon $pe): array
    {
        return $this->flow(fn () => $this->salesBase(), 'orders.created_at', 'COUNT(*)', $s, $e, $ps, $pe);
    }

    /** Money truth: what actually settled, net of refunds. */
    public function collected(Carbon $s, Carbon $e, Carbon $ps, Carbon $pe): array
    {
        return $this->flow(fn () => $this->moneyBase(), self::PAID_AT, 'COALESCE(SUM(p.amount - COALESCE(p.refund_amount,0)),0)', $s, $e, $ps, $pe);
    }

    /** Financial: completed expenses in KES by expense date. */
    public function expenses(Carbon $s, Carbon $e, Carbon $ps, Carbon $pe): array
    {
        $base = fn () => DB::table('expenses')
            ->where('status', 'completed')
            ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds));
        // expense_date is a plain date — no timezone shifting needed.
        $flow = function (Carbon $a, Carbon $b) use ($base) {
            return (float) $base()->whereBetween('expense_date', [
                $a->format('Y-m-d'), $b->format('Y-m-d'),
            ])->sum('amount_kes');
        };
        return [
            'current'  => round($flow($s, $e), 2),
            'previous' => round($flow($ps, $pe), 2),
            'series'   => $base()->whereBetween('expense_date', [$s->format('Y-m-d'), $e->format('Y-m-d')])
                ->selectRaw('expense_date AS d, COALESCE(SUM(amount_kes),0) AS v')
                ->groupBy('expense_date')->orderBy('d')->pluck('v', 'd'),
        ];
    }

    public function newCustomers(Carbon $s, Carbon $e, Carbon $ps, Carbon $pe): array
    {
        // Customers carry no outlet — this metric is business-wide by nature.
        return $this->flow(fn () => DB::table('customers'), 'customers.created_at', 'COUNT(*)', $s, $e, $ps, $pe);
    }

    public function productionCompleted(Carbon $s, Carbon $e, Carbon $ps, Carbon $pe): array
    {
        return $this->flow(
            fn () => DB::table('production_orders')->where('status', 'completed')
                ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds)),
            'production_orders.completed_at', 'COUNT(*)', $s, $e, $ps, $pe,
        );
    }

    /** Of production orders completed in the window, % on or before due date. */
    public function onTimePct(Carbon $s, Carbon $e, Carbon $ps, Carbon $pe): array
    {
        $calc = function (Carbon $a, Carbon $b) {
            $row = DB::table('production_orders')
                ->where('status', 'completed')
                ->whereBetween('completed_at', [$a, $b])
                ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
                ->selectRaw("COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE due_date IS NULL OR " . self::eatDay('completed_at') . " <= due_date) AS on_time")
                ->first();
            return $row->total > 0 ? round($row->on_time / $row->total * 100, 1) : null;
        };
        return ['current' => $calc($s, $e), 'previous' => $calc($ps, $pe), 'series' => collect()];
    }

    // ── Point-in-time metrics (now, not a window) ─────────────────────────────

    /** What a customer still owes on one open order. */
    private const OWED = 'GREATEST(total_amount - COALESCE(pp.paid,0), 0)';

    /**
     * Open orders (pending/partial/deposit) with their settled money joined —
     * the one base that outstanding totals, aging buckets, deposits-held and
     * their drill-downs all share, so the four can never disagree.
     */
    private function openBalances()
    {
        return $this->salesBase()
            ->whereIn('payment_status', ['pending', 'partial', 'deposit'])
            ->leftJoinSub(
                DB::table('payments')->where('status', 'paid')
                    ->selectRaw('order_id, SUM(amount - COALESCE(refund_amount,0)) AS paid')
                    ->groupBy('order_id'),
                'pp', 'pp.order_id', '=', 'orders.id',
            );
    }

    /** Sales minus money truth per open order: what customers still owe. */
    public function outstandingBalance(): array
    {
        $row = $this->openBalances()
            ->selectRaw('COUNT(*) AS orders, COALESCE(SUM(' . self::OWED . '),0) AS owed')
            ->first();
        return ['amount' => round((float) $row->owed, 2), 'orders' => (int) $row->orders];
    }

    /** Aging bucket cutoffs, oldest key last. */
    private function agingCutoffs(): array
    {
        $now = CarbonImmutable::now(self::TZ);
        return [$now->subDays(30), $now->subDays(60), $now->subDays(90)];
    }

    /**
     * The outstanding balance sliced by how long it has been owed —
     * 0-30 / 31-60 / 61-90 / 90+ days by order date — plus deposits held
     * (money already collected on orders we have not fully delivered:
     * a liability, not income).
     */
    public function outstandingAging(): array
    {
        [$d30, $d60, $d90] = $this->agingCutoffs();
        $owed = self::OWED;

        $row = $this->openBalances()->selectRaw("
            COALESCE(SUM(CASE WHEN orders.created_at >= ? THEN {$owed} ELSE 0 END),0) AS a0,
            COUNT(*) FILTER (WHERE orders.created_at >= ?) AS c0,
            COALESCE(SUM(CASE WHEN orders.created_at < ? AND orders.created_at >= ? THEN {$owed} ELSE 0 END),0) AS a1,
            COUNT(*) FILTER (WHERE orders.created_at < ? AND orders.created_at >= ?) AS c1,
            COALESCE(SUM(CASE WHEN orders.created_at < ? AND orders.created_at >= ? THEN {$owed} ELSE 0 END),0) AS a2,
            COUNT(*) FILTER (WHERE orders.created_at < ? AND orders.created_at >= ?) AS c2,
            COALESCE(SUM(CASE WHEN orders.created_at < ? THEN {$owed} ELSE 0 END),0) AS a3,
            COUNT(*) FILTER (WHERE orders.created_at < ?) AS c3
        ", [$d30, $d30, $d30, $d60, $d30, $d60, $d60, $d90, $d60, $d90, $d90, $d90])->first();

        $deposits = $this->openBalances()
            ->where('payment_status', 'deposit')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(LEAST(COALESCE(pp.paid,0), total_amount)),0) AS held')
            ->first();

        return [
            'buckets' => [
                ['key' => '0_30',    'label' => '0–30d',  'amount' => round((float) $row->a0, 2), 'orders' => (int) $row->c0],
                ['key' => '31_60',   'label' => '31–60d', 'amount' => round((float) $row->a1, 2), 'orders' => (int) $row->c1],
                ['key' => '61_90',   'label' => '61–90d', 'amount' => round((float) $row->a2, 2), 'orders' => (int) $row->c2],
                ['key' => '90_plus', 'label' => '90d+',   'amount' => round((float) $row->a3, 2), 'orders' => (int) $row->c3],
            ],
            'deposits_held' => ['orders' => (int) $deposits->n, 'amount' => round((float) $deposits->held, 2)],
        ];
    }

    // ── Drill-downs ───────────────────────────────────────────────────────────

    /**
     * Spec rule 3: every number opens. Each drill is THE SAME base query as
     * its aggregate with the aggregation removed — never a second query that
     * can drift. Rows share one shape: {id, ref, at, who, detail, amount,
     * kind[, order_id]} so every surface renders them with one component.
     */
    public function drill(string $metric, Carbon $s, Carbon $e, int $page = 1, ?string $bucket = null): array
    {
        $perPage = 25;
        $who = "TRIM(CONCAT(COALESCE(customer_first_name,''),' ',COALESCE(customer_last_name,'')))";

        $q = match ($metric) {
            'revenue', 'orders' => $this->salesBase()
                ->whereBetween(DB::raw('orders.created_at'), [$s, $e])
                ->orderByDesc('orders.created_at')
                ->selectRaw("orders.id, order_number AS ref, orders.created_at AS at, {$who} AS who,
                    payment_status AS detail, total_amount AS amount, 'order' AS kind"),

            'collected' => $this->moneyBase()
                ->whereBetween(DB::raw(self::PAID_AT), [$s, $e])
                ->orderByDesc(DB::raw(self::PAID_AT))
                ->selectRaw("p.id, o.order_number AS ref, " . self::PAID_AT . " AS at,
                    TRIM(CONCAT(COALESCE(o.customer_first_name,''),' ',COALESCE(o.customer_last_name,''))) AS who,
                    p.payment_method AS detail, (p.amount - COALESCE(p.refund_amount,0)) AS amount,
                    'payment' AS kind, o.id AS order_id"),

            'outstanding' => $this->openBalances()
                ->when($bucket, fn ($q) => $this->applyAgingBucket($q, $bucket))
                ->orderBy('orders.created_at')
                ->selectRaw("orders.id, order_number AS ref, orders.created_at AS at, {$who} AS who,
                    payment_status AS detail, " . self::OWED . " AS amount, 'order' AS kind"),

            'new_customers' => DB::table('customers')
                ->whereBetween('created_at', [$s, $e])
                ->orderByDesc('created_at')
                ->selectRaw("id, TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS ref,
                    created_at AS at, COALESCE(phone,'') AS who, COALESCE(email,'') AS detail,
                    NULL::numeric AS amount, 'customer' AS kind"),

            'production_completed' => DB::table('production_orders')
                ->where('status', 'completed')
                ->whereBetween('completed_at', [$s, $e])
                ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
                ->orderByDesc('completed_at')
                ->selectRaw("id, order_number AS ref, completed_at AS at, '' AS who,
                    CONCAT('due ', due_date) AS detail, quantity::numeric AS amount, 'production' AS kind"),

            'production_overdue' => DB::table('production_orders')
                ->whereNotIn('status', ['completed', 'cancelled', 'draft'])
                ->where('due_date', '<', CarbonImmutable::now(self::TZ)->format('Y-m-d'))
                ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
                ->orderBy('due_date')
                ->selectRaw("id, order_number AS ref, due_date::timestamp AS at, '' AS who,
                    status AS detail, quantity::numeric AS amount, 'production' AS kind"),

            'expenses' => DB::table('expenses')
                ->where('status', 'completed')
                ->whereBetween('expense_date', [$s->format('Y-m-d'), $e->format('Y-m-d')])
                ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
                ->orderByDesc('expense_date')
                ->selectRaw("id, title AS ref, expense_date::timestamp AS at, COALESCE(vendor_name,'') AS who,
                    COALESCE(department,'') AS detail, amount_kes AS amount, 'expense' AS kind"),

            default => abort(422, "Metric '{$metric}' has no drill-down."),
        };

        $total = (clone $q)->count();
        $rows  = $q->forPage(max(1, $page), $perPage)->get();

        return [
            'metric'   => $metric,
            'rows'     => $rows,
            'total'    => $total,
            'page'     => max(1, $page),
            'per_page' => $perPage,
        ];
    }

    /** Narrow an outstanding drill to one aging bucket (or deposits). */
    private function applyAgingBucket($q, string $bucket)
    {
        [$d30, $d60, $d90] = $this->agingCutoffs();
        return match ($bucket) {
            '0_30'     => $q->where('orders.created_at', '>=', $d30),
            '31_60'    => $q->where('orders.created_at', '<', $d30)->where('orders.created_at', '>=', $d60),
            '61_90'    => $q->where('orders.created_at', '<', $d60)->where('orders.created_at', '>=', $d90),
            '90_plus'  => $q->where('orders.created_at', '<', $d90),
            'deposits' => $q->where('payment_status', 'deposit'),
            default    => abort(422, "Unknown aging bucket '{$bucket}'."),
        };
    }

    public function productionOpen(): array
    {
        $today = CarbonImmutable::now(self::TZ)->format('Y-m-d');
        $row = DB::table('production_orders')
            ->whereNotIn('status', ['completed', 'cancelled', 'draft'])
            ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
            ->selectRaw("COUNT(*) AS wip,
                COUNT(*) FILTER (WHERE due_date < ?) AS overdue", [$today])
            ->first();
        return ['wip' => (int) $row->wip, 'overdue' => (int) $row->overdue];
    }

    public function lowStock(): int
    {
        return (int) DB::table('inventory_items')
            ->whereRaw('(quantity_on_hand - quantity_reserved) <= reorder_point')
            ->where('reorder_point', '>', 0)
            ->count();
    }

    // ── Production intelligence (Phase 3) ─────────────────────────────────────

    /** Hours a task actually took: recorded actual_hours, else the timestamps. */
    private const TASK_HOURS = "CASE
        WHEN t.actual_hours IS NOT NULL AND t.actual_hours > 0 THEN t.actual_hours
        WHEN t.started_at IS NOT NULL AND t.completed_at IS NOT NULL
            THEN EXTRACT(EPOCH FROM (t.completed_at - t.started_at)) / 3600.0
        END";

    /**
     * Average time on the bench per stage, for tasks completed in the window,
     * against the estimate — the same arithmetic the order page shows per
     * task, aggregated into "which stages routinely run over?".
     */
    public function stageCycleTimes(Carbon $s, Carbon $e)
    {
        $act = self::TASK_HOURS;
        return DB::table('production_tasks as t')
            ->join('production_orders as po', 'po.id', '=', 't.production_order_id')
            ->join('production_stages as s', 's.id', '=', 't.production_stage_id')
            ->where('t.status', 'completed')
            ->whereBetween('t.completed_at', [$s, $e])
            ->where('po.status', '!=', 'cancelled')
            ->when($this->outletIds, fn ($q) => $q->whereIn('po.outlet_id', $this->outletIds))
            ->groupBy('s.id', 's.name', 's.sort_order')
            ->orderBy('s.sort_order')
            ->selectRaw("s.name AS stage,
                COUNT(*) AS tasks,
                ROUND(AVG({$act})::numeric, 1) AS avg_hours,
                ROUND(AVG(NULLIF(t.estimated_hours, 0))::numeric, 1) AS avg_est_hours,
                COUNT(*) FILTER (WHERE t.estimated_hours > 0 AND {$act} > t.estimated_hours) AS over_estimate,
                COUNT(*) FILTER (WHERE t.estimated_hours > 0) AS with_estimate")
            ->get();
    }

    /**
     * Where pieces are piling up RIGHT NOW: held at stage k = effective passed
     * at k−1 minus effective passed at k — the exact invariant the piece
     * pipeline enforces, summed across every open order. Sequence-less legacy
     * tasks are excluded (their position in the pipeline is unknown).
     */
    public function bottlenecks()
    {
        $effT    = "CASE WHEN t.status IN ('completed','skipped') THEN po.quantity ELSE COALESCE(t.quantity_done, 0) END";
        $effPrev = "CASE WHEN prev.id IS NULL THEN po.quantity
                         WHEN prev.status IN ('completed','skipped') THEN po.quantity
                         ELSE COALESCE(prev.quantity_done, 0) END";
        return DB::table('production_tasks as t')
            ->join('production_orders as po', 'po.id', '=', 't.production_order_id')
            ->join('production_stages as s', 's.id', '=', 't.production_stage_id')
            ->leftJoin('production_tasks as prev', function ($j) {
                $j->on('prev.production_order_id', '=', 't.production_order_id')
                  ->on('prev.sequence', '=', DB::raw('t.sequence - 1'));
            })
            ->whereNotIn('po.status', ['completed', 'cancelled', 'draft'])
            ->whereNotIn('t.status', ['completed', 'skipped'])
            ->whereNotNull('t.sequence')
            ->when($this->outletIds, fn ($q) => $q->whereIn('po.outlet_id', $this->outletIds))
            ->groupBy('s.id', 's.name', 's.sort_order')
            ->orderByDesc(DB::raw("SUM(GREATEST(({$effPrev}) - ({$effT}), 0))"))
            ->selectRaw("s.name AS stage,
                SUM(GREATEST(({$effPrev}) - ({$effT}), 0)) AS held_pieces,
                COUNT(*) FILTER (WHERE t.status = 'in_progress') AS active_tasks,
                COUNT(*) AS open_tasks")
            ->get();
    }

    /** Who moved how many pieces through their bench in the window. */
    public function tailorThroughput(Carbon $s, Carbon $e)
    {
        $act = self::TASK_HOURS;
        return DB::table('production_tasks as t')
            ->join('production_orders as po', 'po.id', '=', 't.production_order_id')
            ->join('users as u', 'u.id', '=', 't.assigned_to')
            ->where('t.status', 'completed')
            ->whereBetween('t.completed_at', [$s, $e])
            ->where('po.status', '!=', 'cancelled')
            ->when($this->outletIds, fn ($q) => $q->whereIn('po.outlet_id', $this->outletIds))
            ->groupBy('u.id', 'u.first_name', 'u.last_name')
            ->selectRaw("TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS tailor,
                COUNT(*) AS tasks,
                COALESCE(SUM(CASE WHEN t.quantity_done > 0 THEN t.quantity_done ELSE po.quantity END), 0) AS pieces,
                ROUND(AVG({$act})::numeric, 1) AS avg_hours")
            ->orderByDesc(DB::raw('3'))
            ->limit(10)
            ->get();
    }

    /** QC truth from production_quality_checks: pass rate and rework load. */
    public function qcRates(Carbon $s, Carbon $e): array
    {
        $row = DB::table('production_quality_checks as qc')
            ->join('production_orders as po', 'po.id', '=', 'qc.production_order_id')
            ->whereBetween('qc.created_at', [$s, $e])
            ->when($this->outletIds, fn ($q) => $q->whereIn('po.outlet_id', $this->outletIds))
            ->selectRaw('COUNT(*) AS checks,
                COUNT(*) FILTER (WHERE qc.passed) AS passed,
                COALESCE(SUM(qc.passed_quantity), 0) AS pieces_passed,
                COALESCE(SUM(qc.failed_quantity), 0) AS pieces_failed')
            ->first();

        return [
            'checks'        => (int) $row->checks,
            'pass_rate'     => $row->checks > 0 ? round($row->passed / $row->checks * 100, 1) : null,
            'pieces_passed' => (int) $row->pieces_passed,
            'pieces_failed' => (int) $row->pieces_failed,
        ];
    }

    /**
     * Can the floor deliver what is promised? Due-date load for the next 7
     * days vs the floor's actual pace (pieces completing production per day
     * over the last 14). A shortfall is a fact, not a feeling.
     */
    public function capacityOutlook(): array
    {
        $now = CarbonImmutable::now(self::TZ);

        $due = DB::table('production_orders')
            ->whereNotIn('status', ['completed', 'cancelled', 'draft'])
            ->whereBetween('due_date', [$now->format('Y-m-d'), $now->addDays(7)->format('Y-m-d')])
            ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
            ->selectRaw('COUNT(*) AS orders, COALESCE(SUM(quantity), 0) AS pieces')
            ->first();

        $done = DB::table('production_orders')
            ->where('status', 'completed')
            ->where('completed_at', '>=', $now->subDays(14))
            ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
            ->selectRaw('COALESCE(SUM(quantity), 0) AS pieces')
            ->first();

        $daily = round($done->pieces / 14, 1);

        return [
            'due_orders'       => (int) $due->orders,
            'due_pieces'       => (int) $due->pieces,
            'daily_throughput' => $daily,
            'week_capacity'    => round($daily * 7, 1),
            'shortfall'        => max(0, round($due->pieces - $daily * 7, 1)),
        ];
    }

    /** Live material demand on the floor: open orders' allocations by material. */
    public function materialDemand()
    {
        return DB::table('material_allocations as ma')
            ->join('production_orders as po', 'po.id', '=', 'ma.production_order_id')
            ->join('materials as m', 'm.id', '=', 'ma.material_id')
            ->whereNotIn('po.status', ['completed', 'cancelled', 'draft'])
            ->when($this->outletIds, fn ($q) => $q->whereIn('po.outlet_id', $this->outletIds))
            ->groupBy('m.id', 'm.name', 'm.unit_of_measure')
            ->selectRaw('m.name AS material, m.unit_of_measure AS unit,
                COALESCE(SUM(ma.quantity_required), 0) AS required,
                COALESCE(SUM(ma.quantity_allocated), 0) AS allocated,
                COALESCE(SUM(ma.quantity_used), 0) AS used')
            ->orderByDesc(DB::raw('3'))
            ->limit(10)
            ->get();
    }

    // ── Inventory intelligence (Phase 4) ──────────────────────────────────────

    /** Outlet-scope SQL fragment for raw lateral queries (ints only, safe). */
    private function outletScopeSql(string $col): string
    {
        return $this->outletIds
            ? "AND {$col} IN (" . implode(',', array_map('intval', $this->outletIds)) . ")"
            : '';
    }

    /**
     * Stock health + valuation. Each item is priced from product_prices in
     * KES — the variant-specific row when one exists, else the product-level
     * default — at cost and at retail. Items with no price row value at 0
     * and are counted, not hidden.
     */
    public function inventoryHealth(): array
    {
        $scope = $this->outletScopeSql('ii.outlet_id');
        $row = DB::selectOne("
            SELECT COUNT(*)                                            AS skus,
                   COALESCE(SUM(ii.quantity_on_hand), 0)               AS units,
                   COALESCE(SUM(ii.quantity_reserved), 0)              AS reserved,
                   COUNT(*) FILTER (WHERE (ii.quantity_on_hand - ii.quantity_reserved) <= 0) AS out_of_stock,
                   COUNT(*) FILTER (WHERE ii.reorder_point > 0
                       AND (ii.quantity_on_hand - ii.quantity_reserved) <= ii.reorder_point) AS low_stock,
                   COUNT(*) FILTER (WHERE pr.cost_price IS NULL)       AS unpriced,
                   COALESCE(SUM(GREATEST(ii.quantity_on_hand, 0) * COALESCE(pr.cost_price, 0)), 0)    AS cost_value,
                   COALESCE(SUM(GREATEST(ii.quantity_on_hand, 0) * COALESCE(pr.regular_price, 0)), 0) AS retail_value
            FROM inventory_items ii
            LEFT JOIN LATERAL (
                SELECT pp.cost_price, pp.regular_price
                FROM product_prices pp
                WHERE pp.product_id = ii.product_id
                  AND UPPER(pp.currency_code) = 'KES'
                  AND (pp.product_variant_id = ii.product_variant_id OR pp.product_variant_id IS NULL)
                ORDER BY (pp.product_variant_id IS NOT NULL AND pp.product_variant_id = ii.product_variant_id) DESC
                LIMIT 1
            ) pr ON TRUE
            WHERE TRUE {$scope}
        ");

        return [
            'skus'         => (int) $row->skus,
            'units'        => (int) $row->units,
            'reserved'     => (int) $row->reserved,
            'out_of_stock' => (int) $row->out_of_stock,
            'low_stock'    => (int) $row->low_stock,
            'unpriced'     => (int) $row->unpriced,
            'cost_value'   => round((float) $row->cost_value, 2),
            'retail_value' => round((float) $row->retail_value, 2),
        ];
    }

    /** Revenue per product in the window (sales truth via order lines). */
    private function productRevenue(Carbon $s, Carbon $e)
    {
        return DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereNotIn('o.status', self::SALE_EXCLUDED_STATUSES)
            ->whereRaw("UPPER(o.currency_code) = 'KES'")
            ->whereBetween('o.created_at', [$s, $e])
            ->whereNotNull('oi.product_id')
            ->when($this->outletIds, fn ($q) => $q->whereIn('o.outlet_id', $this->outletIds))
            ->groupBy('oi.product_id')
            ->selectRaw('oi.product_id, MAX(oi.product_name) AS product,
                COALESCE(SUM(oi.total_price), 0) AS revenue, COALESCE(SUM(oi.quantity), 0) AS units')
            ->orderByDesc(DB::raw('SUM(oi.total_price)'))
            ->get();
    }

    /**
     * ABC classification by revenue contribution (cumulative 80 / 95 / 100),
     * with days-of-cover per item: on-hand divided by the window's daily
     * sales rate. A-class items with thin cover are the stock that costs
     * real money when it runs out.
     */
    public function abcClassification(Carbon $s, Carbon $e): array
    {
        $rows = $this->productRevenue($s, $e);
        $total = max((float) $rows->sum('revenue'), 0.01);
        $days  = max(1, (int) $s->diffInDays($e) + 1);

        $onHand = DB::table('inventory_items')
            ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity_on_hand) AS units')
            ->pluck('units', 'product_id');

        $cum = 0.0;
        $classes = ['A' => ['count' => 0, 'revenue' => 0.0], 'B' => ['count' => 0, 'revenue' => 0.0], 'C' => ['count' => 0, 'revenue' => 0.0]];
        $items = [];
        foreach ($rows as $r) {
            // Class by the share BEFORE this item: the item that crosses the
            // 80% line is still an A (a single dominant product is A, not C).
            $class = $cum / $total < 0.80 ? 'A' : ($cum / $total < 0.95 ? 'B' : 'C');
            $cum += (float) $r->revenue;
            $classes[$class]['count']++;
            $classes[$class]['revenue'] += (float) $r->revenue;
            $dailyRate = (float) $r->units / $days;
            $stock = (int) ($onHand[$r->product_id] ?? 0);
            $items[] = [
                'product_id' => $r->product_id,
                'product'    => $r->product,
                'class'      => $class,
                'revenue'    => round((float) $r->revenue, 2),
                'share_pct'  => round((float) $r->revenue / $total * 100, 1),
                'units_sold' => (int) $r->units,
                'on_hand'    => $stock,
                'cover_days' => $dailyRate > 0 ? round($stock / $dailyRate, 1) : null,
            ];
        }

        return ['classes' => $classes, 'items' => array_slice($items, 0, 15)];
    }

    /** A-class items whose stock covers under $thresholdDays of demand. */
    public function stockoutRisks(int $thresholdDays = 7): array
    {
        [$s, $e] = self::resolvePeriod('last_30');
        $abc = $this->abcClassification($s, $e);

        return array_values(array_filter(
            $abc['items'],
            fn ($i) => $i['class'] === 'A' && $i['cover_days'] !== null && $i['cover_days'] < $thresholdDays,
        ));
    }

    /** Stock sitting still: on hand > 0, nothing sold in $days (or ever). */
    public function deadStock(int $days = 90)
    {
        $cutoff = CarbonImmutable::now(self::TZ)->subDays($days);
        return DB::table('inventory_items as ii')
            ->join('products as p', 'p.id', '=', 'ii.product_id')
            ->leftJoin(DB::raw("(
                    SELECT oi.product_id, MAX(o.created_at) AS last_sold, MAX(oi.product_name) AS sold_name
                    FROM order_items oi JOIN orders o ON o.id = oi.order_id
                    WHERE o.status NOT IN ('voided','cancelled')
                    GROUP BY oi.product_id
                ) ls"), 'ls.product_id', '=', 'ii.product_id')
            ->where('ii.quantity_on_hand', '>', 0)
            ->when($this->outletIds, fn ($q) => $q->whereIn('ii.outlet_id', $this->outletIds))
            ->where(fn ($q) => $q->whereNull('ls.last_sold')->orWhere('ls.last_sold', '<', $cutoff))
            ->groupBy('p.id', 'p.sku', 'p.slug')
            ->selectRaw("p.id AS product_id,
                COALESCE(MAX(ls.sold_name), p.slug, p.sku) AS product,
                p.sku, SUM(ii.quantity_on_hand) AS units, MAX(ls.last_sold) AS last_sold")
            ->orderByDesc(DB::raw('SUM(ii.quantity_on_hand)'))
            ->limit(12)
            ->get();
    }

    /** Raw-material stock: valuation at unit cost + everything below reorder. */
    public function materialStockHealth(): array
    {
        $agg = DB::table('material_inventory as mi')
            ->join('materials as m', 'm.id', '=', 'mi.material_id')
            ->when($this->outletIds, fn ($q) => $q->whereIn('mi.outlet_id', $this->outletIds))
            ->groupBy('m.id', 'm.name', 'm.unit_of_measure', 'm.reorder_point', 'm.unit_cost')
            ->selectRaw('m.id, m.name, m.unit_of_measure AS unit, m.reorder_point, m.unit_cost,
                COALESCE(SUM(mi.quantity_on_hand), 0) AS on_hand,
                COALESCE(SUM(mi.quantity_available), 0) AS available')
            ->get();

        $below = $agg->filter(fn ($m) => $m->reorder_point > 0 && (float) $m->available <= (float) $m->reorder_point)
            ->sortBy(fn ($m) => (float) $m->available - (float) $m->reorder_point)
            ->values()
            ->take(10);

        return [
            'materials'  => $agg->count(),
            'cost_value' => round((float) $agg->sum(fn ($m) => (float) $m->on_hand * (float) $m->unit_cost), 2),
            'below_reorder' => $below,
        ];
    }

    // ── Procurement intelligence (Phase 4) ────────────────────────────────────

    /**
     * Supplier scorecard from POs + goods-received notes: volume, spend,
     * actual delivery days (first GRN vs order date), late deliveries vs the
     * promised date, and rejection rate at the door.
     */
    public function supplierPerformance(Carbon $s, Carbon $e)
    {
        return DB::table('purchase_orders as po')
            ->join('suppliers as sup', 'sup.id', '=', 'po.supplier_id')
            ->leftJoin(DB::raw('(
                    SELECT purchase_order_id, MIN(received_date) AS received_date
                    FROM goods_received_notes GROUP BY purchase_order_id
                ) g'), 'g.purchase_order_id', '=', 'po.id')
            ->leftJoin(DB::raw('(
                    SELECT grn.purchase_order_id,
                           SUM(gi.quantity_received) AS qty_received,
                           SUM(gi.quantity_rejected) AS qty_rejected
                    FROM grn_items gi JOIN goods_received_notes grn ON grn.id = gi.grn_id
                    GROUP BY grn.purchase_order_id
                ) gq'), 'gq.purchase_order_id', '=', 'po.id')
            ->whereBetween('po.order_date', [$s->format('Y-m-d'), $e->format('Y-m-d')])
            ->where('po.status', '!=', 'cancelled')
            ->when($this->outletIds, fn ($q) => $q->whereIn('po.outlet_id', $this->outletIds))
            ->groupBy('sup.id', 'sup.name', 'sup.rating')
            ->selectRaw("sup.name AS supplier, sup.rating,
                COUNT(*) AS orders,
                COALESCE(SUM(po.total_amount), 0) AS spend,
                ROUND(AVG(g.received_date - po.order_date) FILTER (WHERE g.received_date IS NOT NULL)::numeric, 1) AS avg_delivery_days,
                COUNT(*) FILTER (WHERE g.received_date IS NOT NULL) AS delivered,
                COUNT(*) FILTER (WHERE g.received_date > po.expected_delivery_date) AS late,
                COALESCE(SUM(gq.qty_received), 0) AS qty_received,
                COALESCE(SUM(gq.qty_rejected), 0) AS qty_rejected")
            ->orderByDesc(DB::raw('COALESCE(SUM(po.total_amount), 0)'))
            ->limit(10)
            ->get();
    }

    /**
     * What to buy, grounded in three real numbers per material: available
     * stock, the reorder buffer, and the unmet demand of OPEN production
     * orders (required − allocated). Suggested = (buffer + open demand) −
     * available, with the last price and supplier actually paid for it.
     */
    public function purchaseSuggestions()
    {
        $scopeMi = $this->outletScopeSql('mi.outlet_id');
        $scopePo = $this->outletScopeSql('po2.outlet_id');

        return collect(DB::select("
            SELECT m.id, m.code, m.name, m.unit_of_measure AS unit, m.unit_cost, m.reorder_point,
                   COALESCE(a.available, 0)  AS available,
                   COALESCE(d.shortfall, 0)  AS open_demand,
                   GREATEST(m.reorder_point + COALESCE(d.shortfall, 0) - COALESCE(a.available, 0), 0) AS suggested,
                   lb.unit_price  AS last_price,
                   lb.supplier    AS last_supplier,
                   lb.order_date  AS last_ordered
            FROM materials m
            LEFT JOIN (
                SELECT mi.material_id, SUM(mi.quantity_available) AS available
                FROM material_inventory mi WHERE TRUE {$scopeMi} GROUP BY mi.material_id
            ) a ON a.material_id = m.id
            LEFT JOIN (
                SELECT ma.material_id, SUM(GREATEST(ma.quantity_required - ma.quantity_allocated, 0)) AS shortfall
                FROM material_allocations ma
                JOIN production_orders p ON p.id = ma.production_order_id
                WHERE p.status NOT IN ('completed','cancelled','draft')
                GROUP BY ma.material_id
            ) d ON d.material_id = m.id
            LEFT JOIN (
                SELECT DISTINCT ON (poi.material_id)
                       poi.material_id, poi.unit_price, sup.name AS supplier, po2.order_date
                FROM purchase_order_items poi
                JOIN purchase_orders po2 ON po2.id = poi.purchase_order_id
                JOIN suppliers sup ON sup.id = po2.supplier_id
                WHERE poi.material_id IS NOT NULL {$scopePo}
                ORDER BY poi.material_id, po2.order_date DESC
            ) lb ON lb.material_id = m.id
            WHERE m.is_active = TRUE
              AND GREATEST(m.reorder_point + COALESCE(d.shortfall, 0) - COALESCE(a.available, 0), 0) > 0
            ORDER BY GREATEST(m.reorder_point + COALESCE(d.shortfall, 0) - COALESCE(a.available, 0), 0) * m.unit_cost DESC
            LIMIT 12
        "))->map(fn ($r) => [
            'material'     => $r->name,
            'code'         => $r->code,
            'unit'         => $r->unit,
            'available'    => round((float) $r->available, 2),
            'reorder_point'=> round((float) $r->reorder_point, 2),
            'open_demand'  => round((float) $r->open_demand, 2),
            'suggested'    => round((float) $r->suggested, 2),
            'est_cost'     => round((float) $r->suggested * (float) $r->unit_cost, 2),
            'last_price'   => $r->last_price !== null ? (float) $r->last_price : null,
            'last_supplier'=> $r->last_supplier,
            'last_ordered' => $r->last_ordered,
        ])->values();
    }

    /** Purchase orders still in flight: exposure and the oldest one waiting. */
    public function openPurchaseOrders(): array
    {
        $row = DB::table('purchase_orders')
            ->whereNotIn('status', ['received', 'completed', 'closed', 'cancelled'])
            ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS value, MIN(order_date) AS oldest')
            ->first();

        return [
            'count'       => (int) $row->n,
            'value'       => round((float) $row->value, 2),
            'oldest_days' => $row->oldest
                ? (int) CarbonImmutable::parse($row->oldest)->diffInDays(CarbonImmutable::now(self::TZ))
                : null,
        ];
    }

    // ── Attention feed ────────────────────────────────────────────────────────

    /**
     * "What requires my attention?" — every item cites the real numbers that
     * triggered it and links to the page where it gets fixed. Detectors only
     * emit when they have something to say.
     */
    public function attention(): array
    {
        $items = [];
        $todayEat = CarbonImmutable::now(self::TZ)->format('Y-m-d');

        // 1. Overdue production — the tailoring promise being broken right now.
        $overdue = DB::table('production_orders')
            ->whereNotIn('status', ['completed', 'cancelled', 'draft'])
            ->where('due_date', '<', $todayEat)
            ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
            ->selectRaw('COUNT(*) AS n, MIN(due_date) AS worst')
            ->first();
        if ($overdue->n > 0) {
            $daysLate = (int) CarbonImmutable::parse($overdue->worst)->diffInDays($todayEat);
            $items[] = [
                'key' => 'production_overdue', 'severity' => 'high',
                'title' => "{$overdue->n} production order" . ($overdue->n > 1 ? 's' : '') . ' overdue',
                'detail' => "Worst is {$daysLate} day" . ($daysLate === 1 ? '' : 's') . ' past due.',
                'count' => (int) $overdue->n, 'link' => '/production/orders?status=overdue',
            ];
        }

        // 2. Aging balances — money owed on orders older than 30 days.
        $aging = $this->openBalances()
            ->where('orders.created_at', '<', CarbonImmutable::now(self::TZ)->subDays(30))
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(' . self::OWED . '),0) AS owed')
            ->first();
        if ($aging->n > 0 && (float) $aging->owed > 0) {
            $items[] = [
                'key' => 'balances_aging', 'severity' => 'high',
                'title' => 'KES ' . number_format((float) $aging->owed) . ' owed for over 30 days',
                'detail' => "{$aging->n} order" . ($aging->n > 1 ? 's' : '') . ' with balances older than a month.',
                'count' => (int) $aging->n, 'link' => '/pos/balances',
            ];
        }

        // 3. Payments awaiting approval — claimed money that isn't confirmed.
        $pendingPay = DB::table('payments as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.requires_approval', true)->where('p.approval_status', 'pending_review')
            ->when($this->outletIds, fn ($q) => $q->whereIn('o.outlet_id', $this->outletIds))
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(p.amount),0) AS amt, MIN(p.created_at) AS oldest')
            ->first();
        if ($pendingPay->n > 0) {
            $age = (int) Carbon::parse($pendingPay->oldest)->diffInDays(now());
            $items[] = [
                'key' => 'payment_approvals', 'severity' => $age > 2 ? 'high' : 'medium',
                'title' => "{$pendingPay->n} payment" . ($pendingPay->n > 1 ? 's' : '') . ' awaiting approval (KES ' . number_format((float) $pendingPay->amt) . ')',
                'detail' => "Oldest has waited {$age} day" . ($age === 1 ? '' : 's') . '.',
                'count' => (int) $pendingPay->n, 'link' => '/approvals',
            ];
        }

        // 4. Capacity: next week's due load exceeds the floor's actual pace.
        $cap = $this->capacityOutlook();
        if ($cap['shortfall'] > 0 && $cap['due_pieces'] > 0) {
            $items[] = [
                'key' => 'capacity_shortfall', 'severity' => 'high',
                'title' => "Next 7 days need {$cap['due_pieces']} pieces — recent pace delivers ~{$cap['week_capacity']}",
                'detail' => "Short by ~{$cap['shortfall']} pieces at the current {$cap['daily_throughput']}/day throughput.",
                'count' => (int) ceil($cap['shortfall']), 'link' => '/reports/production',
            ];
        }

        // 5. A-class stockout risk: best sellers with thin cover.
        $risks = $this->stockoutRisks();
        if (count($risks) > 0) {
            $worst = $risks[0];
            $items[] = [
                'key' => 'stockout_risk', 'severity' => 'high',
                'title' => count($risks) . ' top-selling item' . (count($risks) > 1 ? 's' : '') . ' close to stockout',
                'detail' => "\"{$worst['product']}\" has {$worst['on_hand']} left — about {$worst['cover_days']} days at its 30-day sales rate.",
                'count' => count($risks), 'link' => '/reports/inventory',
            ];
        }

        // 6. Low stock — items at or below their reorder point.
        $low = $this->lowStock();
        if ($low > 0) {
            $items[] = [
                'key' => 'low_stock', 'severity' => 'medium',
                'title' => "{$low} item" . ($low > 1 ? 's' : '') . ' at or below reorder point',
                'detail' => 'Available quantity has crossed the reorder threshold.',
                'count' => $low, 'link' => '/reports/inventory',
            ];
        }

        // Severity sort: high before medium, then by count desc.
        usort($items, fn ($a, $b) =>
            [$a['severity'] === 'high' ? 0 : 1, -$a['count']] <=> [$b['severity'] === 'high' ? 0 : 1, -$b['count']]);

        return $items;
    }
}
