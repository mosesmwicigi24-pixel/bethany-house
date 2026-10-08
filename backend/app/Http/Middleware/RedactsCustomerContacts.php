<?php

namespace App\Http\Middleware;

use App\Support\CustomerContacts;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customer CONTACTS need customers.view, everywhere in Reports (owner,
 * 2026-10-01). Names stay: a report about who bought is unreadable without
 * them. A phone number or an email is what turns a report into a call list.
 *
 * Cycle 9 found contacts reaching reports.view alone on eleven endpoints, in
 * the executive attention feed, in the drill-downs' free text and in the
 * customers PDF — none of them checked. Nobody was exposed on the day (every
 * live report viewer also held customers.*), but the role map gives
 * reports.export without customers.view to two roles, so the first such user
 * could have downloaded a phone list.
 *
 * Applied to the RESPONSE, once, rather than to eleven queries, because a
 * check repeated per endpoint is a check the twelfth endpoint forgets. JSON is
 * walked; CSV is re-read column by column. A PDF cannot be edited after it is
 * rendered, so the PDF controller strips contacts from its data before
 * rendering, using the same CustomerContacts rules.
 */
class RedactsCustomerContacts
{
    /**
     * Reports whose contacts are SUPPLIERS', not customers'. The owner's rule
     * covers customers; a buyer needs their supplier list to buy. Without this
     * the email-shaped rule would blank procurement's own suppliers for the
     * very roles (procurement_officer) that hold no customers.view.
     */
    private const SUPPLIER_REPORTS = [
        'api/v1/admin/reports/purchase-orders',
        'api/v1/admin/reports/procurement-intelligence',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user()?->can('customers.view') || $request->is(...self::SUPPLIER_REPORTS)) {
            return $response;
        }

        if ($response instanceof JsonResponse) {
            $response->setData(CustomerContacts::redact($response->getData(true)));

            return $response;
        }

        if (str_contains((string) $response->headers->get('Content-Type'), 'text/csv')) {
            // A streamed CSV has no content to rewrite — setContent() on it
            // throws. Read the stream into memory and re-issue it, headers
            // intact, so a future streaming export is redacted, not a 500.
            if ($response instanceof StreamedResponse) {
                ob_start();
                $response->sendContent();
                $csv = (string) ob_get_clean();

                return new \Illuminate\Http\Response(CustomerContacts::redactCsv($csv), $response->getStatusCode(),
                    $response->headers->all());
            }

            $response->setContent(CustomerContacts::redactCsv((string) $response->getContent()));
        }

        return $response;
    }
}
