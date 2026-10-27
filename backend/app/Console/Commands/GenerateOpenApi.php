<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;

/**
 * Writes the OpenAPI 3.1 contract for /api from the registered routes
 * (native apps programme, Step 2).
 *
 * Every API route becomes an operation with its method, path parameters,
 * security (bearer when auth:sanctum guards it), and the gates the route's
 * middleware declares as vendor extensions: x-permissions, x-roles,
 * x-step-up, x-staff-only, x-throttle. Response bodies are not introspected
 * (controllers answer with ad-hoc JSON, no Resources); each operation refers
 * to the shared error shapes, and a small set of operations the native
 * clients depend on day one (sign-in, session, push registration) carries a
 * hand-written schema in OPERATIONS below. The generated file is the source
 * for the Swift and Kotlin client models; CI runs `--check` so the committed
 * file never drifts from the routes.
 *
 *   php artisan openapi:generate            # write docs/api/openapi.json
 *   php artisan openapi:generate --check    # exit 1 when the file is stale
 */
class GenerateOpenApi extends Command
{
    protected $signature = 'openapi:generate {--check : Fail when the committed file differs from the routes} {--out= : Output path (default docs/api/openapi.json at the repository root)}';

    protected $description = 'Generate the OpenAPI 3.1 contract for the API from the registered routes';

    public function handle(): int
    {
        $spec = $this->build();
        // JSON, not YAML: PHP turns the "200" response keys into integers and a
        // YAML dump would write them unquoted, which OpenAPI tooling rejects.
        $yaml = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        $out = $this->option('out') ?: base_path('../docs/api/openapi.json');

        if ($this->option('check')) {
            $current = is_file($out) ? file_get_contents($out) : null;
            if ($current === $yaml) {
                $this->info('openapi.json is current (' . count($spec['paths']) . ' paths).');
                return self::SUCCESS;
            }
            $this->error('openapi.json is stale: run `php artisan openapi:generate` and commit docs/api/openapi.json.');
            return self::FAILURE;
        }

        if (!is_dir(dirname($out))) {
            mkdir(dirname($out), 0775, true);
        }
        file_put_contents($out, $yaml);
        $this->info('Wrote ' . $out . ' (' . count($spec['paths']) . ' paths).');

        return self::SUCCESS;
    }

    public function build(): array
    {
        $paths = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();
            if (!str_starts_with($uri, 'api/')) {
                continue;
            }
            $path = '/' . preg_replace('/\{(\w+)\?\}/', '{$1}', $uri);

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $paths[$path][strtolower($method)] = $this->operation($route, $method, $path);
            }
        }

        ksort($paths);
        foreach ($paths as &$ops) {
            ksort($ops);
        }
        unset($ops);

        return [
            'openapi' => '3.1.0',
            'info'    => [
                'title'       => 'Bethany House Hub API',
                'version'     => 'v1',
                'description' => "The staff and storefront API behind the Hub console and the native apps.\n\n"
                    . "Conventions every client must honour:\n"
                    . "- Bearer token from `POST /api/v1/admin/auth/login` (Sanctum). Send `X-Device-Id` (16–64 chars `[A-Za-z0-9-]`) and, for polling, `X-Background-Request: 1`.\n"
                    . "- 401: signed out. 423 `reason: pin_locked`: unlock with `POST /api/v1/admin/auth/pin/unlock` then retry. 403 `code: step_up_required`: confirm identity (`/admin/auth/step-up`) then retry. 403 `code: download_approval_required`: request approval. 429: wait `retry_after_seconds`.\n"
                    . "- Money arrives as the server computed it; clients never sum, convert or round. Decimal strings are parsed as decimals, never floats.\n"
                    . "- Pagination is Laravel's `{data, current_page, last_page, per_page, total}`.\n"
                    . "- Timestamps are Africa/Nairobi wall-clock unless suffixed with a zone.",
            ],
            'servers' => [
                ['url' => 'https://hub.bethanyhouse.co.ke', 'description' => 'Production Hub'],
                ['url' => 'http://localhost:8000', 'description' => 'Local (docker-compose.dev.yml)'],
            ],
            'security' => [],
            'paths'    => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Sanctum personal access token'],
                ],
                'schemas'   => self::SCHEMAS,
                'responses' => self::RESPONSES,
            ],
            'tags' => $this->tags($paths),
        ];
    }

    private function operation(Route $route, string $method, string $path): array
    {
        $middleware = $route->gatherMiddleware();
        $action     = $route->getActionName();
        [$controller, $fn] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, null];
        $short      = class_basename($controller);

        $op = [
            'operationId' => $this->operationId($method, $path),
            'summary'     => $fn ? Str::headline($fn) . ' (' . Str::before($short, 'Controller') . ')' : Str::headline(Str::afterLast($path, '/')),
            'tags'        => [$this->tag($path)],
        ];

        $params = [];
        preg_match_all('/\{(\w+)\}/', $path, $m);
        foreach ($m[1] as $name) {
            $params[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
        }
        if ($params) {
            $op['parameters'] = $params;
        }

        $secured    = in_array('auth:sanctum', $middleware, true) || in_array('auth', $middleware, true);
        $perms      = [];
        $roles      = [];
        $throttles  = [];
        $stepUp     = false;
        $staffOnly  = false;
        $signed     = false;
        foreach ($middleware as $mw) {
            if (str_starts_with($mw, 'permission:')) {
                $perms = array_merge($perms, explode('|', Str::before(Str::after($mw, 'permission:'), ',')));
            } elseif (str_starts_with($mw, 'role:')) {
                $roles = array_merge($roles, explode('|', Str::before(Str::after($mw, 'role:'), ',')));
            } elseif (str_starts_with($mw, 'throttle:')) {
                $throttles[] = Str::after($mw, 'throttle:');
            } elseif ($mw === 'step.up') {
                $stepUp = true;
            } elseif ($mw === 'ensure.staff') {
                $staffOnly = true;
            } elseif (str_starts_with($mw, 'signed')) {
                $signed = true;
            }
        }

        if ($secured) {
            $op['security'] = [['bearer' => []]];
        }
        if ($perms) {
            $op['x-permissions'] = array_values(array_unique($perms));
        }
        if ($roles) {
            $op['x-roles'] = array_values(array_unique($roles));
        }
        if ($stepUp) {
            $op['x-step-up'] = true;
        }
        if ($staffOnly) {
            $op['x-staff-only'] = true;
        }
        if ($signed) {
            $op['x-signed-url'] = true;
        }
        if ($throttles) {
            $op['x-throttle'] = array_values(array_unique($throttles));
        }

        $write = in_array($method, ['POST', 'PUT', 'PATCH'], true);
        $known = self::OPERATIONS[$method . ' ' . $path] ?? null;

        if ($known) {
            $op = array_merge($op, $known);
        } elseif ($write) {
            $op['requestBody'] = ['content' => ['application/json' => ['schema' => ['type' => 'object', 'additionalProperties' => true]]]];
        }

        $responses = $known['responses'] ?? ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'additionalProperties' => true]]]]];
        if ($secured) {
            $responses['401'] = ['$ref' => '#/components/responses/Unauthenticated'];
            $responses['423'] = ['$ref' => '#/components/responses/Locked'];
        }
        if ($perms || $roles || $stepUp || $staffOnly) {
            $responses['403'] = ['$ref' => '#/components/responses/Forbidden'];
        }
        if ($write) {
            $responses['422'] = ['$ref' => '#/components/responses/ValidationError'];
        }
        $responses['429'] = ['$ref' => '#/components/responses/RateLimited'];
        ksort($responses);
        $op['responses'] = $responses;

        return $op;
    }

    private function operationId(string $method, string $path): string
    {
        $p = preg_replace('/\{(\w+)\}/', 'by-$1', $path);
        $p = trim(preg_replace('#^/api/#', '', $p), '/');

        return strtolower($method) . '_' . Str::of($p)->replace(['/', '.'], '_')->replace('-', '_');
    }

    private function tag(string $path): string
    {
        $parts = array_values(array_filter(explode('/', $path)));
        // /api/v1/admin/<x>/... → x ; /api/v1/<x>/... → x ; /api/<x> → x
        $i = 1;
        if (($parts[$i] ?? '') === 'v1') {
            $i++;
        }
        if (($parts[$i] ?? '') === 'admin') {
            $i++;
        }
        $tag = $parts[$i] ?? 'root';

        return str_starts_with($tag, '{') ? 'root' : $tag;
    }

    private function tags(array $paths): array
    {
        $names = [];
        foreach ($paths as $ops) {
            foreach ($ops as $op) {
                $names[$op['tags'][0]] = true;
            }
        }
        ksort($names);

        return array_map(fn ($n) => ['name' => $n], array_keys($names));
    }

    /** Hand-written contracts for what a native client needs on day one. */
    private const OPERATIONS = [
        'POST /api/v1/admin/auth/login' => [
            'summary'     => 'Staff sign-in',
            'description' => 'Password step. Answers with a session, a 2FA challenge, or a forced 2FA setup token. Locked accounts answer 423.',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/LoginRequest']]]],
            'responses'   => [
                '200' => ['description' => 'Session, 2FA challenge, or 2FA setup required', 'content' => ['application/json' => ['schema' => ['oneOf' => [
                    ['$ref' => '#/components/schemas/SessionResponse'],
                    ['$ref' => '#/components/schemas/TwoFactorChallenge'],
                    ['$ref' => '#/components/schemas/TwoFactorSetupRequired'],
                ]]]]],
                '423' => ['$ref' => '#/components/responses/Locked'],
            ],
        ],
        'POST /api/v1/admin/auth/2fa/verify' => [
            'summary'     => 'Staff sign-in, second step',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/TwoFactorVerifyRequest']]]],
            'responses'   => ['200' => ['description' => 'Session', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SessionResponse']]]]],
        ],
        'GET /api/v1/admin/auth/me' => [
            'summary'   => 'The signed-in person, their effective permissions and session policy',
            'responses' => ['200' => ['description' => 'Me', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['user'], 'properties' => ['user' => ['$ref' => '#/components/schemas/Me']]]]]]],
        ],
        'POST /api/v1/admin/auth/renew' => [
            'summary'     => 'Renew the session (sliding, inside the role limits)',
            'description' => 'Mints a replacement bearer token for the same session and revokes the current one. The new expiry is the token TTL from now, capped at the role\'s absolute limit counted from the original sign-in. 409 when the token is a kiosk token or has no expiry.',
            'responses'   => [
                '200' => ['description' => 'Renewed', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/RenewResponse']]]],
                '409' => ['description' => 'Not renewable', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
            ],
        ],
        'POST /api/v1/admin/auth/logout' => [
            'summary'   => 'Sign out (revokes the current token)',
            'responses' => ['200' => ['description' => 'Signed out', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Message']]]]],
        ],
        'POST /api/v1/admin/auth/pin/unlock' => [
            'summary'     => 'Unlock a PIN-locked session',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['pin'], 'properties' => ['pin' => ['type' => 'string']]]]]],
            'responses'   => ['200' => ['description' => 'Unlocked', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Message']]]]],
        ],
        'POST /api/v1/admin/push/subscribe-device' => [
            'summary'     => 'Register a native push token (APNs or FCM)',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeviceRegistration']]]],
            'responses'   => ['201' => ['description' => 'Registered', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string'], 'token_type' => ['type' => 'string', 'enum' => ['apns', 'fcm']]]]]]]],
        ],
        'POST /api/v1/admin/push/unsubscribe-device' => [
            'summary'     => 'Remove a native push token',
            'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['token'], 'properties' => ['token' => ['type' => 'string']]]]]],
            'responses'   => ['200' => ['description' => 'Removed', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Message']]]]],
        ],
    ];

    private const SCHEMAS = [
        'Message' => ['type' => 'object', 'required' => ['message'], 'properties' => ['message' => ['type' => 'string']]],
        'Error'   => ['type' => 'object', 'required' => ['message'], 'properties' => [
            'message' => ['type' => 'string'],
            'reason'  => ['type' => 'string', 'description' => 'Machine-readable cause, when one applies'],
            'code'    => ['type' => 'string', 'description' => 'Machine-readable cause, when one applies'],
        ], 'additionalProperties' => true],
        'ValidationError' => ['type' => 'object', 'required' => ['message', 'errors'], 'properties' => [
            'message' => ['type' => 'string'],
            'errors'  => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
        ]],
        'LoginRequest' => ['type' => 'object', 'required' => ['email', 'password'], 'properties' => [
            'email'       => ['type' => 'string', 'format' => 'email'],
            'password'    => ['type' => 'string'],
            'remember_me' => ['type' => 'boolean'],
        ]],
        'TwoFactorChallenge' => ['type' => 'object', 'required' => ['requires_2fa', 'user_id', 'challenge'], 'properties' => [
            'requires_2fa' => ['type' => 'boolean', 'const' => true],
            'user_id'      => ['type' => 'integer'],
            'challenge'    => ['type' => 'string', 'description' => 'One use, five minutes'],
        ]],
        'TwoFactorSetupRequired' => ['type' => 'object', 'required' => ['requires_2fa_setup', 'user_id', 'setup_token'], 'properties' => [
            'requires_2fa_setup' => ['type' => 'boolean', 'const' => true],
            'user_id'            => ['type' => 'integer'],
            'setup_token'        => ['type' => 'string'],
        ]],
        'TwoFactorVerifyRequest' => ['type' => 'object', 'required' => ['user_id', 'challenge'], 'properties' => [
            'user_id'       => ['type' => 'integer'],
            'challenge'     => ['type' => 'string'],
            'code'          => ['type' => 'string', 'description' => 'Six-digit TOTP'],
            'recovery_code' => ['type' => 'string', 'description' => 'Alternative to code; each works once'],
        ]],
        'SessionResponse' => ['type' => 'object', 'required' => ['message', 'user', 'token'], 'properties' => [
            'message' => ['type' => 'string'],
            'user'    => ['$ref' => '#/components/schemas/Me'],
            'token'   => ['type' => 'string', 'description' => 'Bearer token; store in the platform keychain'],
        ]],
        'SessionPolicy' => ['type' => 'object', 'required' => ['idle_minutes', 'absolute_minutes', 'on_idle'], 'properties' => [
            'idle_minutes'     => ['type' => 'integer'],
            'absolute_minutes' => ['type' => 'integer'],
            'on_idle'          => ['type' => 'string', 'enum' => ['sign_out', 'pin_lock']],
        ]],
        'Me' => ['type' => 'object', 'required' => ['id', 'name', 'email', 'permissions'], 'properties' => [
            'id'                   => ['type' => 'integer'],
            'name'                 => ['type' => 'string'],
            'email'                => ['type' => 'string'],
            'user_type'            => ['type' => 'string', 'enum' => ['system', 'staff', 'customer']],
            'permissions'          => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Effective permission slugs; money report pages already removed per FinancialVisibility'],
            'roles'                => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
            'is_owner'             => ['type' => 'boolean'],
            'money_view'           => ['type' => 'string'],
            'outlet'               => ['type' => ['object', 'null'], 'additionalProperties' => true],
            'session_policy'       => ['oneOf' => [['$ref' => '#/components/schemas/SessionPolicy'], ['type' => 'null']]],
            'terminal_pin_set'     => ['type' => 'boolean'],
            'two_factor_required'  => ['type' => 'boolean'],
            'two_factor_enabled'   => ['type' => 'boolean'],
            'recovery_codes_left'  => ['type' => ['integer', 'null']],
            'current_device'       => ['type' => ['object', 'null'], 'additionalProperties' => true],
            'discount_cap_percent' => ['type' => ['number', 'null']],
            'promotion_cap_percent'=> ['type' => ['number', 'null']],
        ], 'additionalProperties' => true],
        'RenewResponse' => ['type' => 'object', 'required' => ['token', 'expires_at'], 'properties' => [
            'message'            => ['type' => 'string'],
            'token'              => ['type' => 'string'],
            'expires_at'         => ['type' => 'string', 'format' => 'date-time'],
            'session_started_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'session_policy'     => ['oneOf' => [['$ref' => '#/components/schemas/SessionPolicy'], ['type' => 'null']]],
        ]],
        'DeviceRegistration' => ['type' => 'object', 'required' => ['platform', 'token'], 'properties' => [
            'platform' => ['type' => 'string', 'enum' => ['ios', 'ipados', 'macos', 'android']],
            'token'    => ['type' => 'string', 'description' => 'APNs device token (hex) or FCM registration token'],
            'model'    => ['type' => 'string', 'description' => 'Hardware model, e.g. iPhone15,2 or SM-X916B'],
        ]],
        'Paginated' => ['type' => 'object', 'properties' => [
            'data'         => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
            'current_page' => ['type' => 'integer'],
            'last_page'    => ['type' => 'integer'],
            'per_page'     => ['type' => 'integer'],
            'total'        => ['type' => 'integer'],
        ]],
        'MoneyByCurrency' => ['type' => 'object', 'description' => 'A list of rows in several currencies: KES total at each row\'s own reporting rate, native figures beside it, unconvertible ones named. Never a sum across currencies.', 'properties' => [
            'kes_total'   => ['type' => ['string', 'number']],
            'by_currency' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
            'unconverted' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
        ]],
    ];

    private const RESPONSES = [
        'Unauthenticated' => ['description' => 'No valid session: sign in again', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
        'Forbidden'       => ['description' => 'Not permitted; `code: step_up_required` asks for identity confirmation, `code: download_approval_required` for an approval', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
        'ValidationError' => ['description' => 'Validation failed', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationError']]]],
        'Locked'          => ['description' => '`reason: pin_locked` (unlock with the terminal PIN) or `reason: account_locked`', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
        'RateLimited'     => ['description' => 'Too many requests; wait `retry_after_seconds`', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string'], 'retry_after_seconds' => ['type' => 'integer'], 'error' => ['type' => 'string']]]]]],
    ];
}
