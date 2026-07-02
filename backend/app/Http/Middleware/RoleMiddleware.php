<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Resolves the user's role from two sources in priority order:
     *
     *  1. users.role  - plain varchar column (fast, no join)
     *  2. Spatie HasRoles - pivot table via getRoleNames() (fallback)
     *
     * Route middleware passes roles as a pipe-separated string, e.g.:
     *   role:super_admin|admin|outlet_manager
     *
     * Laravel delivers that as a single element in $roles:
     *   $roles = ["super_admin|admin|outlet_manager"]
     *
     * We must explode on | before checking membership.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // ── Not authenticated ──────────────────────────────────────────────
        if (!$request->user()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect()->route('admin.login');
        }

        $user     = $request->user();
        $userRole = $this->resolveRole($user);

        // ── Flatten pipe-separated role strings into individual role names ─
        // Before: $roles = ["super_admin|admin"]
        // After:  $allowed = ["super_admin", "admin"]
        $allowed = collect($roles)
            ->flatMap(fn (string $r) => explode('|', $r))
            ->map(fn (string $r) => trim($r))
            ->filter()
            ->values()
            ->all();

        if (empty($allowed)) {
            return $next($request);
        }

        // ── Role check ─────────────────────────────────────────────────────
        if (!in_array($userRole, $allowed, strict: true)) {
            Log::warning('Unauthorized access attempt', [
                'user_id'        => $user->id,
                'user_role'      => $userRole,
                'required_roles' => $allowed,
                'endpoint'       => $request->path(),
                'ip'             => $request->ip(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'message'        => 'Forbidden. You do not have permission to access this resource.',
                    'required_roles' => $allowed,
                    'your_role'      => $userRole,
                ], 403);
            }

            abort(403, 'You do not have permission to access this area.');
        }

        return $next($request);
    }

    /**
     * Resolve the user's role from the most reliable available source.
     *
     * Priority:
     *  1. users.role  varchar column  (populated → use directly)
     *  2. Spatie getRoleNames()       (populated → use first role name)
     *  3. null                        (neither set → will fail the check)
     */
    private function resolveRole($user): ?string
    {
        // 1. Plain varchar column on the users table
        if (!empty($user->role)) {
            return $user->role;
        }

        // 2. Spatie HasRoles - method exists and returns a non-empty collection
        if (method_exists($user, 'getRoleNames')) {
            $spatieName = $user->getRoleNames()->first();
            if (!empty($spatieName)) {
                return $spatieName;
            }
        }

        return null;
    }
}