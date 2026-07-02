<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorIsSetup
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // If user is authenticated and needs to setup 2FA
        if ($user && $user->needsTwoFactorSetup()) {
            // Allow access to 2FA setup and logout routes
            if ($request->routeIs('admin.profile.2fa.setup') || 
                $request->routeIs('admin.logout')) {
                return $next($request);
            }

            // Redirect to 2FA setup with a message
            return redirect()->route('admin.profile.2fa.setup')
                ->with('warning', 'You must set up two-factor authentication to continue.');
        }

        return $next($request);
    }
}