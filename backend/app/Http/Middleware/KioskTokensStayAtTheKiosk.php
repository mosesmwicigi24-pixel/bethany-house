<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A kiosk token (People C2) is a manager's Sanctum token with the single
 * ability `kiosk`, minted for the tablet at the door. Nothing in the API
 * checks abilities — a ['*'] token is the norm — so this is the fence:
 * such a token reaches /api/v1/admin/kiosk/* and nothing else. Whoever
 * picks up the tablet gets the clock-in board, not the manager's hub.
 * Runs on every API request after authentication (bootstrap/app.php).
 */
class KioskTokensStayAtTheKiosk
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user("sanctum");
        $token = $user?->currentAccessToken();
        // Abilities read off the row, not through can(): that word is for Spatie permissions here.
        $abilities = $token ? (array) ($token->abilities ?? []) : [];
        if ($abilities && !in_array('*', $abilities, true) && in_array('kiosk', $abilities, true) && !$request->is('api/v1/admin/kiosk', 'api/v1/admin/kiosk/*')) {
            return response()->json(['message' => 'This tablet is set up as a clock-in kiosk. Sign in on your own device for the rest of the hub.'], 403);
        }

        return $next($request);
    }
}
