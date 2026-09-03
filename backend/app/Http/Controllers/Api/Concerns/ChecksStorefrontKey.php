<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;

/**
 * The storefront-bridge shared-secret gate (HUB_CONTRACT §6): when
 * HUB_STOREFRONT_KEY is configured, every bridge endpoint requires a
 * matching X-Storefront-Key header; unset ⇒ open (the storefront sends
 * no header until told the key). One implementation for every bridge
 * controller — the gate is a single business rule, not one per file.
 */
trait ChecksStorefrontKey
{
    /** Returns a 401 response to short-circuit, or null to proceed. */
    private function rejectBadKey(Request $request)
    {
        $secret = config('services.storefront.key');
        if (!$secret) {
            return null; // open until a key is configured (§6)
        }
        if (!hash_equals($secret, (string) $request->header('X-Storefront-Key'))) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        return null;
    }
}
