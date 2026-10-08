<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\SignsInForReal;
use Tests\TestCase;

/**
 * Plan §12.3: privileged actions need a re-confirmation of identity (TOTP code
 * when two-step sign-in is on, otherwise the password) within the last five
 * minutes, on the session doing it. Before Phase 4C an open console was
 * enough for anything.
 */
class StepUpTest extends TestCase
{
    use RefreshDatabase, SignsInForReal;

    private function roleEdit(string $token, Role $role)
    {
        return $this->withBearer($token, 'PUT', "/api/v1/admin/roles/{$role->id}", ['description' => 'edited ' . uniqid()]);
    }

    public function test_a_role_edit_without_step_up_is_challenged(): void
    {
        $owner = $this->staffWithRoles(['super_admin']);
        $role  = Role::findOrCreate('tailor', 'sanctum');

        $this->roleEdit($this->bearerFor($owner), $role)
            ->assertStatus(403)
            ->assertJson(['code' => 'step_up_required', 'method' => 'password']);
    }

    public function test_the_password_step_up_lets_the_action_through_for_five_minutes(): void
    {
        $owner = $this->staffWithRoles(['super_admin']);
        $role  = Role::findOrCreate('tailor', 'sanctum');
        $t     = $this->bearerFor($owner);

        $this->withBearer($t, 'POST', '/api/v1/admin/auth/step-up', ['password' => 'wrong'])->assertStatus(422);
        $this->withBearer($t, 'POST', '/api/v1/admin/auth/step-up', ['password' => 'password'])->assertOk()
            ->assertJsonStructure(['confirmed_until']);

        $this->roleEdit($t, $role)->assertOk();

        $this->travel(4)->minutes();
        $this->roleEdit($t, $role)->assertOk();

        $this->travel(2)->minutes();   // 6 minutes after the step-up
        $this->roleEdit($t, $role)->assertStatus(403)->assertJson(['code' => 'step_up_required']);
    }

    public function test_a_step_up_covers_only_the_session_that_made_it(): void
    {
        $owner = $this->staffWithRoles(['super_admin']);
        $role  = Role::findOrCreate('tailor', 'sanctum');
        $laptop = $this->bearerFor($owner);
        $phone  = $this->bearerFor($owner);

        $this->withBearer($laptop, 'POST', '/api/v1/admin/auth/step-up', ['password' => 'password'])->assertOk();

        $this->roleEdit($laptop, $role)->assertOk();
        $this->roleEdit($phone, $role)->assertStatus(403);
    }

    public function test_with_two_step_sign_in_on_the_code_is_required_not_the_password(): void
    {
        $g = new Google2FA();
        $secret = $g->generateSecretKey();
        $owner = $this->staffWithRoles(['super_admin'], ['two_factor_enabled' => true, 'two_factor_secret' => encrypt($secret)]);
        $role  = Role::findOrCreate('tailor', 'sanctum');
        $t     = $this->bearerFor($owner);

        $this->roleEdit($t, $role)->assertStatus(403)->assertJson(['method' => 'totp']);
        $this->withBearer($t, 'POST', '/api/v1/admin/auth/step-up', ['password' => 'password'])->assertStatus(422);
        $this->withBearer($t, 'POST', '/api/v1/admin/auth/step-up', ['code' => $g->getCurrentOtp($secret)])->assertOk();

        $this->roleEdit($t, $role)->assertOk();
    }

    public function test_step_up_attempts_are_limited(): void
    {
        $owner = $this->staffWithRoles(['super_admin']);
        $t = $this->bearerFor($owner);

        for ($i = 0; $i < 5; $i++) {
            $this->withBearer($t, 'POST', '/api/v1/admin/auth/step-up', ['password' => 'nope'])->assertStatus(422);
        }
        $this->withBearer($t, 'POST', '/api/v1/admin/auth/step-up', ['password' => 'password'])->assertStatus(429);
        $this->assertDatabaseHas('activity_log', ['event' => 'step_up_failed', 'causer_id' => $owner->id]);
    }

    public function test_permission_is_checked_before_step_up(): void
    {
        // An account that may not edit roles at all is told so — not asked to confirm first.
        $clerk = $this->staffWithRoles(['pos_clerk']);
        $role  = Role::findOrCreate('tailor', 'sanctum');

        $res = $this->roleEdit($this->bearerFor($clerk), $role);
        $res->assertStatus(403);
        $this->assertNotSame('step_up_required', $res->json('code'));
    }

    /** Every privileged route the spec names that exists today is behind step-up. */
    public function test_the_privileged_routes_are_all_behind_step_up(): void
    {
        $owner = $this->staffWithRoles(['super_admin']);
        $owner->givePermissionTo(Permission::findOrCreate('settings.manage_database', 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $t = $this->bearerFor($owner);
        $role = Role::findOrCreate('tailor', 'sanctum');
        $clerk = $this->staffWithRoles(['pos_clerk']);

        $routes = [
            ['PUT',    "/api/v1/admin/roles/{$role->id}"],
            ['POST',   "/api/v1/admin/roles/{$role->id}/permissions"],
            ['POST',   '/api/v1/admin/permissions'],
            ['PUT',    "/api/v1/admin/users/{$clerk->id}/role"],
            ['POST',   "/api/v1/admin/users/{$clerk->id}/2fa/reset"],
            ['PUT',    '/api/v1/admin/settings'],
            ['PUT',    '/api/v1/admin/settings/payment-providers'],
            ['POST',   '/api/v1/admin/tax-rates'],
            ['PUT',    '/api/v1/admin/currencies-management/1/rates'],
            ['POST',   '/api/v1/admin/payment-methods-management'],
            ['POST',   '/api/v1/admin/database/backups'],
            ['POST',   '/api/v1/admin/database/backups/1/restore'],
            ['GET',    '/api/v1/admin/database/backups/1/download'],
            ['POST',   '/api/v1/admin/database/wipe'],
            ['GET',    '/api/v1/admin/users/export'],
            ['GET',    '/api/v1/admin/orders/export'],
            ['GET',    '/api/v1/admin/activity-logs/export'],
            ['POST',   '/api/v1/admin/reports/export/excel'],
            ['POST',   '/api/v1/admin/downloads/approvers'],
        ];

        foreach ($routes as [$method, $uri]) {
            $res = $this->withBearer($t, $method, $uri);
            $this->assertSame('step_up_required', $res->json('code'), "{$method} {$uri} answered {$res->getStatusCode()} without step-up");
        }
    }

    public function test_a_user_edit_needs_step_up_only_when_roles_change(): void
    {
        $owner = $this->staffWithRoles(['super_admin']);
        $clerk = $this->staffWithRoles(['pos_clerk']);
        $t = $this->bearerFor($owner);
        $clerkRole = Role::findOrCreate('pos_clerk', 'sanctum');
        $tailor    = Role::findOrCreate('tailor', 'sanctum');

        $this->withBearer($t, 'PUT', "/api/v1/admin/users/{$clerk->id}", ['first_name' => 'Renamed', 'role_ids' => [$clerkRole->id]])->assertOk();
        $this->withBearer($t, 'PUT', "/api/v1/admin/users/{$clerk->id}", ['role_ids' => [$tailor->id]])
            ->assertStatus(403)->assertJson(['code' => 'step_up_required']);
        $this->assertSame(['pos_clerk'], $clerk->fresh()->getRoleNames()->all());

        $this->withBearer($t, 'POST', '/api/v1/admin/auth/step-up', ['password' => 'password'])->assertOk();
        $this->withBearer($t, 'PUT', "/api/v1/admin/users/{$clerk->id}", ['role_ids' => [$tailor->id]])->assertOk();
        $this->assertSame(['tailor'], $clerk->fresh()->getRoleNames()->all());
    }
}
