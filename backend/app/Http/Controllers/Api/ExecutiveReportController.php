<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reporting\MetricEngine;
use Illuminate\Http\Request;

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
        ]);
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
