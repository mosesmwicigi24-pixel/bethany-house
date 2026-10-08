<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The owner never transacts (role hardening, Phase 1B). The owner's accounts —
 * super_admin and system_admin — oversee the till; they do not ring up sales,
 * take money at the counter, void, refund over the counter, or work a drawer.
 * A sale he rang himself is a sale nobody independent checks.
 *
 * This is a route middleware on exactly the POS write endpoints, not a
 * permission: super_admin passes every permission check through
 * AuthServiceProvider's Gate::before, so a permission could never stop him.
 * Holding a till role as well (super_admin + pos_clerk) does not lift it —
 * the deny looks only for the owner roles. Every POS read stays open, so the
 * owner can still watch the screens.
 */
class OwnerDoesNotTransact
{
    /** Roles that mark an account as the owner's. */
    private const OWNER_ROLES = ['super_admin', 'system_admin'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // No guard name: an owner role under any guard counts.
        if ($user && $user->hasAnyRole(self::OWNER_ROLES)) {
            return response()->json([
                'message' => "The owner's account does not transact at the till. A cashier or outlet manager must do this.",
                'code'    => 'OWNER_DOES_NOT_TRANSACT',
            ], 403);
        }

        return $next($request);
    }
}
