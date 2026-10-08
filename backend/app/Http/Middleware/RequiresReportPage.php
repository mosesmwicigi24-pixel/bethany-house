<?php

namespace App\Http\Middleware;

use App\Support\ReportPages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `report.page:<page>` — the caller must hold that report page's permission
 * (Role Hardening Plan §6, Phase 3A). See App\Support\ReportPages.
 *
 *   report.page:sales            one page
 *   report.page:sales,financial  any of these pages
 *   report.page:any              any report page at all (shared helpers such
 *                                as the outlet filter and the schedule list)
 *   report.page:drill            the pages that offer the {metric} drill —
 *                                a drill inherits the page it opens from
 *
 * For a single page the key is recorded on the request (`report_page`), so a
 * CSV asked of the same endpoint is judged against THAT page's export rule
 * (ExportsCsv::wantsExport) rather than a section-wide one.
 *
 * It runs before report.window and the snapshot, so a caller without the page
 * is refused before any of their input is read.
 */
class RequiresReportPage
{
    public function handle(Request $request, Closure $next, string ...$pages): Response
    {
        $user = $request->user();

        if ($pages === ['any']) {
            $pages = ReportPages::keys();
        } elseif ($pages === ['drill']) {
            // An unknown metric is answered by the controller (422) — but only
            // to someone who reads reports at all.
            $pages = ReportPages::DRILLS[(string) $request->route('metric')] ?? ReportPages::keys();
        }

        abort_unless(
            ReportPages::canViewAny($user, $pages),
            403,
            count($pages) === 1
                ? 'This report page requires the ' . ReportPages::slug($pages[0]) . ' permission.'
                : 'You do not have access to the report this belongs to.',
        );

        if (count($pages) === 1) {
            $request->attributes->set('report_page', $pages[0]);
        }

        return $next($request);
    }
}
