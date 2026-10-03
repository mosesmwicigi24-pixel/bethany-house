<?php

namespace App\Http\Middleware;

use App\Services\DataScopeResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reports are business-wide for everyone who may read them (owner,
 * 2026-09-30, restated for Phase 4A on 2026-10-03).
 *
 * Order, Quotation, Expense and ProductionTask carry a viewer scope, and from
 * Phase 4A an outlet manager is bounded to their outlets. The legacy report
 * family reads those models through Eloquent (Order::query()->recognised()…),
 * so without this the manager's Sales page would quietly drop to one shop
 * while the MetricEngine pages (raw SQL) kept the whole business — the exact
 * split the 2026-09-30 decision closed.
 *
 * One switch for the section, rather than withoutViewerScope() sprinkled over
 * forty report queries: a report endpoint added later inherits it. Reaching a
 * report still requires that page's permission (report.page, role hardening
 * 3A); this lifts only the row boundary.
 */
class ReportsAreBusinessWide
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(DataScopeResolver::BUSINESS_WIDE, true);

        return $next($request);
    }
}
