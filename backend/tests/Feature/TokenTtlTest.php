<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit SEC-5: API tokens were minted with no expiry (SANCTUM_TOKEN_EXPIRY null).
 * User::createAuthToken() now stamps a per-token expires_at from
 * config('sanctum.access_ttl_minutes'), without touching the global expiration
 * (which would retroactively expire all existing tokens).
 */
class TokenTtlTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_token_gets_a_per_token_expiry(): void
    {
        config(['sanctum.access_ttl_minutes' => 120]);
        $user = User::factory()->create();

        $token = $user->createAuthToken('auth_token');

        $this->assertNotNull($token->accessToken->expires_at);
        $this->assertEqualsWithDelta(
            now()->addMinutes(120)->timestamp,
            $token->accessToken->expires_at->timestamp,
            10,
        );
    }

    public function test_zero_ttl_leaves_token_without_expiry(): void
    {
        config(['sanctum.access_ttl_minutes' => 0]);
        $user = User::factory()->create();

        $token = $user->createAuthToken('auth_token');

        $this->assertNull($token->accessToken->expires_at);
    }
}
