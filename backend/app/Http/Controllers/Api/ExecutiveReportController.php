<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ExportsCsv;
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
    use ExportsCsv;

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
     * Engine Room — the Overview strip: just the six revenue-engine summaries
     * in one lightweight call. Every engine is either served from its own
     * 10-minute cache or is a summary-sized query, so this stays cheap; each
     * one is computed independently so a single engine failing degrades to a
     * null (the SPA renders a "—" card) instead of taking the Overview down.
     * reports.view via the route group; outlet scoping via MetricEngine::for.
     */
    public function engineRoom(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'nullable|integer|exists:outlets,id',
        ]);

        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);

        $safe = static function (callable $compute) {
            try {
                return $compute();
            } catch (\Throwable $e) {
                report($e);

                return null;
            }
        };

        return response()->json([
            'collections'   => $safe(static fn () => $engine->collectionsFunnel()['summary']),
            'stockout'      => $safe(static fn () => $engine->stockoutLoss()['summary']),
            'winback'       => $safe(static fn () => $engine->winBackEconomics()['summary']),
            'attach'        => $safe(static fn () => $engine->attachRates()['summary']),
            'replenishment' => $safe(static fn () => $engine->replenishmentRadar()['summary']),
            'seasonal'      => $safe(static fn () => $engine->seasonalDemand()['summary']),
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
        $radar  = $engine->replenishmentRadar();

        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['Customer', 'Phone', 'Product', 'Tier', 'Status', 'Cycle Days', 'Purchase Events', 'Last Purchase', 'Next Due', 'Days Over', 'Expected Value (KES)', 'Last Pinged At', 'Last Ping Status'],
                collect($radar['due'])->map(fn ($r) => [
                    $r['name'], $r['phone'], $r['product_name'], $r['tier'], $r['status'],
                    $r['cycle_days'], $r['purchase_events'], $r['last_purchase_at'], $r['next_due_at'],
                    $r['days_over'], $r['avg_value'], $r['last_pinged_at'], $r['last_ping_status'],
                ]),
                'replenishment_radar',
            );
        }

        return response()->json($radar);
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
        $funnel = $engine->collectionsFunnel();

        if ($this->wantsExport($request)) {
            // One flat sheet: the three follow-up lists concatenated, tagged
            // by stage so a filter on column A rebuilds each list.
            $rows = collect($funnel['open_quotes'])->map(fn ($r) => [
                'open_quote', $r['number'], $r['customer'], $r['phone'],
                $r['value'], '', $r['value'], $r['age_days'], $r['status'],
            ])->concat(collect($funnel['stalled_deposits'])->map(fn ($r) => [
                'stalled_deposit', $r['number'], $r['customer'], $r['phone'],
                $r['total'], $r['deposit_paid'], $r['balance_due'], $r['days_since_last_payment'], 'stalled',
            ]))->concat(collect($funnel['unpaid_balances'])->map(fn ($r) => [
                'unpaid_balance', $r['number'], $r['customer'], $r['phone'],
                $r['total'], $r['paid'], $r['balance'], $r['days_outstanding'], $r['bucket'],
            ]));

            return $this->csvResponse(
                ['Stage', 'Number', 'Customer', 'Phone', 'Total (KES)', 'Paid (KES)', 'Balance (KES)', 'Days', 'Status'],
                $rows,
                'collections_funnel',
            );
        }

        return response()->json($funnel);
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
        $report = $engine->attachRates((int) ($validated['days'] ?? 180));

        if ($this->wantsExport($request)) {
            // Anchor → companion pairs, flattened one pair per row.
            $rows = collect($report['anchors'])->flatMap(fn ($a) => collect($a['companions'])->map(fn ($c) => [
                $a['name'], $a['baskets'], $c['name'], $c['sku'],
                $c['attach_rate_pct'], $c['lift'], $c['avg_value'],
                $c['missed'], $c['missed_revenue_estimate'],
            ]));

            return $this->csvResponse(
                ['Anchor Product', 'Anchor Baskets', 'Companion Product', 'Companion SKU', 'Attach Rate %', 'Lift', 'Avg Value (KES)', 'Missed Baskets', 'Missed Revenue Est (KES)'],
                $rows,
                'attach_rates',
            );
        }

        return response()->json($report);
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
        $report = $engine->stockoutLoss((int) ($validated['days'] ?? 90));

        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['Product', 'Currently Out', 'Out Streak (days)', 'Out Days (window)', 'Velocity/Day', 'Avg Price (KES)', 'Est Lost Revenue (KES)', 'Est Daily Loss (KES)', 'Confidence'],
                collect($report['products'])->map(fn ($p) => [
                    $p['name'], $p['currently_out'] ? 'yes' : 'no', $p['out_streak_days'],
                    $p['out_days_window'], $p['velocity_per_day'], $p['avg_price'],
                    $p['est_lost_revenue'], $p['est_daily_loss'], $p['confidence'],
                ]),
                'stockout_loss',
            );
        }

        return response()->json($report);
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
        $report = $engine->seasonalDemand();

        if ($this->wantsExport($request)) {
            // One row per (season, product) projection.
            $rows = collect($report['seasons'])->flatMap(fn ($s) => collect($s['products'] ?? [])->map(fn ($p) => [
                $s['label'], $s['start'], $s['end'], $s['days_until_start'],
                $p['name'], $p['lift'], $p['projected_units'], $p['stock'], $p['gap'],
                $p['lead_days'], $p['order_by'], $p['days_until_order_by'],
                $p['est_gap_value'], $p['basis'],
            ]));

            return $this->csvResponse(
                ['Season', 'Start', 'End', 'Days Until Start', 'Product', 'Lift', 'Projected Units', 'Stock', 'Gap', 'Lead Days', 'Order By', 'Days Until Order-By', 'Est Gap Value (KES)', 'Basis'],
                $rows,
                'seasonal_demand',
            );
        }

        return response()->json($report);
    }

    /**
     * International corridor — diaspora & regional orders (non-KES currency
     * OR is_international) over a trailing window (default 180 days): native
     * per-currency rollups, per-country grouping, corridor top products and
     * a 6-month trend. KES equivalents appear only where the currencies
     * table carries a real configured rate — never invented conversions.
     * reports.view via the route group; outlet scoping via MetricEngine::for;
     * cached 10 minutes inside the engine (keyed by scope + days).
     */
    public function international(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'nullable|integer|exists:outlets,id',
            'days'      => 'nullable|integer|min:30|max:365',
        ]);

        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);
        $report = $engine->internationalCorridor((int) ($validated['days'] ?? 180));

        if ($this->wantsExport($request)) {
            // Currency rows then country rows, tagged by a 'group' column.
            // Country revenue is a per-currency map (never summed across
            // currencies) — rendered as "USD 1200; ZMW 800".
            $rows = collect($report['currencies'])->map(fn ($c) => [
                'currency', $c['currency'], $c['orders'], $c['customers'],
                $c['revenue_native'], $c['paid_native'], $c['avg_order_native'], $c['kes_equivalent'],
            ])->concat(collect($report['countries'])->map(fn ($c) => [
                'country', $c['country_name'], $c['orders'], $c['customers'],
                collect($c['revenue'])->map(fn ($v, $cur) => "{$cur} {$v}")->implode('; '),
                '', '', '',
            ]));

            return $this->csvResponse(
                ['Group', 'Name', 'Orders', 'Customers', 'Revenue (native)', 'Paid (native)', 'Avg Order (native)', 'KES Equivalent'],
                $rows,
                'international_corridor',
            );
        }

        return response()->json($report);
    }

    /**
     * Win-back economics — dormant customers scored against their OWN
     * purchase rhythm, with the KES at risk, outreach history and 30-day
     * recovery attribution. "As of now" like the radar (dormancy only means
     * anything against today). reports.view via the route group; outlet
     * scoping via MetricEngine::for.
     */
    /**
     * The unconfirmed order queue — GET /reports/order-pipeline
     *
     * Revenue is recognised on confirmation, so an unconfirmed cart is not
     * income. That is the correct accounting answer and a useless operational
     * one: 98 carts worth KES 1.1m do not stop existing because a report stops
     * counting them. This is the list a human works through, aged so the
     * triage order is obvious, and exportable so it can be worked offline.
     */
    public function orderPipeline(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'nullable|integer|exists:outlets,id',
            'sort'      => 'nullable|in:value,age',
            'limit'     => 'nullable|integer|min:1|max:500',
        ]);

        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);
        $report = $engine->orderPipeline(
            $validated['sort']  ?? 'value',
            (int) ($validated['limit'] ?? 200),
        );

        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['Order', 'Channel', 'Customer', 'Phone', 'Email', 'Items', 'Currency', 'Value', 'Age (days)', 'Created'],
                collect($report['orders'])->map(fn ($o) => [
                    $o['order_number'],
                    $o['channel'],
                    $o['customer_name'] ?? '',
                    $o['customer_phone'] ?? '',
                    $o['customer_email'] ?? '',
                    $o['item_count'],
                    $o['currency_code'],
                    $o['total_amount'],
                    $o['age_days'],
                    $o['created_at'],
                ])->all(),
                'order-pipeline',
            );
        }

        return response()->json($report);
    }

    public function winBack(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'nullable|integer|exists:outlets,id',
        ]);

        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);
        $report = $engine->winBackEconomics();

        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['Customer', 'Phone', 'Revenue 365d (KES)', 'Orders 365d', 'Last Order', 'Days Quiet', 'Median Gap (days)', 'Urgency', 'Last Outreach Channel', 'Last Outreach At', 'Recovered Order', 'Recovered Amount (KES)'],
                collect($report['customers'])->map(fn ($c) => [
                    $c['name'], $c['phone'], $c['revenue_365'], $c['orders_365'],
                    $c['last_order_at'], $c['days_quiet'], $c['median_gap_days'], $c['urgency'],
                    $c['last_outreach']['channel'] ?? '', $c['last_outreach']['at'] ?? '',
                    $c['recovered']['order_number'] ?? '', $c['recovered']['amount'] ?? '',
                ]),
                'win_back_economics',
            );
        }

        return response()->json($report);
    }

    /**
     * Institutional accounts — churches/institutions as accounts: rollups,
     * buyer-turnover risk and cohort cross-sell. Identification is heuristic
     * (customer_type='business' or an institution keyword in the name — see
     * MetricEngine::INSTITUTION_NAME_REGEX). "As of now" like the radar (a
     * quiet church only means anything against today). reports.view via the
     * route group; outlet scoping via MetricEngine::for; cached 10 minutes
     * inside the engine (keyed by scope).
     */
    public function institutions(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'nullable|integer|exists:outlets,id',
        ]);

        $engine = MetricEngine::for($request->user(), isset($validated['outlet_id']) ? (int) $validated['outlet_id'] : null);
        $report = $engine->institutionalAccounts();

        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['Institution', 'Phone', 'Revenue 365d (KES)', 'Orders 365d', 'Last Order', 'Days Quiet', 'At Risk', 'Buyer Contacts', 'Products Bought', 'Top Product', 'Cross-Sell Suggestions'],
                collect($report['accounts'])->map(fn ($a) => [
                    $a['name'], $a['phone'], $a['revenue_365'], $a['orders_365'],
                    $a['last_order_at'], $a['days_quiet'], $a['risk'] ? 'yes' : 'no',
                    $a['buyer_contacts'], $a['products_bought'], $a['top_product'],
                    collect($a['cross_sell'])->pluck('name')->implode('; '),
                ]),
                'institutional_accounts',
            );
        }

        return response()->json($report);
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

    /**
     * Outreach log — the unified audit feed behind every proactive contact
     * the Hub makes: automated replenishment-radar WhatsApp pings
     * (replenishment_pings) merged with manual win-back outreach
     * (win_back_outreach), newest first, capped at 100 rows.
     *
     * Outcomes are attributed the same way the engines themselves do it:
     *  - winback rows reuse the win-back recovery rule — the FIRST order by
     *    the same identity within 30 days AFTER the contact reads
     *    "won back +KES X";
     *  - radar pings read "reordered" when the same phone bought the same
     *    product again within one cycle after the ping.
     *
     * Both tables are audit-sized and indexed on (phone, …, created_at), so
     * the two feed queries and the four summary counts stay cheap. The feed
     * is deliberately global (the audit tables carry no outlet dimension).
     * reports.view via the route group; ?export=csv via ExportsCsv.
     */
    public function outreachLog(Request $request)
    {
        // Radar pings, newest first. The customer name comes from the linked
        // customer when the ping resolved one; else the row's canonical phone
        // (with the product name right beside it, context is never lost).
        $pings = collect(DB::select("
            SELECT p.id, p.created_at, p.phone, p.product_id, p.product_name,
                   p.cycle_days, p.status,
                   NULLIF(TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))), '') AS customer_name,
                   EXISTS (
                       SELECT 1
                       FROM orders o
                       JOIN order_items oi ON oi.order_id = o.id
                       WHERE oi.product_id = p.product_id
                         AND o.status NOT IN ('voided','cancelled')
                         AND normalize_phone(o.customer_phone) = '+' || p.phone
                         AND o.created_at >  p.created_at
                         AND o.created_at <= p.created_at + (p.cycle_days * INTERVAL '1 day')
                   ) AS reordered
            FROM replenishment_pings p
            LEFT JOIN customers c ON c.id = p.customer_id
            ORDER BY p.created_at DESC
            LIMIT 100
        "));

        // Manual win-back outreach, newest first, each with its first
        // attributable order — the same 30-day identity rule
        // MetricEngine::winBackEconomics uses for the recovery summary.
        $outreach = collect(DB::select("
            SELECT w.id, w.created_at, w.name, w.phone, w.channel,
                   NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))), '') AS by_name,
                   fo.recovered_amount
            FROM win_back_outreach w
            LEFT JOIN users u ON u.id = w.contacted_by
            LEFT JOIN LATERAL (
                SELECT o.total_amount AS recovered_amount
                FROM orders o
                WHERE o.status NOT IN ('voided','cancelled')
                  AND UPPER(o.currency_code) = 'KES'
                  AND o.created_at >  w.created_at
                  AND o.created_at <= w.created_at + INTERVAL '30 days'
                  AND (
                        (w.customer_id IS NOT NULL AND o.customer_id = w.customer_id)
                     OR (w.phone IS NOT NULL AND normalize_phone(o.customer_phone) = '+' || w.phone)
                  )
                ORDER BY o.created_at ASC
                LIMIT 1
            ) fo ON TRUE
            ORDER BY w.created_at DESC
            LIMIT 100
        "));

        $rows = $pings->map(fn ($p) => [
            'at'           => $p->created_at,
            'type'         => 'radar_ping',
            'name'         => $p->customer_name ?? $p->phone,
            'phone'        => $p->phone,
            'channel'      => 'whatsapp',
            'product_name' => $p->product_name,
            'status'       => $p->status,
            'by'           => 'automation',
            'outcome'      => $p->reordered ? 'reordered' : '—',
        ])->concat($outreach->map(fn ($w) => [
            'at'           => $w->created_at,
            'type'         => 'winback',
            'name'         => $w->name,
            'phone'        => $w->phone,
            'channel'      => $w->channel,
            'product_name' => null,
            'status'       => 'logged',
            'by'           => $w->by_name ?? '—',
            'outcome'      => $w->recovered_amount !== null
                ? 'won back +KES ' . number_format(round((float) $w->recovered_amount))
                : '—',
        ]))
            // Timestamps share one 'Y-m-d H:i:s' shape, so the string sort is
            // the chronological sort.
            ->sortByDesc('at')
            ->take(100)
            ->values();

        if ($this->wantsExport($request)) {
            return $this->csvResponse(
                ['When', 'Type', 'Name', 'Phone', 'Channel', 'Product', 'Status', 'By', 'Outcome'],
                $rows->map(fn ($r) => [
                    $r['at'], $r['type'], $r['name'], $r['phone'], $r['channel'],
                    $r['product_name'], $r['status'], $r['by'], $r['outcome'],
                ]),
                'outreach_log',
            );
        }

        $since30 = now()->subDays(30);

        return response()->json([
            'summary' => [
                'pings_30d'    => (int) DB::table('replenishment_pings')
                    ->where('status', 'sent')->where('created_at', '>=', $since30)->count(),
                'outreach_30d' => (int) DB::table('win_back_outreach')
                    ->where('created_at', '>=', $since30)->count(),
                'failures_30d' => (int) DB::table('replenishment_pings')
                    ->where('status', 'failed')->where('created_at', '>=', $since30)->count(),
                'won_back_30d' => (int) (DB::selectOne("
                    SELECT COUNT(*) AS n
                    FROM win_back_outreach w
                    WHERE w.created_at >= ?
                      AND EXISTS (
                        SELECT 1 FROM orders o
                        WHERE o.status NOT IN ('voided','cancelled')
                          AND UPPER(o.currency_code) = 'KES'
                          AND o.created_at >  w.created_at
                          AND o.created_at <= w.created_at + INTERVAL '30 days'
                          AND (
                                (w.customer_id IS NOT NULL AND o.customer_id = w.customer_id)
                             OR (w.phone IS NOT NULL AND normalize_phone(o.customer_phone) = '+' || w.phone)
                          )
                      )
                ", [$since30])->n ?? 0),
            ],
            'rows' => $rows->all(),
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
