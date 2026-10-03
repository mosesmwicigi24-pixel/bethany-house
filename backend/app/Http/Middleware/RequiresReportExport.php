<?php

namespace App\Http\Middleware;

use App\Support\ReportPages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `report.export` — the route hands back a FILE (a report PDF), so the caller
 * needs that page's export right as well as its view: reports.export, or
 * reports.export_supply on Inventory / Procurement. Must follow a single-page
 * `report.page:<page>`, which records the page; without one it refuses, so a
 * route wired wrongly fails closed.
 */
class RequiresReportExport
{
    public function handle(Request $request, Closure $next): Response
    {
        $page = $request->attributes->get('report_page');

        abort_unless(
            is_string($page) && ReportPages::canExport($request->user(), $page),
            403,
            'Downloading this report requires the reports.export permission'
                . (in_array($page, ReportPages::SUPPLY_PAGES, true) ? ' (or reports.export_supply).' : '.'),
        );

        return $next($request);
    }
}
