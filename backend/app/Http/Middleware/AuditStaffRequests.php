<?php

namespace App\Http\Middleware;

use App\Support\Audit\AuditContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * request_logs: every API call a staff member makes — who, what, when, from
 * where, with what result, and for list endpoints how many records came back.
 *
 * The activity log says what CHANGED. This says what was LOOKED AT, which is
 * how data actually leaves a business: paging through every customer, one
 * screen at a time, never pressing a download button.
 *
 *  - Staff only (User::canAccessAdmin), whatever route group the call is in.
 *    Customers on the storefront are not staff and are not recorded here.
 *  - Written in terminate(), after the response has gone to the browser —
 *    staff never wait on it — and never able to fail the request.
 *  - GETs are de-duplicated per user + URL for audit.request_log.get_dedupe_seconds:
 *    every screen polls, and a row per poll would bury the reads that matter.
 *    Writes are recorded every time.
 *  - Secret query parameters (tokens, signatures) are stored as [REDACTED].
 */
class AuditStaffRequests
{
    private const MAX_DECODE_BYTES = 2_000_000;
    private const MAX_QUERY_BYTES  = 2_000;

    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('audit_started_at', microtime(true));

        $response = $next($request);

        // Lets anyone quoting a problem hand over the id that finds its trail.
        $response->headers->set('X-Request-Id', app(AuditContext::class)->requestId());

        return $response;
    }

    public function terminate(Request $request, $response): void
    {
        try {
            $user = $request->user();
            if (!$user || !method_exists($user, 'canAccessAdmin') || !$user->canAccessAdmin()) {
                return;
            }

            foreach ((array) config('audit.request_log.skip_paths', []) as $skip) {
                if ($request->is($skip) || $request->is($skip . '/*')) {
                    return;
                }
            }

            $method = $request->method();
            if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                $window = (int) config('audit.request_log.get_dedupe_seconds', 300);
                $key    = 'audit:req:' . $user->id . ':' . sha1($method . ' ' . $request->getRequestUri());
                if ($window > 0 && !Cache::add($key, 1, $window)) {
                    return;
                }
            }

            $started = (float) $request->attributes->get('audit_started_at', defined('LARAVEL_START') ? LARAVEL_START : microtime(true));

            DB::table('request_logs')->insert([
                'occurred_at'   => now(),
                'user_id'       => $user->id,
                'method'        => $method,
                'path'          => mb_substr('/' . ltrim($request->path(), '/'), 0, 500),
                'route'         => mb_substr((string) $request->route()?->uri(), 0, 255) ?: null,
                'status'        => $response->getStatusCode(),
                'duration_ms'   => (int) round((microtime(true) - $started) * 1000),
                'ip_address'    => $request->ip(),
                'user_agent'    => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
                'request_id'    => app(AuditContext::class)->requestId(),
                'rows_returned' => $this->rowsReturned($response),
                'query'         => $this->query($request),
            ]);
        } catch (\Throwable $e) {
            Log::warning('request audit write failed', ['error' => $e->getMessage(), 'path' => $request->path()]);
        }
    }

    /** Records in a list response: {data: [...]} (paginated) or a bare [...]. */
    private function rowsReturned($response): ?int
    {
        if (!$response instanceof JsonResponse || strlen((string) $response->getContent()) > self::MAX_DECODE_BYTES) {
            return null;
        }
        $data = $response->getData(true);
        if (!is_array($data)) {
            return null;
        }
        if (isset($data['data']) && is_array($data['data']) && array_is_list($data['data'])) {
            return count($data['data']);
        }
        return array_is_list($data) ? count($data) : null;
    }

    private function query(Request $request): ?string
    {
        $query = $request->query();
        if ($query === []) {
            return null;
        }

        $redact = array_map('strtolower', (array) config('audit.request_log.redact_query_keys', []));
        array_walk_recursive($query, function (&$value, $key) use ($redact) {
            foreach ($redact as $needle) {
                if (is_string($key) && str_contains(strtolower($key), $needle)) {
                    $value = '[REDACTED]';
                    return;
                }
            }
        });
        foreach (array_keys($query) as $key) {
            foreach ($redact as $needle) {
                if (is_string($key) && str_contains(strtolower($key), $needle) && is_array($query[$key])) {
                    $query[$key] = '[REDACTED]';
                }
            }
        }

        $json = json_encode($query, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return strlen($json) <= self::MAX_QUERY_BYTES
            ? $json
            : json_encode(['_truncated' => true, 'keys' => array_keys($query)]);
    }
}
