<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a product cost us is never public.
 *
 * The storefront endpoints serialize ProductPrice rows whole, so every price
 * row carried cost_price to anyone on the internet — 1,162 of them in the
 * public product lists on 2026-10-02. Hiding the column on the model would
 * also hide it from the admin price editor, which needs it; so the public
 * group strips it on the way out instead, wherever it sits in the payload.
 */
class StripsInternalCost
{
    private const KEYS = ['cost_price', 'unit_cost', 'cost_amount', 'average_cost', 'last_cost'];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response->setData($this->strip($response->getData(true)));
        }

        return $response;
    }

    private function strip(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::KEYS, true)) {
                unset($value[$key]);
                continue;
            }
            $value[$key] = $this->strip($item);
        }

        return $value;
    }
}
