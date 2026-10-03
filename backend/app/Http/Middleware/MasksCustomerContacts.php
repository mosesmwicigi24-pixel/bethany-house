<?php

namespace App\Http\Middleware;

use App\Support\CustomerContacts;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customer contacts in the API serializer, outside Reports (Phase 4A, plan
 * §9). Route middleware `contacts.mask:<permission>[|<permission>…]` names
 * the capability that governs the read; CustomerContacts::policyFor decides
 * what the caller's roles may receive and CustomerContacts::apply enforces it
 * on the response — JSON walked, CSV re-read column by column. So the raw
 * value never reaches a masked role's browser, and a field added to a payload
 * later inherits the rule instead of needing someone to remember it.
 *
 * The same shape as RedactsCustomerContacts (Reports), applied to the
 * operational screens: orders, invoices, quotations, the pending queue,
 * interest carts, customers, shipments, returns, payments, the till and the
 * search palette. A PDF cannot be edited after it is rendered;
 * DocumentPdfController masks its data before rendering with the same rules.
 */
class MasksCustomerContacts
{
    public function handle(Request $request, Closure $next, string $permissions = 'orders.view'): Response
    {
        $response = $next($request);

        $policy = CustomerContacts::policyFor($request->user(), explode('|', $permissions));
        if (CustomerContacts::isFull($policy)) {
            return $response;
        }

        if ($response instanceof JsonResponse) {
            $response->setData(CustomerContacts::apply($response->getData(true), $policy));

            return $response;
        }

        if (str_contains((string) $response->headers->get('Content-Type'), 'text/csv')) {
            if ($response instanceof StreamedResponse) {
                ob_start();
                $response->sendContent();
                $csv = (string) ob_get_clean();

                return new \Illuminate\Http\Response(CustomerContacts::applyCsv($csv, $policy),
                    $response->getStatusCode(), $response->headers->all());
            }

            $response->setContent(CustomerContacts::applyCsv((string) $response->getContent(), $policy));
        }

        return $response;
    }
}
