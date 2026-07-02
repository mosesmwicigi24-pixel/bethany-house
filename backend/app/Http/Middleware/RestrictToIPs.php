<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RestrictToIPs
{
    /**
     * Allowed IP addresses
     */
    protected $allowedIps = [
        // Add your office/server IPs
        // '192.168.1.100',
        // '10.0.0.1',
    ];

    public function handle(Request $request, Closure $next)
    {
        // Get allowed IPs from config or use default
        $allowedIps = config('app.allowed_admin_ips', $this->allowedIps);

        // If no IPs configured, allow all (disable feature)
        if (empty($allowedIps)) {
            return $next($request);
        }

        // Check if current IP is allowed
        if (!in_array($request->ip(), $allowedIps)) {
            Log::warning('IP-restricted endpoint accessed from unauthorized IP', [
                'ip' => $request->ip(),
                'endpoint' => $request->path()
            ]);

            return response()->json([
                'message' => 'Access denied from your IP address'
            ], 403);
        }

        return $next($request);
    }
}