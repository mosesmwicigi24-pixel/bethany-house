<?php

namespace App\Support;

use App\Models\DownloadRequest;
use App\Services\ActivityLogService;
use App\Services\Downloads\DownloadPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The rules an export of customer data obeys (Phase 4A, plan §9 / §17).
 *
 *   - Parity: the export runs the SCREEN's query — the same builder, so the
 *     same viewer scope and filters — and its CSV passes the same
 *     contacts.mask middleware as the screen's JSON. A controller never
 *     builds a second query for its export.
 *   - Row cap: at most rowCap() rows (10,000 unless configured); when the cap cuts the result the
 *     response says so (X-Export-Truncated) instead of looking complete.
 *   - Bulk contacts: more than BULK_CONTACTS_ROWS rows carrying customer
 *     contacts IN FULL (the caller's role sees them unmasked) marks the
 *     download request `bulk_contacts`, which only a super admin approver may
 *     decide (DownloadPolicy::mayDecide). Whether the gate HOLDS is still the
 *     config switch audit.downloads.enforce: off, the export is recorded as
 *     one that would have needed a super admin; on, it is held.
 */
final class ContactExport
{
    /** Default row cap; config audit.downloads.export_row_cap overrides. */
    public const ROW_CAP            = 10000;
    public const BULK_CONTACTS_ROWS = 200;

    public static function rowCap(): int
    {
        return max(1, (int) config('audit.downloads.export_row_cap', self::ROW_CAP));
    }

    /**
     * Run the screen's query under the cap.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return array{0: Collection, 1: bool}  [rows, truncated]
     */
    public static function rows($query): array
    {
        $cap       = self::rowCap();
        $rows      = $query->limit($cap + 1)->get();
        $truncated = $rows->count() > $cap;

        return [$truncated ? $rows->take($cap)->values() : $rows, $truncated];
    }

    /**
     * Classify the download this request is (DownloadGate opened its row
     * before the controller ran) by what it carries.
     *
     * @param  string|string[]  $permissions the capability the screen reads through (for the mask)
     */
    public static function classify(Request $request, int $rows, string|array $permissions): void
    {
        if ($rows <= self::BULK_CONTACTS_ROWS) {
            return;
        }

        $policy = CustomerContacts::policyFor($request->user(), $permissions);
        if ($policy['contacts'] !== CustomerContacts::FULL) {
            return;     // masked or omitted contacts are not a contact list
        }

        $dr = $request->attributes->get('download_request');
        if (!$dr instanceof DownloadRequest) {
            return;     // not a staff download (the gate passed it through)
        }

        // An approved replay (single-use token): the approver must have been
        // one who may decide a bulk-contacts export — the rows may have grown
        // past the threshold since a delegate approved a smaller file.
        if ($dr->decided_by !== null && $dr->category !== DownloadPolicy::BULK_CONTACTS) {
            $decider = \App\Models\User::find($dr->decided_by);
            if (!app(DownloadPolicy::class)->mayDecideBulkContacts($decider)) {
                abort(response()->json([
                    'code'    => 'download_bulk_contacts_needs_super_admin',
                    'message' => 'This export now carries more than ' . self::BULK_CONTACTS_ROWS
                        . ' customers\' contacts; it needs a super admin\'s approval. Ask again.',
                ], 403));
            }
        }

        if ($dr->category !== DownloadPolicy::BULK_CONTACTS) {
            $dr->forceFill(['category' => DownloadPolicy::BULK_CONTACTS])->save();
            ActivityLogService::log('download_bulk_contacts', $dr, [
                'rows'      => $rows,
                'threshold' => self::BULK_CONTACTS_ROWS,
                'path'      => $dr->path,
                'filters'   => $dr->payload,
                'enforcing' => (bool) config('audit.downloads.enforce', false),
            ], "Export of {$rows} customers' contacts — super admin approval", $request->user());
        }
    }

    /** Say on the response what the cap did. */
    public static function annotate(Response $response, int $rows, bool $truncated): Response
    {
        $response->headers->set('X-Export-Rows', (string) $rows);
        $response->headers->set('X-Export-Row-Cap', (string) self::rowCap());
        if ($truncated) {
            $response->headers->set('X-Export-Truncated', '1');
        }

        return $response;
    }
}
