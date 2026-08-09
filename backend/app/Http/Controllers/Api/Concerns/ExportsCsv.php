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

    private function wantsExport(Request $request): bool
    {
        return $request->filled('export');
    }
}
