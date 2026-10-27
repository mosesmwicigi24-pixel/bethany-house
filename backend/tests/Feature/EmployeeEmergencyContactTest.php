<?php

namespace Tests\Feature;

use App\Models\EmployeeProfile;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The emergency contact on the employee file, edited in place (owner,
 * 27 Oct 2026): up to two contacts, phones on the hub's one phone rule and
 * saved as E.164, a name required beside every number, the file keeper or
 * the person themselves may change it, and every change is in the trail.
 */
class EmployeeEmergencyContactTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        $this->shop = Outlet::factory()->create();
    }

    private function staff(array $permissions = []): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        if ($permissions) {
            $role = Role::findOrCreate('probe_' . uniqid(), 'sanctum');
            foreach ($permissions as $p) {
                $role->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
            }
            $u->assignRole($role);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
        DB::table('outlet_user')->insert(['user_id' => $u->id, 'outlet_id' => $this->shop->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $u->fresh();
    }

    public function test_the_file_keeper_saves_two_contacts_and_phones_are_stored_as_e164(): void
    {
        $person = $this->staff();
        Sanctum::actingAs($this->staff(['hr.view', 'hr.edit']));

        $r = $this->putJson("/api/v1/admin/people/{$person->id}", [
            'emergency_contact_name'           => '  Jane Wanjiru ',
            'emergency_contact_phone'          => '0722 123 456',
            'emergency_contact_relationship'   => 'Spouse',
            'emergency_contact_2_name'         => 'Peter Okello',
            'emergency_contact_2_phone'        => '+256 772 123 456',
            'emergency_contact_2_relationship' => 'Cousin',
        ])->assertOk();

        $this->assertSame('Jane Wanjiru', $r->json('person.profile.emergency_contact_name'));
        $this->assertSame('+254722123456', $r->json('person.profile.emergency_contact_phone'));
        $this->assertSame('Spouse', $r->json('person.profile.emergency_contact_relationship'));
        $this->assertSame('+256772123456', $r->json('person.profile.emergency_contact_2_phone'));
        $this->assertSame('Cousin', $r->json('person.profile.emergency_contact_2_relationship'), 'free text for Other is kept');
        $this->assertDatabaseHas('employee_profiles', ['user_id' => $person->id, 'emergency_contact_phone' => '+254722123456', 'emergency_contact_2_name' => 'Peter Okello']);

        // Only the keys sent change: the contact edit leaves the rest of the file alone.
        EmployeeProfile::where('user_id', $person->id)->update(['job_title' => 'Cutter']);
        $this->putJson("/api/v1/admin/people/{$person->id}", ['emergency_contact_2_name' => null, 'emergency_contact_2_phone' => null, 'emergency_contact_2_relationship' => null])->assertOk();
        $p = EmployeeProfile::where('user_id', $person->id)->first();
        $this->assertNull($p->emergency_contact_2_phone);
        $this->assertSame('Cutter', $p->job_title);
        $this->assertSame('+254722123456', $p->emergency_contact_phone);
    }

    public function test_a_number_needs_a_name_and_must_be_one_real_phone(): void
    {
        $person = $this->staff();
        Sanctum::actingAs($this->staff(['hr.view', 'hr.edit']));
        $url = "/api/v1/admin/people/{$person->id}";

        $this->putJson($url, ['emergency_contact_phone' => '0722123456'])->assertStatus(422)->assertJsonValidationErrors('emergency_contact_name');
        $this->putJson($url, ['emergency_contact_name' => 'Jane', 'emergency_contact_phone' => 'call the office'])->assertStatus(422)->assertJsonValidationErrors('emergency_contact_phone');
        $this->putJson($url, ['emergency_contact_name' => 'Jane', 'emergency_contact_phone' => '0722123456 / 0733123456'])->assertStatus(422)->assertJsonValidationErrors('emergency_contact_phone');
        $this->putJson($url, ['emergency_contact_name' => 'Jane', 'emergency_contact_phone' => '0700'])->assertStatus(422)->assertJsonValidationErrors('emergency_contact_phone');
        $this->putJson($url, ['emergency_contact_name' => 'Jane', 'emergency_contact_phone' => '0700000000'])->assertStatus(422)->assertJsonValidationErrors('emergency_contact_phone');
        $this->putJson($url, ['emergency_contact_2_name' => 'Peter', 'emergency_contact_2_phone' => '07221234'])->assertStatus(422)->assertJsonValidationErrors('emergency_contact_2_phone');
        $this->assertDatabaseMissing('employee_profiles', ['user_id' => $person->id]);

        // A name alone is fine (the number may come later).
        $this->putJson($url, ['emergency_contact_name' => 'Jane'])->assertOk();

        // Clearing the name while a number stays on file is refused, even on a partial update.
        $this->putJson($url, ['emergency_contact_name' => 'Jane', 'emergency_contact_phone' => '0722123456'])->assertOk();
        $this->putJson($url, ['emergency_contact_name' => null])->assertStatus(422)->assertJsonValidationErrors('emergency_contact_name');
        $this->assertSame('Jane', EmployeeProfile::where('user_id', $person->id)->value('emergency_contact_name'));
    }

    public function test_a_number_already_on_file_may_be_sent_back_unchanged(): void
    {
        $person = $this->staff();
        // Free text from before this rule (the field was a plain string).
        EmployeeProfile::factory()->create(['user_id' => $person->id, 'emergency_contact_name' => 'Jane', 'emergency_contact_phone' => '0722 123 456 (home)']);
        Sanctum::actingAs($this->staff(['hr.view', 'hr.edit']));

        $this->putJson("/api/v1/admin/people/{$person->id}", ['emergency_contact_name' => 'Jane W', 'emergency_contact_phone' => '0722 123 456 (home)'])->assertOk();
        $p = EmployeeProfile::where('user_id', $person->id)->first();
        $this->assertSame('Jane W', $p->emergency_contact_name);
        $this->assertSame('0722 123 456 (home)', $p->emergency_contact_phone, 'kept as stored, not rewritten');
    }

    public function test_a_role_without_hr_edit_cannot_change_someone_elses_contact(): void
    {
        $person = $this->staff();
        $viewer = $this->staff(['hr.view']);
        Sanctum::actingAs($viewer);

        $this->assertFalse($this->getJson("/api/v1/admin/people/{$person->id}")->assertOk()->json('person.can.edit_contact'));
        $this->putJson("/api/v1/admin/people/{$person->id}", ['emergency_contact_name' => 'Jane', 'emergency_contact_phone' => '0722123456'])->assertStatus(403);
        $this->assertDatabaseMissing('employee_profiles', ['user_id' => $person->id]);

        // Their own file, seen through People, offers the contact to edit (through My file).
        $this->assertTrue($this->getJson("/api/v1/admin/people/{$viewer->id}")->assertOk()->json('person.can.edit_contact'));
        $this->assertFalse($this->getJson("/api/v1/admin/people/{$viewer->id}")->json('person.can.edit'));

        Sanctum::actingAs($this->staff(['hr.view', 'hr.edit']));
        $this->assertTrue($this->getJson("/api/v1/admin/people/{$person->id}")->json('person.can.edit_contact'));
    }

    public function test_a_person_edits_their_own_contacts_through_my_file(): void
    {
        $me = $this->staff();
        Sanctum::actingAs($me);

        $this->assertTrue($this->getJson('/api/v1/admin/me/file')->assertOk()->json('person.can.edit_contact'));

        $r = $this->putJson('/api/v1/admin/me/file', [
            'emergency_contact_name' => 'Mum', 'emergency_contact_phone' => '712480391', 'emergency_contact_relationship' => 'Parent',
            'emergency_contact_2_name' => 'Brother', 'emergency_contact_2_phone' => '+255 754 123 456',
            'job_title' => 'Owner',
        ])->assertOk();
        $this->assertSame('+254712480391', $r->json('person.profile.emergency_contact_phone'));
        $this->assertSame('+255754123456', $r->json('person.profile.emergency_contact_2_phone'));
        $this->assertNull(EmployeeProfile::where('user_id', $me->id)->value('job_title'), 'job title is the file keeper\'s');

        $this->putJson('/api/v1/admin/me/file', ['emergency_contact_2_phone' => '0733123456', 'emergency_contact_2_name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('emergency_contact_2_name');
    }

    public function test_every_change_is_in_the_audit_trail_old_to_new(): void
    {
        $person = $this->staff();
        EmployeeProfile::factory()->create(['user_id' => $person->id, 'emergency_contact_name' => 'Jane', 'emergency_contact_phone' => '+254722123456']);
        $keeper = $this->staff(['hr.view', 'hr.edit']);
        Sanctum::actingAs($keeper);

        $this->putJson("/api/v1/admin/people/{$person->id}", ['emergency_contact_name' => 'Mary', 'emergency_contact_phone' => '0733 123 456', 'emergency_contact_relationship' => 'Sibling'])->assertOk();

        $row = DB::table('activity_log')->where('subject_type', EmployeeProfile::class)->where('event', 'updated')->orderByDesc('id')->first();
        $this->assertNotNull($row);
        $this->assertSame($keeper->id, (int) $row->causer_id);
        $changes = json_decode($row->properties, true)['changes'];
        $this->assertEquals(['old' => 'Jane', 'new' => 'Mary'], $changes['emergency_contact_name']);
        $this->assertEquals(['old' => '+254722123456', 'new' => '+254733123456'], $changes['emergency_contact_phone']);
        $this->assertEquals(['old' => null, 'new' => 'Sibling'], $changes['emergency_contact_relationship']);
    }
}
