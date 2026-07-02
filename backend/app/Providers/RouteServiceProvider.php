<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // ═══════════════════════════════════════════════════════════════════════
        // AUTHENTICATION RATE LIMITER
        // Strict limit to prevent brute force attacks on login/register
        // ═══════════════════════════════════════════════════════════════════════
        
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Too many login attempts. Please try again later.',
                        'retry_after_seconds' => $headers['Retry-After'] ?? 60,
                        'error' => 'rate_limit_exceeded'
                    ], 429, $headers);
                });
        });

        // ═══════════════════════════════════════════════════════════════════════
        // PUBLIC API RATE LIMITER
        // Generous but prevents abuse of public endpoints
        // Applied to product browsing, categories, etc.
        // ═══════════════════════════════════════════════════════════════════════
        
        RateLimiter::for('public-api', function (Request $request) {
            // Check if user has API key for better rate limit
            $apiKeyName = $request->attributes->get('api_key_name');
            
            if ($apiKeyName) {
                // Users with valid API key get more generous limit
                return Limit::perMinute(100)
                    ->by($apiKeyName)
                    ->response(function (Request $request, array $headers) {
                        return response()->json([
                            'message' => 'Rate limit exceeded for your API key. Please slow down.',
                            'retry_after_seconds' => $headers['Retry-After'] ?? 60,
                            'error' => 'rate_limit_exceeded'
                        ], 429, $headers);
                    });
            }
            
            // Anonymous users get standard limit
            return Limit::perMinute(60)
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Too many requests. Please slow down or use an API key for higher limits.',
                        'retry_after_seconds' => $headers['Retry-After'] ?? 60,
                        'error' => 'rate_limit_exceeded',
                        'hint' => 'Contact support for an API key to get higher rate limits'
                    ], 429, $headers);
                });
        });

        // ═══════════════════════════════════════════════════════════════════════
        // SEARCH RATE LIMITER
        // Very strict for expensive search queries
        // ═══════════════════════════════════════════════════════════════════════
        
        RateLimiter::for('search', function (Request $request) {
            return Limit::perMinute(20)
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Too many search requests. Please slow down.',
                        'retry_after_seconds' => $headers['Retry-After'] ?? 60,
                        'error' => 'search_rate_limit_exceeded'
                    ], 429, $headers);
                });
        });

        // ═══════════════════════════════════════════════════════════════════════
        // AUTHENTICATED API RATE LIMITER
        // Standard limit for logged-in users
        // More generous than public since users are authenticated
        // ═══════════════════════════════════════════════════════════════════════
        
        RateLimiter::for('api', function (Request $request) {
            // Authenticated users get limit by their user ID
            if ($request->user()) {
                return Limit::perMinute(120)
                    ->by($request->user()->id)
                    ->response(function (Request $request, array $headers) {
                        return response()->json([
                            'message' => 'Too many requests. Please slow down.',
                            'retry_after_seconds' => $headers['Retry-After'] ?? 60,
                            'error' => 'rate_limit_exceeded'
                        ], 429, $headers);
                    });
            }
            
            // Fallback to IP-based limit if somehow not authenticated
            return Limit::perMinute(60)
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Too many requests. Please slow down.',
                        'retry_after_seconds' => $headers['Retry-After'] ?? 60,
                        'error' => 'rate_limit_exceeded'
                    ], 429, $headers);
                });
        });

        // ═══════════════════════════════════════════════════════════════════════
        // ADMIN API RATE LIMITER
        // Very generous for internal admin operations
        // Admins need to perform bulk operations quickly
        // ═══════════════════════════════════════════════════════════════════════
        
        RateLimiter::for('admin-api', function (Request $request) {
            return Limit::perMinute(300)
                ->by($request->user()?->id ?? $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Admin rate limit exceeded. Please slow down.',
                        'retry_after_seconds' => $headers['Retry-After'] ?? 60,
                        'error' => 'rate_limit_exceeded'
                    ], 429, $headers);
                });
        });

        // ═══════════════════════════════════════════════════════════════════════
        // WEBHOOKS RATE LIMITER
        // For payment provider callbacks (M-PESA, Paystack, etc.)
        // Limited per IP to prevent abuse
        // ═══════════════════════════════════════════════════════════════════════
        
        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(100)
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Webhook rate limit exceeded.',
                        'retry_after_seconds' => $headers['Retry-After'] ?? 60,
                        'error' => 'webhook_rate_limit_exceeded'
                    ], 429, $headers);
                });
        });

        // ═══════════════════════════════════════════════════════════════════════
        // OPTIONAL: GLOBAL API RATE LIMITER (Commented out - use specific limiters instead)
        // ═══════════════════════════════════════════════════════════════════════
        
        /*
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
        */
    }
}