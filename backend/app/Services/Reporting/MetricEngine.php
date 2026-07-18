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
 *  - ONE CLOCK. All period boundaries are Africa/Nairobi days converted to
 *    UTC for querying; series bucket by the Nairobi day (fixed +3, no DST).
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

        return [
            $start->utc()->toMutable(), $end->utc()->toMutable(),
            $prevStart->utc()->toMutable(), $prevEnd->utc()->toMutable(),
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

    /** Nairobi-day bucket for a UTC timestamp column (EAT is fixed UTC+3). */
    private static function eatDay(string $col): string
    {
        return "DATE({$col} + INTERVAL '3 hours')";
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
                $a->copy()->addHours(3)->format('Y-m-d'),
                $b->copy()->addHours(3)->format('Y-m-d'),
            ])->sum('amount_kes');
        };
        return [
            'current'  => round($flow($s, $e), 2),
            'previous' => round($flow($ps, $pe), 2),
            'series'   => $base()->whereBetween('expense_date', [$s->copy()->addHours(3)->format('Y-m-d'), $e->copy()->addHours(3)->format('Y-m-d')])
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

    /** Sales minus money truth per open order: what customers still owe. */
    public function outstandingBalance(): array
    {
        $row = $this->salesBase()
            ->whereIn('payment_status', ['pending', 'partial', 'deposit'])
            ->leftJoinSub(
                DB::table('payments')->where('status', 'paid')
                    ->selectRaw('order_id, SUM(amount - COALESCE(refund_amount,0)) AS paid')
                    ->groupBy('order_id'),
                'pp', 'pp.order_id', '=', 'orders.id',
            )
            ->selectRaw('COUNT(*) AS orders, COALESCE(SUM(GREATEST(total_amount - COALESCE(pp.paid,0), 0)),0) AS owed')
            ->first();
        return ['amount' => round((float) $row->owed, 2), 'orders' => (int) $row->orders];
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
        $aging = $this->salesBase()
            ->whereIn('payment_status', ['pending', 'partial', 'deposit'])
            ->where('orders.created_at', '<', CarbonImmutable::now(self::TZ)->subDays(30)->utc())
            ->leftJoinSub(
                DB::table('payments')->where('status', 'paid')
                    ->selectRaw('order_id, SUM(amount - COALESCE(refund_amount,0)) AS paid')->groupBy('order_id'),
                'pp', 'pp.order_id', '=', 'orders.id',
            )
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(GREATEST(total_amount - COALESCE(pp.paid,0),0)),0) AS owed')
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

        // 4. Low stock — items at or below their reorder point.
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
