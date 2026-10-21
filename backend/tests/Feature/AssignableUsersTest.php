<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The Assign Tasks dropdown (owner, 2026-10-21): a manager who may assign
 * stages (production.manage_assignees) but may not open the staff list
 * (users.view) saw nobody. GET /admin/users/assignable is open to every
 * staff login and answers with who and where only.
 */
class AssignableUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function staff(string $role, array $attrs = []): User
    {
        $u = User::factory()->create($attrs + ['status' => 'active', 'user_type' => 'staff']);
        $u->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    public function test_an_outlet_manager_without_users_view_sees_every_active_staff_name_but_no_contacts(): void
    {
        $me     = $this->staff('outlet_manager');
        $tailor = $this->staff('tailor', ['first_name' => 'Joseph', 'last_name' => 'Otieno', 'email' => 'joseph@bethany.test', 'phone' => '0799123456']);
        $tailor->outlets()->attach(Outlet::factory()->create(['name' => 'Workshop'])->id, ['is_primary' => true]);
        $this->staff('tailor', ['first_name' => 'Gone', 'status' => 'inactive']);
        User::factory()->create(['first_name' => 'Shopper', 'user_type' => 'customer', 'status' => 'active']);

        $this->assertFalse($me->can('users.view'), 'the fixture must be a manager who cannot open the staff list');
        $this->assertTrue($me->can('production.manage_assignees'));
        Sanctum::actingAs($me);

        $this->getJson('/api/v1/admin/users')->assertStatus(403);

        $rows = collect($this->getJson('/api/v1/admin/users/assignable')->assertOk()->json('data'));
        $row  = $rows->firstWhere('id', $tailor->id);
        $this->assertSame(['Joseph', 'Otieno', 'Workshop', ['tailor']], [$row['first_name'], $row['last_name'], $row['outlet']['name'], $row['roles']]);
        $this->assertArrayNotHasKey('email', $row);
        $this->assertArrayNotHasKey('phone', $row);
        $this->assertNotNull($rows->firstWhere('id', $me->id), 'a manager can assign a stage to herself');
        $this->assertNull($rows->firstWhere('first_name', 'Gone'), 'inactive staff are not offered');
        $this->assertNull($rows->firstWhere('first_name', 'Shopper'), 'customers are not staff');
    }

    public function test_a_signed_out_caller_gets_nothing(): void
    {
        $this->getJson('/api/v1/admin/users/assignable')->assertStatus(401);
    }
}
