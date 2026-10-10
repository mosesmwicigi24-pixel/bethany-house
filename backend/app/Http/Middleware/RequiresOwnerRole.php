<?php

namespace App\Http\Middleware;

use App\Support\DiscountRule;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The route is the super_admin's alone — by ROLE, exactly as
 * App\Support\DiscountRule::isOwner() decides it. Not a permission: super_admin
 * passes every permission through Gate::before, and a permission would be one
 * more thing an admin could grant himself.
 *
 * Why not `role:super_admin`: that alias (App\Http\Middleware\RoleMiddleware)
 * reads a users.role column or, failing it, only the FIRST of a person's
 * Spatie roles, so its answer can differ from isOwner() for someone who holds
 * several roles. A route that sets the discount limits must agree with the
 * rule that enforces them.
 *
 * Put it BEFORE step.up, so someone who may not do the thing is told so, not
 * asked to confirm first.
 */
class RequiresOwnerRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof \App\Models\User || !DiscountRule::isOwner($user)) {
            return response()->json([
                'message' => 'Only a super admin can do this.',
                'code'    => 'SUPER_ADMIN_ONLY',
            ], 403);
        }

        return $next($request);
    }
}
