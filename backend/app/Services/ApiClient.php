<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * ApiClient
 *
 * Central HTTP client used by all Livewire admin components to talk to
 * the internal REST API. Every request is automatically:
 *
 *  - Sent to the configured API base URL  (APP_API_URL in .env)
 *  - Authenticated with the Sanctum token stored in the web session
 *  - Given a 15-second timeout before throwing a readable exception
 *
 * Usage inside a Livewire component:
 *
 *   use App\Services\ApiClient;
 *
 *   $users  = ApiClient::get('/users', ['search' => $this->search]);
 *   $result = ApiClient::post('/users', $payload);
 *   $result = ApiClient::patch("/users/{$id}/status", ['status' => 'active']);
 *   $result = ApiClient::delete("/users/{$id}");
 */
class ApiClient
{
    // ── Session key where the Sanctum token is stored ──────────
    public const TOKEN_KEY = 'admin_api_token';

    // ── Base URL ────────────────────────────────────────────────
    private static function baseUrl(): string
    {
        return rtrim(config('app.api_url', env('APP_API_URL', 'http://localhost:8000/api')), '/');
    }

    // ── Build an authenticated PendingRequest ───────────────────
    private static function client(): PendingRequest
    {
        $token = Session::get(self::TOKEN_KEY);

        $client = Http::baseUrl(self::baseUrl())
            ->acceptJson()
            ->timeout(15);

        if ($token) {
            $client = $client->withToken($token);
        }

        return $client;
    }

    // ── Public verbs ────────────────────────────────────────────

    /**
     * GET /endpoint  - returns the decoded JSON body as an array.
     * Returns [] on failure (logs the error).
     */
    public static function get(string $endpoint, array $query = []): array
    {
        return self::send('get', $endpoint, $query);
    }

    /**
     * POST /endpoint  - returns the decoded JSON body as an array.
     */
    public static function post(string $endpoint, array $data = []): array
    {
        return self::send('post', $endpoint, $data);
    }

    /**
     * PUT /endpoint
     */
    public static function put(string $endpoint, array $data = []): array
    {
        return self::send('put', $endpoint, $data);
    }

    /**
     * PATCH /endpoint
     */
    public static function patch(string $endpoint, array $data = []): array
    {
        return self::send('patch', $endpoint, $data);
    }

    /**
     * DELETE /endpoint
     */
    public static function delete(string $endpoint, array $data = []): array
    {
        return self::send('delete', $endpoint, $data);
    }

    // ── Response helpers ────────────────────────────────────────

    /**
     * Like get() but returns the full Response object so the caller
     * can check status codes (useful for paginated responses).
     */
    public static function getResponse(string $endpoint, array $query = []): Response
    {
        return self::client()->get($endpoint, $query);
    }

    /**
     * Like post() but returns the full Response object.
     */
    public static function postResponse(string $endpoint, array $data = []): Response
    {
        return self::client()->post($endpoint, $data);
    }

    // ── Paginated GET ───────────────────────────────────────────

    /**
     * Fetch a paginated resource. Returns a normalised array:
     *
     *   [
     *     'data'         => [...],
     *     'total'        => 0,
     *     'current_page' => 1,
     *     'last_page'    => 1,
     *     'per_page'     => 20,
     *   ]
     */
    public static function paginate(string $endpoint, array $query = []): array
    {
        $response = self::client()->get($endpoint, $query);

        if (! $response->successful()) {
            self::logFailure('GET', $endpoint, $response);
            return self::emptyPagination();
        }

        $body = $response->json();

        // Already a standard Laravel paginator response
        if (isset($body['data'], $body['total'])) {
            return $body;
        }

        // API returned a plain array - wrap it
        if (is_array($body)) {
            return array_merge(self::emptyPagination(), ['data' => $body, 'total' => count($body)]);
        }

        return self::emptyPagination();
    }

    // ── Token management ────────────────────────────────────────

    /**
     * Store a Sanctum plain-text token in the web session.
     * Call this right after a successful login response.
     */
    public static function storeToken(string $plainTextToken): void
    {
        Session::put(self::TOKEN_KEY, $plainTextToken);
    }

    /**
     * Remove the stored token (call on logout).
     */
    public static function forgetToken(): void
    {
        Session::forget(self::TOKEN_KEY);
    }

    /**
     * Check whether a token is currently stored.
     */
    public static function hasToken(): bool
    {
        return Session::has(self::TOKEN_KEY);
    }

    // ── Private helpers ─────────────────────────────────────────

    private static function send(string $method, string $endpoint, array $data = []): array
    {
        try {
            $response = self::client()->{$method}($endpoint, $data);

            if (! $response->successful()) {
                self::logFailure(strtoupper($method), $endpoint, $response);
            }

            return $response->json() ?? [];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('ApiClient connection failed', [
                'method'   => strtoupper($method),
                'endpoint' => $endpoint,
                'error'    => $e->getMessage(),
            ]);

            return ['message' => 'Could not reach the API. Check APP_API_URL in your .env file.', 'error' => true];
        }
    }

    private static function logFailure(string $method, string $endpoint, Response $response): void
    {
        Log::warning('ApiClient request failed', [
            'method'   => $method,
            'endpoint' => $endpoint,
            'status'   => $response->status(),
            'body'     => $response->body(),
        ]);
    }

    private static function emptyPagination(): array
    {
        return [
            'data'         => [],
            'total'        => 0,
            'current_page' => 1,
            'last_page'    => 1,
            'per_page'     => 20,
        ];
    }
}