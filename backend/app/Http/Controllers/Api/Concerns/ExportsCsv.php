<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;

/**
 * CSV export for report endpoints — the same in-memory response
 * ReportController::csvResponse() builds (UTF-8 BOM for Excel, timestamped
 * filename, no-store cache headers), extracted so the MetricEngine-backed
 * endpoints in ExecutiveReportController can honour ?export=csv too.
 */
trait ExportsCsv
{
    /**
     * Stream a CSV response.
     * $headers: array of column header strings
     * $rows: iterable of arrays (one per row)
     * $filename: download filename without extension
     */
    private function csvResponse(array $headers, iterable $rows, string $filename): \Illuminate\Http\Response
    {
        // Build the CSV entirely in memory so errors are catchable and
        // output-buffering conflicts with streamDownload are avoided.
        $out = fopen('php://temp', 'r+');
        // UTF-8 BOM for Excel compatibility
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, array_values((array) $row));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '_' . now()->format('Ymd_His') . '.csv"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * Is the caller asking for a FILE — and may they have one?
     *
     * `reports.export` gated four explicit export routes while twenty-two
     * report endpoints honoured `?export=csv` behind `reports.view` alone, so
     * the permission was bypassable by appending a query parameter. Two roles
     * sat on the wrong side of that (outlet_manager, procurement_officer).
     *
     * Closed here rather than route by route, because a door repeated is a
     * door forgotten: this is the single place a report decides to hand back a
     * file. An endpoint added later inherits the check.
     *
     * The decision it enforces is the business's own. Looking at a figure on a
     * screen and taking a file out of the building are different acts here —
     * that is why a download-approval regime with owner copies exists (#366).
     * A permission named `reports.export` should therefore mean something. If
     * a role ought to be able to export, granting it `reports.export` is the
     * one-line answer, and then it is a decision rather than an accident.
     *
     * Since Phase 3A the rule is per page: the route's `report.page:<page>`
     * names the page, and a file needs that page's export right —
     * reports.export, or reports.export_supply on Inventory / Procurement
     * (App\Support\ReportPages::canExport). A route that names no single
     * page falls back to reports.export alone, the stricter of the two.
     */
    private function wantsExport(Request $request): bool
    {
        if (! $request->filled('export')) {
            return false;
        }

        $page = $request->attributes->get('report_page');

        abort_unless(
            is_string($page)
                ? \App\Support\ReportPages::canExport($request->user(), $page)
                : (bool) $request->user()?->can(\App\Support\ReportPages::EXPORT),
            403,
            'Downloading a report requires the reports.export permission.',
        );

        return true;
    }
}
