<?php

namespace App\Http\Middleware;

use App\Models\DownloadRequest;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Downloads\DownloadPolicy;
use App\Services\Downloads\DownloadRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every file that leaves the hub passes here (owner decision 2026-09-21).
 *
 * For a staff member's request the route runs first — so its own permission
 * check still decides who may see the data at all — and then:
 *
 *   view     (in-app media)                       → through, untouched
 *   exempt   (invoice, quotation, receipt)         → through, recorded for the daily digest
 *   carries a valid single-use download token      → through, recorded against its approval
 *   the owner                                       → through, recorded, approved by being his
 *   any other successful FILE response:
 *       enforcing   → held: the file is discarded; 403 { code: download_approval_required,
 *                     held: <uuid> } — the console asks for a reason and turns that exact,
 *                     server-recorded attempt into a request for approval
 *       not enforcing (shadow) → through, recorded as "would have been held"
 *
 * A file is a PDF, CSV, spreadsheet, archive or a named attachment — from ANY
 * route, listed or not, so a download endpoint added later fails closed.
 * Routes in audit.downloads.always_gated are downloads whatever they return
 * (JSON "exports" included), and get their row — and so their export id, which
 * hub-rendered PDFs print in the footer — before the work runs.
 *
 * Customers, public pages and unauthenticated calls are not staff and pass.
 */
class DownloadGate
{
    public const TOKEN_HEADER = 'X-Download-Token';

    public function __construct(private DownloadPolicy $policy, private DownloadRecorder $recorder) {}

    public function handle(Request $request, Closure $next)
    {
        if (in_array($request->method(), ['HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // Group middleware runs before the route's auth:sanctum, so resolve
        // the bearer token directly (the guard caches it for the route).
        $user = $request->user() ?? auth('sanctum')->user();
        if (!$user instanceof User || !$user->canAccessAdmin()) {
            return $next($request);
        }

        $action   = DownloadPolicy::action($request);
        $declared = $this->policy->declared($action);

        if ($declared === DownloadPolicy::VIEW) {
            return $next($request);
        }

        if ($declared === DownloadPolicy::EXEMPT) {
            $response = $next($request);
            return $response->isSuccessful() && $this->policy->isFileResponse($response)
                ? $this->recordExempt($request, $user, $action, $response)
                : $response;
        }

        $token = (string) ($request->header(self::TOKEN_HEADER) ?: $request->query('dl_token', ''));
        if ($token !== '') {
            return $this->withToken($request, $next, $user, $token);
        }

        // Listed downloads get their row first: the export id reaches the renderer.
        $dr = $declared !== null
            ? $this->recorder->open($request, $user, $action, $declared, $this->initialStatus($user), $this->shadow($user))
            : null;

        $response = $next($request);

        if (!$response->isSuccessful()) {
            $dr?->forceFill(['status' => DownloadRequest::FAILED, 'decision_note' => 'HTTP ' . $response->getStatusCode()])->save();
            return $response;
        }
        if ($dr === null && !$this->policy->isFileResponse($response)) {
            return $response;   // an ordinary screen
        }

        $dr ??= $this->recorder->open($request, $user, $action, DownloadPolicy::GATED, $this->initialStatus($user), $this->shadow($user));

        if ($dr->status === DownloadRequest::HELD) {
            return $this->hold($request, $user, $dr);
        }

        return $this->recorder->finalize($dr, $request, $response);
    }

    private function initialStatus(User $user): string
    {
        return match (true) {
            $this->policy->isOwner($user)               => DownloadRequest::AUTO,
            !config('audit.downloads.enforce', false)   => DownloadRequest::DOWNLOADED,   // shadow
            default                                      => DownloadRequest::HELD,
        };
    }

    private function shadow(User $user): bool
    {
        return !$this->policy->isOwner($user) && !config('audit.downloads.enforce', false);
    }

    private function hold(Request $request, User $user, DownloadRequest $dr): Response
    {
        ActivityLogService::log('download_held', $dr, [
            'what'    => $dr->label,
            'path'    => $dr->path,
            'filters' => $dr->payload,
        ], "Download held for approval: {$dr->label}", $user);

        return response()->json([
            'code'     => 'download_approval_required',
            'message'  => 'This download needs approval before it can be taken.',
            'held'     => $dr->uuid,
            'download' => [
                'label'    => $dr->label,
                'method'   => $dr->method,
                'path'     => $dr->path,
                'payload'  => $dr->payload,
                'category' => $dr->category,
            ],
        ], 403);
    }

    private function withToken(Request $request, Closure $next, User $user, string $token): Response
    {
        $dr = DownloadRequest::where('token_hash', hash('sha256', $token))->first();

        $problem = match (true) {
            !$dr                                                      => 'unknown or already used',
            $dr->user_id !== $user->id                                => 'issued to someone else',
            $dr->status !== DownloadRequest::APPROVED                 => "request is {$dr->status}",
            !$dr->token_expires_at || $dr->token_expires_at->isPast() => 'expired',
            strtoupper($dr->method) !== $request->method()            => 'a different request than was approved',
            '/' . ltrim($request->path(), '/') !== $dr->path          => 'a different request than was approved',
            DownloadPolicy::canonical((array) $dr->payload) != DownloadPolicy::payload($request) => 'different filters than were approved',
            default                                                   => null,
        };

        if ($problem !== null) {
            ActivityLogService::log('download_token_refused', $dr, ['reason' => $problem, 'path' => $request->path()],
                "Download link refused ({$problem})", $user);
            return response()->json(['code' => 'download_token_invalid', 'message' => "This download link cannot be used: {$problem}."], 403);
        }

        // Single use: burned before the work runs, not after.
        $dr->forceFill(['token_hash' => null])->save();
        $this->recorder->stamp($request, $dr);

        $response = $next($request);
        if (!$response->isSuccessful()) {
            $dr->forceFill(['status' => DownloadRequest::FAILED, 'decision_note' => 'HTTP ' . $response->getStatusCode()])->save();
            return $response;
        }
        return $this->recorder->finalize($dr, $request, $response);
    }

    private function recordExempt(Request $request, User $user, ?string $action, Response $response): Response
    {
        try {
            $dr = $this->recorder->open($request, $user, $action, DownloadPolicy::EXEMPT, DownloadRequest::DOWNLOADED);
            return $this->recorder->finalize($dr, $request, $response);
        } catch (\Throwable $e) {
            Log::error('exempt download recording failed', ['error' => $e->getMessage()]);
            return $response;
        }
    }
}
