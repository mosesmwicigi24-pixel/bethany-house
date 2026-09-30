<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;

/**
 * ONE resolver for "which window does this report answer for".
 *
 * There were two identical private copies — ReportController's and
 * ReportPdfController's — and when the first was taught to accept both date
 * spellings (D4, #382) the second was not. The printed report therefore kept
 * the old behaviour: a PDF exported with `from`/`to` covered the last thirty
 * days instead of the window on the screen it was exported from, silently,
 * and that is the copy people carry into meetings and quote back later.
 *
 * Three conventions exist across the section: `from`/`to` on the executive
 * endpoints, `start_date`/`end_date` here, and `period` on the intelligence
 * ones. `NormalisesReportWindow` mirrors the first two for every request in
 * the section; this resolver accepts both directly as well, so a controller
 * that is somehow reached without the middleware still behaves.
 */
trait ResolvesReportWindow
{
    /**
     * @return array{0: string, 1: string} [start, end-of-day]
     */
    private function dateRange(Request $request): array
    {
        $start = $request->get('start_date', $request->get('from', now()->subDays(29)->format('Y-m-d')));
        $end   = $request->get('end_date',   $request->get('to',   now()->format('Y-m-d')));

        // A caller who sends a full timestamp used to produce
        // "2026-09-30 14:00:00 23:59:59" — an invalid date that took the whole
        // query down with a 500 rather than reporting anything.
        return [$start, substr($end, 0, 10) . ' 23:59:59'];
    }
}
