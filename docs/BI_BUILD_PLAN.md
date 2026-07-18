# Business Intelligence Platform — build plan

**Contract:** every phase implements `docs/REPORTS_SPEC.md`. The platform goal:
answer *what happened, why, what needs attention, what to do next, what's
likely next* — from one backend that powers the web portal, iPad app, and
future mobile surfaces.

## Architecture (settled in Phase 1)

- **`App\Services\Reporting\MetricEngine`** is the only place canonical
  metrics are computed. Controllers compose and gate; they never write SQL
  for a number the engine owns. New metrics are added to the engine with a
  truth-source name (sales/money/drawer), a test, and a glossary line in
  REPORTS_SPEC.
- **Period grammar**: `?period=today|yesterday|last_7|last_30|this_month|
  last_month|this_quarter|this_year|custom&from&to` — resolved once, Nairobi
  day boundaries, previous-equivalent window always computed. Every future
  reporting endpoint takes this grammar; the ad-hoc `days=`/`start_date=`
  params on legacy endpoints are migrated, not multiplied.
- **Scope**: `MetricEngine::for($user, $outletId)` — outlet-assigned users are
  filtered in the query; `reports.financial` gates CFO blocks server-side.
- **Response shape**: every KPI is `{current, previous, series}` so any
  surface (web, iPad, mobile) renders value + delta + sparkline from the same
  contract without client-side math beyond percentage.

## Phase 1 — SHIPPED: metric engine + executive dashboard

Engine (periods, truth-separated sales/money/production/inventory metrics,
attention feed with 4 detectors), `GET /admin/reports/executive`, RBAC tests,
executive UI (period chips, delta+sparkline cards, needs-attention panel).

## Phase 2 — Sales & Money intelligence

- Migrate `salesSummary` + Sales report page onto the engine (fixing its
  paid-only "revenue" to true sales truth; collections become a first-class
  money view beside it).
- Drill-down API: every KPI gains `GET /reports/drill/{metric}` returning the
  source rows of the same query (spec rule 3) with pagination.
- Discounts, voids/returns, price-override exceptions; sales by
  hour/day-of-week retained; salesperson dimension.
- Aging buckets (30/60/90) on outstanding balances + deposits-held view.

## Phase 3 — Production intelligence

- Stage cycle times from task timestamps (avg time per stage, over-estimate
  flags — same arithmetic as the order page's timing intelligence).
- Bottleneck report (held pieces per stage over time), tailor throughput and
  QC pass/rework rates, capacity view (open pieces vs recent daily
  throughput), material consumption vs allocations.

## Phase 4 — Inventory & Procurement intelligence

- Valuation, turnover, stock aging, dead stock, ABC classification (revenue
  contribution), shrinkage from adjustment reasons.
- BOM-driven material demand from open production orders → purchase
  suggestions with lead-time awareness; supplier performance (delivery time,
  fill rate, price drift).

## Phase 5 — Customers & Financial statements

- Segments (churches/institutions/individuals), retention cohorts, dormancy
  detection, lifetime value on money truth.
- P&L on the engine (earned-revenue rule), cash-flow view, expense trends
  vs budget; payment-method reconciliation report.

## Phase 6 — Insights engine ("AI layer")

Deterministic detectors over engine metrics — never fabricated, every insight
cites its numbers and links to its drill-down:
- runway: material X runs out in N days at current consumption;
- trend: revenue/margin declining K periods in a row;
- capacity: due-date load next week exceeds recent throughput;
- dormancy: top-20 customer with no order in 60 days;
- price drift: supplier price up >10% vs 90-day average.
Delivered on the dashboard, plus scheduled digests (email exists; WhatsApp
via the existing EOD delivery channel). LLM-composed narrative summaries can
sit on top later; the detectors stay deterministic.

## Standing rules for every phase

1. Engine first, endpoint second, UI third — with a feature test proving the
   truth-source arithmetic before the UI exists.
2. No new metric without: truth source, glossary entry, test, drill-down.
3. Migrate legacy endpoints opportunistically; delete their bespoke SQL when
   their page moves to the engine. Never leave two ways to compute one number.
4. Exports/schedules reuse the engine query (spec rule 10).
5. Performance: indexed source queries; materialised views only when a real
   dataset proves too slow, refreshed from source.
