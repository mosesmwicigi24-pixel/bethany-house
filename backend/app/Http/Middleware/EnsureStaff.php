<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The /api/v1/admin/* API is the staff console. Its routes carry per-permission
 * gates, but a permission gate is an ALLOWLIST: every admin route without one
 * was reachable by any authenticated user — including a self-registered
 * storefront customer (user_type 'customer', a ['*'] Sanctum token, no roles).
 * That left the internal messaging system (/admin/channels — readable AND
 * writable), internal comments and user enumeration (/admin/comments,
 * /comments/users), global search (/admin/search) and staff profile/user-by-role
 * lookups open to any stranger who registered.
 *
 * This is the boundary the app already names but never enforced on the API:
 * User::canAccessAdmin() = isSystem() || isStaff(), used until now only at admin
 * LOGIN. It runs behind auth:sanctum (so the user is resolved) and in FRONT of
 * the per-route permission checks — an outer staff boundary, not a replacement
 * for them.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->canAccessAdmin()) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        return $next($request);
    }
}
