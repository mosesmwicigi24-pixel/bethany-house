<?php

use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    | Current domain map (direct-port setup, no Nginx):
    |   - localhost:5173   → Vite dev server (React admin, `npm run dev`)
    |   - localhost:3001   → Next.js customer frontend (NEXTJS_PORT)
    |   - localhost:3002   → React admin container (REACT_ADMIN_PORT)
    |   - localhost:8000   → Laravel API direct host port (LARAVEL_PORT)
    |
    | Note: Bearer-token auth used by the React admin works regardless of this
    | list. Stateful domains only affect cookie-based SPA sessions (Next.js).
    |
    | Production - set explicitly in .env rather than relying on defaults:
    |   SANCTUM_STATEFUL_DOMAINS=yourdomain.com,admin.yourdomain.com
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s,%s',
        // Core defaults (kept for compatibility)
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        implode(',', array_filter([
            // React admin - Vite dev server
            'localhost:5173',
            // React admin - Docker container (REACT_ADMIN_PORT)
            'localhost:3002',
            // Next.js customer frontend - Docker container (NEXTJS_PORT)
            'localhost:3001',
            // Laravel API - direct host port (LARAVEL_PORT, replaces Nginx :8001)
            'localhost:8000',
            // Current application URL with port (resolves production domain automatically)
            Sanctum::currentApplicationUrlWithPort(),
        ]))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    | Token lifetime strategy:
    |   - null    → tokens never expire (rely on explicit logout)
    |   - 120     → 2 hours  (recommended for admin sessions)
    |   - 10080   → 7 days   (recommended for remember_me tokens)
    |
    | To apply per-token expiry rather than a global value:
    |   $user->createToken('auth_token', ['*'], now()->addHours(2))
    |
    */

    'expiration' => env('SANCTUM_TOKEN_EXPIRY', null),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies'      => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token'  => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];