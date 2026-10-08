<?php

namespace App\Http\Middleware;

use App\Services\Auth\StepUp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `step.up` — the route runs only if the caller re-confirmed their identity on
 * this session within the step-up window (App\Services\Auth\StepUp). Otherwise
 * 403 { code: step_up_required, method: totp|password }; the console asks for
 * the code or password (POST /admin/auth/step-up) and repeats the request.
 *
 * Put it AFTER the route's permission checks, so a person who may not do the
 * thing at all is told so, not asked to confirm first.
 */
class RequireStepUp
{
    public function __construct(private StepUp $stepUp) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->stepUp->ensure($request);

        return $next($request);
    }
}
