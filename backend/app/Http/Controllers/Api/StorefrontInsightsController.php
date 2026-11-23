<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reporting\MetricEngine;
use Illuminate\Http\JsonResponse;

class StorefrontInsightsController extends Controller
{
    /**
     * Public: what the storefront may say about real buying patterns.
     *
     *   bought_together  { "<product slug>": ["<companion slug>", …] }
     *                    up to 5 companions, strongest first
     *   popular          ["<product slug>", …] most-bought first
     *
     * Mined by MetricEngine::storefrontAffinity() from recognised sales on
     * every channel — till, web, WhatsApp/chat and quotes that became orders
     * — with the same thresholds as the till's "usually bought with"
     * suggestions (PosController::suggestions). Slugs of published products
     * only: no customer, no count, no amount.
     *
     * Business-wide (unscoped), like the till's suggestions: affinity is
     * about the catalogue, not an outlet.
     *
     * Purely advisory. The storefront has its own fallback for a product
     * with no entry, so an empty feed is a normal answer and a failure here
     * must never break a product page — it degrades to the empty feed.
     */
    public function index(): JsonResponse
    {
        try {
            $feed = MetricEngine::unscoped()->storefrontAffinity();
        } catch (\Throwable $e) {
            report($e);
            $feed = ['bought_together' => [], 'popular' => [], 'window_days' => null, 'generated_at' => null];
        }

        return response()->json([
            'data' => [
                // An empty map reaches the wire as [] (PHP has one empty
                // array, and the API's response middleware re-encodes): the
                // storefront reads both [] and {} as "no pairs".
                'bought_together' => $feed['bought_together'],
                'popular'         => $feed['popular'],
            ],
            'meta' => [
                'window_days'  => $feed['window_days'],
                'generated_at' => $feed['generated_at'],
            ],
        ]);
    }
}
