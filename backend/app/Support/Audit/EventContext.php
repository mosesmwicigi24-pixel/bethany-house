<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/**
 * Who, from where, through which door — stamped on every activity_log row
 * under properties._audit (role hardening 4D, plan §13).
 *
 *   roles     the causer's role names AT THE TIME of the action. A user's roles
 *             change; the trail must say what they held when they acted.
 *   outlet_id the subject's outlet when it has one, else the request's
 *             outlet_id/outlet parameter (a filter the caller chose — recorded
 *             as such via outlet_source, never trusted as a grant).
 *   token_id  the Sanctum personal-access-token id the call came in on, or a
 *             one-way fingerprint of the web session (a session id is itself a
 *             credential, so it is never stored raw).
 *   channel   till | web_console | storefront | chat_agent | api_key | webhook
 *             | job | console — see channel().
 *   outcome   success | failure. Explicit properties.outcome wins; otherwise an
 *             event named *_failed / *_refused / *_blocked / *_denied is a failure.
 *
 * Why properties and not new columns: AuditSealer hashes every column of each
 * sealed range and records the column list per seal; adding columns would make
 * every future seal carry a different shape, and the append-only trigger means
 * old rows can never be filled. A stable JSON key needs no migration, keeps old
 * rows and seals untouched, and is queryable (properties->'_audit'->>'channel').
 *
 * Never throws: every probe is defensive, and ActivityLogService wraps the call.
 */
final class EventContext
{
    public const KEY = '_audit';

    private const FAILURE_SUFFIXES = ['_failed', '_refused', '_blocked', '_denied'];

    public static function build(mixed $causer, mixed $subject, string $event, array $properties): array
    {
        [$outletId, $outletSource] = self::outlet($subject);

        return array_filter([
            'roles'         => self::roles($causer),
            'outlet_id'     => $outletId,
            'outlet_source' => $outletSource,
            'token_id'      => self::token($causer),
            'channel'       => self::channel($causer),
            'outcome'       => self::outcome($event, $properties),
        ], fn ($v) => $v !== null);
    }

    /** @return list<string> */
    private static function roles(mixed $causer): array
    {
        if (!$causer || !method_exists($causer, 'getRoleNames')) {
            return [];
        }
        try {
            return array_values($causer->getRoleNames()->sort()->values()->all());
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{0: int|null, 1: string|null} */
    private static function outlet(mixed $subject): array
    {
        if ($subject instanceof Model) {
            $attrs = $subject->getAttributes();
            if (isset($attrs['outlet_id']) && is_numeric($attrs['outlet_id'])) {
                return [(int) $attrs['outlet_id'], 'subject'];
            }
        }

        $route = Request::route();
        if ($route === null) {
            return [null, null];
        }

        $param = $route->parameter('outlet') ?? $route->parameter('outletId');
        if ($param instanceof Model) {
            return [(int) $param->getKey(), 'request'];
        }
        if (is_numeric($param)) {
            return [(int) $param, 'request'];
        }

        $input = Request::input('outlet_id');
        if (is_numeric($input)) {
            return [(int) $input, 'request'];
        }

        return [null, null];
    }

    private static function token(mixed $causer): ?string
    {
        if ($causer && method_exists($causer, 'currentAccessToken')) {
            $token = $causer->currentAccessToken();
            if ($token instanceof \Laravel\Sanctum\PersonalAccessToken && $token->getKey()) {
                return 'pat:' . $token->getKey();
            }
        }

        try {
            $request = Request::instance();
            if ($request->hasSession() && $request->session()->getId()) {
                return 'session:' . substr(hash('sha256', $request->session()->getId()), 0, 16);
            }
        } catch (\Throwable) {
            // no session on this request
        }

        return null;
    }

    /**
     * Derived, never taken from the caller: from the run mode, the causer and
     * the matched route. Queue jobs are marked by AuditContext (the
     * JobProcessing listener in AppServiceProvider) because a job has no route.
     */
    public static function channel(mixed $causer): string
    {
        $context = app()->bound(AuditContext::class) ? app(AuditContext::class) : null;
        if ($context?->channel() !== null) {
            return $context->channel();
        }

        $route = Request::route();
        if ($route === null) {
            return app()->runningInConsole() ? 'console' : 'unknown';
        }

        if (self::isAgent($causer)) {
            return 'chat_agent';
        }

        if (Request::instance()->attributes->has('api_key_id')) {
            return 'api_key';
        }

        $uri = ltrim((string) $route->uri(), '/');

        if (str_starts_with($uri, 'api/webhooks/') || str_contains($uri, 'callback') || str_contains($uri, 'webhook')) {
            return 'webhook';
        }
        if (str_starts_with($uri, 'api/v1/admin/pos')) {
            return 'till';
        }
        if (str_starts_with($uri, 'api/v1/admin') || $uri === 'admin' || str_starts_with($uri, 'admin/')) {
            return 'web_console';
        }

        return 'storefront';
    }

    /**
     * The sales agent's service account: config pos.agent_user_email, or the
     * account holding pos.discount_campaign by DIRECT grant (it belongs to the
     * agent and to no role — CampaignPermissionIsServiceAccountOnlyTest).
     * Not user_type=system: that is how super admins are stored.
     */
    private static function isAgent(mixed $causer): bool
    {
        if (!$causer) {
            return false;
        }
        $email = (string) config('pos.agent_user_email', '');
        if ($email !== '' && strcasecmp((string) ($causer->email ?? ''), $email) === 0) {
            return true;
        }

        try {
            return method_exists($causer, 'hasDirectPermission')
                && $causer->hasDirectPermission('pos.discount_campaign');
        } catch (\Throwable) {
            return false;   // permission not catalogued on this database
        }
    }

    private static function outcome(string $event, array $properties): string
    {
        $explicit = $properties['outcome'] ?? null;
        if (in_array($explicit, ['success', 'failure'], true)) {
            return $explicit;
        }
        foreach (self::FAILURE_SUFFIXES as $suffix) {
            if (str_ends_with($event, $suffix)) {
                return 'failure';
            }
        }

        return 'success';
    }
}
