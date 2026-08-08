<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reverse proxy must be trusted, or $request->ip() is the Docker bridge
 * gateway nginx connects from rather than the real client.
 *
 * This is not cosmetic. Twelve call sites read that value:
 *   - seven rate limiters key ->by($request->ip()), including 'auth' at
 *     perMinute(5). Sharing one bucket meant any five failed logins anywhere
 *     locked out everyone for the rest of the minute.
 *   - AuditLog::ip_address, so the audit trail could not attribute an action.
 *   - ValidateApiKey and RestrictToIPs match an allowlist against it, so an IP
 *     allowlist admitted everyone or no one.
 *
 * These tests pin the behaviour so a future middleware refactor cannot quietly
 * drop it again — the symptom (a shared login bucket) is invisible until the
 * day two people sign in at once.
 */
class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_ip_comes_from_the_forwarded_header_not_the_proxy(): void
    {
        $seen = null;

        // A throwaway route is used rather than a real endpoint so the assertion
        // is about resolution alone, with no auth or validation in the way.
        \Illuminate\Support\Facades\Route::get('/__ip-probe', function (\Illuminate\Http\Request $request) use (&$seen) {
            $seen = $request->ip();

            return response()->json(['ip' => $request->ip()]);
        });

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])   // the bridge gateway
            ->get('/__ip-probe', ['X-Forwarded-For' => '41.90.64.10'])
            ->assertOk()
            ->assertJson(['ip' => '41.90.64.10']);

        $this->assertSame('41.90.64.10', $seen);
    }

    public function test_the_scheme_is_taken_from_the_forwarded_proto(): void
    {
        // nginx terminates TLS and forwards plain HTTP. Without the proto header
        // trusted, Laravel believes every request is insecure and generates
        // http:// URLs — which browsers then block as mixed content.
        \Illuminate\Support\Facades\Route::get('/__proto-probe', fn (\Illuminate\Http\Request $request) => response()->json([
            'secure' => $request->isSecure(),
        ]));

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])
            ->get('/__proto-probe', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertJson(['secure' => true]);
    }

    public function test_an_untrusted_source_cannot_spoof_its_address(): void
    {
        // The allowlist is private ranges only, so a request arriving from a
        // public address keeps that address no matter what it claims. This is
        // what makes trusting the header safe rather than an open door.
        \Illuminate\Support\Facades\Route::get('/__spoof-probe', fn (\Illuminate\Http\Request $request) => response()->json([
            'ip' => $request->ip(),
        ]));

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])   // public, untrusted
            ->get('/__spoof-probe', ['X-Forwarded-For' => '10.0.0.1'])
            ->assertOk()
            ->assertJson(['ip' => '203.0.113.7']);
    }
}
