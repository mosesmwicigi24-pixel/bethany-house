<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * An account that is not `active` must not be able to use the API — not
 * through a token it already holds, not through a token it can still mint.
 *
 * Found in Reports hardening cycle 9 (2026-10-01): a staff user set to
 * `inactive` kept their Sanctum tokens and read every report. Login has
 * always refused non-active accounts (AuthController::login / adminLogin),
 * but nothing after login looked at `status` again:
 *   - EnsureStaff checks user_type only;
 *   - UserController revoked tokens only when the status became `suspended`,
 *     and only on the two status endpoints — the edit form (PUT /users/{id}),
 *     which is the path production actually used to deactivate staff, and
 *     the customer status endpoint never revoked at all;
 *   - adminVerify2fa mints a token from user_id + TOTP without the check.
 *
 * Statuses the API writes: active | inactive | suspended (the three
 * validators in UserController and CustomerController). Only `active`
 * may reach the API.
 *
 * These tests use REAL bearer tokens, not Sanctum::actingAs, because the
 * defect is precisely that an issued token outlives the account's status.
 */
class InactiveAccountAccessTest extends TestCase
{
    use RefreshDatabase;

    /** GET routes across every ensure.staff group plus the admin auth group. */
    private const ADMIN_ROUTES = [
        '/api/v1/admin/auth/me',
        '/api/v1/admin/users',
        '/api/v1/admin/customers',
        '/api/v1/admin/reports/sales/by-customer',
        '/api/v1/admin/channels',
        '/api/v1/admin/roles',
    ];

    public static function nonActiveStatuses(): array
    {
        return ['inactive' => ['inactive'], 'suspended' => ['suspended']];
    }

    private function superAdmin(string $status = 'active'): User
    {
        $user = User::factory()->create(['user_type' => 'staff', 'status' => $status]);
        $user->assignRole(Role::findOrCreate('super_admin', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function bearer(User $user): string
    {
        return $user->createAuthToken('auth_token')->plainTextToken;
    }

    /** Each request must authenticate afresh — the guard caches the user otherwise. */
    private function getWithToken(string $uri, string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}")->getJson($uri);
    }

    // ── 1. A token already held stops working ────────────────────────────────

    public function test_positive_control_an_active_super_admin_token_reaches_every_sampled_route(): void
    {
        $token = $this->bearer($this->superAdmin());

        foreach (self::ADMIN_ROUTES as $uri) {
            $res = $this->getWithToken($uri, $token);
            $this->assertSame(200, $res->getStatusCode(), "active control {$uri}: " . substr((string) $res->getContent(), 0, 200));
        }
    }

    /**
     * 401, not 403: the console (react-admin api/client.ts) clears its stored
     * token and returns to login ONLY on 401. A 403 would leave a deactivated
     * person inside a half-working console instead of ending the session.
     *
     * @dataProvider nonActiveStatuses
     */
    public function test_a_token_issued_before_deactivation_is_refused_on_every_admin_route(string $status): void
    {
        $user  = $this->superAdmin();
        $token = $this->bearer($user);

        // Status changed underneath the token, the way any writer (or a
        // direct DB edit) can leave it.
        DB::table('users')->where('id', $user->id)->update(['status' => $status]);

        foreach (self::ADMIN_ROUTES as $uri) {
            $res = $this->getWithToken($uri, $token);
            $this->assertSame(401, $res->getStatusCode(), "{$status} {$uri}: " . substr((string) $res->getContent(), 0, 200));
        }
    }

    /**
     * The customer realm (auth:sanctum without ensure.staff). Its handlers are
     * not what is under test — several resolve `$user->customer`, a relation
     * User does not define — so the control asserts AUTHENTICATED (anything
     * but 401) and the case asserts the token no longer authenticates.
     *
     * @dataProvider nonActiveStatuses
     */
    public function test_a_non_active_customer_token_is_refused_on_the_customer_api(string $status): void
    {
        $user  = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $token = $this->bearer($user);
        $control = $this->getWithToken('/api/v1/cart', $token);
        $this->assertNotSame(401, $control->getStatusCode(), 'active control must authenticate: ' . substr((string) $control->getContent(), 0, 300));

        DB::table('users')->where('id', $user->id)->update(['status' => $status]);

        $this->assertSame(401, $this->getWithToken('/api/v1/cart', $token)->getStatusCode());
    }

    /**
     * The staff boundary itself, for callers that are not bearer tokens
     * (stateful cookie sessions from the hub domain resolve through the web
     * guard, which the token check never sees). This is the cycle-9 case.
     *
     * @dataProvider nonActiveStatuses
     */
    public function test_ensure_staff_refuses_a_non_active_staff_user_however_authenticated(string $status): void
    {
        Sanctum::actingAs($this->superAdmin($status));

        $this->getJson('/api/v1/admin/reports/sales/by-customer')->assertStatus(403);
        $this->getJson('/api/v1/admin/users')->assertStatus(403);
    }

    // ── 2. Every writer that moves an account off `active` revokes tokens ────

    private function staffWithToken(): array
    {
        $staff = User::factory()->create(['user_type' => 'staff', 'status' => 'active']);

        return [$staff, $this->bearer($staff)];
    }

    private function tokenCount(User $user): int
    {
        return DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count();
    }

    /** @dataProvider nonActiveStatuses */
    public function test_the_edit_form_revokes_tokens_when_it_deactivates(string $status): void
    {
        [$staff] = $this->staffWithToken();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->putJson("/api/v1/admin/users/{$staff->id}", ['status' => $status])
            ->assertOk();

        $this->assertSame($status, $staff->fresh()->status);
        $this->assertSame(0, $this->tokenCount($staff), "PUT /users/{id} status={$status} must revoke tokens");
    }

    /** @dataProvider nonActiveStatuses */
    public function test_the_status_endpoint_revokes_tokens_for_every_non_active_status(string $status): void
    {
        [$staff] = $this->staffWithToken();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->putJson("/api/v1/admin/users/{$staff->id}/status", ['status' => $status])
            ->assertOk();

        $this->assertSame(0, $this->tokenCount($staff), "PUT /users/{id}/status status={$status} must revoke tokens");
    }

    /** @dataProvider nonActiveStatuses */
    public function test_the_bulk_status_endpoint_revokes_tokens_for_every_non_active_status(string $status): void
    {
        [$a] = $this->staffWithToken();
        [$b] = $this->staffWithToken();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson('/api/v1/admin/users/bulk-update-status', ['user_ids' => [$a->id, $b->id], 'status' => $status])
            ->assertOk();

        $this->assertSame(0, $this->tokenCount($a) + $this->tokenCount($b), "bulk status={$status} must revoke tokens");
    }

    /** @dataProvider nonActiveStatuses */
    public function test_the_customer_status_endpoint_revokes_the_customers_tokens(string $status): void
    {
        $user = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);
        $this->bearer($user);
        $customer = Customer::create(['user_id' => $user->id, 'first_name' => 'Walk', 'last_name' => 'In']);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->putJson("/api/v1/admin/customers/{$customer->id}/status", ['status' => $status])
            ->assertOk();

        $this->assertSame(0, $this->tokenCount($user), "customer status={$status} must revoke tokens");
    }

    public function test_reactivating_does_not_bring_an_old_token_back(): void
    {
        [$staff, $token] = $this->staffWithToken();
        $admin = $this->superAdmin();

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/users/{$staff->id}", ['status' => 'inactive'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/users/{$staff->id}", ['status' => 'active'])->assertOk();

        $this->assertSame('active', $staff->fresh()->status);
        $this->assertSame(401, $this->getWithToken('/api/v1/admin/auth/me', $token)->getStatusCode(),
            'a token from before the deactivation must stay dead after reactivation');
    }

    public function test_edits_that_leave_the_account_active_keep_its_sessions(): void
    {
        [$staff] = $this->staffWithToken();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->putJson("/api/v1/admin/users/{$staff->id}", ['first_name' => 'Renamed', 'status' => 'active'])
            ->assertOk();

        $this->assertSame(1, $this->tokenCount($staff), 'a rename must not log the person out');
    }

    // ── 3. No new token for a non-active account ─────────────────────────────

    /** @dataProvider nonActiveStatuses */
    public function test_admin_login_refuses_a_non_active_account(string $status): void
    {
        $user = User::factory()->create(['user_type' => 'staff', 'status' => $status, 'password' => bcrypt('correct-horse')]);

        $this->postJson('/api/v1/admin/auth/login', ['email' => $user->email, 'password' => 'correct-horse'])
            ->assertStatus(422);
        $this->assertSame(0, $this->tokenCount($user));
    }

    /**
     * adminVerify2fa issues a token from user_id + a valid TOTP. It re-checks
     * canAccessAdmin() ("prevents a demoted/deactivated account…") but never
     * status, so a deactivated person with their authenticator can mint a
     * fresh token without passing adminLogin at all.
     *
     * @dataProvider nonActiveStatuses
     */
    public function test_admin_2fa_verify_refuses_a_non_active_account(string $status): void
    {
        $g      = new Google2FA();
        $secret = $g->generateSecretKey();
        $user   = User::factory()->create([
            'user_type'          => 'staff',
            'status'             => $status,
            'two_factor_enabled' => true,
            'two_factor_secret'  => encrypt($secret),
        ]);

        $res = $this->postJson('/api/v1/admin/auth/2fa/verify', [
            'user_id' => $user->id,
            'code'    => $g->getCurrentOtp($secret),
        ]);

        $this->assertContains($res->getStatusCode(), [403, 422], 'non-active 2FA verify: ' . substr((string) $res->getContent(), 0, 200));
        $this->assertArrayNotHasKey('token', $res->json() ?? []);
        $this->assertSame(0, $this->tokenCount($user));
    }
}
