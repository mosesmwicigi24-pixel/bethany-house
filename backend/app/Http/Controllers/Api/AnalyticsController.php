<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Storefront visit analytics.
 *
 * track()    — public: the storefront posts one minimal record per page load
 *              (country resolved by the storefront's geo-IP; device parsed from
 *              the UA). No IP is stored — country + device only.
 * overview() — admin: the Insights dashboard. Emits `markets` (one row per
 *              country carrying visits, orders, revenue and conversion — the
 *              unified view the dashboard renders), plus the original
 *              visitors_by_country / buyers_by_country lists and the device/OS
 *              mix as a rough purchasing-power signal.
 */
class AnalyticsController extends Controller
{
    public function track(Request $request)
    {
        $v = $request->validate([
            'country'     => 'nullable|string|max:2',
            'device_type' => 'nullable|string|max:20',
            'os'          => 'nullable|string|max:30',
            'browser'     => 'nullable|string|max:30',
            'is_mobile'   => 'sometimes|boolean',
            'path'        => 'nullable|string|max:300',
            'referrer'    => 'nullable|string|max:300',
        ]);

        try {
            DB::table('site_visits')->insert([
                'country_code' => !empty($v['country']) ? strtoupper($v['country']) : null,
                'device_type'  => $v['device_type'] ?? null,
                'os'           => $v['os'] ?? null,
                'browser'      => $v['browser'] ?? null,
                'is_mobile'    => (bool) ($v['is_mobile'] ?? false),
                'path'         => $v['path'] ?? null,
                'referrer'     => $v['referrer'] ?? null,
                'created_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            // Analytics must never break the storefront.
        }

        return response()->noContent();
    }

    public function overview(Request $request)
    {
        $days  = max(1, min(365, (int) $request->get('days', 30)));
        $since = now()->subDays($days);

        // Visits per country — the FULL set, not a top-N. `markets` below joins
        // this against the buyer tally, and a truncated visit list would silently
        // drop the visits column for any country that buys from outside the top
        // 50. `visitors_by_country` is still capped at 50 for its own consumers.
        $visitTally = DB::table('site_visits')
            ->where('created_at', '>=', $since)->whereNotNull('country_code')
            ->select('country_code', DB::raw('count(*) as visits'))
            ->groupBy('country_code')->orderByDesc('visits')->get()
            ->map(fn ($r) => (object) ['country_code' => $r->country_code, 'visits' => (int) $r->visits]);

        $visitorsByCountry = $visitTally->take(50)->values();

        // Online buyers by country. Country codes are captured on few orders, so
        // fall back to the phone dialing-prefix (shared CountryInference resolver
        // — same one the Intelligence audience view uses) before giving up.
        $onlineOrders = DB::table('orders as o')
            ->leftJoin('customers as c1', 'c1.id', '=', 'o.customer_id')
            ->leftJoin('customers as c2', 'c2.user_id', '=', 'o.user_id')
            ->where('o.created_at', '>=', $since)
            ->where('o.order_type', 'online')
            ->selectRaw("o.customer_country_code, o.shipping_country_code, o.billing_country_code,
                         COALESCE(NULLIF(o.customer_phone,''), c1.phone, c2.phone) as phone,
                         o.total_amount")
            ->get();
        $buyerTally = [];
        foreach ($onlineOrders as $o) {
            $code = \App\Support\CountryInference::resolve(
                [$o->customer_country_code, $o->shipping_country_code, $o->billing_country_code],
                $o->phone,
            );
            if ($code === null) { continue; }
            $buyerTally[$code] ??= ['country_code' => $code, 'orders' => 0, 'revenue' => 0.0];
            $buyerTally[$code]['orders']++;
            $buyerTally[$code]['revenue'] += (float) $o->total_amount;
        }
        $buyersByCountry = collect(array_values($buyerTally))
            ->sortByDesc('orders')->take(50)->values();

        // ── Markets: one row per country, traffic AND money together ──────────
        // The dashboard's whole point is that these two are not the same list —
        // a country can send thousands of visits and buy nothing, or buy without
        // ever registering a visit (geo unresolved, or the country was inferred
        // from the buyer's phone). Joining them in the client meant unioning two
        // separately-truncated lists, so the join happens here instead, once.
        //
        // No country NAME is emitted: the client resolves names from the alpha-2
        // via Intl.DisplayNames, which covers all 249 ISO codes, where our
        // `countries` table is seeded with ~50. One source of truth beats two
        // that disagree.
        $visitsByCode = $visitTally->keyBy('country_code');
        $markets = collect(array_unique(array_merge(
                $visitsByCode->keys()->all(),
                array_keys($buyerTally),
            )))
            ->map(function (string $code) use ($visitsByCode, $buyerTally) {
                $visits  = (int) ($visitsByCode[$code]->visits ?? 0);
                $orders  = (int) ($buyerTally[$code]['orders'] ?? 0);
                $revenue = round((float) ($buyerTally[$code]['revenue'] ?? 0), 2);

                return [
                    'country_code' => $code,
                    'visits'       => $visits,
                    'orders'       => $orders,
                    'revenue'      => $revenue,
                    // null, not 0 — "we cannot compute this" is a different fact
                    // from "nobody converted", and the client renders them apart.
                    'conversion_pct' => $visits > 0 ? round($orders * 100 / $visits, 2) : null,
                ];
            })
            // Money first, traffic as the tie-break: the default reading order
            // the redesign is built around.
            ->sortBy([['revenue', 'desc'], ['visits', 'desc']])
            ->values();

        $devices = DB::table('site_visits')
            ->where('created_at', '>=', $since)->whereNotNull('device_type')
            ->select('device_type', DB::raw('count(*) as visits'))
            ->groupBy('device_type')->orderByDesc('visits')->get();

        $os = DB::table('site_visits')
            ->where('created_at', '>=', $since)->whereNotNull('os')
            ->select('os', DB::raw('count(*) as visits'))
            ->groupBy('os')->orderByDesc('visits')->limit(12)->get();

        return response()->json([
            'range_days' => $days,
            'totals'     => [
                'visits'    => DB::table('site_visits')->where('created_at', '>=', $since)->count(),
                'orders'    => DB::table('orders')->where('created_at', '>=', $since)->where('order_type', 'online')->count(),
                // The TRUE distinct-country count. This read $visitorsByCountry
                // (a ->limit(50) query), so any month with more than 50 visitor
                // countries reported a flat "50" on the KPI card.
                'countries' => $visitTally->count(),
                'mobile_share' => (function () use ($since) {
                    $total = DB::table('site_visits')->where('created_at', '>=', $since)->count();
                    if ($total === 0) return 0;
                    $mobile = DB::table('site_visits')->where('created_at', '>=', $since)->where('is_mobile', true)->count();
                    return round($mobile * 100 / $total);
                })(),
            ],
            // markets = the unified view the Insights page renders. The two
            // lists below are kept verbatim so nothing else that reads this
            // endpoint has to change.
            'markets'             => $markets,
            'visitors_by_country' => $visitorsByCountry,
            'buyers_by_country'   => $buyersByCountry,
            'devices'             => $devices,
            'os'                  => $os,
        ]);
    }
}
