<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Role hardening 4D: a refused attempt on a sensitive route (users, roles,
 * settings, payments, approvals, exports, database, audit) leaves an
 * `authorization_denied` entry — who, which route, which rule, from where —
 * at most once per user + route per five minutes. Allowed checks and refusals
 * on ordinary screens write nothing.
 */
class AuthorizationDeniedAuditTest extends TestCase
{
    use RefreshDatabase;

    private function staffWith(array $permissions, string $role = 'probe_role'): User
    {
        $r = Role::findOrCreate($role, 'sanctum');
        foreach ($permissions as $p) {
            $r->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole($r);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $u;
    }

    private function denials(): \Illuminate\Support\Collection
    {
        return DB::table('activity_log')->where('event', 'authorization_denied')->orderBy('id')->get();
    }

    public function test_a_refused_permission_on_a_sensitive_route_is_recorded_once_per_window(): void
    {
        Permission::findOrCreate('users.view', 'sanctum');
        $u = $this->staffWith(['orders.view']);
        $this->actingAs($u, 'sanctum');

        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/users?page=2')->assertForbidden();

        $rows = $this->denials();
        $this->assertCount(1, $rows, 'repeat refusals within five minutes must not flood the trail');
        $row = $rows->first();
        $this->assertSame($u->id, (int) $row->causer_id);
        $this->assertNotNull($row->ip_address);
        $props = json_decode($row->properties, true);
        $this->assertSame('api/v1/admin/users', $props['route']);
        $this->assertSame('GET', $props['method']);
        $this->assertStringContainsString('users.view', $props['rule']);
        $this->assertSame('failure', $props['_audit']['outcome']);

        $this->travel(6)->minutes();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->assertCount(2, $this->denials());
    }

    public function test_a_role_gate_and_a_controller_refusal_are_recorded_with_their_rule(): void
    {
        $u = $this->staffWith(['orders.view']);
        $this->actingAs($u, 'sanctum');

        // activity-logs sits behind role:super_admin
        $this->getJson('/api/v1/admin/activity-logs')->assertForbidden();

        $props = json_decode($this->denials()->last()->properties, true);
        $this->assertSame('api/v1/admin/activity-logs', $props['route']);
        $this->assertStringContainsString('super_admin', $props['rule']);
    }

    public function test_refusals_on_ordinary_screens_and_allowed_calls_write_nothing(): void
    {
        Permission::findOrCreate('products.view', 'sanctum');
        $u = $this->staffWith(['users.view']);
        $this->actingAs($u, 'sanctum');

        $this->getJson('/api/v1/admin/products')->assertForbidden();   // not a sensitive group
        $this->getJson('/api/v1/admin/users')->assertOk();              // allowed

        $this->assertCount(0, $this->denials());
    }
}
