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
 * overview() — admin: the Insights dashboard — visitors by country, buyers by
 *              country (from orders), and the device/OS mix as a rough
 *              purchasing-power signal.
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

        $visitorsByCountry = DB::table('site_visits')
            ->where('created_at', '>=', $since)->whereNotNull('country_code')
            ->select('country_code', DB::raw('count(*) as visits'))
            ->groupBy('country_code')->orderByDesc('visits')->limit(50)->get();

        $buyersByCountry = DB::table('orders')
            ->where('created_at', '>=', $since)
            ->where('order_type', 'online')
            ->whereNotNull('customer_country_code')
            ->select(
                'customer_country_code as country_code',
                DB::raw('count(*) as orders'),
                DB::raw('coalesce(sum(total_amount), 0) as revenue'),
            )
            ->groupBy('customer_country_code')->orderByDesc('orders')->limit(50)->get();

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
                'countries' => $visitorsByCountry->count(),
                'mobile_share' => (function () use ($since) {
                    $total = DB::table('site_visits')->where('created_at', '>=', $since)->count();
                    if ($total === 0) return 0;
                    $mobile = DB::table('site_visits')->where('created_at', '>=', $since)->where('is_mobile', true)->count();
                    return round($mobile * 100 / $total);
                })(),
            ],
            'visitors_by_country' => $visitorsByCountry,
            'buyers_by_country'   => $buyersByCountry,
            'devices'             => $devices,
            'os'                  => $os,
        ]);
    }
}
