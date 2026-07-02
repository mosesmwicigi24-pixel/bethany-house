<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiKey
{
    public function handle(Request $request, Closure $next, $required = 'optional'): Response
    {
        $apiKey = $request->header('X-API-Key') ?? $request->query('api_key');

        // If no API key and it's required, reject
        if (!$apiKey && $required === 'required') {
            return response()->json([
                'message' => 'API key is required',
                'hint' => 'Include X-API-Key header or api_key query parameter'
            ], 401);
        }

        // If API key provided, validate it
        if ($apiKey) {
            $key = DB::table('api_keys')
                ->where('key', $apiKey)
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                })
                ->first();

            if (!$key) {
                return response()->json([
                    'message' => 'Invalid or expired API key'
                ], 401);
            }

            // Check IP whitelist if configured
            if ($key->allowed_ips) {
                $allowedIps = json_decode($key->allowed_ips, true);
                if (!in_array($request->ip(), $allowedIps)) {
                    return response()->json([
                        'message' => 'API key not authorized from your IP address'
                    ], 403);
                }
            }

            // Check endpoint whitelist if configured
            if ($key->allowed_endpoints) {
                $allowedEndpoints = json_decode($key->allowed_endpoints, true);
                $currentPath = $request->path();
                
                $isAllowed = false;
                foreach ($allowedEndpoints as $pattern) {
                    if (fnmatch($pattern, $currentPath)) {
                        $isAllowed = true;
                        break;
                    }
                }
                
                if (!$isAllowed) {
                    return response()->json([
                        'message' => 'API key not authorized for this endpoint'
                    ], 403);
                }
            }

            // Update usage statistics
            DB::table('api_keys')
                ->where('id', $key->id)
                ->increment('total_requests');
            
            DB::table('api_keys')
                ->where('id', $key->id)
                ->update(['last_used_at' => now()]);

            // Attach key info to request for logging
            $request->attributes->set('api_key_id', $key->id);
            $request->attributes->set('api_key_name', $key->name);
        }

        return $next($request);
    }
}