<?php

namespace App\Http\Middleware;

use App\Services\ActivityLogService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Exceptions\UnauthorizedException;

/**
 * activity_log `authorization_denied`: a signed-in user refused (403) on a
 * SENSITIVE route — users, roles, settings, payments, approvals, exports,
 * database, the audit trail (role hardening 4D, plan §13).
 *
 * Reads the finished response, so every refusal is seen whichever layer made
 * it: the permission/role route middleware, ensure.staff, a controller's
 * abort(403) or a MakerChecker refusal. Allowed calls cost one integer compare.
 *
 * Not every refusal, and not every time: ordinary screens are left to
 * request_logs (which already holds the 403 status), and the same user hitting
 * the same route is recorded once per audit.denied.dedupe_seconds — a screen
 * that polls a refused endpoint must not bury the trail.
 *
 * Never fails the request: any error here is logged and swallowed.
 */
class RecordsDeniedAuthorizations
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if ($response->getStatusCode() === 403) {
            try {
                $this->record($request, $response);
            } catch (\Throwable $e) {
                Log::warning('authorization_denied audit failed', ['error' => $e->getMessage(), 'path' => $request->path()]);
            }
        }

        return $response;
    }

    private function record(Request $request, $response): void
    {
        $user  = $request->user();
        $route = $request->route();
        if (!$user || !$route) {
            return;
        }

        $uri = ltrim((string) $route->uri(), '/');
        if (!Str::is((array) config('audit.denied.sensitive_routes', []), $uri)) {
            return;
        }

        $window = (int) config('audit.denied.dedupe_seconds', 300);
        $key = 'audit:denied:' . $user->id . ':' . sha1($request->method() . ' ' . $uri);
        if ($window > 0 && !Cache::add($key, 1, $window)) {
            return;
        }

        ActivityLogService::log('authorization_denied', null, [
            'route'   => $uri,
            'method'  => $request->method(),
            'path'    => '/' . ltrim($request->path(), '/'),
            'rule'    => $this->rule($request, $response),
            'ip'      => $request->ip(),
            'outcome' => 'failure',
        ], "Access denied: {$request->method()} /{$uri}", $user);
    }

    /** The rule that refused, as precisely as the refusal tells us. */
    private function rule(Request $request, $response): string
    {
        $e = $response->exception ?? null;
        if ($e instanceof UnauthorizedException) {
            $perms = $e->getRequiredPermissions();
            $roles = $e->getRequiredRoles();
            if ($perms !== []) {
                return 'permission:' . implode('|', $perms);
            }
            if ($roles !== []) {
                return 'role:' . implode('|', $roles);
            }
        }

        if ($response instanceof JsonResponse) {
            $body = $response->getData(true);
            if (is_array($body)) {
                if (!empty($body['required_roles']) && is_array($body['required_roles'])) {
                    return 'role:' . implode('|', $body['required_roles']);
                }
                if (!empty($body['code']) && is_string($body['code'])) {
                    return 'code:' . $body['code'];
                }
            }
        }

        // Fall back to the gates declared on the route, then the message.
        $gates = array_values(array_filter(
            $request->route()->gatherMiddleware(),
            fn ($m) => is_string($m) && (str_starts_with($m, 'permission:') || str_starts_with($m, 'role:') || $m === 'ensure.staff')
        ));
        $message = $e?->getMessage() ?: '';

        return mb_substr(trim(implode(' ', $gates) . ($message !== '' ? ' — ' . $message : '')), 0, 300) ?: 'unknown';
    }
}
