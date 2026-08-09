<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WinBackOutreach;
use App\Services\Reporting\MetricEngine;
use App\Support\Phone;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The Executive Dashboard — one call answering "how is the business right
 * now?" across sales, money, production, inventory and customers.
 *
 * Every figure is computed by MetricEngine (the canonical metric layer per
 * docs/REPORTS_SPEC.md); this controller only decides WHICH blocks the
 * caller may see:
 *   - route gate: reports.view
 *   - financial block (expenses, net position): reports.financial only
 *   - outlet managers: every number auto-scoped to their assigned outlets
 */
class ExecutiveReportController extends Controller
{
    public function executive(Request $request)
    {
        $validated = $request->validate([
            'period'    => 'nullable|string|in:today,yesterday,last_7,last_30,this_month,last_month,this_quarter,this_year,custom',
            'from'      => 'nullable|date|required_if:period,custom',
            'to'        => 'nullable|date|required_if:period,custom',
            'outlet_id' => 'nullable|integer|exists:outlets,id',
        ]);

        $periodKey = $validated['period'] ?? 'this_month';
        [$s, $e, $ps, $pe] = MetricEngine::resolvePeriod($periodKey, $validated['from'] ?? null, $validated['to'] ?? null);

        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);

        $revenue    = $engine->revenue($s, $e, $ps, $pe);
        $orders     = $engine->ordersCount($s, $e, $ps, $pe);
        $collected  = $engine->collected($s, $e, $ps, $pe);
        $customers  = $engine->newCustomers($s, $e, $ps, $pe);
        $completed  = $engine->productionCompleted($s, $e, $ps, $pe);
        $onTime     = $engine->onTimePct($s, $e, $ps, $pe);
        $open       = $engine->productionOpen();
        $owed       = $engine->outstandingBalance();

        $payload = [
            'period' => [
                'key'            => $periodKey,
                'start'          => $s->toIso8601String(),
                'end'            => $e->toIso8601String(),
                'previous_start' => $ps->toIso8601String(),
                'previous_end'   => $pe->toIso8601String(),
            ],
            'kpis' => [
                'sales' => [
                    'revenue'       => $revenue,
                    'orders'        => $orders,
                    // AOV derived here, from the same two numbers it must always agree with.
                    'aov'           => [
                        'current'  => $orders['current']  > 0 ? round($revenue['current'] / $orders['current'], 2)   : 0,
                        'previous' => $orders['previous'] > 0 ? round($revenue['previous'] / $orders['previous'], 2) : 0,
                        'series'   => collect(),
                    ],
                    'new_customers' => $customers,
                ],
                'money' => [
                    'collected'   => $collected,
                    'outstanding' => $owed,
                    'aging'       => $engine->outstandingAging(),
                ],
                'production' => [
                    'completed'   => $completed,
                    'on_time_pct' => $onTime,
                    'wip'         => $open['wip'],
                    'overdue'     => $open['overdue'],
                ],
                'inventory' => [
                    'low_stock' => $engine->lowStock(),
                ],
            ],
            'attention' => $engine->attention(),
        ];

        // CFO block: reports.financial holders only (rule 5 of the spec).
        if ($request->user()->can('reports.financial')) {
            $expenses = $engine->expenses($s, $e, $ps, $pe);
            $payload['kpis']['financial'] = [
                'expenses' => $expenses,
                'net_collected' => [
                    'current'  => round($collected['current'] - $expenses['current'], 2),
                    'previous' => round($collected['previous'] - $expenses['previous'], 2),
                    'series'   => collect(),
                ],
            ];
        }

        return response()->json($payload);
    }

    /**
     * Production intelligence — the floor explained: which stages run over,
     * where pieces pile up, who moves them, QC truth, whether next week's
     * promises fit the floor's actual pace, and what materials the open
     * orders demand. All MetricEngine, all scoped.
     */
    public function productionIntelligence(Request $request)
    {
        $validated = $request->validate([
            'period'    => 'nullable|string|in:today,yesterday,last_7,last_30,this_month,last_month,this_quarter,this_year,custom',
            'from'      => 'nullable|date|required_if:period,custom',
            'to'        => 'nullable|date|required_if:period,custom',
            'outlet_id' => 'nullable|integer|exists:outlets,id',
        ]);

        $periodKey = $validated['period'] ?? 'this_month';
        [$s, $e] = MetricEngine::resolvePeriod($periodKey, $validated['from'] ?? null, $validated['to'] ?? null);
        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);

        return response()->json([
            'period'      => ['key' => $periodKey, 'start' => $s->toIso8601String(), 'end' => $e->toIso8601String()],
            'cycle_times' => $engine->stageCycleTimes($s, $e),
            'bottlenecks' => $engine->bottlenecks(),
            'tailors'     => $engine->tailorThroughput($s, $e),
            'qc'          => $engine->qcRates($s, $e),
            'capacity'    => $engine->capacityOutlook(),
            'materials'   => $engine->materialDemand(),
        ]);
    }

    /** Inventory intelligence: valuation, ABC + cover, dead stock, materials. */
    public function inventoryIntelligence(Request $request)
    {
        $validated = $request->validate([
            'period'    => 'nullable|string|in:today,yesterday,last_7,last_30,this_month,last_month,this_quarter,this_year,custom',
            'from'      => 'nullable|date|required_if:period,custom',
            'to'        => 'nullable|date|required_if:period,custom',
            'outlet_id' => 'nullable|integer|exists:outlets,id',
        ]);

        $periodKey = $validated['period'] ?? 'last_30';
        [$s, $e] = MetricEngine::resolvePeriod($periodKey, $validated['from'] ?? null, $validated['to'] ?? null);
        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);

        return response()->json([
            'period'         => ['key' => $periodKey, 'start' => $s->toIso8601String(), 'end' => $e->toIso8601String()],
            'health'         => $engine->inventoryHealth(),
            'abc'            => $engine->abcClassification($s, $e),
            'stockout_risks' => $engine->stockoutRisks(),
            'dead_stock'     => $engine->deadStock(),
            'shrinkage'      => $engine->shrinkage($s, $e),
            'materials'      => $engine->materialStockHealth(),
        ]);
    }

    /** Procurement intelligence: supplier scorecard, buy list, open POs. */
    public function procurementIntelligence(Request $request)
    {
        $validated = $request->validate([
            'period'    => 'nullable|string|in:today,yesterday,last_7,last_30,this_month,last_month,this_quarter,this_year,custom',
            'from'      => 'nullable|date|required_if:period,custom',
            'to'        => 'nullable|date|required_if:period,custom',
            'outlet_id' => 'nullable|integer|exists:outlets,id',
        ]);

        $periodKey = $validated['period'] ?? 'this_quarter';
        [$s, $e] = MetricEngine::resolvePeriod($periodKey, $validated['from'] ?? null, $validated['to'] ?? null);
        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);

        return response()->json([
            'period'      => ['key' => $periodKey, 'start' => $s->toIso8601String(), 'end' => $e->toIso8601String()],
            'suppliers'   => $engine->supplierPerformance($s, $e),
            'suggestions' => $engine->purchaseSuggestions(),
            'open_pos'    => $engine->openPurchaseOrders(),
        ]);
    }

    /** Customer intelligence: segments, new vs returning, top + dormant. */
    public function customerIntelligence(Request $request)
    {
        $validated = $request->validate([
            'period'    => 'nullable|string|in:today,yesterday,last_7,last_30,this_month,last_month,this_quarter,this_year,custom',
            'from'      => 'nullable|date|required_if:period,custom',
            'to'        => 'nullable|date|required_if:period,custom',
            'outlet_id' => 'nullable|integer|exists:outlets,id',
        ]);

        $periodKey = $validated['period'] ?? 'this_month';
        [$s, $e] = MetricEngine::resolvePeriod($periodKey, $validated['from'] ?? null, $validated['to'] ?? null);
        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);

        return response()->json([
            'period'           => ['key' => $periodKey, 'start' => $s->toIso8601String(), 'end' => $e->toIso8601String()],
            'segments'         => $engine->customerSegments($s, $e),
            'new_vs_returning' => $engine->newVsReturning($s, $e),
            'top_customers'    => $engine->topCustomers($s, $e),
            'dormant'          => $engine->dormantTopCustomers(),
            'rfm'              => $engine->rfmSegments(),
        ]);
    }

    /**
     * Replenishment Radar — per-customer product reorder cycles. "As of now"
     * by design: the radar has no period parameter because due/overdue only
     * makes sense against today. reports.view via the route group; outlet
     * scoping via MetricEngine::for like every other report.
     */
    public function replenishment(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'nullable|integer|exists:outlets,id',
        ]);

        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);

        return response()->json($engine->replenishmentRadar());
    }

    /**
     * Collections funnel — quote/deposit/balance: every shilling promised but
     * not collected, staged with per-row follow-up lists. "As of now" like the
     * replenishment radar (owed only means anything against today).
     * reports.view via the route group; outlet scoping via MetricEngine::for.
     */
    public function collections(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'nullable|integer|exists:outlets,id',
        ]);

        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);

        return response()->json($engine->collectionsFunnel());
    }

    /**
     * Attach-rate intelligence — basket affinities over the trailing window
     * (default 180 days): which products sell together, how strongly (attach
     * rate + lift), and the ESTIMATED revenue missed on anchor sales where
     * the usual companion never made it into the basket. reports.view via the
     * route group; outlet scoping via MetricEngine::for; result cached 10
     * minutes inside the engine (keyed by scope + days).
     */
    public function attachRates(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'nullable|integer|exists:outlets,id',
            'days'      => 'nullable|integer|min:30|max:365',
        ]);

        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);

        return response()->json($engine->attachRates((int) ($validated['days'] ?? 180)));
    }

    /**
     * Stock-out revenue loss — zero-stock windows RECONSTRUCTED from the
     * inventory ledger over the trailing window (default 90 days), sales
     * velocity computed over in-stock days only, and the estimated KES lost
     * while shelves sat empty — plus the live "bleeding now" rate for
     * products out right now. reports.view via the route group; outlet
     * scoping via MetricEngine::for; cached 10 minutes inside the engine
     * (keyed by scope + days).
     */
    public function stockoutLoss(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'nullable|integer|exists:outlets,id',
            'days'      => 'nullable|integer|min:30|max:365',
        ]);

        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);

        return response()->json($engine->stockoutLoss((int) ($validated['days'] ?? 90)));
    }

    /**
     * Seasonal demand planning — the liturgical calendar (SeasonCalendar's
     * pure computus, not the storefront CMS seasons) crossed with combined
     * sales history (hub orders UNION imported legacy POS aggregates):
     * per-season product lift, projected units, stock gap and the ORDER-BY
     * date each purchase must leave by given supplier lead times. "As of
     * now" like the radar — the horizon is always the next 120 days.
     * reports.view via the route group; outlet scoping via MetricEngine::for;
     * cached 10 minutes inside the engine.
     */
    public function seasonalDemand(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'nullable|integer|exists:outlets,id',
        ]);

        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);

        return response()->json($engine->seasonalDemand());
    }

    /**
     * Win-back economics — dormant customers scored against their OWN
     * purchase rhythm, with the KES at risk, outreach history and 30-day
     * recovery attribution. "As of now" like the radar (dormancy only means
     * anything against today). reports.view via the route group; outlet
     * scoping via MetricEngine::for.
     */
    public function winBack(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'nullable|integer|exists:outlets,id',
        ]);

        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);

        return response()->json($engine->winBackEconomics());
    }

    /**
     * Log a manual win-back contact (WhatsApp opened / call made / other).
     *
     * The row is stamped with the authenticated user and a SERVER-computed
     * snapshot of the customer's trailing-365 revenue and days quiet —
     * client-sent revenue_365/days_quiet are accepted only as a fallback
     * when the identity has no resolvable sales history (defence against a
     * stale tab writing fiction into the attribution base).
     *
     * Dedupe: the same identity (customer_id or canonical phone) logged
     * within the last 24h returns 200 {already_logged: true} and writes
     * nothing — two clerks, one call.
     */
    public function winBackOutreach(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|integer|exists:customers,id',
            'phone'       => 'nullable|string|max:32',
            'name'        => 'required|string|max:200',
            'channel'     => 'required|string|in:whatsapp,call,other',
            // Fallback snapshot values only — the server recomputes when it can.
            'revenue_365' => 'nullable|numeric|min:0',
            'days_quiet'  => 'nullable|integer|min:0',
        ]);

        $customerId = isset($validated['customer_id']) ? (int) $validated['customer_id'] : null;
        $canonical  = Phone::canonical($validated['phone'] ?? null);
        abort_if($customerId === null && $canonical === null, 422, 'customer_id or a usable phone is required.');

        $existing = WinBackOutreach::query()
            ->where('created_at', '>=', now()->subDay())
            ->where(function ($q) use ($customerId, $canonical) {
                if ($customerId !== null) {
                    $q->orWhere('customer_id', $customerId);
                }
                if ($canonical !== null) {
                    $q->orWhere('phone', $canonical);
                }
            })
            ->latest('created_at')
            ->first();

        if ($existing !== null) {
            return response()->json(['already_logged' => true, 'outreach' => $existing]);
        }

        // Server-side truth for the snapshot: this identity's trailing-365
        // sales (canonical phone bridged via normalize_phone, same as the
        // win-back cohort itself).
        $snap = DB::table('orders')
            ->whereNotIn('status', ['voided', 'cancelled'])
            ->whereRaw("UPPER(currency_code) = 'KES'")
            ->where('created_at', '>=', now()->subDays(365))
            ->where(function ($q) use ($customerId, $canonical) {
                if ($customerId !== null) {
                    $q->orWhere('customer_id', $customerId);
                }
                if ($canonical !== null) {
                    $q->orWhereRaw('normalize_phone(customer_phone) = ?', ['+' . $canonical]);
                }
            })
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS revenue_365, MAX(created_at) AS last_order_at')
            ->first();

        $hasHistory = $snap !== null && $snap->last_order_at !== null;
        $revenue365 = $hasHistory
            ? round((float) $snap->revenue_365, 2)
            : (isset($validated['revenue_365']) ? round((float) $validated['revenue_365'], 2) : null);
        $daysQuiet = $hasHistory
            ? (int) Carbon::parse($snap->last_order_at)->diffInDays(now())
            : ($validated['days_quiet'] ?? null);

        $outreach = WinBackOutreach::create([
            'customer_id'            => $customerId,
            'phone'                  => $canonical,
            'name'                   => $validated['name'],
            'channel'                => $validated['channel'],
            'contacted_by'           => $request->user()->id,
            'revenue_365_at_contact' => $revenue365,
            'days_quiet_at_contact'  => $daysQuiet,
        ]);

        return response()->json(['already_logged' => false, 'outreach' => $outreach], 201);
    }

    /** CFO block: earned P&L, budget-aware expenses, cash flow, rails. */
    public function financialIntelligence(Request $request)
    {
        abort_unless($request->user()->can('reports.financial'), 403, 'Financial intelligence requires reports.financial.');

        $validated = $request->validate([
            'period'    => 'nullable|string|in:today,yesterday,last_7,last_30,this_month,last_month,this_quarter,this_year,custom',
            'from'      => 'nullable|date|required_if:period,custom',
            'to'        => 'nullable|date|required_if:period,custom',
            'outlet_id' => 'nullable|integer|exists:outlets,id',
        ]);

        $periodKey = $validated['period'] ?? 'this_month';
        [$s, $e] = MetricEngine::resolvePeriod($periodKey, $validated['from'] ?? null, $validated['to'] ?? null);
        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);

        return response()->json([
            'period'      => ['key' => $periodKey, 'start' => $s->toIso8601String(), 'end' => $e->toIso8601String()],
            'pnl'         => $engine->earnedPnl($s, $e),
            'expenses'    => $engine->expensesByCategory($s, $e),
            'cash_flow'   => $engine->cashFlowWeekly($s, $e),
            'rails'       => $engine->methodReconciliation($s, $e),
        ]);
    }

    /**
     * Row-level drill-down for a KPI — the same query as the aggregate with
     * the aggregation removed (spec rule 3). Financial metrics stay behind
     * reports.financial, exactly like the block they came from.
     */
    public function drill(Request $request, string $metric)
    {
        $validated = $request->validate([
            'period' => 'nullable|string|in:today,yesterday,last_7,last_30,this_month,last_month,this_quarter,this_year,custom',
            'from'   => 'nullable|date|required_if:period,custom',
            'to'     => 'nullable|date|required_if:period,custom',
            'outlet_id' => 'nullable|integer|exists:outlets,id',
            'page'   => 'nullable|integer|min:1',
            'bucket' => 'nullable|string|in:0_30,31_60,61_90,90_plus,deposits',
        ]);

        if ($metric === 'expenses') {
            abort_unless($request->user()->can('reports.financial'), 403, 'Financial drill-downs require reports.financial.');
        }

        [$s, $e] = MetricEngine::resolvePeriod($validated['period'] ?? 'this_month', $validated['from'] ?? null, $validated['to'] ?? null);
        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);

        return response()->json($engine->drill(
            $metric, $s, $e,
            (int) ($validated['page'] ?? 1),
            $validated['bucket'] ?? null,
        ));
    }
}
