<?php

namespace App\Http\Middleware;

use App\Support\FinancialVisibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Money over a period is the owner's and finance's (owner, 2026-10-15).
 *
 * On a money list that takes a date window — the payments ledger, its
 * analytics and export, the cash report — anyone else is answered for TODAY
 * (Nairobi), whatever window they asked for: every spelling the endpoint
 * reads is overwritten, so no parameter is left to widen it. The response
 * carries `X-Money-Window: today` so the console can say so.
 *
 * Who may read further back: FinancialVisibility::mayReadMoneyHistory.
 */
class LimitsMoneyWindowToToday
{
    private const WINDOW_FIELDS = ['start_date', 'end_date', 'from', 'to', 'date_from', 'date_to'];

    public function handle(Request $request, Closure $next): Response
    {
        if (FinancialVisibility::mayReadMoneyHistory($request->user())) {
            return $next($request);
        }

        $today = today()->toDateString();
        $request->query->add(array_fill_keys(self::WINDOW_FIELDS, $today));
        $request->merge(array_fill_keys(self::WINDOW_FIELDS, $today));

        $response = $next($request);
        $response->headers->set('X-Money-Window', 'today');

        return $response;
    }
}
