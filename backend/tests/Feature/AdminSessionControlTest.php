<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SignsInForReal;
use Tests\TestCase;

/**
 * Plan §12.4: system_admin / super_admin can see and end anyone's sessions
 * (tier rules). Plan §17: an old session reused after a role is removed → 401.
 */
class AdminSessionControlTest extends TestCase
{
    use RefreshDatabase, SignsInForReal;

    private function tokens(User $u): int
    {
        return DB::table('personal_access_tokens')->where('tokenable_id', $u->id)->count();
    }

    public function test_a_system_admin_lists_and_ends_a_clerks_sessions(): void
    {
        $sys   = $this->systemAdmin();
        $clerk = $this->staffWithRoles(['pos_clerk']);
        $a = $this->bearerFor($clerk);
        $this->bearerFor($clerk);
        $t = $this->bearerFor($sys);

        $list = $this->withBearer($t, 'GET', "/api/v1/admin/users/{$clerk->id}/sessions")->assertOk()->json('data');
        $this->assertCount(2, $list);

        $this->withBearer($t, 'POST', "/api/v1/admin/users/{$clerk->id}/sessions/{$list[0]['id']}/revoke")->assertOk();
        $this->assertSame(1, $this->tokens($clerk));

        $this->withBearer($t, 'POST', "/api/v1/admin/users/{$clerk->id}/sessions/revoke-all")->assertOk()->assertJson(['revoked_count' => 1]);
        $this->assertSame(0, $this->tokens($clerk));
        $this->withBearer($a, 'GET', '/api/v1/admin/auth/me')->assertStatus(401);
        $this->assertDatabaseHas('activity_log', ['event' => 'sessions_revoked_by_admin', 'subject_id' => $clerk->id, 'causer_id' => $sys->id]);
    }

    public function test_a_system_admin_cannot_end_a_tier_one_or_owner_session(): void
    {
        $sys = $this->systemAdmin();
        $t = $this->bearerFor($sys);
        foreach (['admin', 'finance_manager', 'super_admin', 'system_admin'] as $role) {
            $target = $this->staffWithRoles([$role]);
            $this->bearerFor($target);
            $this->withBearer($t, 'POST', "/api/v1/admin/users/{$target->id}/sessions/revoke-all")->assertForbidden();
            $this->withBearer($t, 'GET', "/api/v1/admin/users/{$target->id}/sessions")->assertForbidden();
            $this->assertSame(1, $this->tokens($target), "{$role}'s session was ended by a system_admin");
        }
    }

    public function test_a_super_admin_ends_anyones_sessions_and_a_stacked_role_counts_at_its_highest_tier(): void
    {
        $owner = $this->staffWithRoles(['super_admin']);
        $fm = $this->staffWithRoles(['finance_manager']);
        $this->bearerFor($fm);
        $this->withBearer($this->bearerFor($owner), 'POST', "/api/v1/admin/users/{$fm->id}/sessions/revoke-all")->assertOk();
        $this->assertSame(0, $this->tokens($fm));

        // A clerk who is also admin is Tier 1: out of a system_admin's reach.
        $stacked = $this->staffWithRoles(['pos_clerk', 'admin']);
        $this->bearerFor($stacked);
        $this->withBearer($this->bearerFor($this->systemAdmin()), 'POST', "/api/v1/admin/users/{$stacked->id}/sessions/revoke-all")
            ->assertForbidden();
    }

    public function test_nobody_else_can_end_another_persons_sessions(): void
    {
        $om = $this->staffWithRoles(['outlet_manager']);
        $clerk = $this->staffWithRoles(['pos_clerk']);
        $this->bearerFor($clerk);

        $res = $this->withBearer($this->bearerFor($om), 'POST', "/api/v1/admin/users/{$clerk->id}/sessions/revoke-all");
        $this->assertContains($res->getStatusCode(), [403]);
        $this->assertSame(1, $this->tokens($clerk));
    }

    public function test_own_sessions_list_names_the_device_and_address(): void
    {
        $u = $this->staffWithRoles(['accountant']);
        $this->withServerVariables(['REMOTE_ADDR' => '41.90.10.5'])
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0'])
            ->postJson('/api/v1/admin/auth/login', ['email' => $u->email, 'password' => 'password'])->assertOk();

        $rows = $this->withBearer($this->bearerFor($u), 'GET', '/api/v1/admin/profile/sessions')->assertOk()->json('data');
        $signedIn = collect($rows)->firstWhere('ip', '41.90.10.5');
        $this->assertNotNull($signedIn);
        $this->assertSame('Firefox on Windows', $signedIn['agent']);
    }

    // ── §17: reuse an old session after role removal → 401 ─────────────────

    public function test_an_old_session_is_refused_after_a_role_is_removed(): void
    {
        $owner = $this->staffWithRoles(['super_admin']);
        $u     = $this->staffWithRoles(['finance_manager', 'accountant']);
        $old   = $this->bearerFor($u);
        $this->withBearer($old, 'GET', '/api/v1/admin/auth/me')->assertOk();

        $t = $this->bearerFor($owner);
        $this->withBearer($t, 'POST', '/api/v1/admin/auth/step-up', ['password' => 'password'])->assertOk();
        $this->withBearer($t, 'PUT', "/api/v1/admin/users/{$u->id}", ['role_ids' => [Role::findByName('accountant', 'sanctum')->id]])->assertOk();

        $this->withBearer($old, 'GET', '/api/v1/admin/auth/me')->assertStatus(401);
        $this->assertSame(0, $this->tokens($u));
    }

    public function test_an_unchanged_role_set_leaves_sessions_alone(): void
    {
        $owner = $this->staffWithRoles(['super_admin']);
        $u     = $this->staffWithRoles(['accountant']);
        $old   = $this->bearerFor($u);

        $this->withBearer($this->bearerFor($owner), 'PUT', "/api/v1/admin/users/{$u->id}", [
            'first_name' => 'Renamed', 'role_ids' => [Role::findByName('accountant', 'sanctum')->id],
        ])->assertOk();

        $this->withBearer($old, 'GET', '/api/v1/admin/auth/me')->assertOk();
    }

    public function test_adding_a_role_keeps_the_person_signed_in(): void
    {
        // The owner assigns roles himself; giving a cashier another role must
        // not sign them out mid-shift (permissions are re-read per request).
        $owner = $this->staffWithRoles(['super_admin']);
        $u     = $this->staffWithRoles(['accountant']);
        $old   = $this->bearerFor($u);

        $t = $this->bearerFor($owner);
        $this->withBearer($t, 'POST', '/api/v1/admin/auth/step-up', ['password' => 'password'])->assertOk();
        $this->withBearer($t, 'PUT', "/api/v1/admin/users/{$u->id}", ['role_ids' => [
            Role::findByName('accountant', 'sanctum')->id, Role::findOrCreate('finance_manager', 'sanctum')->id,
        ]])->assertOk();

        $this->withBearer($old, 'GET', '/api/v1/admin/auth/me')->assertOk();
        $this->assertSame(1, $this->tokens($u));
    }
}
