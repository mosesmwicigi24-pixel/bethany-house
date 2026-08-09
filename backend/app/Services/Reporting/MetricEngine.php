<?php

namespace App\Services\Reporting;

use App\Models\User;
use App\Support\Phone;
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

    /** Business-wide engine for scheduled jobs (no request user to scope by). */
    public static function unscoped(): self
    {
        return new self(null);
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

    /**
     * Shrinkage: stock written off in the window — negative approved
     * adjustments (damaged / stolen / expired / shrinkage / corrections),
     * valued at the transaction's own unit cost. Sales, voids and restocks
     * are movements, not losses, and never appear here.
     */
    public function shrinkage(Carbon $s, Carbon $e): array
    {
        $writeOffReasons = ['damaged', 'stolen', 'expired', 'shrinkage', 'correction', 'stock_count', 'recount', 'adjustment'];
        $base = fn () => DB::table('inventory_transactions as it')
            ->join('inventory_items as ii', 'ii.id', '=', 'it.inventory_item_id')
            ->leftJoin('products as p', 'p.id', '=', 'ii.product_id')
            ->where('it.quantity_change', '<', 0)
            ->whereIn(DB::raw('COALESCE(it.reason_code, it.transaction_type)'), $writeOffReasons)
            ->where(fn ($q) => $q->whereNull('it.status')->orWhere('it.status', 'approved'))
            ->whereBetween('it.created_at', [$s, $e])
            ->when($this->outletIds, fn ($q) => $q->whereIn('ii.outlet_id', $this->outletIds));

        $byReason = $base()
            ->groupBy(DB::raw('COALESCE(it.reason_code, it.transaction_type)'))
            ->selectRaw("COALESCE(it.reason_code, it.transaction_type) AS reason,
                SUM(-it.quantity_change) AS units,
                COALESCE(SUM(-it.quantity_change * COALESCE(it.unit_cost, 0)), 0) AS value,
                COUNT(*) AS entries")
            ->orderByDesc(DB::raw('COALESCE(SUM(-it.quantity_change * COALESCE(it.unit_cost, 0)), 0)'))
            ->get();

        $topItems = $base()
            ->groupBy('p.id', 'p.slug', 'p.sku')
            ->selectRaw("COALESCE(p.slug, p.sku, 'unknown') AS product,
                SUM(-it.quantity_change) AS units,
                COALESCE(SUM(-it.quantity_change * COALESCE(it.unit_cost, 0)), 0) AS value")
            ->orderByDesc(DB::raw('COALESCE(SUM(-it.quantity_change * COALESCE(it.unit_cost, 0)), 0)'))
            ->limit(8)
            ->get();

        return [
            'units'     => (int) $byReason->sum('units'),
            'value'     => round((float) $byReason->sum('value'), 2),
            'by_reason' => $byReason,
            'top_items' => $topItems,
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
                   lb.supplier_id AS last_supplier_id,
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
                       poi.material_id, poi.unit_price, sup.name AS supplier, sup.id AS supplier_id, po2.order_date
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
            'material_id'  => (int) $r->id,
            'code'         => $r->code,
            'unit'         => $r->unit,
            'available'    => round((float) $r->available, 2),
            'reorder_point'=> round((float) $r->reorder_point, 2),
            'open_demand'  => round((float) $r->open_demand, 2),
            'suggested'    => round((float) $r->suggested, 2),
            'est_cost'     => round((float) $r->suggested * (float) $r->unit_cost, 2),
            'last_price'   => $r->last_price !== null ? (float) $r->last_price : null,
            'last_supplier'=> $r->last_supplier,
            'last_supplier_id' => $r->last_supplier_id !== null ? (int) $r->last_supplier_id : null,
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

    // ── Customer intelligence (Phase 5) ───────────────────────────────────────
    //
    // Live POS orders carry the customer as a snapshot (name + phone) with a
    // NULL customer_id, so identity is keyed on customer_id when present and
    // otherwise on the FULL phone string (docs: never last-N digits). Orders
    // with neither are walk-ins and are reported as such, not guessed.

    /** Per-order customer key expression (id wins, else full phone). */
    private const CUSTOMER_KEY = "COALESCE(o.customer_id::text, NULLIF(o.customer_phone, ''))";

    /** Sales-truth orders aliased o, with the customer key attached. */
    private function customerOrders()
    {
        return DB::table('orders as o')
            ->whereNotIn('o.status', self::SALE_EXCLUDED_STATUSES)
            ->whereRaw("UPPER(o.currency_code) = 'KES'")
            ->when($this->outletIds, fn ($q) => $q->whereIn('o.outlet_id', $this->outletIds));
    }

    /** Revenue and orders per segment (customers.customer_type, else walk-in). */
    public function customerSegments(Carbon $s, Carbon $e)
    {
        return $this->customerOrders()
            ->leftJoin(DB::raw("(
                    SELECT DISTINCT ON (phone) id, phone, customer_type
                    FROM customers WHERE phone IS NOT NULL AND phone <> ''
                    ORDER BY phone, id
                ) cp"), 'cp.phone', '=', 'o.customer_phone')
            ->leftJoin('customers as cid', 'cid.id', '=', 'o.customer_id')
            ->whereBetween('o.created_at', [$s, $e])
            ->groupBy(DB::raw("COALESCE(cid.customer_type, cp.customer_type, 'walk_in')"))
            ->selectRaw("COALESCE(cid.customer_type, cp.customer_type, 'walk_in') AS segment,
                COUNT(*) AS orders, COALESCE(SUM(o.total_amount), 0) AS revenue,
                COUNT(DISTINCT " . self::CUSTOMER_KEY . ") AS customers")
            ->orderByDesc(DB::raw('COALESCE(SUM(o.total_amount), 0)'))
            ->get();
    }

    /**
     * New vs returning revenue in the window. An order is RETURNING when its
     * customer key has an order before the window opened; keyless walk-ins
     * are split out honestly instead of being counted either way.
     */
    public function newVsReturning(Carbon $s, Carbon $e): array
    {
        $key = self::CUSTOMER_KEY;
        $rows = DB::select("
            WITH keyed AS (
                SELECT o.id, o.total_amount, o.created_at, {$key} AS ckey,
                       MIN(o.created_at) OVER (PARTITION BY {$key}) AS first_order_at
                FROM orders o
                WHERE o.status NOT IN ('voided','cancelled')
                  AND UPPER(o.currency_code) = 'KES'
                  " . ($this->outletIds ? 'AND o.outlet_id IN (' . implode(',', array_map('intval', $this->outletIds)) . ')' : '') . "
            )
            SELECT CASE WHEN ckey IS NULL THEN 'anonymous'
                        WHEN first_order_at < ? THEN 'returning' ELSE 'new' END AS bucket,
                   COUNT(*) AS orders, COALESCE(SUM(total_amount), 0) AS revenue,
                   COUNT(DISTINCT ckey) AS customers
            FROM keyed WHERE created_at BETWEEN ? AND ?
            GROUP BY 1
        ", [$s, $s, $e]);

        $out = ['new' => null, 'returning' => null, 'anonymous' => null];
        foreach ($rows as $r) {
            $out[$r->bucket] = [
                'orders' => (int) $r->orders,
                'revenue' => round((float) $r->revenue, 2),
                'customers' => (int) $r->customers,
            ];
        }
        return $out;
    }

    /** Window's top customers with their all-time value beside the period. */
    public function topCustomers(Carbon $s, Carbon $e)
    {
        $key = self::CUSTOMER_KEY;
        return collect(DB::select("
            WITH keyed AS (
                SELECT {$key} AS ckey, o.total_amount, o.created_at,
                       TRIM(CONCAT(COALESCE(o.customer_first_name,''),' ',COALESCE(o.customer_last_name,''))) AS name,
                       o.customer_phone AS phone
                FROM orders o
                WHERE o.status NOT IN ('voided','cancelled') AND UPPER(o.currency_code) = 'KES'
                  AND {$key} IS NOT NULL
                  " . ($this->outletIds ? 'AND o.outlet_id IN (' . implode(',', array_map('intval', $this->outletIds)) . ')' : '') . "
            )
            SELECT ckey, MAX(name) AS name, MAX(phone) AS phone,
                   COUNT(*) FILTER (WHERE created_at BETWEEN ? AND ?) AS period_orders,
                   COALESCE(SUM(total_amount) FILTER (WHERE created_at BETWEEN ? AND ?), 0) AS period_revenue,
                   COUNT(*) AS lifetime_orders,
                   COALESCE(SUM(total_amount), 0) AS lifetime_revenue,
                   MAX(created_at) AS last_order_at
            FROM keyed
            GROUP BY ckey
            HAVING COUNT(*) FILTER (WHERE created_at BETWEEN ? AND ?) > 0
            ORDER BY period_revenue DESC
            LIMIT 10
        ", [$s, $e, $s, $e, $s, $e]));
    }

    /**
     * Top customers of the trailing year who have gone quiet for 60+ days —
     * the list a shop actually calls. Every row cites its numbers.
     */
    public function dormantTopCustomers(int $quietDays = 60, int $topN = 20)
    {
        $key = self::CUSTOMER_KEY;
        $now = CarbonImmutable::now(self::TZ);
        return collect(DB::select("
            WITH keyed AS (
                SELECT {$key} AS ckey, o.total_amount, o.created_at,
                       TRIM(CONCAT(COALESCE(o.customer_first_name,''),' ',COALESCE(o.customer_last_name,''))) AS name,
                       o.customer_phone AS phone
                FROM orders o
                WHERE o.status NOT IN ('voided','cancelled') AND UPPER(o.currency_code) = 'KES'
                  AND {$key} IS NOT NULL AND o.created_at >= ?
                  " . ($this->outletIds ? 'AND o.outlet_id IN (' . implode(',', array_map('intval', $this->outletIds)) . ')' : '') . "
            ), ranked AS (
                SELECT ckey, MAX(name) AS name, MAX(phone) AS phone,
                       SUM(total_amount) AS revenue_12m, MAX(created_at) AS last_order_at
                FROM keyed GROUP BY ckey
                ORDER BY SUM(total_amount) DESC LIMIT ?
            )
            SELECT * FROM ranked WHERE last_order_at < ?
            ORDER BY revenue_12m DESC
        ", [$now->subDays(365), $topN, $now->subDays($quietDays)]))
            ->map(fn ($r) => [
                'name' => $r->name !== '' ? $r->name : ($r->phone ?? 'Unknown'),
                'phone' => $r->phone,
                'revenue_12m' => round((float) $r->revenue_12m, 2),
                'last_order_at' => $r->last_order_at,
                'days_quiet' => (int) CarbonImmutable::parse($r->last_order_at)->diffInDays(CarbonImmutable::now(self::TZ)),
            ])->values();
    }

    /**
     * RFM segmentation over the trailing 365 days (docs/REPORTS_SPEC).
     *
     * Identity is the CANONICAL key: customer_id, else normalize_phone(phone)
     * — so 0722… and +254722… stop being two different "customers" — else
     * lowercased email. Keyless orders are reported honestly as anonymous.
     *
     * R/F/M are scored 1–5 by NTILE over the cohort (self-calibrating; no
     * magic KES thresholds) and mapped to named segments a shop can act on:
     *
     *   champions        recent + frequent (r≥4, f≥4)          — protect them
     *   loyal            steady repeaters  (r≥3, f≥3)          — nurture
     *   promising        recent but new    (r≥4, f≤2)          — convert to loyal
     *   cant_lose        high-value gone quiet (r≤2, f≥4|m≥4)  — call TODAY
     *   at_risk          were regulars, fading (r≤2, f≥3)      — win back
     *   hibernating      low value, long quiet (r≤2, f≤2)      — cheap re-ping
     *   needs_attention  everyone in the middle                — watch
     *
     * Returns segment rollups, a phone-ready action list (cant_lose + at_risk,
     * biggest money first), and the anonymous walk-in remainder.
     */
    public function rfmSegments(): array
    {
        $key = "COALESCE(o.customer_id::text, normalize_phone(o.customer_phone), LOWER(NULLIF(o.customer_email,'')))";
        $now = CarbonImmutable::now(self::TZ);
        $since = $now->subDays(365);
        $outletSql = $this->outletIds
            ? 'AND o.outlet_id IN (' . implode(',', array_map('intval', $this->outletIds)) . ')'
            : '';

        $scored = "
            WITH keyed AS (
                SELECT {$key} AS ckey, o.total_amount, o.created_at,
                       TRIM(CONCAT(COALESCE(o.customer_first_name,''),' ',COALESCE(o.customer_last_name,''))) AS name,
                       o.customer_phone AS phone
                FROM orders o
                WHERE o.status NOT IN ('voided','cancelled')
                  AND UPPER(o.currency_code) = 'KES'
                  AND o.created_at >= ?
                  {$outletSql}
            ), per_customer AS (
                SELECT ckey, MAX(name) AS name, MAX(phone) AS phone,
                       COUNT(*)                    AS frequency,
                       COALESCE(SUM(total_amount), 0) AS monetary,
                       MAX(created_at)             AS last_order_at
                FROM keyed WHERE ckey IS NOT NULL GROUP BY ckey
            ), scored AS (
                -- R is scored on ABSOLUTE days-quiet thresholds (stable and
                -- explainable; matches the shop's existing 60-day convention).
                -- F and M are scored relative to the cohort via CUME_DIST so
                -- the best customer always scores 5 — NTILE(5) over a small
                -- cohort caps at score=N and makes top segments unreachable.
                SELECT *,
                       CASE
                           WHEN last_order_at >= NOW() - INTERVAL '30 days'  THEN 5
                           WHEN last_order_at >= NOW() - INTERVAL '60 days'  THEN 4
                           WHEN last_order_at >= NOW() - INTERVAL '90 days'  THEN 3
                           WHEN last_order_at >= NOW() - INTERVAL '180 days' THEN 2
                           ELSE 1
                       END AS r_score,
                       CEIL(CUME_DIST() OVER (ORDER BY frequency ASC) * 5)::int AS f_score,
                       CEIL(CUME_DIST() OVER (ORDER BY monetary  ASC) * 5)::int AS m_score
                FROM per_customer
            )
            SELECT *,
                   CASE
                       WHEN r_score >= 4 AND f_score >= 4                     THEN 'champions'
                       WHEN r_score <= 2 AND (f_score >= 4 OR m_score >= 4)   THEN 'cant_lose'
                       WHEN r_score <= 2 AND f_score >= 3                     THEN 'at_risk'
                       WHEN r_score >= 3 AND f_score >= 3                     THEN 'loyal'
                       WHEN r_score >= 4 AND f_score <= 2                     THEN 'promising'
                       WHEN r_score <= 2 AND f_score <= 2                     THEN 'hibernating'
                       ELSE 'needs_attention'
                   END AS segment
            FROM scored
        ";

        $segments = collect(DB::select("
            SELECT segment, COUNT(*) AS customers,
                   COALESCE(SUM(monetary), 0) AS revenue_365,
                   ROUND(AVG(EXTRACT(EPOCH FROM (NOW() - last_order_at)) / 86400)) AS avg_days_quiet
            FROM ({$scored}) s
            GROUP BY segment
        ", [$since]))->keyBy('segment');

        $order = ['champions', 'loyal', 'promising', 'needs_attention', 'at_risk', 'cant_lose', 'hibernating'];
        $totalRevenue = (float) $segments->sum('revenue_365');
        $segmentRows = collect($order)->map(function ($seg) use ($segments, $totalRevenue) {
            $row = $segments->get($seg);
            return [
                'segment'        => $seg,
                'customers'      => (int) ($row->customers ?? 0),
                'revenue_365'    => round((float) ($row->revenue_365 ?? 0), 2),
                'revenue_share'  => $totalRevenue > 0 ? round((float) ($row->revenue_365 ?? 0) / $totalRevenue * 100, 1) : 0.0,
                'avg_days_quiet' => (int) ($row->avg_days_quiet ?? 0),
            ];
        })->values();

        // The call list: money walking out of the door, biggest first.
        $actionList = collect(DB::select("
            SELECT name, phone, segment, frequency, monetary, last_order_at
            FROM ({$scored}) s
            WHERE segment IN ('cant_lose', 'at_risk')
            ORDER BY monetary DESC
            LIMIT 15
        ", [$since]))->map(fn ($r) => [
            'name'          => $r->name !== '' ? $r->name : ($r->phone ?? 'Unknown'),
            'phone'         => $r->phone,
            'segment'       => $r->segment,
            'orders_365'    => (int) $r->frequency,
            'revenue_365'   => round((float) $r->monetary, 2),
            'last_order_at' => $r->last_order_at,
            'days_quiet'    => (int) CarbonImmutable::parse($r->last_order_at)->diffInDays($now),
        ])->values();

        $anonymous = DB::selectOne("
            SELECT COUNT(*) AS orders, COALESCE(SUM(o.total_amount), 0) AS revenue
            FROM orders o
            WHERE o.status NOT IN ('voided','cancelled')
              AND UPPER(o.currency_code) = 'KES'
              AND o.created_at >= ?
              AND {$key} IS NULL
              {$outletSql}
        ", [$since]);

        return [
            'window_days' => 365,
            'segments'    => $segmentRows,
            'action_list' => $actionList,
            'anonymous'   => [
                'orders'  => (int) ($anonymous->orders ?? 0),
                'revenue' => round((float) ($anonymous->revenue ?? 0), 2),
            ],
        ];
    }

    /**
     * Win-back economics — the RFM win-back list turned into a MEASURED
     * recovery engine: KES at risk per dormant customer, urgency against the
     * customer's OWN rhythm, manual-outreach logging, and 30-day recovery
     * attribution.
     *
     * Identity is the CANONICAL key (customer_id, else normalize_phone, else
     * lowercased email — same as rfmSegments) over the same trailing-365-day
     * sales-truth cohort.
     *
     * DORMANT = the customer's silence is long relative to THEIR OWN cadence,
     * not a flat 60-day rule: a monthly buyer 70 days quiet is an emergency,
     * an annual bulk buyer 70 days quiet is on schedule. Concretely a
     * customer is dormant when ALL of:
     *   - 2+ distinct purchase days in the window (a personal rhythm exists —
     *     the median gap between consecutive purchase days is measurable);
     *   - days_quiet > 1.5 × their median inter-purchase gap;
     *   - days_quiet >= 45 (noise floor — nobody is "dormant" at 3 weeks);
     *   - revenue_365 > 0 (there is money at risk).
     * urgency = days_quiet / median_gap ("3.2× overdue"). Single-purchase
     * customers have no measurable cadence and are deliberately excluded —
     * they are promising/hibernating RFM material, not win-back economics.
     *
     * OUTREACH rows (win_back_outreach) match a customer by customer_id OR by
     * phone: the table stores canonical E.164 digits with no '+' (the
     * replenishment_pings convention), the cohort key stores normalize_phone
     * output ('+'-prefixed), so the SQL bridge is '+' || w.phone.
     *
     * RECOVERY: an outreach is "won back" when the SAME identity places a
     * sales-truth order within 30 days AFTER the outreach; the recovered
     * value is that FIRST order's total (later orders are regular revenue,
     * not attribution). Note the happy asymmetry: a customer who recovers
     * stops being dormant, so they leave the list — the summary's trailing
     * 90-day won_back / recovered_revenue figures are where recoveries are
     * counted, while row-level `recovered` marks the rare relapse (recovered
     * once, quiet again).
     *
     * Summary: customers_at_risk / annual_value_at_risk (the WHOLE cohort,
     * not just the displayed page), contacted_30d (outreach rows),
     * won_back_90d (distinct customers with outreach in the last 90d who
     * ordered within 30d after it), recovered_revenue_90d (sum of distinct
     * first-orders-after-outreach), win_back_rate_pct (won-back customers /
     * distinct customers contacted in 90d). List is ranked revenue_365 desc,
     * capped at $limit.
     */
    public function winBackEconomics(int $limit = 50): array
    {
        $key = "COALESCE(o.customer_id::text, normalize_phone(o.customer_phone), LOWER(NULLIF(o.customer_email,'')))";
        $now   = CarbonImmutable::now(self::TZ);
        $today = $now->format('Y-m-d');
        $since = $now->subDays(365);
        $outletSql = $this->outletIds
            ? 'AND o.outlet_id IN (' . implode(',', array_map('intval', $this->outletIds)) . ')'
            : '';

        // 1. The dormant cohort, ranked by money at risk. Gaps are measured
        // between DISTINCT purchase days (two same-day orders are one visit,
        // and a 0-day gap would poison the median).
        $rows = DB::select("
            WITH keyed AS (
                SELECT {$key} AS ckey, o.customer_id, o.total_amount, o.created_at,
                       TRIM(CONCAT(COALESCE(o.customer_first_name,''),' ',COALESCE(o.customer_last_name,''))) AS name,
                       o.customer_phone AS phone
                FROM orders o
                WHERE o.status NOT IN ('voided','cancelled')
                  AND UPPER(o.currency_code) = 'KES'
                  AND o.created_at >= ?
                  AND {$key} IS NOT NULL
                  {$outletSql}
            ), buy_days AS (
                SELECT DISTINCT ckey, DATE(created_at) AS d FROM keyed
            ), gapped AS (
                SELECT ckey, d - LAG(d) OVER (PARTITION BY ckey ORDER BY d) AS gap_days
                FROM buy_days
            ), rhythm AS (
                SELECT ckey, PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY gap_days) AS median_gap
                FROM gapped WHERE gap_days IS NOT NULL
                GROUP BY ckey
            ), per_customer AS (
                SELECT ckey, MAX(name) AS name, MAX(phone) AS phone,
                       MAX(customer_id) AS customer_id,
                       COUNT(*) AS orders_365,
                       COALESCE(SUM(total_amount), 0) AS revenue_365,
                       MAX(created_at) AS last_order_at
                FROM keyed GROUP BY ckey
            )
            SELECT p.*, r.median_gap,
                   (?::date - DATE(p.last_order_at)) AS days_quiet
            FROM per_customer p
            JOIN rhythm r ON r.ckey = p.ckey
            WHERE r.median_gap > 0
              AND p.revenue_365 > 0
              AND (?::date - DATE(p.last_order_at)) >= 45
              AND (?::date - DATE(p.last_order_at)) > 1.5 * r.median_gap
            ORDER BY p.revenue_365 DESC
        ", [$since, $today, $today, $today]);

        // 2. Every outreach row, each with its FIRST attributable order (same
        // identity, within 30 days after the contact) — one query feeds both
        // the per-row "last contacted / won back" columns and the summary.
        $outreach = collect(DB::select("
            SELECT w.id, w.customer_id, w.phone, w.channel, w.created_at,
                   NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))), '') AS by_name,
                   fo.order_id, fo.order_number, fo.recovered_amount, fo.ordered_at
            FROM win_back_outreach w
            LEFT JOIN users u ON u.id = w.contacted_by
            LEFT JOIN LATERAL (
                SELECT o.id AS order_id, o.order_number, o.total_amount AS recovered_amount,
                       o.created_at AS ordered_at
                FROM orders o
                WHERE o.status NOT IN ('voided','cancelled')
                  AND UPPER(o.currency_code) = 'KES'
                  AND o.created_at >  w.created_at
                  AND o.created_at <= w.created_at + INTERVAL '30 days'
                  AND (
                        (w.customer_id IS NOT NULL AND o.customer_id = w.customer_id)
                     OR (w.phone IS NOT NULL AND normalize_phone(o.customer_phone) = '+' || w.phone)
                  )
                  {$outletSql}
                ORDER BY o.created_at ASC
                LIMIT 1
            ) fo ON TRUE
            ORDER BY w.created_at DESC
        "));

        // Latest outreach per identity handle (rows are already newest-first).
        $latestByCustomerId = [];
        $latestByPhone      = [];
        foreach ($outreach as $w) {
            if ($w->customer_id !== null && !isset($latestByCustomerId[(int) $w->customer_id])) {
                $latestByCustomerId[(int) $w->customer_id] = $w;
            }
            if ($w->phone !== null && $w->phone !== '' && !isset($latestByPhone[$w->phone])) {
                $latestByPhone[$w->phone] = $w;
            }
        }

        $customers = collect($rows)->take($limit)->map(function ($r) use ($latestByCustomerId, $latestByPhone) {
            $canonPhone = Phone::canonical($r->phone);
            $byId    = $r->customer_id !== null ? ($latestByCustomerId[(int) $r->customer_id] ?? null) : null;
            $byPhone = $canonPhone !== null ? ($latestByPhone[$canonPhone] ?? null) : null;
            // Same customer may be reachable via both handles — take the newer.
            $last = ($byId && $byPhone)
                ? ($byId->created_at >= $byPhone->created_at ? $byId : $byPhone)
                : ($byId ?? $byPhone);

            return [
                'ckey'            => $r->ckey,
                'customer_id'     => $r->customer_id !== null ? (int) $r->customer_id : null,
                'name'            => ($r->name !== null && $r->name !== '') ? $r->name : ($r->phone ?? 'Unknown'),
                'phone'           => $r->phone,
                'revenue_365'     => round((float) $r->revenue_365, 2),
                'orders_365'      => (int) $r->orders_365,
                'last_order_at'   => $r->last_order_at,
                'days_quiet'      => (int) $r->days_quiet,
                'median_gap_days' => round((float) $r->median_gap, 1),
                'urgency'         => round((int) $r->days_quiet / max((float) $r->median_gap, 1), 1),
                'last_outreach'   => $last ? [
                    'channel' => $last->channel,
                    'at'      => $last->created_at,
                    'by_name' => $last->by_name,
                ] : null,
                'recovered'       => ($last && $last->order_id !== null) ? [
                    'order_number' => $last->order_number,
                    'amount'       => round((float) $last->recovered_amount, 2),
                    'at'           => $last->ordered_at,
                ] : null,
            ];
        })->values();

        // 3. Summary. Identity handle for de-duping outreach rows: prefer the
        // customer_id, fall back to canonical phone, else the row id.
        $handle = fn ($w) => $w->customer_id !== null ? 'c:' . $w->customer_id
            : (($w->phone !== null && $w->phone !== '') ? 'p:' . $w->phone : 'w:' . $w->id);

        $o90 = $outreach->filter(fn ($w) => CarbonImmutable::parse($w->created_at) >= $now->subDays(90));
        $contacted90 = $o90->map($handle)->unique();
        $wonBack90   = $o90->filter(fn ($w) => $w->order_id !== null)->map($handle)->unique();
        $recovered90 = $o90->filter(fn ($w) => $w->order_id !== null)
            ->unique(fn ($w) => $w->order_id)
            ->sum(fn ($w) => (float) $w->recovered_amount);

        return [
            'summary' => [
                'customers_at_risk'     => count($rows),
                'annual_value_at_risk'  => round((float) collect($rows)->sum('revenue_365'), 2),
                'contacted_30d'         => $outreach->filter(fn ($w) => CarbonImmutable::parse($w->created_at) >= $now->subDays(30))->count(),
                'won_back_90d'          => $wonBack90->count(),
                'recovered_revenue_90d' => round($recovered90, 2),
                'win_back_rate_pct'     => $contacted90->count() > 0
                    ? round($wonBack90->count() / $contacted90->count() * 100, 1)
                    : 0.0,
            ],
            'customers' => $customers->all(),
        ];
    }

    /**
     * Replenishment Radar — proactive revenue on consumables.
     *
     * Churches rebuy communion bread, altar wine and prefilled cups on a
     * rhythm. Per (customer, product) pair this detects that rhythm from the
     * trailing 540 days of sales truth and surfaces who is DUE or OVERDUE to
     * reorder, with the expected value of the reorder.
     *
     * Identity is the CANONICAL key (customer_id, else normalize_phone, else
     * lowercased email — same as rfmSegments). A purchase EVENT is one
     * distinct purchase date per pair (same-day orders collapse); its value
     * is that day's total line spend on the product.
     *
     * Two tiers of rhythm:
     *   established — >= 3 events, the coefficient of variation of the
     *     consecutive-date gaps is <= 0.6, and the median gap (the cycle)
     *     is a believable 5–120 days. Unchanged from day one.
     *   provisional — EXACTLY 2 events whose single gap is a believable
     *     5–120 days; the cycle IS that gap. One repeat purchase is a
     *     hypothesis, not a confirmed rhythm — these rows surface for the
     *     humans (young datasets have real signal here) but automation
     *     (ReplenishmentPingService) only ever acts on established rows.
     *     The 3rd order either confirms the pair into established or the
     *     CV gate quietly drops it.
     *
     * next_due = last purchase + cycle, same due logic for both tiers:
     *   overdue  — today is past next_due by more than 25% of the cycle
     *   due_soon — today is within 7 days of next_due (or past it)
     * Pairs more than 3 cycles past their last purchase are LAPSED — that is
     * win-back territory (rfmSegments), not radar material — and excluded.
     *
     * Established rows always sort before provisional; within a tier it is
     * overdue-first, then expected value desc. summary.maturing_pairs counts
     * ALL 2-event pairs regardless of due status — the pipeline metric.
     */
    public function replenishmentRadar(int $limit = 50): array
    {
        $key = "COALESCE(o.customer_id::text, normalize_phone(o.customer_phone), LOWER(NULLIF(o.customer_email,'')))";
        $now   = CarbonImmutable::now(self::TZ);
        $today = $now->format('Y-m-d');
        $since = $now->subDays(540);
        $outletSql = $this->outletIds
            ? 'AND o.outlet_id IN (' . implode(',', array_map('intval', $this->outletIds)) . ')'
            : '';

        $rows = DB::select("
            WITH events AS (
                -- One purchase EVENT per (customer, product, day); the event's
                -- value is that day's total line spend on the product.
                SELECT {$key} AS ckey, oi.product_id,
                       DATE(o.created_at) AS buy_date,
                       SUM(oi.total_price) AS event_value,
                       MAX(TRIM(CONCAT(COALESCE(o.customer_first_name,''),' ',COALESCE(o.customer_last_name,'')))) AS name,
                       MAX(o.customer_phone) AS phone
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                WHERE o.status NOT IN ('voided','cancelled')
                  AND UPPER(o.currency_code) = 'KES'
                  AND oi.product_id IS NOT NULL
                  AND o.created_at >= ?
                  AND {$key} IS NOT NULL
                  {$outletSql}
                GROUP BY 1, 2, 3
            ), gapped AS (
                SELECT *,
                       buy_date - LAG(buy_date) OVER (PARTITION BY ckey, product_id ORDER BY buy_date) AS gap_days
                FROM events
            ), pairs AS (
                SELECT ckey, product_id,
                       MAX(name) AS name, MAX(phone) AS phone,
                       COUNT(*) AS purchase_events,
                       MAX(buy_date) AS last_purchase_at,
                       ROUND(AVG(event_value)::numeric, 2) AS avg_value,
                       PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY gap_days) AS median_gap,
                       AVG(gap_days) AS avg_gap,
                       STDDEV_SAMP(gap_days) AS sd_gap
                FROM gapped
                GROUP BY ckey, product_id
                HAVING COUNT(*) >= 2
            ), rhythmic AS (
                -- established: 3+ events, interval CV <= 0.6, believable cycle.
                -- provisional: exactly 2 events, single believable gap = cycle.
                SELECT p.*,
                       CASE WHEN p.purchase_events >= 3 THEN 'established' ELSE 'provisional' END AS tier,
                       ROUND(p.median_gap)::int AS cycle_days,
                       p.last_purchase_at + ROUND(p.median_gap)::int AS next_due_at
                FROM pairs p
                WHERE p.avg_gap > 0
                  AND p.median_gap BETWEEN 5 AND 120
                  AND (p.purchase_events = 2
                       OR COALESCE(p.sd_gap / NULLIF(p.avg_gap, 0), 0) <= 0.6)
            )
            SELECT r.ckey, r.name, r.phone, r.product_id,
                   COALESCE(pt.name, pr.slug, pr.sku) AS product_name,
                   r.tier, r.cycle_days, r.purchase_events, r.last_purchase_at, r.next_due_at,
                   (?::date - r.next_due_at) AS days_over,
                   r.avg_value,
                   CASE WHEN ?::date > r.next_due_at + ROUND(r.cycle_days * 0.25)::int
                        THEN 'overdue' ELSE 'due_soon' END AS status
            FROM rhythmic r
            JOIN products pr ON pr.id = r.product_id
            LEFT JOIN product_translations pt
                   ON pt.product_id = pr.id AND pt.language_code = 'en'
            WHERE ?::date <= r.last_purchase_at + r.cycle_days * 3   -- lapsed = win-back, not radar
              AND ?::date >= r.next_due_at - 7                       -- the due window has opened
            ORDER BY CASE WHEN r.tier = 'established' THEN 0 ELSE 1 END,
                     CASE WHEN ?::date > r.next_due_at + ROUND(r.cycle_days * 0.25)::int THEN 0 ELSE 1 END,
                     r.avg_value DESC
        ", [$since, $today, $today, $today, $today, $today]);

        // Latest automated ping per (canonical phone, product) — the pings
        // table stores canonical E.164 digits, radar rows carry the raw
        // order phone, so the join key is Phone::canonical on our side.
        $lastPings = collect(DB::select("
            SELECT DISTINCT ON (phone, product_id) phone, product_id, status, created_at
            FROM replenishment_pings
            WHERE status IN ('sent', 'failed')
            ORDER BY phone, product_id, created_at DESC
        "))->keyBy(fn ($p) => $p->phone . '|' . $p->product_id);

        $due = collect($rows)->map(function ($r) use ($lastPings) {
            $ping = $lastPings->get(Phone::canonical($r->phone) . '|' . $r->product_id);

            return [
                'ckey'             => $r->ckey,
                'name'             => ($r->name !== null && $r->name !== '') ? $r->name : ($r->phone ?? 'Unknown'),
                'phone'            => $r->phone,
                'product_id'       => (int) $r->product_id,
                'product_name'     => $r->product_name,
                'tier'             => $r->tier,
                'cycle_days'       => (int) $r->cycle_days,
                'purchase_events'  => (int) $r->purchase_events,
                'last_purchase_at' => $r->last_purchase_at,
                'next_due_at'      => $r->next_due_at,
                'days_over'        => (int) $r->days_over,
                'status'           => $r->status,
                'avg_value'        => round((float) $r->avg_value, 2),
                'last_pinged_at'   => $ping?->created_at,
                'last_ping_status' => $ping?->status,
            ];
        })->values();

        $pings30d = (int) DB::table('replenishment_pings')
            ->where('status', 'sent')
            ->where('created_at', '>=', $now->subDays(30))
            ->count();

        // The pipeline metric: every pair sitting at exactly 2 purchase dates,
        // due or not — one more order confirms (or kills) each cycle.
        $maturingPairs = (int) (DB::selectOne("
            WITH events AS (
                SELECT {$key} AS ckey, oi.product_id
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                WHERE o.status NOT IN ('voided','cancelled')
                  AND UPPER(o.currency_code) = 'KES'
                  AND oi.product_id IS NOT NULL
                  AND o.created_at >= ?
                  AND {$key} IS NOT NULL
                  {$outletSql}
                GROUP BY 1, 2, DATE(o.created_at)
            )
            SELECT COUNT(*) AS maturing
            FROM (SELECT 1 FROM events GROUP BY ckey, product_id HAVING COUNT(*) = 2) m
        ", [$since])->maturing ?? 0);

        return [
            'summary' => [
                'due_customers'     => $due->pluck('ckey')->unique()->count(),
                'due_pairs'         => $due->count(),
                'provisional_pairs' => $due->where('tier', 'provisional')->count(),
                'maturing_pairs'    => $maturingPairs,
                'expected_revenue'  => round((float) $due->sum('avg_value'), 2),
                'pings_30d'         => $pings30d,
            ],
            'due' => $due->take($limit)->values()->all(),
        ];
    }

    // ── Financial intelligence (Phase 5) ──────────────────────────────────────

    /**
     * P&L on the earned-revenue rule (docs glossary): revenue counts when an
     * order becomes FULLY PAID, dated by its final settling payment. COGS is
     * estimated from the price book's cost_price per line — lines without a
     * cost row are counted and reported, never valued at zero silently.
     */
    public function earnedPnl(Carbon $s, Carbon $e): array
    {
        $scope = $this->outletScopeSql('o.outlet_id');
        $earned = DB::selectOne("
            WITH paid AS (
                SELECT order_id, SUM(amount - COALESCE(refund_amount,0)) AS net,
                       MAX(COALESCE(paid_at, created_at)) AS settled_at
                FROM payments WHERE status = 'paid' GROUP BY order_id
            )
            SELECT COUNT(*) AS orders, COALESCE(SUM(o.total_amount), 0) AS revenue
            FROM orders o JOIN paid p ON p.order_id = o.id
            WHERE o.status NOT IN ('voided','cancelled')
              AND UPPER(o.currency_code) = 'KES' {$scope}
              AND p.net >= o.total_amount - 0.01
              AND p.settled_at BETWEEN ? AND ?
        ", [$s, $e]);

        $cogs = DB::selectOne("
            WITH paid AS (
                SELECT order_id, SUM(amount - COALESCE(refund_amount,0)) AS net,
                       MAX(COALESCE(paid_at, created_at)) AS settled_at
                FROM payments WHERE status = 'paid' GROUP BY order_id
            )
            -- Prefer the cost snapshotted on the line at sale time; fall back to
            -- the current product cost for historical lines that predate it.
            SELECT COALESCE(SUM(oi.quantity * COALESCE(oi.cost_price, pr.cost_price)), 0) AS cogs,
                   COUNT(*) FILTER (WHERE COALESCE(oi.cost_price, pr.cost_price) IS NULL) AS unpriced_lines
            FROM orders o
            JOIN paid p ON p.order_id = o.id
            JOIN order_items oi ON oi.order_id = o.id
            LEFT JOIN LATERAL (
                SELECT pp.cost_price FROM product_prices pp
                WHERE pp.product_id = oi.product_id AND UPPER(pp.currency_code) = 'KES'
                  AND (pp.product_variant_id = oi.product_variant_id OR pp.product_variant_id IS NULL)
                ORDER BY (pp.product_variant_id IS NOT NULL AND pp.product_variant_id = oi.product_variant_id) DESC
                LIMIT 1
            ) pr ON TRUE
            WHERE o.status NOT IN ('voided','cancelled')
              AND UPPER(o.currency_code) = 'KES' {$scope}
              AND p.net >= o.total_amount - 0.01
              AND p.settled_at BETWEEN ? AND ?
        ", [$s, $e]);

        $expensesTotal = (float) DB::table('expenses')
            ->where('status', 'completed')
            ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
            ->whereBetween('expense_date', [$s->format('Y-m-d'), $e->format('Y-m-d')])
            ->sum('amount_kes');

        $revenue = round((float) $earned->revenue, 2);
        $cogsVal = round((float) $cogs->cogs, 2);
        return [
            'earned_orders'  => (int) $earned->orders,
            'earned_revenue' => $revenue,
            'cogs_estimate'  => $cogsVal,
            'unpriced_lines' => (int) $cogs->unpriced_lines,
            'gross_profit'   => round($revenue - $cogsVal, 2),
            'expenses'       => round($expensesTotal, 2),
            'net_profit'     => round($revenue - $cogsVal - $expensesTotal, 2),
            'gross_margin_pct' => $revenue > 0 ? round(($revenue - $cogsVal) / $revenue * 100, 1) : null,
        ];
    }

    /** Completed expenses by category, with the category's monthly budget. */
    public function expensesByCategory(Carbon $s, Carbon $e)
    {
        return DB::table('expenses as x')
            ->leftJoin('expense_categories as ec', 'ec.id', '=', 'x.category_id')
            ->where('x.status', 'completed')
            ->when($this->outletIds, fn ($q) => $q->whereIn('x.outlet_id', $this->outletIds))
            ->whereBetween('x.expense_date', [$s->format('Y-m-d'), $e->format('Y-m-d')])
            ->groupBy('ec.id', 'ec.name', 'ec.budget_monthly')
            ->selectRaw("COALESCE(ec.name, 'Uncategorised') AS category,
                COALESCE(SUM(x.amount_kes), 0) AS spent,
                COUNT(*) AS entries, ec.budget_monthly")
            ->orderByDesc(DB::raw('COALESCE(SUM(x.amount_kes), 0)'))
            ->get();
    }

    /** Weekly money in (collected) vs money out (expenses) over the window. */
    public function cashFlowWeekly(Carbon $s, Carbon $e): array
    {
        $in = $this->moneyBase()
            ->whereBetween(DB::raw(self::PAID_AT), [$s, $e])
            ->selectRaw("DATE_TRUNC('week', " . self::PAID_AT . ")::date AS wk,
                COALESCE(SUM(p.amount - COALESCE(p.refund_amount,0)), 0) AS v")
            ->groupBy(DB::raw("DATE_TRUNC('week', " . self::PAID_AT . ")"))
            ->orderBy('wk')->pluck('v', 'wk');

        $out = DB::table('expenses')
            ->where('status', 'completed')
            ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
            ->whereBetween('expense_date', [$s->format('Y-m-d'), $e->format('Y-m-d')])
            ->selectRaw("DATE_TRUNC('week', expense_date)::date AS wk, COALESCE(SUM(amount_kes), 0) AS v")
            ->groupBy(DB::raw("DATE_TRUNC('week', expense_date)"))
            ->orderBy('wk')->pluck('v', 'wk');

        $weeks = collect($in->keys())->merge($out->keys())->unique()->sort()->values();
        return $weeks->map(fn ($wk) => [
            'week' => $wk,
            'in'   => round((float) ($in[$wk] ?? 0), 2),
            'out'  => round((float) ($out[$wk] ?? 0), 2),
            'net'  => round((float) ($in[$wk] ?? 0) - (float) ($out[$wk] ?? 0), 2),
        ])->all();
    }

    /** Money truth per payment rail: gross, refunds, net, count. */
    public function methodReconciliation(Carbon $s, Carbon $e)
    {
        return $this->moneyBase()
            ->whereBetween(DB::raw(self::PAID_AT), [$s, $e])
            ->groupBy('p.payment_method')
            ->selectRaw("p.payment_method AS method, COUNT(*) AS payments,
                COALESCE(SUM(p.amount), 0) AS gross,
                COALESCE(SUM(COALESCE(p.refund_amount, 0)), 0) AS refunds,
                COALESCE(SUM(p.amount - COALESCE(p.refund_amount, 0)), 0) AS net")
            ->orderByDesc(DB::raw('COALESCE(SUM(p.amount - COALESCE(p.refund_amount, 0)), 0)'))
            ->get();
    }

    // ── Insight detectors (Phase 6) ───────────────────────────────────────────
    // Deterministic, data-cited, never fabricated: each detector reads real
    // rows, does arithmetic a human could reproduce, and links to the page
    // where the finding gets acted on.

    /**
     * Material runway: available stock ÷ the last 30 days' burn rate
     * (quantity_used on allocations of orders raised in that window).
     * Only materials that are actually burning can run out.
     */
    public function materialRunway(int $thresholdDays = 14)
    {
        $now = CarbonImmutable::now(self::TZ);
        $scopeMi = $this->outletScopeSql('mi.outlet_id');
        $scopePo = $this->outletScopeSql('po.outlet_id');

        return collect(DB::select("
            SELECT m.id, m.name, m.unit_of_measure AS unit,
                   COALESCE(a.available, 0) AS available,
                   ROUND((b.used_30d / 30.0)::numeric, 2) AS daily_burn,
                   ROUND((COALESCE(a.available, 0) / (b.used_30d / 30.0))::numeric, 1) AS runway_days
            FROM materials m
            JOIN (
                SELECT ma.material_id, SUM(ma.quantity_used) AS used_30d
                FROM material_allocations ma
                JOIN production_orders po ON po.id = ma.production_order_id
                WHERE po.status <> 'cancelled' AND po.created_at >= ? {$scopePo}
                GROUP BY ma.material_id
                HAVING SUM(ma.quantity_used) > 0
            ) b ON b.material_id = m.id
            LEFT JOIN (
                SELECT mi.material_id, SUM(mi.quantity_available) AS available
                FROM material_inventory mi WHERE TRUE {$scopeMi} GROUP BY mi.material_id
            ) a ON a.material_id = m.id
            WHERE m.is_active = TRUE
              AND COALESCE(a.available, 0) / (b.used_30d / 30.0) < ?
            ORDER BY COALESCE(a.available, 0) / (b.used_30d / 30.0)
            LIMIT 10
        ", [$now->subDays(30), $thresholdDays]));
    }

    /**
     * Revenue trend: the last three FULL months, strictly declining. A streak
     * is a pattern worth a sentence; noise is not.
     */
    public function revenueTrend(): ?array
    {
        $now = CarbonImmutable::now(self::TZ);
        $months = [];
        for ($i = 3; $i >= 1; $i--) {
            $start = $now->subMonthsNoOverflow($i)->startOfMonth();
            $end   = $now->subMonthsNoOverflow($i)->endOfMonth();
            $months[] = [
                'month'   => $start->format('M Y'),
                'revenue' => round((float) $this->salesBase()
                    ->whereBetween('orders.created_at', [$start->toMutable(), $end->toMutable()])
                    ->sum('total_amount'), 2),
            ];
        }

        [$m3, $m2, $m1] = $months; // oldest → latest full month
        $declining = $m3['revenue'] > $m2['revenue'] && $m2['revenue'] > $m1['revenue'] && $m3['revenue'] > 0;
        if (!$declining) {
            return null;
        }

        return [
            'months'      => $months,
            'decline_pct' => round(($m3['revenue'] - $m1['revenue']) / $m3['revenue'] * 100, 1),
        ];
    }

    /**
     * Supplier price drift: the latest price paid for a material vs the
     * average of the PRIOR purchases in the trailing 90 days. A >10% jump is
     * a negotiation (or a substitution) waiting to happen.
     */
    public function supplierPriceDrift(float $thresholdPct = 10.0)
    {
        $now = CarbonImmutable::now(self::TZ);
        $scopePo = $this->outletScopeSql('po.outlet_id');

        return collect(DB::select("
            WITH buys AS (
                SELECT poi.material_id, poi.unit_price, po.order_date, sup.name AS supplier, sup.id AS supplier_id,
                       ROW_NUMBER() OVER (PARTITION BY poi.material_id ORDER BY po.order_date DESC, poi.id DESC) AS rn
                FROM purchase_order_items poi
                JOIN purchase_orders po ON po.id = poi.purchase_order_id AND po.status <> 'cancelled'
                JOIN suppliers sup ON sup.id = po.supplier_id
                WHERE poi.material_id IS NOT NULL AND po.order_date >= ? {$scopePo}
            )
            SELECT b.material_id, m.name, b.unit_price AS last_price, b.supplier, b.supplier_id, b.order_date,
                   prior.avg_price AS avg_prior,
                   ROUND(((b.unit_price - prior.avg_price) / prior.avg_price * 100)::numeric, 1) AS drift_pct
            FROM buys b
            JOIN materials m ON m.id = b.material_id
            JOIN (
                SELECT material_id, AVG(unit_price) AS avg_price
                FROM buys WHERE rn > 1 GROUP BY material_id
            ) prior ON prior.material_id = b.material_id
            WHERE b.rn = 1 AND prior.avg_price > 0
              AND b.unit_price > prior.avg_price * (1 + ? / 100.0)
            ORDER BY (b.unit_price - prior.avg_price) / prior.avg_price DESC
            LIMIT 10
        ", [$now->subDays(90)->format('Y-m-d'), $thresholdPct]));
    }

    // ── Collections funnel ────────────────────────────────────────────────────
    //
    // Money on the table, staged: quotations that never became orders, orders
    // where a deposit landed and then nothing moved, and confirmed balances
    // aging out. Balances reuse openBalances()/outstandingAging() so this
    // report can never disagree with the Overview's outstanding numbers.

    /** How many days without payment movement makes a deposit "stalled". */
    private const STALL_DAYS = 7;

    /** Open quotations: on offer, not yet an order (KES, scoped). */
    private function openQuotes()
    {
        return DB::table('quotations')
            ->whereNotIn('status', ['converted', 'declined', 'expired'])
            ->whereNull('converted_order_id')
            ->whereRaw("UPPER(currency_code) = 'KES'")
            ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds));
    }

    /** openBalances() plus WHEN money last moved on each order. */
    private function openBalancesWithLastPayment()
    {
        return $this->openBalances()->leftJoinSub(
            DB::table('payments')->where('status', 'paid')
                ->selectRaw('order_id, MAX(COALESCE(paid_at, created_at)) AS last_paid_at')
                ->groupBy('order_id'),
            'lp', 'lp.order_id', '=', 'orders.id',
        );
    }

    /**
     * The collections funnel — every shilling promised but not collected, in
     * three stages with per-row action lists (top 25 each by value):
     *
     *  1. open_quotes      — issued offers that never became orders.
     *  2. stalled_deposits — deposit/partial orders with no payment movement
     *                        for STALL_DAYS+ days (the warmest money there is:
     *                        the customer already paid once).
     *  3. unpaid_balances  — every open balance, aging-bucketed.
     *
     * "As of now" by design, like the replenishment radar: owed/stalled only
     * means anything against today. Quote age runs from issue (issued_at,
     * else created_at); balance age from the order date, matching
     * outstandingAging() so the buckets here ARE the Overview's buckets.
     */
    public function collectionsFunnel(): array
    {
        $now      = CarbonImmutable::now(self::TZ);
        $today    = $now->format('Y-m-d');
        $owed     = self::OWED;
        $custName = "TRIM(CONCAT(COALESCE(customer_first_name,''),' ',COALESCE(customer_last_name,'')))";

        // Stage 1 — open quotes.
        $quoteRows = $this->openQuotes()
            ->selectRaw("id, COALESCE(quote_number, CONCAT('#', id)) AS number,
                {$custName} AS customer, customer_phone AS phone,
                total_amount AS value, status, valid_until AS expires_at,
                (?::date - COALESCE(issued_at, created_at)::date) AS age_days", [$today])
            ->orderByDesc('total_amount')
            ->limit(25)
            ->get()
            ->map(fn ($r) => [
                'id'         => (int) $r->id,
                'number'     => $r->number,
                'customer'   => $r->customer,
                'phone'      => $r->phone,
                'value'      => round((float) $r->value, 2),
                'age_days'   => (int) $r->age_days,
                'status'     => $r->status,
                'expires_at' => $r->expires_at,
            ])->values()->all();

        $quoteSummary = $this->openQuotes()
            ->selectRaw("COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS value,
                COALESCE(AVG(?::date - COALESCE(issued_at, created_at)::date),0) AS avg_age", [$today])
            ->first();

        // Stage 2 — stalled deposits: money already down, then silence.
        $stallCutoff = $now->subDays(self::STALL_DAYS)->toMutable();
        $stalledBase = fn () => $this->openBalancesWithLastPayment()
            ->whereIn('payment_status', ['deposit', 'partial'])
            ->whereRaw('COALESCE(lp.last_paid_at, orders.created_at) < ?', [$stallCutoff]);

        $stalledRows = $stalledBase()
            ->selectRaw("orders.id, order_number AS number, {$custName} AS customer,
                customer_phone AS phone, total_amount AS total,
                LEAST(COALESCE(pp.paid,0), total_amount) AS deposit_paid,
                {$owed} AS balance_due,
                (?::date - COALESCE(lp.last_paid_at, orders.created_at)::date) AS days_since_last_payment", [$today])
            ->orderByDesc(DB::raw($owed))
            ->limit(25)
            ->get()
            ->map(fn ($r) => [
                'id'                      => (int) $r->id,
                'number'                  => $r->number,
                'customer'                => $r->customer,
                'phone'                   => $r->phone,
                'total'                   => round((float) $r->total, 2),
                'deposit_paid'            => round((float) $r->deposit_paid, 2),
                'balance_due'             => round((float) $r->balance_due, 2),
                'days_since_last_payment' => (int) $r->days_since_last_payment,
            ])->values()->all();

        $stalledSummary = $stalledBase()
            ->selectRaw("COUNT(*) AS n,
                COALESCE(SUM(LEAST(COALESCE(pp.paid,0), total_amount)),0) AS held,
                COALESCE(SUM({$owed}),0) AS due")
            ->first();

        // Stage 3 — every open balance, aging-bucketed.
        [$d30, $d60, $d90] = $this->agingCutoffs();
        $unpaidRows = $this->openBalances()
            ->selectRaw("orders.id, order_number AS number, {$custName} AS customer,
                customer_phone AS phone, total_amount AS total,
                COALESCE(pp.paid,0) AS paid, {$owed} AS balance,
                (?::date - orders.created_at::date) AS days_outstanding,
                CASE WHEN orders.created_at >= ? THEN '0_30'
                     WHEN orders.created_at >= ? THEN '31_60'
                     WHEN orders.created_at >= ? THEN '61_90'
                     ELSE '90_plus' END AS bucket", [$today, $d30, $d60, $d90])
            ->orderByDesc(DB::raw($owed))
            ->limit(25)
            ->get()
            ->map(fn ($r) => [
                'id'               => (int) $r->id,
                'number'           => $r->number,
                'customer'         => $r->customer,
                'phone'            => $r->phone,
                'total'            => round((float) $r->total, 2),
                'paid'             => round((float) $r->paid, 2),
                'balance'          => round((float) $r->balance, 2),
                'days_outstanding' => (int) $r->days_outstanding,
                'bucket'           => $r->bucket,
            ])->values()->all();

        $outstanding = $this->outstandingBalance();

        // Funnel speed: how quotes convert and how deposits complete (90d).
        $since90 = $now->subDays(90)->toMutable();

        $convRow = DB::table('quotations')
            ->where('created_at', '>=', $since90)
            ->whereRaw("UPPER(currency_code) = 'KES'")
            ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
            ->selectRaw("COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status = 'converted' OR converted_order_id IS NOT NULL) AS converted")
            ->first();

        $avgQuoteToOrder = DB::table('quotations as q')
            ->join('orders as o', 'o.id', '=', 'q.converted_order_id')
            ->where('q.created_at', '>=', $since90)
            ->when($this->outletIds, fn ($q) => $q->whereIn('q.outlet_id', $this->outletIds))
            ->selectRaw('AVG(o.created_at::date - q.created_at::date) AS d')
            ->value('d');

        // Of orders fully settled in the last 90 days via 2+ payments: days
        // from the first payment (the deposit) to the last (paid in full).
        $avgDepositToPaid = DB::table('orders')
            ->whereNotIn('status', self::SALE_EXCLUDED_STATUSES)
            ->whereRaw("UPPER(currency_code) = 'KES'")
            ->where('payment_status', 'paid')
            ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
            ->joinSub(
                DB::table('payments')->where('status', 'paid')
                    ->selectRaw('order_id, COUNT(*) AS n,
                        MIN(COALESCE(paid_at, created_at)) AS first_pay,
                        MAX(COALESCE(paid_at, created_at)) AS last_pay')
                    ->groupBy('order_id'),
                'ps', 'ps.order_id', '=', 'orders.id',
            )
            ->where('ps.n', '>=', 2)
            ->where('ps.last_pay', '>=', $since90)
            ->selectRaw('AVG(ps.last_pay::date - ps.first_pay::date) AS d')
            ->value('d');

        return [
            'summary' => [
                // One headline: everything promised but not collected.
                'money_on_table' => round((float) $quoteSummary->value + $outstanding['amount'], 2),
                'open_quotes' => [
                    'count'        => (int) $quoteSummary->n,
                    'value'        => round((float) $quoteSummary->value, 2),
                    'avg_age_days' => round((float) $quoteSummary->avg_age, 1),
                ],
                'stalled_deposits' => [
                    'count'        => (int) $stalledSummary->n,
                    'deposit_held' => round((float) $stalledSummary->held, 2),
                    'balance_due'  => round((float) $stalledSummary->due, 2),
                ],
                'unpaid_balances' => [
                    'count' => $outstanding['orders'],
                    'value' => $outstanding['amount'],
                ],
                'aging' => $this->outstandingAging(),
                'conversion' => [
                    'quotes_converted_rate_90d' => $convRow->total > 0
                        ? round($convRow->converted / $convRow->total * 100, 1) : null,
                    'avg_days_quote_to_order'   => $avgQuoteToOrder !== null ? round((float) $avgQuoteToOrder, 1) : null,
                    'avg_days_deposit_to_paid'  => $avgDepositToPaid !== null ? round((float) $avgDepositToPaid, 1) : null,
                ],
            ],
            'open_quotes'      => $quoteRows,
            'stalled_deposits' => $stalledRows,
            'unpaid_balances'  => $unpaidRows,
        ];
    }

    /**
     * Attach-rate intelligence — basket affinity mining over the trailing
     * $days of sales truth. The question it answers: "when a customer buys X,
     * what should have been in the basket with it, and how much revenue did
     * we leave on the counter by not asking?"
     *
     * Definitions (all sales truth: non-voided/cancelled KES orders, outlet
     * scope in the query like every other metric here):
     *
     *  - BASKET      = the distinct products on one order (order_items grouped
     *                  by order; null product_id lines excluded).
     *  - ANCHOR      = a product appearing in >= 5 baskets — enough history to
     *                  say anything at all.
     *  - attach_rate = baskets(anchor + companion) / baskets(anchor)
     *  - lift        = attach_rate / (baskets(companion) / total_baskets) —
     *                  how much MORE likely the companion is next to the
     *                  anchor than on its own. lift 1.0 = coincidence.
     *  - Kept pairs: co-basket support >= 3 AND lift >= 1.2 AND
     *                attach_rate >= 0.10; top 5 companions per anchor.
     *  - avg_value   = the companion's average line spend (SUM total_price per
     *                  basket) across the baskets where it DID attach.
     *  - missed      = baskets(anchor) - baskets(anchor + companion): anchor
     *                  sales where nobody offered the companion.
     *  - missed_revenue_estimate = missed x attach_rate x avg_value — an
     *                  EXPECTED-VALUE PROXY, not booked money: "if the missed
     *                  baskets had attached at the observed rate". Labelled an
     *                  estimate everywhere it surfaces.
     *
     * Anchors rank by their summed companion missed-revenue (the fix-this-
     * first order), capped at 30. The whole result is cached 10 minutes per
     * (outlet scope, days) — affinity moves slowly; the POS suggestion
     * endpoint hits this on every cart change and must not re-mine baskets
     * each keystroke.
     */
    public function attachRates(int $days = 180): array
    {
        $scopeKey = $this->outletIds !== null
            ? implode('-', collect($this->outletIds)->map(fn ($id) => (int) $id)->sort()->values()->all())
            : 'all';

        return \Illuminate\Support\Facades\Cache::remember(
            "metrics:attach_rates:{$scopeKey}:{$days}",
            600,
            fn () => $this->computeAttachRates($days),
        );
    }

    /** The uncached mining pass behind attachRates(). */
    private function computeAttachRates(int $days): array
    {
        $since = CarbonImmutable::now(self::TZ)->subDays($days)->startOfDay();
        $outletSql = $this->outletIds
            ? 'AND o.outlet_id IN (' . implode(',', array_map('intval', $this->outletIds)) . ')'
            : '';

        // One CTE both queries share: a basket line = (order, product) with
        // that basket's total spend on the product.
        $linesCte = "
            lines AS (
                SELECT o.id AS order_id, oi.product_id,
                       SUM(oi.total_price) AS line_value
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                WHERE o.status NOT IN ('voided','cancelled')
                  AND UPPER(o.currency_code) = 'KES'
                  AND oi.product_id IS NOT NULL
                  AND o.created_at >= ?
                  {$outletSql}
                GROUP BY o.id, oi.product_id
            )";

        $shape = DB::selectOne("
            WITH {$linesCte}
            SELECT COUNT(*) AS total_baskets,
                   COALESCE(AVG(n), 0) AS avg_items,
                   COUNT(*) FILTER (WHERE n >= 2) AS multi
            FROM (SELECT order_id, COUNT(*) AS n FROM lines GROUP BY order_id) b
        ", [$since]);

        $totalBaskets = (int) $shape->total_baskets;
        $summary = [
            'total_baskets'                  => $totalBaskets,
            'multi_item_pct'                 => $totalBaskets > 0
                ? round((int) $shape->multi / $totalBaskets * 100, 1) : 0.0,
            'avg_basket_items'               => round((float) $shape->avg_items, 2),
            'missed_revenue_estimate_total'  => 0.0,
            'top_pair'                       => null,
        ];

        if ($totalBaskets === 0) {
            return ['summary' => $summary, 'anchors' => []];
        }

        // Directional pairs: every (anchor, companion) that co-occurred in
        // >= 3 baskets, with the anchor seen in >= 5 baskets. attach_rate and
        // lift are computed in SQL; the keep-thresholds are applied there too
        // so PHP only shapes what survives.
        $pairs = DB::select("
            WITH {$linesCte},
            prod AS (
                SELECT product_id, COUNT(*) AS baskets FROM lines GROUP BY product_id
            ),
            pairs AS (
                SELECT a.product_id AS anchor_id, b.product_id AS companion_id,
                       COUNT(*) AS together, AVG(b.line_value) AS avg_value
                FROM lines a
                JOIN lines b ON b.order_id = a.order_id AND b.product_id <> a.product_id
                GROUP BY a.product_id, b.product_id
                HAVING COUNT(*) >= 3
            )
            SELECT p.anchor_id, p.companion_id, p.together,
                   pa.baskets AS anchor_baskets,
                   ROUND(p.avg_value::numeric, 2) AS avg_value,
                   ROUND((p.together::numeric / pa.baskets), 4) AS attach_rate,
                   ROUND((p.together::numeric / pa.baskets)
                       / (pc.baskets::numeric / ?), 2) AS lift,
                   COALESCE(pta.name, pra.slug, pra.sku) AS anchor_name,
                   COALESCE(ptc.name, prc.slug, prc.sku) AS companion_name,
                   prc.sku AS companion_sku
            FROM pairs p
            JOIN prod pa ON pa.product_id = p.anchor_id AND pa.baskets >= 5
            JOIN prod pc ON pc.product_id = p.companion_id
            JOIN products pra ON pra.id = p.anchor_id
            JOIN products prc ON prc.id = p.companion_id
            LEFT JOIN product_translations pta
                   ON pta.product_id = p.anchor_id AND pta.language_code = 'en'
            LEFT JOIN product_translations ptc
                   ON ptc.product_id = p.companion_id AND ptc.language_code = 'en'
            WHERE (p.together::numeric / pa.baskets) >= 0.10
              AND (p.together::numeric / pa.baskets) / (pc.baskets::numeric / ?) >= 1.2
            ORDER BY p.anchor_id, (p.together::numeric / pa.baskets) DESC
        ", [$since, $totalBaskets, $totalBaskets]);

        $anchors = collect($pairs)
            ->groupBy('anchor_id')
            ->map(function ($rows) {
                $first = $rows->first();
                $companions = $rows->take(5)->map(function ($r) {
                    $attach = (float) $r->attach_rate;
                    $avg    = (float) $r->avg_value;
                    $missed = (int) $r->anchor_baskets - (int) $r->together;

                    return [
                        'product_id'               => (int) $r->companion_id,
                        'name'                     => $r->companion_name,
                        'sku'                      => $r->companion_sku,
                        'attach_rate_pct'          => round($attach * 100, 1),
                        'lift'                     => (float) $r->lift,
                        'avg_value'                => round($avg, 2),
                        'missed'                   => $missed,
                        'missed_revenue_estimate'  => round($missed * $attach * $avg, 2),
                    ];
                })->values();

                return [
                    'product_id' => (int) $first->anchor_id,
                    'name'       => $first->anchor_name,
                    'baskets'    => (int) $first->anchor_baskets,
                    'companions' => $companions->all(),
                    'missed_revenue_estimate' => round($companions->sum('missed_revenue_estimate'), 2),
                ];
            })
            ->sortByDesc('missed_revenue_estimate')
            ->take(30)
            ->values();

        $summary['missed_revenue_estimate_total'] = round($anchors->sum('missed_revenue_estimate'), 2);

        // Top pair = the single strongest attach among everything kept.
        $best = collect($pairs)->sortByDesc('attach_rate')->first();
        if ($best !== null) {
            $summary['top_pair'] = [
                'anchor'      => $best->anchor_name,
                'companion'   => $best->companion_name,
                'attach_rate' => round((float) $best->attach_rate * 100, 1),
            ];
        }

        return ['summary' => $summary, 'anchors' => $anchors->all()];
    }

    // ── Stock-out revenue loss ────────────────────────────────────────────────

    /**
     * Stock-out revenue loss — what empty shelves cost, in KES.
     *
     * For every product that SOLD in the trailing window, the zero-stock
     * windows are RECONSTRUCTED from inventory_transactions by walking each
     * inventory item's ledger backwards from its live quantity_on_hand
     * (balance before a transaction = balance after − quantity_change).
     * A product counts as OUT only while ALL of its inventory items (across
     * the scoped outlets) sat at ≤ 0 — the honest definition: if any outlet
     * could still sell it, the shelf wasn't truly empty.
     *
     * Velocity is units sold ÷ IN-stock days only (out-days excluded from
     * the divisor), so a product that spent half the window unavailable
     * isn't punished with a halved rate. est_lost_revenue =
     * out_days × velocity × avg unit price from the same sold lines.
     * Products with zero sales in the window are skipped: no observed
     * velocity means no measurable loss, and inventing one would be a guess.
     *
     * Confidence: some legacy write paths never logged
     * inventory_transactions, so an item with no ledger rows AT ALL has
     * unknown history. A product whose items are all ledger-less is reported
     * as confidence 'low' — its only defensible claim is the CURRENT
     * out-streak (dated from the stock row's updated_at, the last time
     * anything touched it) — and its KES stay OUT of the measured summary
     * totals, counted in low_confidence_count instead. Any transaction
     * history at all makes the product 'measured'.
     */
    public function stockoutLoss(int $days = 90): array
    {
        $scopeKey = $this->outletIds !== null
            ? implode('-', collect($this->outletIds)->map(fn ($id) => (int) $id)->sort()->values()->all())
            : 'all';

        return \Illuminate\Support\Facades\Cache::remember(
            "metrics:stockout_loss:{$scopeKey}:{$days}",
            600,
            fn () => $this->computeStockoutLoss($days),
        );
    }

    /** The uncached reconstruction pass behind stockoutLoss(). */
    private function computeStockoutLoss(int $days): array
    {
        $now     = CarbonImmutable::now(self::TZ);
        $since   = $now->subDays($days); // exact rolling window: length == $days
        $nowTs   = $now->getTimestamp();
        $sinceTs = $since->getTimestamp();

        $empty = [
            'summary' => [
                'products_currently_out'  => 0,
                'est_daily_loss_now'      => 0.0,
                'est_lost_revenue_window' => 0.0,
                'low_confidence_count'    => 0,
            ],
            'products' => [],
        ];

        // 1. Sales truth per product over the window: units + revenue give
        //    the velocity numerator and the avg unit price.
        $outletSql = $this->outletIds
            ? 'AND o.outlet_id IN (' . implode(',', array_map('intval', $this->outletIds)) . ')'
            : '';
        $sales = collect(DB::select("
            SELECT oi.product_id,
                   SUM(oi.quantity)    AS units,
                   SUM(oi.total_price) AS revenue,
                   COALESCE(MAX(pt.name), MAX(p.slug), MAX(p.sku)) AS name
            FROM order_items oi
            JOIN orders o   ON o.id = oi.order_id
            JOIN products p ON p.id = oi.product_id
            LEFT JOIN product_translations pt
                   ON pt.product_id = p.id AND pt.language_code = 'en'
            WHERE o.status NOT IN ('voided','cancelled')
              AND UPPER(o.currency_code) = 'KES'
              AND oi.product_id IS NOT NULL
              AND o.created_at >= ?
              {$outletSql}
            GROUP BY oi.product_id
            HAVING SUM(oi.quantity) > 0
        ", [$since->toMutable()]))->keyBy('product_id');

        if ($sales->isEmpty()) {
            return $empty;
        }

        // 2. The live stock rows behind those products (outlet-scoped).
        $stockRows = DB::table('inventory_items')
            ->whereIn('product_id', $sales->keys()->all())
            ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
            ->get(['id', 'product_id', 'quantity_on_hand', 'created_at', 'updated_at']);

        if ($stockRows->isEmpty()) {
            return $empty;
        }

        // 3. Every ledger row for those items (all-time: pre-window rows are
        //    needed to derive the balance that held before the window).
        $ledger = DB::table('inventory_transactions')
            ->whereIn('inventory_item_id', $stockRows->pluck('id')->all())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['inventory_item_id', 'quantity_change', 'created_at'])
            ->groupBy('inventory_item_id');

        // 4. Per item: reconstruct the ≤0 intervals as [startTs, endTs].
        $itemZero  = []; // item id → merged ascending zero intervals
        $itemHasTx = []; // item id → bool (any ledger history at all)
        foreach ($stockRows as $it) {
            $rows  = $ledger->get($it->id) ?? collect();
            $hasTx = $rows->isNotEmpty();
            $itemHasTx[$it->id] = $hasTx;

            $segments = []; // [start, end, balance], built newest-first
            if ($hasTx) {
                $balance = (int) $it->quantity_on_hand;
                $end     = $nowTs;
                foreach ($rows as $t) { // newest → oldest
                    $ts = Carbon::parse($t->created_at)->getTimestamp();
                    $segments[] = [$ts, $end, $balance];
                    $balance -= (int) $t->quantity_change;
                    $end = $ts;
                }
                // Before the earliest ledger row the derived balance held
                // constant — bounded by the stock row's own birth.
                $born = Carbon::parse($it->created_at)->getTimestamp();
                if ($born < $end) {
                    $segments[] = [$born, $end, $balance];
                }
            } elseif ((int) $it->quantity_on_hand > 0) {
                // No ledger, stock on hand: assume it stayed stocked. (This
                // item alone can never put its product "out".)
                $segments[] = [$sinceTs, $nowTs, (int) $it->quantity_on_hand];
            } else {
                // No ledger, at zero NOW: history unknown — the only
                // defensible out-window starts at updated_at (last touch).
                $touched = Carbon::parse($it->updated_at)->getTimestamp();
                $segments[] = [min($touched, $nowTs), $nowTs, 0];
            }

            $zeros = [];
            foreach ($segments as [$s, $e, $b]) {
                if ($b <= 0 && $e > $s) {
                    $zeros[] = [$s, $e];
                }
            }
            usort($zeros, fn ($x, $y) => $x[0] <=> $y[0]);
            $merged = [];
            foreach ($zeros as $z) {
                $n = count($merged);
                if ($n > 0 && $z[0] <= $merged[$n - 1][1]) {
                    $merged[$n - 1][1] = max($merged[$n - 1][1], $z[1]);
                } else {
                    $merged[] = $z;
                }
            }
            $itemZero[$it->id] = $merged;
        }

        // 5. Per product: OUT = intersection of all items' zero intervals.
        $rowsOut = [];
        foreach ($stockRows->groupBy('product_id') as $pid => $its) {
            $sale = $sales->get($pid);
            if ($sale === null) {
                continue;
            }

            $out = null;
            foreach ($its as $it) {
                $z   = $itemZero[$it->id];
                $out = $out === null ? $z : self::intersectIntervals($out, $z);
                if ($out === []) {
                    break;
                }
            }
            $out ??= [];

            $outSeconds = 0.0;
            foreach ($out as [$s, $e]) {
                $outSeconds += max(0, min($e, $nowTs) - max($s, $sinceTs));
            }
            $outDaysWindow = round($outSeconds / 86400, 1);

            $currentlyOut = $its->every(fn ($it) => (int) $it->quantity_on_hand <= 0);
            if (! $currentlyOut && $outDaysWindow <= 0) {
                continue; // sold fine, never out — nothing lost, no row.
            }

            $streak = 0.0;
            if ($currentlyOut && $out !== []) {
                // The final interval necessarily reaches "now"; its start is
                // when the LAST outlet ran dry (unclipped, so a streak can
                // honestly exceed the window).
                $streak = round(($nowTs - $out[count($out) - 1][0]) / 86400, 1);
            }

            $inStockDays = max(1.0, $days - $outDaysWindow);
            $velocity    = (float) $sale->units / $inStockDays;
            $avgPrice    = (float) $sale->revenue / (float) $sale->units;

            $rowsOut[] = [
                'product_id'       => (int) $pid,
                'name'             => $sale->name,
                'currently_out'    => $currentlyOut,
                'out_streak_days'  => $streak,
                'out_days_window'  => $outDaysWindow,
                'velocity_per_day' => round($velocity, 2),
                'avg_price'        => round($avgPrice, 2),
                'est_lost_revenue' => round($outDaysWindow * $velocity * $avgPrice, 2),
                'est_daily_loss'   => $currentlyOut ? round($velocity * $avgPrice, 2) : 0.0,
                'confidence'       => $its->contains(fn ($it) => $itemHasTx[$it->id]) ? 'measured' : 'low',
            ];
        }

        // 6. Summary over EVERYTHING that qualified (before the display cap).
        //    Low-confidence rows are visible but their KES never enter the
        //    measured totals — a guess dressed as a sum stops being honest.
        $all      = collect($rowsOut);
        $measured = $all->where('confidence', 'measured');
        $summary  = [
            'products_currently_out'  => $all->where('currently_out', true)->count(),
            'est_daily_loss_now'      => round((float) $measured->sum('est_daily_loss'), 2),
            'est_lost_revenue_window' => round((float) $measured->sum('est_lost_revenue'), 2),
            'low_confidence_count'    => $all->where('confidence', 'low')->count(),
        ];

        $products = $all
            ->sortBy([['est_lost_revenue', 'desc'], ['est_daily_loss', 'desc']])
            ->take(30)
            ->values()
            ->all();

        return ['summary' => $summary, 'products' => $products];
    }

    /**
     * Intersection of two ascending, non-overlapping interval lists
     * ([[start, end], ...] in epoch seconds).
     */
    private static function intersectIntervals(array $a, array $b): array
    {
        $out = [];
        $i = $j = 0;
        while ($i < count($a) && $j < count($b)) {
            $s = max($a[$i][0], $b[$j][0]);
            $e = min($a[$i][1], $b[$j][1]);
            if ($e > $s) {
                $out[] = [$s, $e];
            }
            $a[$i][1] <= $b[$j][1] ? $i++ : $j++;
        }

        return $out;
    }

    // ── Seasonal demand planning ──────────────────────────────────────────────

    /**
     * Liturgical demand planner — "what will the church year ask of the
     * shelves next, and when must the POs go out?"
     *
     * For every non-ordinary liturgical season starting or running within the
     * next $horizonDays (SeasonCalendar computes the calendar; holy_week is
     * exposed as a sub-window of lent), each product's seasonal lift is
     * measured from COMBINED history — hub order_items UNION the imported
     * legacy_sales_daily aggregates — over the prior two years:
     *
     *   lift            = (seasonal units/day) ÷ (ordinary-time units/day)
     *   projected_units = trailing-60d hub velocity × lift × season length
     *                     (when the hub has no velocity yet, historical
     *                      seasonal units/day × length — basis 'historical')
     *   gap             = max(0, projected − stock on hand across outlets)
     *   order_by        = season start − the product's supplier lead time
     *                     (most recent supplier's average PO→GRN days,
     *                      falling back to the global average, then 14)
     *
     * Legacy rows are analytics-only aggregates with no outlet, so they are
     * included under every scope; hub-side sums remain outlet-scoped like
     * every other metric. Cached 10 minutes per scope.
     *
     * Before the operator has run `seasonal:import-legacy` (and while the hub
     * itself is young) there is no history: the report still returns the full
     * season timeline with empty product lists and history_depth_days = 0 so
     * the UI can invite the import instead of rendering a broken page.
     */
    public function seasonalDemand(int $horizonDays = 120): array
    {
        $scopeKey = $this->outletIds !== null
            ? implode('-', collect($this->outletIds)->map(fn ($id) => (int) $id)->sort()->values()->all())
            : 'all';

        return \Illuminate\Support\Facades\Cache::remember(
            "metrics:seasonal_demand:{$scopeKey}:{$horizonDays}",
            600,
            fn () => $this->computeSeasonalDemand($horizonDays),
        );
    }

    /** Minimum ordinary-time velocity used as the lift denominator (div-0 guard). */
    private const SEASONAL_BASELINE_FLOOR = 0.05;

    /** Sanity ceiling on lift so one thin baseline cannot project absurd volumes. */
    private const SEASONAL_LIFT_CAP = 25.0;

    /** The uncached projection pass behind seasonalDemand(). */
    private function computeSeasonalDemand(int $horizonDays): array
    {
        $today      = CarbonImmutable::now(self::TZ)->startOfDay();
        $horizonEnd = $today->addDays($horizonDays);

        // The demand seasons ahead (ordinary stretches are the baseline, not
        // a surge to plan for). holy_week rides inside lent — kept for the
        // drill-in view but excluded from summary totals to avoid counting
        // the same units twice.
        $upcoming = array_values(array_filter(
            \App\Services\SeasonCalendar::seasonsForRange($today, $horizonEnd),
            fn ($w) => ! in_array($w['key'], \App\Services\SeasonCalendar::ORDINARY_KEYS, true),
        ));

        $seasonShape = fn (array $w, array $products) => array_filter([
            'key'              => $w['key'],
            'label'            => $w['label'],
            'start'            => $w['start']->toDateString(),
            'end'              => $w['end']->toDateString(),
            'days_until_start' => max(0, (int) round($today->diffInDays($w['start'], false))),
            'length_days'      => (int) round($w['start']->diffInDays($w['end'])) + 1,
            'sub_of'           => $w['key'] === 'holy_week' ? 'lent' : null,
            'products'         => $products,
        ], fn ($v) => $v !== null);

        // History depth: the earliest combined sales date. No history → the
        // graceful "import legacy history to unlock projections" shape.
        $legacyMin = DB::table('legacy_sales_daily')->min('sale_date');
        $hubMin    = DB::table('orders')
            ->whereNotIn('status', self::SALE_EXCLUDED_STATUSES)
            ->whereRaw("UPPER(currency_code) = 'KES'")
            ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
            ->min('created_at');

        $starts = array_filter([
            $legacyMin !== null ? CarbonImmutable::parse((string) $legacyMin, self::TZ)->startOfDay() : null,
            $hubMin !== null ? CarbonImmutable::parse((string) $hubMin, self::TZ)->startOfDay() : null,
        ]);
        $historyStart = $starts === [] ? null : min($starts);

        if ($historyStart === null || $historyStart->gte($today)) {
            return [
                'seasons' => array_map(fn ($w) => $seasonShape($w, []), $upcoming),
                'summary' => [
                    'next_season' => $upcoming === [] ? null : [
                        'key'   => $upcoming[0]['key'],
                        'label' => $upcoming[0]['label'],
                        'start' => $upcoming[0]['start']->toDateString(),
                    ],
                    'total_gap_value'    => 0.0,
                    'urgent_orders'      => 0,
                    'history_depth_days' => 0,
                ],
            ];
        }

        $historyDepthDays = (int) round($historyStart->diffInDays($today));

        // Observation range: prior two liturgical years, clipped to the days
        // history actually covers (a window with no coverage must not dilute
        // a units-per-day denominator).
        $obsStart = max($historyStart, $today->subYears(2));
        $obsEnd   = $today->subDay(); // full days only
        $clip     = function (array $w) use ($obsStart, $obsEnd): ?array {
            $s = max($w['start'], $obsStart);
            $e = min($w['end'], $obsEnd);

            return $s->lte($e) ? ['start' => $s, 'end' => $e] : null;
        };

        $observed = \App\Services\SeasonCalendar::seasonsForRange($obsStart, $obsEnd);

        // Ordinary-time baseline: units/day per product across every clipped
        // ordinary stretch (epiphany + post-Pentecost).
        $ordinaryDays  = 0;
        $ordinaryUnits = [];
        foreach ($observed as $w) {
            if (! in_array($w['key'], \App\Services\SeasonCalendar::ORDINARY_KEYS, true)) {
                continue;
            }
            if (($c = $clip($w)) === null) {
                continue;
            }
            $ordinaryDays += (int) round($c['start']->diffInDays($c['end'])) + 1;
            foreach ($this->combinedSalesAgg($c['start'], $c['end']) as $pid => $agg) {
                $ordinaryUnits[$pid] = ($ordinaryUnits[$pid] ?? 0.0) + $agg['units'];
            }
        }

        // Current hub velocity + price: trailing 60 days of hub sales truth.
        $hub60 = collect(DB::select("
            SELECT oi.product_id,
                   SUM(oi.quantity)    AS units,
                   SUM(oi.total_price) AS revenue
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE o.status NOT IN ('voided','cancelled')
              AND UPPER(o.currency_code) = 'KES'
              AND oi.product_id IS NOT NULL
              AND o.created_at >= ?
              " . $this->outletScopeSql('o.outlet_id') . '
            GROUP BY oi.product_id
        ', [$today->subDays(60)->toDateTimeString()]))->keyBy('product_id');

        $seasons     = [];
        $allRows     = []; // rows outside holy_week, for the summary
        foreach ($upcoming as $w) {
            // Prior occurrences of this season key — the upcoming window
            // itself (matched by start date) never feeds its own history.
            $seasonalDays  = 0;
            $seasonalUnits = [];
            $seasonalRev   = [];
            foreach ($observed as $h) {
                if ($h['key'] !== $w['key'] || $h['start']->equalTo($w['start'])) {
                    continue;
                }
                if (($c = $clip($h)) === null) {
                    continue;
                }
                $seasonalDays += (int) round($c['start']->diffInDays($c['end'])) + 1;
                foreach ($this->combinedSalesAgg($c['start'], $c['end']) as $pid => $agg) {
                    $seasonalUnits[$pid] = ($seasonalUnits[$pid] ?? 0.0) + $agg['units'];
                    $seasonalRev[$pid]   = ($seasonalRev[$pid] ?? 0.0) + $agg['revenue'];
                }
            }

            if ($seasonalDays === 0 || $seasonalUnits === []) {
                $seasons[] = $seasonShape($w, []);
                continue;
            }

            $lengthDays = (int) round($w['start']->diffInDays($w['end'])) + 1;
            $productIds = array_keys($seasonalUnits);
            $stocks     = $this->stockOnHandByProduct($productIds);
            $leads      = $this->productLeadDays($productIds);
            $names      = $this->productNames($productIds);

            $rows = [];
            foreach ($seasonalUnits as $pid => $units) {
                if ($units <= 0) {
                    continue; // no seasonal history → no basis to project
                }
                $seasonalPerDay = $units / $seasonalDays;
                $baselinePerDay = max(($ordinaryUnits[$pid] ?? 0.0) / max(1, $ordinaryDays), self::SEASONAL_BASELINE_FLOOR);
                $lift           = min($seasonalPerDay / $baselinePerDay, self::SEASONAL_LIFT_CAP);

                $hub    = $hub60->get($pid);
                $hubVel = $hub !== null ? (float) $hub->units / 60.0 : 0.0;

                if ($hubVel > 0) {
                    $projected = (int) round($hubVel * $lift * $lengthDays);
                    $basis     = 'hub';
                } else {
                    $projected = (int) round($seasonalPerDay * $lengthDays);
                    $basis     = 'historical';
                }

                $avgPrice = $hub !== null && (float) $hub->units > 0
                    ? (float) $hub->revenue / (float) $hub->units
                    : ($units > 0 ? ($seasonalRev[$pid] ?? 0.0) / $units : 0.0);

                $stock    = (int) ($stocks[$pid] ?? 0);
                $gap      = max(0, $projected - $stock);
                $leadDays = $leads[$pid];
                $orderBy  = $w['start']->subDays($leadDays);

                $rows[] = [
                    'product_id'          => (int) $pid,
                    'name'                => $names[$pid] ?? ('#' . $pid),
                    'lift'                => round($lift, 2),
                    'projected_units'     => $projected,
                    'stock'               => $stock,
                    'gap'                 => $gap,
                    'lead_days'           => $leadDays,
                    'order_by'            => $orderBy->toDateString(),
                    'days_until_order_by' => (int) round($today->diffInDays($orderBy, false)),
                    'est_gap_value'       => round($gap * $avgPrice, 2),
                    'basis'               => $basis,
                    '_projected_value'    => $projected * $avgPrice,
                ];
            }

            usort($rows, fn ($a, $b) => $b['_projected_value'] <=> $a['_projected_value']);
            $rows = array_map(function ($r) {
                unset($r['_projected_value']);

                return $r;
            }, array_slice($rows, 0, 20));

            if ($w['key'] !== 'holy_week') {
                $allRows = array_merge($allRows, $rows);
            }
            $seasons[] = $seasonShape($w, $rows);
        }

        $urgent = array_values(array_filter(
            $allRows,
            fn ($r) => $r['gap'] > 0 && $r['days_until_order_by'] <= 14,
        ));

        return [
            'seasons' => $seasons,
            'summary' => [
                'next_season' => $upcoming === [] ? null : [
                    'key'   => $upcoming[0]['key'],
                    'label' => $upcoming[0]['label'],
                    'start' => $upcoming[0]['start']->toDateString(),
                ],
                'total_gap_value'    => round(array_sum(array_column($allRows, 'est_gap_value')), 2),
                'urgent_orders'      => count($urgent),
                'history_depth_days' => $historyDepthDays,
            ],
        ];
    }

    /**
     * Combined sales history for one inclusive date window: hub order_items
     * (sales truth, outlet-scoped) UNION ALL legacy_sales_daily (analytics
     * aggregates, no outlet dimension — included under every scope).
     *
     * @return array<int, array{units: float, revenue: float}> keyed by product_id
     */
    private function combinedSalesAgg(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::select('
            SELECT t.product_id, SUM(t.units) AS units, SUM(t.revenue) AS revenue
            FROM (
                SELECT oi.product_id, oi.quantity AS units, oi.total_price AS revenue
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                WHERE o.status NOT IN (\'voided\',\'cancelled\')
                  AND UPPER(o.currency_code) = \'KES\'
                  AND oi.product_id IS NOT NULL
                  AND o.created_at >= ? AND o.created_at < ?
                  ' . $this->outletScopeSql('o.outlet_id') . '
                UNION ALL
                SELECT l.product_id, l.units, l.revenue
                FROM legacy_sales_daily l
                WHERE l.product_id IS NOT NULL
                  AND l.sale_date >= ? AND l.sale_date <= ?
            ) t
            GROUP BY t.product_id
        ', [
            $start->toDateTimeString(),
            $end->addDay()->toDateTimeString(),
            $start->toDateString(),
            $end->toDateString(),
        ]);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->product_id] = [
                'units'   => (float) $r->units,
                'revenue' => (float) $r->revenue,
            ];
        }

        return $out;
    }

    /** @return array<int, int> product_id → summed quantity_on_hand (scoped). */
    private function stockOnHandByProduct(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('inventory_items')
            ->whereIn('product_id', $productIds)
            ->when($this->outletIds, fn ($q) => $q->whereIn('outlet_id', $this->outletIds))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity_on_hand) AS qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($q) => (int) $q)
            ->all();
    }

    /** @return array<int, string> product_id → display name (en translation, slug, sku). */
    private function productNames(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return collect(DB::select('
            SELECT p.id, COALESCE(MAX(pt.name), MAX(p.slug), MAX(p.sku)) AS name
            FROM products p
            LEFT JOIN product_translations pt
                   ON pt.product_id = p.id AND pt.language_code = \'en\'
            WHERE p.id IN (' . implode(',', array_map('intval', $productIds)) . ')
            GROUP BY p.id
        '))->pluck('name', 'id')->all();
    }

    /**
     * Supplier lead time per product, in days: the average PO-date → first-GRN
     * gap of the product's MOST RECENT supplier, falling back to the average
     * across all delivered POs, then to a conservative 14 days.
     *
     * @return array<int, int> product_id → lead days (≥ 1)
     */
    private function productLeadDays(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        // Average delivered lead per supplier (PO order_date → first GRN).
        $supplierLead = collect(DB::select("
            SELECT po.supplier_id, AVG(g.received_date - po.order_date) AS lead_days
            FROM purchase_orders po
            JOIN (
                SELECT purchase_order_id, MIN(received_date) AS received_date
                FROM goods_received_notes GROUP BY purchase_order_id
            ) g ON g.purchase_order_id = po.id
            WHERE po.status != 'cancelled'
            GROUP BY po.supplier_id
        "))->pluck('lead_days', 'supplier_id');

        $globalLead = $supplierLead->isNotEmpty()
            ? (float) $supplierLead->avg(fn ($v) => (float) $v)
            : null;

        // Each product's most recent supplier.
        $recentSupplier = collect(DB::select('
            SELECT DISTINCT ON (poi.product_id) poi.product_id, po.supplier_id
            FROM purchase_order_items poi
            JOIN purchase_orders po ON po.id = poi.purchase_order_id
            WHERE poi.product_id IN (' . implode(',', array_map('intval', $productIds)) . ")
              AND po.status != 'cancelled'
            ORDER BY poi.product_id, po.order_date DESC, po.id DESC
        "))->pluck('supplier_id', 'product_id');

        $out = [];
        foreach ($productIds as $pid) {
            $supplierId = $recentSupplier->get($pid);
            $lead       = $supplierId !== null && $supplierLead->has($supplierId)
                ? (float) $supplierLead->get($supplierId)
                : $globalLead;

            $out[(int) $pid] = max(1, (int) round($lead ?? 14.0));
        }

        return $out;
    }

    // ── Attention feed ────────────────────────────────────────────────────────

    /**
     * "What requires my attention?" — every item cites the real numbers that
     * triggered it and links to the page where it gets fixed. Detectors only
     * emit when they have something to say.
     *
     * Each item carries the original flat shape ({key, severity, title,
     * detail, count, link} — the digest email renders exactly these) plus,
     * ADDITIVELY, an `actions` array of one-click next steps
     * ({type:'navigate'|'create_po', label, ...}) and, where the upstream
     * query already computed them, an `entities` array with the top rows
     * behind the headline so the UI can show WHICH product/material/customer
     * without another round-trip.
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
                'actions' => [
                    ['type' => 'navigate', 'label' => 'View overdue orders', 'to' => '/production/orders?status=overdue'],
                ],
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
                'count' => (int) $aging->n, 'link' => '/pos/outstanding-balances',
                'actions' => [
                    ['type' => 'navigate', 'label' => 'Chase balances', 'to' => '/pos/outstanding-balances'],
                ],
            ];
        }

        // 2b. Quotes stalling — sizeable offers a week+ old that never became
        // orders. The collections funnel's front stage, advertised here so
        // the money is chased while the customer still remembers asking.
        $stallingQuotes = $this->openQuotes()
            ->whereRaw('COALESCE(issued_at, created_at) < ?', [CarbonImmutable::now(self::TZ)->subDays(7)->toMutable()])
            ->where('total_amount', '>=', 5000)
            ->selectRaw("id, COALESCE(quote_number, CONCAT('#', id)) AS number,
                TRIM(CONCAT(COALESCE(customer_first_name,''),' ',COALESCE(customer_last_name,''))) AS customer,
                total_amount AS value,
                (?::date - COALESCE(issued_at, created_at)::date) AS age_days", [$todayEat])
            ->orderByDesc('total_amount')
            ->get();
        if ($stallingQuotes->isNotEmpty()) {
            $stallingValue = $stallingQuotes->sum(fn ($q) => (float) $q->value);
            $worst = $stallingQuotes->first();
            $items[] = [
                'key' => 'quotes_stalling', 'severity' => $stallingValue >= 50000 ? 'high' : 'medium',
                'title' => 'KES ' . number_format($stallingValue) . ' quoted but never ordered ('
                    . $stallingQuotes->count() . ' quote' . ($stallingQuotes->count() > 1 ? 's' : '') . ' 7+ days old)',
                'detail' => "Biggest is {$worst->number} — KES " . number_format((float) $worst->value)
                    . " sitting {$worst->age_days} day" . ((int) $worst->age_days === 1 ? '' : 's') . ' without an order.',
                'count' => $stallingQuotes->count(), 'link' => '/reports/sales?tab=collections',
                'entities' => $stallingQuotes->take(3)->map(fn ($q) => [
                    'number'   => $q->number,
                    'customer' => $q->customer,
                    'value'    => round((float) $q->value, 2),
                    'age_days' => (int) $q->age_days,
                ])->values()->all(),
                'actions' => [
                    ['type' => 'navigate', 'label' => 'Open collections funnel', 'to' => '/reports/sales?tab=collections'],
                ],
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
                'actions' => [
                    ['type' => 'navigate', 'label' => 'Review approvals', 'to' => '/approvals'],
                ],
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
                'actions' => [
                    ['type' => 'navigate', 'label' => 'Open production report', 'to' => '/reports/production'],
                ],
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
                'entities' => array_map(fn ($r) => [
                    'product_id' => $r['product_id'],
                    'product'    => $r['product'],
                    'cover_days' => $r['cover_days'],
                ], array_slice($risks, 0, 3)),
                'actions' => [
                    ['type' => 'navigate', 'label' => 'Open inventory report', 'to' => '/reports/inventory'],
                ],
            ];
        }

        // 5b. Realized stock-out losses: shelves that ARE empty right now,
        // priced at each product's own in-stock sales velocity. stockout_risk
        // above is the forecast (thin cover); this is the bleed already
        // happening.
        $loss = $this->stockoutLoss();
        if ($loss['summary']['est_daily_loss_now'] > 0) {
            $bleeding = collect($loss['products'])
                ->filter(fn ($p) => $p['currently_out'] && $p['confidence'] === 'measured')
                ->sortByDesc('est_daily_loss')
                ->values();
            $daily = (float) $loss['summary']['est_daily_loss_now'];
            $nOut  = (int) $loss['summary']['products_currently_out'];
            $top   = $bleeding->first();
            $items[] = [
                'key' => 'stockout_loss', 'severity' => $daily >= 2000 ? 'high' : 'medium',
                'title' => 'Empty shelves are costing ~KES ' . number_format($daily) . ' per day — '
                    . $nOut . ' product' . ($nOut === 1 ? '' : 's') . ' out of stock',
                'detail' => $top !== null
                    ? "Worst is \"{$top['name']}\" — out {$top['out_streak_days']} day"
                        . ((float) $top['out_streak_days'] === 1.0 ? '' : 's')
                        . ', usually sells ' . $top['velocity_per_day'] . '/day (~KES '
                        . number_format($top['est_daily_loss']) . '/day lost).'
                    : 'Estimated from each product\'s in-stock sales velocity.',
                'count' => $nOut, 'link' => '/reports/inventory?tab=intelligence',
                'entities' => $bleeding->take(3)->map(fn ($p) => [
                    'product_id'      => $p['product_id'],
                    'name'            => $p['name'],
                    'out_streak_days' => $p['out_streak_days'],
                    'daily_loss'      => $p['est_daily_loss'],
                ])->values()->all(),
                'actions' => [
                    ['type' => 'navigate', 'label' => 'See stock-out losses', 'to' => '/reports/inventory?tab=intelligence'],
                ],
            ];
        }

        // 5c. Seasonal order windows: the liturgical calendar is about to ask
        // for stock the shelves can't cover, and the supplier lead time means
        // the PO must leave NOW — not when the season starts.
        $seasonal = $this->seasonalDemand();
        if ($seasonal['summary']['urgent_orders'] > 0) {
            $urgentRows   = [];
            $leadSeason   = null;
            foreach ($seasonal['seasons'] as $sw) {
                if (($sw['sub_of'] ?? null) !== null) {
                    continue; // holy_week rows already counted inside lent
                }
                $rows = array_filter(
                    $sw['products'],
                    fn ($r) => $r['gap'] > 0 && $r['days_until_order_by'] <= 14,
                );
                if ($rows !== [] && $leadSeason === null) {
                    $leadSeason = $sw;
                }
                $urgentRows = array_merge($urgentRows, array_values($rows));
            }
            usort($urgentRows, fn ($a, $b) => $b['est_gap_value'] <=> $a['est_gap_value']);

            $urgentGap = round(array_sum(array_column($urgentRows, 'est_gap_value')), 2);
            $earliest  = min(array_column($urgentRows, 'order_by'));
            $n         = count($urgentRows);
            $items[] = [
                'key'      => 'seasonal_order_window',
                'severity' => min(array_column($urgentRows, 'days_until_order_by')) <= 7 ? 'high' : 'medium',
                'title'    => "{$leadSeason['label']} demand needs {$n} order" . ($n === 1 ? '' : 's') . ' placed',
                'detail'   => "{$leadSeason['label']} starts in {$leadSeason['days_until_start']}d — {$n} product"
                    . ($n === 1 ? '' : 's') . " need ordering by {$earliest} (est KES " . number_format($urgentGap) . ' gap).',
                'count'    => $n, 'link' => '/reports/procurement?tab=seasonal',
                'entities' => array_map(fn ($r) => [
                    'product_id' => $r['product_id'],
                    'name'       => $r['name'],
                    'gap'        => $r['gap'],
                    'order_by'   => $r['order_by'],
                ], array_slice($urgentRows, 0, 3)),
                'actions'  => [
                    ['type' => 'navigate', 'label' => 'Open seasonal demand', 'to' => '/reports/procurement?tab=seasonal'],
                ],
            ];
        }

        // 6. Top customers gone quiet: relationship revenue slipping away.
        $dormant = $this->dormantTopCustomers();
        if ($dormant->isNotEmpty()) {
            $worst = $dormant->first();
            $valueAtRisk = (float) $dormant->sum('revenue_12m');
            $items[] = [
                'key' => 'dormant_customers', 'severity' => 'medium',
                'title' => $dormant->count() . ' top customer' . ($dormant->count() > 1 ? 's' : '') . ' quiet for 60+ days',
                // Lead with the money: the KES at risk is what makes someone
                // actually open the win-back tab.
                'detail' => 'KES ' . number_format($valueAtRisk) . " of annual value gone quiet — top: {$worst['name']} (KES "
                    . number_format($worst['revenue_12m']) . ", last ordered {$worst['days_quiet']} days ago).",
                'count' => $dormant->count(), 'link' => '/reports/customers',
                'entities' => $dormant->take(3)->map(fn ($c) => [
                    'name'       => $c['name'],
                    'phone'      => $c['phone'],
                    'days_quiet' => $c['days_quiet'],
                ])->values()->all(),
                'actions' => [
                    ['type' => 'navigate', 'label' => 'Open win-back economics', 'to' => '/reports/customers?tab=winback'],
                    ['type' => 'navigate', 'label' => 'Open customer report', 'to' => '/reports/customers'],
                ],
            ];
        }

        // 6b. Replenishment radar: consumables due for their usual reorder —
        // proactive revenue instead of waiting for the church to remember.
        $radar = $this->replenishmentRadar();
        if ($radar['summary']['due_pairs'] > 0) {
            $due = collect($radar['due']);
            $top = $due->first();
            $pairs = $radar['summary']['due_pairs'];
            $bigOverdue = $due->contains(fn ($d) => $d['status'] === 'overdue' && $d['avg_value'] >= 5000);
            $topWhen = $top['days_over'] > 0
                ? "{$top['days_over']} day" . ($top['days_over'] === 1 ? '' : 's') . ' past'
                : 'coming up on';
            $items[] = [
                'key' => 'replenishment_due', 'severity' => $bigOverdue ? 'high' : 'medium',
                'title' => "{$pairs} usual reorder" . ($pairs > 1 ? 's' : '') . ' due — ~KES '
                    . number_format($radar['summary']['expected_revenue']) . ' waiting',
                'detail' => "{$top['name']} is {$topWhen} the ~{$top['cycle_days']}-day \"{$top['product_name']}\" cycle"
                    . " (usually KES " . number_format($top['avg_value']) . ').',
                'count' => $pairs, 'link' => '/reports/customers',
                'entities' => $due->take(3)->map(fn ($d) => [
                    'name'      => $d['name'],
                    'phone'     => $d['phone'],
                    'product'   => $d['product_name'],
                    'days_over' => $d['days_over'],
                    'avg_value' => $d['avg_value'],
                ])->values()->all(),
                'actions' => [
                    ['type' => 'navigate', 'label' => 'Open radar', 'to' => '/reports/customers?tab=replenishment'],
                ],
            ];
        }

        // 6c. Institutional accounts at risk: churches with real annual value
        // gone quiet — buyer turnover often hides behind the silence (the
        // person who ordered left; the church still needs communion supplies).
        $inst = $this->institutionalAccounts();
        if ($inst['summary']['at_risk_count'] > 0) {
            $atRiskValue = (float) $inst['summary']['at_risk_value'];
            $riskyAccounts = collect($inst['accounts'])->filter(fn ($a) => $a['risk'])->values();
            $topRisk = $riskyAccounts->first();
            $n = (int) $inst['summary']['at_risk_count'];
            $items[] = [
                'key' => 'institution_risk', 'severity' => $atRiskValue >= 50000 ? 'high' : 'medium',
                'title' => 'KES ' . number_format($atRiskValue) . ' of church/institution revenue at risk — '
                    . $n . ' account' . ($n === 1 ? '' : 's') . ' quiet 60+ days',
                'detail' => $topRisk !== null
                    ? "Biggest is {$topRisk['name']} — KES " . number_format($topRisk['revenue_365'])
                        . " a year, silent {$topRisk['days_quiet']} days"
                        . ($topRisk['buyer_contacts'] > 1 ? ' (buyer contact has changed before — the person may have moved on).' : '.')
                    : 'Institutions with 60+ quiet days and KES 10,000+ annual value.',
                'count' => $n, 'link' => '/reports/customers?tab=institutions',
                'entities' => $riskyAccounts->take(3)->map(fn ($a) => [
                    'name'        => $a['name'],
                    'phone'       => $a['phone'],
                    'revenue_365' => $a['revenue_365'],
                    'days_quiet'  => $a['days_quiet'],
                ])->values()->all(),
                'actions' => [
                    ['type' => 'navigate', 'label' => 'Open institutional accounts', 'to' => '/reports/customers?tab=institutions'],
                ],
            ];
        }

        // 7. Material runway: fabric that runs out mid-batch stops the floor.
        $runway = $this->materialRunway();
        if ($runway->isNotEmpty()) {
            $worst = $runway->first();
            $items[] = [
                'key' => 'material_runway', 'severity' => (float) $worst->runway_days < 7 ? 'high' : 'medium',
                'title' => "{$worst->name} runs out in ~{$worst->runway_days} days at current use",
                'detail' => number_format((float) $worst->available, 1) . " {$worst->unit} left, burning {$worst->daily_burn} {$worst->unit}/day"
                    . ($runway->count() > 1 ? ' — ' . ($runway->count() - 1) . ' more material' . ($runway->count() > 2 ? 's' : '') . ' under 14 days.' : '.'),
                'count' => $runway->count(), 'link' => '/reports/procurement',
                'entities' => $runway->take(3)->map(fn ($m) => [
                    'material_id' => (int) $m->id,
                    'name'        => $m->name,
                    'runway_days' => (float) $m->runway_days,
                ])->values()->all(),
                'actions' => [
                    ['type' => 'create_po', 'label' => 'Draft purchase order',
                     'material_ids' => $runway->pluck('id')->map(fn ($id) => (int) $id)->values()->all()],
                    ['type' => 'navigate', 'label' => 'Open procurement report', 'to' => '/reports/procurement'],
                ],
            ];
        }

        // 8. Revenue declining three full months running.
        $trend = $this->revenueTrend();
        if ($trend !== null) {
            $mo = $trend['months'];
            $items[] = [
                'key' => 'revenue_trend', 'severity' => 'medium',
                'title' => "Revenue has fallen 3 months straight (−{$trend['decline_pct']}%)",
                'detail' => "{$mo[0]['month']}: KES " . number_format($mo[0]['revenue'])
                    . " → {$mo[1]['month']}: KES " . number_format($mo[1]['revenue'])
                    . " → {$mo[2]['month']}: KES " . number_format($mo[2]['revenue']) . '.',
                'count' => 3, 'link' => '/reports/sales',
                'actions' => [
                    ['type' => 'navigate', 'label' => 'Open sales report', 'to' => '/reports/sales'],
                ],
            ];
        }

        // 9. Supplier price drift: quiet cost creep on materials.
        $drift = $this->supplierPriceDrift();
        if ($drift->isNotEmpty()) {
            $worst = $drift->first();
            $items[] = [
                'key' => 'price_drift', 'severity' => 'medium',
                'title' => "{$worst->name} price up {$worst->drift_pct}% on the last purchase",
                'detail' => "Paid KES " . number_format((float) $worst->last_price, 2) . " to {$worst->supplier} vs a KES "
                    . number_format((float) $worst->avg_prior, 2) . " 90-day average"
                    . ($drift->count() > 1 ? ' — ' . ($drift->count() - 1) . ' more material' . ($drift->count() > 2 ? 's' : '') . ' drifting.' : '.'),
                'count' => $drift->count(), 'link' => '/reports/procurement',
                'entities' => $drift->take(3)->map(fn ($d) => [
                    'material_id' => (int) $d->material_id,
                    'name'        => $d->name,
                    'supplier_id' => (int) $d->supplier_id,
                    'supplier'    => $d->supplier,
                    'drift_pct'   => (float) $d->drift_pct,
                ])->values()->all(),
                'actions' => [
                    ['type' => 'navigate', 'label' => 'Open procurement report', 'to' => '/reports/procurement'],
                ],
            ];
        }

        // 10. Low stock — items at or below their reorder point.
        $low = $this->lowStock();
        if ($low > 0) {
            $items[] = [
                'key' => 'low_stock', 'severity' => 'medium',
                'title' => "{$low} item" . ($low > 1 ? 's' : '') . ' at or below reorder point',
                'detail' => 'Available quantity has crossed the reorder threshold.',
                'count' => $low, 'link' => '/reports/inventory',
                'actions' => [
                    ['type' => 'navigate', 'label' => 'Open inventory report', 'to' => '/reports/inventory'],
                ],
            ];
        }

        // Severity sort: high before medium, then by count desc.
        usort($items, fn ($a, $b) =>
            [$a['severity'] === 'high' ? 0 : 1, -$a['count']] <=> [$b['severity'] === 'high' ? 0 : 1, -$b['count']]);

        return $items;
    }

    // ── Institutional accounts ────────────────────────────────────────────────

    /**
     * HEURISTIC institution detector. A canonical-key identity counts as an
     * institution when its linked customers row says customer_type='business'
     * OR any of its display names (the order-snapshot first/last concat, the
     * customers first/last concat, or customers.company) contains a church /
     * institution keyword.
     *
     * The keyword list is a heuristic tuned to THIS customer base (PCEA
     * ST.LUKE, KARURA COMMUNITY CHAPEL, AIC KASINA, RGC RUIRU, …) — it will
     * miss institutions with plain-person names and could catch an individual
     * with an unlucky surname. Short acronyms (PCEA/ACK/AIC/SDA/RGC/GFI) are
     * word-bounded (\y) so "Jackson" never matches ACK; longer stems are
     * left-bounded only so MINISTR catches Ministry/Ministries and MISSION
     * catches Missions.
     */
    private const INSTITUTION_NAME_REGEX =
        '\y(PCEA|ACK|AIC|SDA|RGC|GFI)\y'
        . '|\y(CHURCH|CHAPEL|CATHEDRAL|PARISH|MINISTR|APOSTOLIC|COMMUNITY|CENTRE|CENTER'
        . '|TABERNACLE|WORSHIP|FAITH|GOSPEL|DELIVERANCE|REVIVAL|MISSION|DIOCESE|SAINT)'
        . '|\yST\.';

    /**
     * Institutional accounts — churches and institutions as ACCOUNTS, not
     * anonymous rows: per-institution rollups, buyer-turnover risk, and
     * cohort cross-sell.
     *
     * Institutions buy big, rebuy, and carry buyer-turnover risk (the PERSON
     * who orders for the church changes; the church remains). Identity is the
     * CANONICAL key (customer_id, else normalize_phone, else lowercased email
     * — same as rfmSegments) over the trailing-365-day sales truth; the
     * institution test is the INSTITUTION_NAME_REGEX heuristic above plus
     * customers.customer_type='business'.
     *
     * Per account: revenue_365 / orders_365 / last_order_at / days_quiet,
     * distinct products bought + the top product by spend, and
     * buyer_contacts = DISTINCT (normalized phone | lowercased email) combos
     * seen on its orders — more than one contact means the person ordering
     * has changed at least once (the turnover signal). risk = quiet for more
     * than 60 days AND >= KES 10,000 of trailing-365 revenue.
     *
     * CROSS-SELL: for each institution, the top 3 products it has NEVER
     * bought among products popular WITH OTHER institutions — cohort
     * popularity, so the pitch writes itself ("6 of 9 churches buy prefilled
     * cups"). "Popular" = bought by >= 2 institutions in the cohort;
     * adoption_pct = buying institutions / ALL institutions.
     *
     * Summary spans the WHOLE identified cohort (not the display cap):
     * institutions, revenue_365_total, share_of_total_revenue_pct (of ALL
     * trailing-365 sales-truth revenue), at_risk_count, at_risk_value.
     * accounts ranked revenue_365 desc, capped at 30. Cached 10 minutes,
     * keyed by outlet scope.
     */
    public function institutionalAccounts(): array
    {
        $scopeKey = $this->outletIds !== null
            ? implode('-', collect($this->outletIds)->map(fn ($id) => (int) $id)->sort()->values()->all())
            : 'all';

        return \Illuminate\Support\Facades\Cache::remember(
            "metrics:institutional_accounts:{$scopeKey}",
            600,
            fn () => $this->computeInstitutionalAccounts(),
        );
    }

    /** The uncached rollup pass behind institutionalAccounts(). */
    private function computeInstitutionalAccounts(): array
    {
        $key   = "COALESCE(o.customer_id::text, normalize_phone(o.customer_phone), LOWER(NULLIF(o.customer_email,'')))";
        $now   = CarbonImmutable::now(self::TZ);
        $today = $now->format('Y-m-d');
        $since = $now->subDays(365)->toMutable();
        $regex = self::INSTITUTION_NAME_REGEX;
        $outletSql = $this->outletIds
            ? 'AND o.outlet_id IN (' . implode(',', array_map('intval', $this->outletIds)) . ')'
            : '';

        // Shared CTE chain: keyed sales-truth orders → per-identity rollup →
        // the identities that pass the institution test. buyer_contact is the
        // normalized phone|email pair actually typed on each order — the
        // canonical key collapses them into one account, so counting the
        // DISTINCT pairs is exactly the buyer-turnover signal.
        $instCte = "
            WITH keyed AS (
                SELECT {$key} AS ckey, o.id AS order_id, o.customer_id, o.total_amount, o.created_at,
                       TRIM(CONCAT(COALESCE(o.customer_first_name,''),' ',COALESCE(o.customer_last_name,''))) AS name,
                       o.customer_phone AS phone,
                       NULLIF(CONCAT(
                           COALESCE(normalize_phone(o.customer_phone), ''), '|',
                           COALESCE(LOWER(NULLIF(o.customer_email,'')), '')
                       ), '|') AS buyer_contact
                FROM orders o
                WHERE o.status NOT IN ('voided','cancelled')
                  AND UPPER(o.currency_code) = 'KES'
                  AND o.created_at >= ?
                  AND {$key} IS NOT NULL
                  {$outletSql}
            ), per_customer AS (
                SELECT k.ckey, MAX(k.name) AS name, MAX(k.phone) AS phone,
                       MAX(k.customer_id) AS customer_id,
                       COUNT(*)                       AS orders_365,
                       COALESCE(SUM(k.total_amount), 0) AS revenue_365,
                       MAX(k.created_at)              AS last_order_at,
                       COUNT(DISTINCT k.buyer_contact) AS buyer_contacts
                FROM keyed k GROUP BY k.ckey
            ), institutions AS (
                SELECT p.*
                FROM per_customer p
                LEFT JOIN customers c ON c.id = p.customer_id
                WHERE c.customer_type = 'business'
                   OR p.name ~* '{$regex}'
                   OR COALESCE(c.company, '') ~* '{$regex}'
                   OR TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) ~* '{$regex}'
            )
        ";

        // 1. The institution cohort, ranked by money.
        $rows = DB::select("
            {$instCte}
            SELECT i.*, (?::date - DATE(i.last_order_at)) AS days_quiet
            FROM institutions i
            ORDER BY i.revenue_365 DESC
        ", [$since, $today]);

        if ($rows === []) {
            return [
                'summary' => [
                    'institutions'               => 0,
                    'revenue_365_total'          => 0.0,
                    'share_of_total_revenue_pct' => 0.0,
                    'at_risk_count'              => 0,
                    'at_risk_value'              => 0.0,
                ],
                'accounts' => [],
            ];
        }

        // 2. Everything every institution bought: per (institution, product)
        //    spend — feeds distinct-product counts, top product, cohort
        //    adoption AND the never-bought cross-sell in one pass.
        $lines = DB::select("
            {$instCte}
            SELECT k.ckey, oi.product_id,
                   COALESCE(MAX(pt.name), MAX(p.slug), MAX(p.sku)) AS product_name,
                   SUM(oi.total_price) AS spend
            FROM keyed k
            JOIN order_items oi ON oi.order_id = k.order_id AND oi.product_id IS NOT NULL
            JOIN products p     ON p.id = oi.product_id
            LEFT JOIN product_translations pt
                   ON pt.product_id = p.id AND pt.language_code = 'en'
            WHERE k.ckey IN (SELECT ckey FROM institutions)
            GROUP BY k.ckey, oi.product_id
        ", [$since]);

        // 3. Denominator for the revenue share: ALL trailing-365 sales truth.
        $totalRevenue = (float) $this->salesBase()
            ->where('created_at', '>=', $since)
            ->sum('total_amount');

        // Per-institution product map + cohort adoption per product.
        $byInst  = collect($lines)->groupBy('ckey');
        $cohortN = count($rows);
        $adoption = collect($lines)
            ->groupBy('product_id')
            ->map(fn ($ls) => [
                'product_id' => (int) $ls->first()->product_id,
                'name'       => $ls->first()->product_name,
                'buyers'     => $ls->pluck('ckey')->unique()->count(),
            ])
            // "Popular with the cohort" needs more than one buyer — a single
            // church's pet product is not a cross-sell thesis.
            ->filter(fn ($p) => $p['buyers'] >= 2)
            ->sortByDesc('buyers')
            ->values();

        $risky    = collect($rows)->filter(fn ($r) => (int) $r->days_quiet > 60 && (float) $r->revenue_365 >= 10000);
        $instRevenue = (float) collect($rows)->sum(fn ($r) => (float) $r->revenue_365);

        $accounts = collect($rows)->take(30)->map(function ($r) use ($byInst, $adoption, $cohortN) {
            $owned = $byInst->get($r->ckey) ?? collect();
            $ownedIds = $owned->pluck('product_id')->map(fn ($id) => (int) $id)->flip();
            $top = $owned->sortByDesc(fn ($l) => (float) $l->spend)->first();

            $crossSell = $adoption
                ->reject(fn ($p) => $ownedIds->has($p['product_id']))
                ->take(3)
                ->map(fn ($p) => [
                    'product_id'   => $p['product_id'],
                    'name'         => $p['name'],
                    'adoption_pct' => $cohortN > 0 ? round($p['buyers'] / $cohortN * 100, 1) : 0.0,
                ])
                ->values()
                ->all();

            return [
                'ckey'            => $r->ckey,
                'name'            => ($r->name !== null && $r->name !== '') ? $r->name : ($r->phone ?? 'Unknown'),
                'phone'           => $r->phone,
                'revenue_365'     => round((float) $r->revenue_365, 2),
                'orders_365'      => (int) $r->orders_365,
                'last_order_at'   => $r->last_order_at,
                'days_quiet'      => (int) $r->days_quiet,
                'risk'            => (int) $r->days_quiet > 60 && (float) $r->revenue_365 >= 10000,
                'buyer_contacts'  => (int) $r->buyer_contacts,
                'products_bought' => $owned->count(),
                'top_product'     => $top?->product_name,
                'cross_sell'      => $crossSell,
            ];
        })->values();

        return [
            'summary' => [
                'institutions'               => $cohortN,
                'revenue_365_total'          => round($instRevenue, 2),
                'share_of_total_revenue_pct' => $totalRevenue > 0
                    ? round($instRevenue / $totalRevenue * 100, 1)
                    : 0.0,
                'at_risk_count'              => $risky->count(),
                'at_risk_value'              => round((float) $risky->sum(fn ($r) => (float) $r->revenue_365), 2),
            ],
            'accounts' => $accounts->all(),
        ];
    }

    // ── International corridor ────────────────────────────────────────────────

    /**
     * International corridor — the diaspora/regional business the KES-only
     * reports deliberately exclude (see the salesBase() currency filter and
     * the excluded-currency warning on the Sales page). A corridor order is
     * SALES TRUTH (non-voided/cancelled) that is either not in KES or is
     * flagged is_international (a KES order shipped abroad counts too).
     *
     * Native currencies are NEVER silently summed together. Per-currency
     * rollups stay native; a KES equivalent is reported only where the
     * currencies table carries a usable configured rate, and the corridor
     * total only when EVERY active corridor currency converts — otherwise
     * kes_equivalent_total is null and rates_unavailable says why.
     *
     * Cached 10 minutes, keyed by scope + days (same idiom as attachRates).
     */
    public function internationalCorridor(int $days = 180): array
    {
        $scopeKey = $this->outletIds !== null
            ? implode('-', collect($this->outletIds)->map(fn ($id) => (int) $id)->sort()->values()->all())
            : 'all';

        return \Illuminate\Support\Facades\Cache::remember(
            "metrics:international_corridor:{$scopeKey}:{$days}",
            600,
            fn () => $this->computeInternationalCorridor($days),
        );
    }

    /** Corridor membership, shared by every query in the rollup pass. */
    private const CORRIDOR_WHERE = "(UPPER(o.currency_code) <> 'KES' OR o.is_international = true)";

    /** The uncached rollup pass behind internationalCorridor(). */
    private function computeInternationalCorridor(int $days): array
    {
        $key   = "COALESCE(o.customer_id::text, normalize_phone(o.customer_phone), LOWER(NULLIF(o.customer_email,'')))";
        $since = CarbonImmutable::now(self::TZ)->subDays($days)->startOfDay();
        $outletSql = $this->outletIds
            ? 'AND o.outlet_id IN (' . implode(',', array_map('intval', $this->outletIds)) . ')'
            : '';
        $corridor = self::CORRIDOR_WHERE;

        // 1. Per-currency rollup (sales truth: what was sold, natively).
        $currencies = DB::select("
            SELECT UPPER(o.currency_code) AS currency,
                   COUNT(DISTINCT o.id)   AS orders,
                   COALESCE(SUM(o.total_amount), 0) AS revenue_native,
                   COUNT(DISTINCT {$key}) AS customers
            FROM orders o
            WHERE o.status NOT IN ('voided','cancelled')
              AND {$corridor}
              AND o.created_at >= ?
              {$outletSql}
            GROUP BY UPPER(o.currency_code)
            ORDER BY orders DESC, currency
        ", [$since]);

        // 1b. Money truth per currency: settled payments net of refunds on
        //     corridor orders. Only payments whose OWN currency matches the
        //     order's count — a USD order settled with a KES payment row must
        //     not leak into a "USD paid" figure.
        $paidByCurrency = collect(DB::select("
            SELECT UPPER(p.currency_code) AS currency,
                   SUM(p.amount - p.refund_amount) AS paid
            FROM payments p
            JOIN orders o ON o.id = p.order_id
            WHERE p.status = 'paid'
              AND UPPER(p.currency_code) = UPPER(o.currency_code)
              AND o.status NOT IN ('voided','cancelled')
              AND {$corridor}
              AND o.created_at >= ?
              {$outletSql}
            GROUP BY 1
        ", [$since]))->pluck('paid', 'currency');

        // 2. Per-country rollup. Revenue stays a per-currency map — a country
        //    can legitimately order in more than one currency (a Lusaka church
        //    paying USD one month and ZMW the next) and summing them would be
        //    fiction.
        $countryRows = DB::select("
            SELECT COALESCE(NULLIF(UPPER(o.customer_country_code), ''), 'unknown') AS country,
                   UPPER(o.currency_code) AS currency,
                   COUNT(DISTINCT o.id)   AS orders,
                   COALESCE(SUM(o.total_amount), 0) AS revenue_native,
                   COUNT(DISTINCT {$key}) AS customers
            FROM orders o
            WHERE o.status NOT IN ('voided','cancelled')
              AND {$corridor}
              AND o.created_at >= ?
              {$outletSql}
            GROUP BY 1, 2
        ", [$since]);

        $countryNames = DB::table('countries')->pluck('name', 'code')
            ->mapWithKeys(fn ($name, $code) => [strtoupper($code) => $name]);

        $countries = collect($countryRows)
            ->groupBy('country')
            ->map(function ($rows, $country) use ($countryNames) {
                // customers per currency can overlap across currencies; take
                // the max as the honest floor rather than a double-counted sum.
                return [
                    'country'      => $country,
                    'country_name' => $country === 'unknown' ? 'Unknown' : ($countryNames[$country] ?? $country),
                    'orders'       => (int) $rows->sum(fn ($r) => (int) $r->orders),
                    'customers'    => (int) $rows->max(fn ($r) => (int) $r->customers),
                    'revenue'      => $rows->mapWithKeys(fn ($r) => [$r->currency => round((float) $r->revenue_native, 2)])->all(),
                ];
            })
            ->sortByDesc('orders')
            ->values();

        // 3. Top products across the corridor, by the number of corridor
        //    orders they appear in (order count, not revenue — revenue would
        //    mix currencies).
        $topProducts = DB::select("
            SELECT oi.product_id,
                   COALESCE(MAX(pt.name), MAX(pr.slug), MAX(pr.sku)) AS name,
                   COUNT(DISTINCT o.id) AS orders,
                   COALESCE(SUM(oi.quantity), 0) AS units
            FROM orders o
            JOIN order_items oi ON oi.order_id = o.id AND oi.product_id IS NOT NULL
            JOIN products pr    ON pr.id = oi.product_id
            LEFT JOIN product_translations pt
                   ON pt.product_id = pr.id AND pt.language_code = 'en'
            WHERE o.status NOT IN ('voided','cancelled')
              AND {$corridor}
              AND o.created_at >= ?
              {$outletSql}
            GROUP BY oi.product_id
            ORDER BY orders DESC, units DESC
            LIMIT 10
        ", [$since]);

        // 4. Monthly trend — a fixed trailing 6 calendar months (zero-filled),
        //    independent of ?days: the mini-chart answers "is the corridor
        //    growing", which needs stable buckets.
        $trendSince = CarbonImmutable::now(self::TZ)->subMonthsNoOverflow(5)->startOfMonth();
        $trendRows = DB::select("
            SELECT TO_CHAR(o.created_at, 'YYYY-MM') AS month,
                   UPPER(o.currency_code) AS currency,
                   COUNT(DISTINCT o.id)   AS orders,
                   COALESCE(SUM(o.total_amount), 0) AS revenue_native
            FROM orders o
            WHERE o.status NOT IN ('voided','cancelled')
              AND {$corridor}
              AND o.created_at >= ?
              {$outletSql}
            GROUP BY 1, 2
        ", [$trendSince]);

        // 5. Denominators + distinct corridor customers in one pass.
        $totals = DB::selectOne("
            SELECT COUNT(DISTINCT o.id) FILTER (WHERE {$corridor}) AS corridor_orders,
                   COUNT(DISTINCT {$key}) FILTER (WHERE {$corridor}) AS corridor_customers,
                   COUNT(DISTINCT o.id) AS all_orders
            FROM orders o
            WHERE o.status NOT IN ('voided','cancelled')
              AND o.created_at >= ?
              {$outletSql}
        ", [$since]);

        // ── KES equivalence, only where a real configured rate exists. ───────
        // currencies.exchange_rate is base-relative (Currency::convert: base
        // amount = amount / rate; KES is the base at 1.0). The schema DEFAULTs
        // exchange_rate to 1.0, so a NON-base currency sitting at exactly 1.0
        // is an unconfigured row, not a peg — parity with KES is not a rate
        // anyone set on purpose, and inventing 1:1 USD→KES would be worse
        // than reporting native totals only.
        $rateRows = DB::table('currencies')->get(['code', 'exchange_rate', 'is_base']);
        $rates    = [];
        foreach ($rateRows as $r) {
            $code = strtoupper($r->code);
            $rate = (float) $r->exchange_rate;
            if ($rate <= 0) {
                continue;
            }
            if ($code === 'KES' || $r->is_base) {
                $rates[$code] = $rate; // base row (1.0 by convention)
            } elseif (abs($rate - 1.0) > 1e-9) {
                $rates[$code] = $rate;
            }
        }
        $rates['KES'] = $rates['KES'] ?? 1.0;

        $toKes = function (string $currency, float $amount) use ($rates): ?float {
            $rate = $rates[strtoupper($currency)] ?? null;

            return $rate !== null ? round($amount / $rate, 2) : null;
        };

        $currencyOut = collect($currencies)->map(fn ($c) => [
            'currency'         => $c->currency,
            'orders'           => (int) $c->orders,
            'revenue_native'   => round((float) $c->revenue_native, 2),
            'paid_native'      => round((float) ($paidByCurrency[$c->currency] ?? 0), 2),
            'customers'        => (int) $c->customers,
            'avg_order_native' => (int) $c->orders > 0
                ? round((float) $c->revenue_native / (int) $c->orders, 2)
                : 0.0,
            'kes_equivalent'   => $toKes($c->currency, (float) $c->revenue_native),
        ])->values();

        $ratesUnavailable = $currencyOut->contains(fn ($c) => $c['kes_equivalent'] === null);
        $kesTotal = (! $ratesUnavailable && $currencyOut->isNotEmpty())
            ? round((float) $currencyOut->sum(fn ($c) => (float) $c['kes_equivalent']), 2)
            : null;

        // Zero-filled 6-month trend; revenue converts only when every rate is
        // known (a partially-converted line would silently understate months).
        $byMonth = collect($trendRows)->groupBy('month');
        $trend   = collect(range(5, 0))->map(function ($back) use ($byMonth, $toKes, $ratesUnavailable) {
            $month = CarbonImmutable::now(self::TZ)->subMonthsNoOverflow($back)->format('Y-m');
            $rows  = $byMonth->get($month, collect());
            $kes   = null;
            if (! $ratesUnavailable) {
                $kes = round((float) $rows->sum(fn ($r) => $toKes($r->currency, (float) $r->revenue_native) ?? 0.0), 2);
            }

            return [
                'month'          => $month,
                'orders'         => (int) $rows->sum(fn ($r) => (int) $r->orders),
                'kes_equivalent' => $kes,
            ];
        })->values();

        return [
            'summary' => [
                'corridor_orders'         => (int) $totals->corridor_orders,
                'corridor_customers'      => (int) $totals->corridor_customers,
                'currencies_active'       => $currencyOut->count(),
                'share_of_all_orders_pct' => (int) $totals->all_orders > 0
                    ? round((int) $totals->corridor_orders / (int) $totals->all_orders * 100, 1)
                    : 0.0,
                'kes_equivalent_total'    => $kesTotal,
                'rates_unavailable'       => $ratesUnavailable,
            ],
            'currencies'   => $currencyOut->all(),
            'countries'    => $countries->all(),
            'top_products' => collect($topProducts)->map(fn ($p) => [
                'product_id' => (int) $p->product_id,
                'name'       => $p->name,
                'orders'     => (int) $p->orders,
                'units'      => (int) $p->units,
            ])->all(),
            'trend'        => $trend->all(),
            'window_days'  => $days,
        ];
    }
}
