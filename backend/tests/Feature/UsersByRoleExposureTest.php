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
 * Phase 1C, item 6 — the assignee picker is not a staff directory.
 *
 * GET /admin/users/role/{role} is open to every staff login (assignee
 * dropdowns) and returned each user's email and phone. A picker needs who and
 * where: id, name, outlet. Contact details stay with users.view, which is the
 * permission that opens the staff list itself.
 */
class UsersByRoleExposureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync');
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findByName($role, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user->fresh());

        return $user->fresh();
    }

    private function aTailor(): User
    {
        $t = User::factory()->create([
            'first_name' => 'Joseph', 'last_name' => 'Otieno',
            'email' => 'joseph.otieno@bethany.test', 'phone' => '0799123456', 'status' => 'active',
        ]);
        $t->assignRole(Role::findByName('tailor', 'sanctum'));
        $t->outlets()->attach(Outlet::factory()->create(['name' => 'Workshop'])->id, ['is_primary' => true]);

        return $t;
    }

    public function test_a_tailor_gets_names_and_outlets_but_no_contacts(): void
    {
        $other = $this->aTailor();
        $this->actAs('tailor');

        $res  = $this->getJson('/api/v1/admin/users/role/tailor')->assertOk();
        $row  = collect($res->json('data'))->firstWhere('id', $other->id);

        $this->assertNotNull($row, 'the picker still lists the person');
        $this->assertSame('Joseph', $row['first_name']);
        $this->assertSame('Otieno', $row['last_name']);
        $this->assertSame('Workshop', $row['outlet']['name']);
        $this->assertStringNotContainsString('joseph.otieno@bethany.test', $res->getContent());
        $this->assertStringNotContainsString('0799123456', $res->getContent());
    }

    public function test_someone_who_can_list_staff_still_sees_contacts(): void
    {
        $other = $this->aTailor();
        $this->actAs('admin');

        $row = collect($this->getJson('/api/v1/admin/users/role/tailor')->assertOk()->json('data'))
            ->firstWhere('id', $other->id);

        $this->assertSame('joseph.otieno@bethany.test', $row['email']);
        $this->assertSame('0799123456', $row['phone']);
    }
}
