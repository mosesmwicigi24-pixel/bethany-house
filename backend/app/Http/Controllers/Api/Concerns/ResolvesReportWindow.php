<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Support\ReportInput;
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
        // ReportInput::date returns the calendar day alone, so a caller who
        // sends a full timestamp no longer produces "2026-09-30 14:00:00
        // 23:59:59", and a malformed one is a 422 instead of a 500 from
        // Postgres (cycle 8). The middleware has already refused it; this is
        // the second line for a controller reached without it.
        $start = ReportInput::date('start_date', $request->get('start_date', $request->get('from')))
            ?? now()->subDays(29)->format('Y-m-d');
        $end   = ReportInput::date('end_date', $request->get('end_date', $request->get('to')))
            ?? now()->format('Y-m-d');

        return [$start, $end . ' 23:59:59'];
    }
}
