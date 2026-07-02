<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogApiRequests
{
    public function handle(Request $request, Closure $next)
    {
        $startTime = microtime(true);

        $response = $next($request);

        $duration = round((microtime(true) - $startTime) * 1000, 2); // ms

        // Log all API requests
        Log::channel('api')->info('API Request', [
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_id' => $request->user()?->id,
            'api_key' => $request->attributes->get('api_key_name'),
            'status' => $response->getStatusCode(),
            'duration_ms' => $duration,
            'user_agent' => $request->userAgent(),
        ]);

        // Alert on suspicious activity
        if ($response->getStatusCode() >= 400) {
            Log::channel('security')->warning('Failed API Request', [
                'method' => $request->method(),
                'path' => $request->path(),
                'ip' => $request->ip(),
                'status' => $response->getStatusCode(),
                'user_id' => $request->user()?->id,
            ]);
        }

        return $response;
    }
}