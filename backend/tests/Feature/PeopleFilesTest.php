<?php

namespace Tests\Feature;

use App\Models\EmployeeProfile;
use App\Models\Outlet;
use App\Models\PublicHoliday;
use App\Models\StaffDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\StepsUp;
use Tests\TestCase;

/** Bethany People PR 2 — the employee file, documents and holidays. */
class PeopleFilesTest extends TestCase
{
    use RefreshDatabase;
    use StepsUp;

    private Outlet $shop;
    private Outlet $other;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('private');
        Artisan::call('permission:sync');
        $this->shop  = Outlet::factory()->create();
        $this->other = Outlet::factory()->create();
    }

    private function staff(array $permissions = [], ?Outlet $outlet = null): User
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
        $outlet ??= $this->shop;
        DB::table('outlet_user')->insert(['user_id' => $u->id, 'outlet_id' => $outlet->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        return $u->fresh();
    }

    public function test_hr_view_is_scoped_to_own_outlets_and_identifiers_are_masked(): void
    {
        $mine  = $this->staff();
        $theirs = $this->staff([], $this->other);
        EmployeeProfile::factory()->create(['user_id' => $mine->id, 'national_id_no' => '12345678', 'kra_pin' => 'A001234567Z']);

        Sanctum::actingAs($this->staff(['hr.view']));
        $list = $this->getJson('/api/v1/admin/people')->assertOk();
        $ids = collect($list->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));

        $r = $this->getJson("/api/v1/admin/people/{$mine->id}")->assertOk();
        $this->assertSame('•••••678', $r->json('person.masked.national_id_no'));
        $this->assertSame('••••••••67Z', $r->json('person.masked.kra_pin'));
        $this->assertFalse($r->json('person.can.view_sensitive'));
        $this->assertStringNotContainsString('12345678', $r->getContent());

        $this->getJson("/api/v1/admin/people/{$theirs->id}")->assertStatus(403);
        $this->putJson("/api/v1/admin/people/{$mine->id}", ['job_title' => 'Cutter'])->assertStatus(403);

        Sanctum::actingAs($this->staff());
        $this->getJson('/api/v1/admin/people')->assertStatus(403);
    }

    public function test_all_access_sees_everyone_and_hr_edit_saves_the_file(): void
    {
        $theirs = $this->staff([], $this->other);
        Sanctum::actingAs($this->staff(['hr.view', 'hr.edit', 'outlets.all_access']));
        $this->assertTrue(collect($this->getJson('/api/v1/admin/people')->json('data'))->pluck('id')->contains($theirs->id));

        $r = $this->putJson("/api/v1/admin/people/{$theirs->id}", [
            'job_title' => 'Cutter', 'department' => 'Production', 'employment_type' => 'casual',
            'hire_date' => '2026-02-01', 'emergency_contact_name' => 'Jane', 'emergency_contact_phone' => '0712000000',
        ])->assertOk();
        $this->assertSame('Cutter', $r->json('person.profile.job_title'));
        $this->assertSame('casual', $r->json('person.profile.employment_type'));
        $this->assertDatabaseHas('employee_profiles', ['user_id' => $theirs->id, 'department' => 'Production']);

        $this->putJson("/api/v1/admin/people/{$theirs->id}", ['employment_type' => 'freelance'])->assertStatus(422);
        $this->putJson("/api/v1/admin/people/{$theirs->id}", ['hire_date' => '2026-02-01', 'end_date' => '2026-01-01'])->assertStatus(422);
    }

    public function test_sensitive_values_need_the_permission_and_a_fresh_step_up_and_are_logged(): void
    {
        $person = $this->staff();
        EmployeeProfile::factory()->create(['user_id' => $person->id, 'bank_account_no' => '0011223344']);

        // hr.view alone: 403 before any step-up question.
        Sanctum::actingAs($this->staff(['hr.view']));
        $this->getJson("/api/v1/admin/people/{$person->id}/sensitive")->assertStatus(403);

        // hr.view_sensitive without a fresh step-up: the challenge.
        $keeper = $this->staff(['hr.view', 'hr.edit', 'hr.view_sensitive']);
        Sanctum::actingAs($keeper);
        $this->getJson("/api/v1/admin/people/{$person->id}/sensitive")->assertStatus(403)->assertJsonPath('code', 'step_up_required');

        $this->stepUp($keeper);
        $r = $this->getJson("/api/v1/admin/people/{$person->id}/sensitive")->assertOk();
        $this->assertSame('0011223344', $r->json('sensitive.bank_account_no'));
        $this->assertDatabaseHas('activity_log', ['description' => "{$keeper->first_name} viewed identifiers and pay details for {$person->first_name} {$person->last_name}"]);

        $this->putJson("/api/v1/admin/people/{$person->id}/sensitive", ['kra_pin' => 'A009876543B', 'mpesa_phone' => '254712000000'])->assertOk();
        $profile = EmployeeProfile::where('user_id', $person->id)->first();
        $this->assertSame('A009876543B', $profile->kra_pin);
        // Encrypted at rest: the raw column is not the value.
        $raw = DB::table('employee_profiles')->where('user_id', $person->id)->value('kra_pin');
        $this->assertNotSame('A009876543B', $raw);
        $this->assertStringNotContainsString('A009876543B', $raw);
        // Redacted in the audit trail.
        $this->assertStringNotContainsString('A009876543B', (string) DB::table('activity_log')->where('subject_type', EmployeeProfile::class)->orderByDesc('id')->value('properties'));
    }

    public function test_a_person_sees_their_own_file_and_edits_only_their_own_contact(): void
    {
        $me = $this->staff();
        EmployeeProfile::factory()->create(['user_id' => $me->id, 'national_id_no' => '87654321', 'job_title' => 'Tailor']);
        Sanctum::actingAs($me);

        $r = $this->getJson('/api/v1/admin/me/file')->assertOk();
        $this->assertSame('Tailor', $r->json('person.profile.job_title'));
        $this->assertSame('•••••321', $r->json('person.masked.national_id_no'));
        $this->assertFalse($r->json('person.can.edit'));

        $this->putJson('/api/v1/admin/me/file', ['emergency_contact_name' => 'Mum', 'emergency_contact_phone' => '0700', 'job_title' => 'Owner'])->assertOk();
        $p = EmployeeProfile::where('user_id', $me->id)->first();
        $this->assertSame('Mum', $p->emergency_contact_name);
        $this->assertSame('Tailor', $p->job_title);

        $this->getJson('/api/v1/admin/me/file/sensitive')->assertStatus(403)->assertJsonPath('code', 'step_up_required');
        $this->stepUp($me);
        $this->assertSame('87654321', $this->getJson('/api/v1/admin/me/file/sensitive')->json('sensitive.national_id_no'));
    }

    public function test_documents_upload_link_remove_and_expiring(): void
    {
        $person = $this->staff();
        $keeper = $this->staff(['hr.view', 'hr.edit']);
        Sanctum::actingAs($keeper);

        $r = $this->post("/api/v1/admin/people/{$person->id}/documents", [
            'kind' => 'national_id', 'title' => 'ID copy', 'expires_at' => now()->addDays(10)->toDateString(),
            'file' => UploadedFile::fake()->image('id.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(201);
        $docId = $r->json('document.id');
        $this->assertCount(1, Storage::disk('private')->allFiles("people/{$person->id}/documents"));

        $this->post("/api/v1/admin/people/{$person->id}/documents", ['kind' => 'contract', 'title' => 'x', 'file' => UploadedFile::fake()->create('evil.html', 10, 'text/html')], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $link = $this->getJson("/api/v1/admin/people/{$person->id}/documents/{$docId}")->assertOk();
        $this->assertStringContainsString('/files/staff-documents/' . $docId, $link->json('url'));
        $this->assertStringContainsString('signature=', $link->json('url'));

        $exp = $this->getJson('/api/v1/admin/people/documents/expiring?days=30')->assertOk();
        $this->assertSame($docId, $exp->json('data.0.id'));
        $this->assertSame($person->id, $exp->json('data.0.user.id'));

        // The person sees their own document and gets a link too.
        Sanctum::actingAs($person);
        $this->assertSame($docId, $this->getJson('/api/v1/admin/me/file')->json('person.documents.0.id'));
        $this->getJson("/api/v1/admin/me/file/documents/{$docId}")->assertOk();
        // But not someone else's.
        $stranger = $this->staff();
        StaffDocument::create(['user_id' => $stranger->id, 'kind' => 'other', 'title' => 'x', 'path' => 'people/x/y.pdf']);
        $this->getJson('/api/v1/admin/me/file/documents/' . StaffDocument::where('user_id', $stranger->id)->value('id'))->assertStatus(404);

        Sanctum::actingAs($keeper);
        $this->deleteJson("/api/v1/admin/people/{$person->id}/documents/{$docId}")->assertOk();
        $this->assertNotNull(StaffDocument::find($docId)->removed_at);
        $this->assertCount(1, Storage::disk('private')->allFiles("people/{$person->id}/documents"), 'the file is kept');
        $this->getJson("/api/v1/admin/people/{$person->id}/documents/{$docId}")->assertStatus(404);
    }

    public function test_expiry_command_notifies_file_keepers_once(): void
    {
        $person = $this->staff();
        $keeper = $this->staff(['hr.view', 'hr.edit']);
        $this->staff(['hr.view']); // not a keeper
        StaffDocument::create(['user_id' => $person->id, 'kind' => 'contract', 'title' => 'Contract', 'path' => 'p', 'expires_at' => now()->addDays(5)->toDateString()]);
        StaffDocument::create(['user_id' => $person->id, 'kind' => 'other', 'title' => 'Far', 'path' => 'p', 'expires_at' => now()->addDays(200)->toDateString()]);

        Artisan::call('hr:document-expiry', ['--days' => 30]);
        Notification::assertSentTo($keeper, \App\Notifications\InAppNotification::class, fn ($n) => $n->title === '1 staff document expiring');
        Notification::assertCount(1);
    }

    public function test_holidays_are_seeded_readable_by_all_and_edited_by_hr_edit(): void
    {
        Sanctum::actingAs($this->staff());
        $r = $this->getJson('/api/v1/admin/holidays?year=2026')->assertOk();
        $names = collect($r->json('data'))->pluck('name', 'date');
        $this->assertSame('Jamhuri Day', $names['2026-12-12']);
        $this->assertSame('Good Friday', $names['2026-04-03']);
        $this->assertSame('Easter Monday', $names['2026-04-06']);
        $this->assertArrayNotHasKey('2026-03-20', $names->all(), 'Eid is gazetted, not guessed');

        $this->postJson('/api/v1/admin/holidays', ['date' => '2026-03-20', 'name' => 'Eid al-Fitr'])->assertStatus(403);

        Sanctum::actingAs($this->staff(['hr.view', 'hr.edit']));
        $this->postJson('/api/v1/admin/holidays', ['date' => '2026-03-20', 'name' => 'Eid al-Fitr', 'is_confirmed' => false])->assertStatus(201);
        $this->postJson('/api/v1/admin/holidays', ['date' => '2026-03-20', 'name' => 'Again'])->assertStatus(422);
        $id = PublicHoliday::where('date', '2026-03-20')->value('id');
        $this->putJson("/api/v1/admin/holidays/{$id}", ['is_confirmed' => true])->assertOk();
        $this->assertTrue(PublicHoliday::find($id)->is_confirmed);
        $this->deleteJson("/api/v1/admin/holidays/{$id}")->assertOk();
        $this->assertNull(PublicHoliday::find($id));
    }
}
