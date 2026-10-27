<?php

namespace Tests\Feature;

use App\Models\EmployeeProfile;
use App\Models\Outlet;
use App\Models\PayGroup;
use App\Models\Shift;
use App\Models\StaffDocument;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\Auth\TerminalPin;
use App\Support\Automations;
use App\Support\AttendanceRules;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

/**
 * Bethany People C2 — the automations table and its four alerts, the
 * clock-in kiosk (token fence, PIN, photo, shift rules) and message by
 * work status. Fixture: Bethany House at (-1.2921, 36.8219), fence 50 m.
 */
class AutomationsKioskTest extends TestCase
{
    use RefreshDatabase, StepsUp;

    private const LAT = -1.2921;
    private const LNG = 36.8219;
    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('private');
        Artisan::call('permission:sync');
        $this->outlet = Outlet::factory()->create(['latitude' => self::LAT, 'longitude' => self::LNG, 'geofence_radius_meters' => 50, 'is_active' => true]);
        AttendanceRules::save(['clock_in_without_shift' => 'allow']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(array $permissions = [], ?int $reportsTo = null, ?Outlet $outlet = null): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff', 'reports_to' => $reportsTo]);
        if ($permissions) {
            $role = Role::findOrCreate('probe_' . uniqid(), 'sanctum');
            foreach ($permissions as $p) $role->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
            $u->assignRole($role);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
        DB::table('outlet_user')->insert(['user_id' => $u->id, 'outlet_id' => ($outlet ?? $this->outlet)->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        EmployeeProfile::factory()->create(['user_id' => $u->id, 'hire_date' => '2024-01-01']);
        return $u->fresh();
    }

    /** A 64×64 white JPEG as a data URL. */
    private function jpeg(): string
    {
        $im = imagecreatetruecolor(64, 64);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        ob_start(); imagejpeg($im, null, 70); $bytes = ob_get_clean();
        return 'data:image/jpeg;base64,' . base64_encode($bytes);
    }

    public function test_automations_are_seeded_read_by_the_team_and_written_with_settings_edit(): void
    {
        $this->assertSame(15, Automations::get('missed_shift')['threshold']);
        $this->assertSame('hr', Automations::get('document_expiring')['recipients']);
        $viewer = $this->staff(['attendance.view_team']);
        $admin = $this->staff(['attendance.view_team', 'settings.edit']);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/admin/attendance/automations')->assertOk()->assertJsonCount(5, 'automations')->assertJsonPath('automations.0.key', 'missed_shift')->assertJsonPath('automations.0.enabled', true);
        $this->putJson('/api/v1/admin/attendance/automations/missed_shift', ['threshold' => 20])->assertStatus(403);
        Sanctum::actingAs($admin);
        $this->putJson('/api/v1/admin/attendance/automations/missed_shift', ['threshold' => 20, 'recipients' => 'owner', 'extra_user_ids' => [$viewer->id]])->assertOk()->assertJsonPath('automation.threshold', 20)->assertJsonPath('automation.recipients', 'owner');
        $this->putJson('/api/v1/admin/attendance/automations/missed_shift', ['threshold' => 2])->assertStatus(422);       // below min 5
        $this->putJson('/api/v1/admin/attendance/automations/outside_fence', ['threshold' => 5])->assertStatus(422);     // no threshold on this one
        $this->putJson('/api/v1/admin/attendance/automations/nope', ['enabled' => false])->assertStatus(404);
        $this->assertSame([$viewer->id], Automations::get('missed_shift')['extra_user_ids']);
        $this->assertDatabaseHas('activity_log', ['event' => 'automation_saved']);
    }

    public function test_recipients_managers_hr_owner_and_extra_people_never_the_subject(): void
    {
        $boss = $this->staff(['attendance.view_team', 'attendance.manage']);
        $hr = $this->staff(['hr.edit']);
        $owner = User::factory()->create(['status' => 'active', 'user_type' => 'staff']); $owner->assignRole('super_admin');
        $extra = $this->staff();
        $u = $this->staff([], $boss->id);
        $this->assertEqualsCanonicalizing([$boss->id], Automations::recipientsFor('missed_shift', $u)->pluck('id')->all());
        Automations::save('missed_shift', ['recipients' => 'hr', 'extra_user_ids' => [$extra->id, $u->id]]);
        // hr = whoever holds hr.edit, the owner included (super_admin holds everything, as for the expiry notice before C2).
        $this->assertEqualsCanonicalizing([$hr->id, $owner->id, $extra->id], Automations::recipientsFor('missed_shift', $u)->pluck('id')->all());
        Automations::save('missed_shift', ['recipients' => 'owner', 'extra_user_ids' => []]);
        $owners = Automations::recipientsFor('missed_shift', $u)->pluck('id')->all();
        $this->assertContains($owner->id, $owners);
        $this->assertEmpty(array_intersect([$hr->id, $extra->id, $boss->id, $u->id], $owners));
    }

    public function test_missed_shift_alert_goes_to_the_manager_once_after_the_threshold(): void
    {
        $boss = $this->staff(['attendance.view_team', 'attendance.manage']);
        $u = $this->staff([], $boss->id);
        Shift::factory()->published()->create(['outlet_id' => $this->outlet->id, 'user_id' => $u->id, 'date' => '2026-03-02', 'starts_at' => '08:00', 'ends_at' => '17:00']);
        Carbon::setTestNow('2026-03-02 08:10:00');
        $this->artisan('roster:remind')->assertExitCode(0);
        Notification::assertNothingSentTo($boss);
        Carbon::setTestNow('2026-03-02 08:16:00');
        $this->artisan('roster:remind')->assertExitCode(0);
        Notification::assertSentTo($boss, InAppNotification::class, fn ($n) => str_contains($n->title, 'has not clocked in') && $n->data['automation'] === 'missed_shift');
        $this->artisan('roster:remind')->assertExitCode(0);
        Notification::assertSentToTimes($boss, InAppNotification::class, 1);
        // Switched off: a second person's missed shift is silent.
        Automations::save('missed_shift', ['enabled' => false]);
        $v = $this->staff([], $boss->id);
        Shift::factory()->published()->create(['outlet_id' => $this->outlet->id, 'user_id' => $v->id, 'date' => '2026-03-02', 'starts_at' => '07:00', 'ends_at' => '17:00']);
        $this->artisan('roster:remind')->assertExitCode(0);
        Notification::assertSentToTimes($boss, InAppNotification::class, 1);
    }

    public function test_outside_the_zone_alert_fires_on_an_override_punch(): void
    {
        // Only a manager may force a punch through the fence (C1 rule); the alert goes to THEIR manager.
        $boss = $this->staff(['attendance.view_team', 'attendance.manage']);
        $u = $this->staff(['attendance.manage'], $boss->id);
        Sanctum::actingAs($u);
        $far = self::LAT + 2000 / 111_320;
        $this->postJson('/api/v1/admin/time-clock/clock-in', ['outlet_id' => $this->outlet->id, 'latitude' => $far, 'longitude' => self::LNG, 'accuracy' => 8])->assertStatus(422);
        $this->postJson('/api/v1/admin/time-clock/clock-in', ['outlet_id' => $this->outlet->id, 'latitude' => $far, 'longitude' => self::LNG, 'accuracy' => 8, 'force' => true, 'reason' => 'Delivery run'])->assertCreated()->assertJsonPath('entry.clock_in_method', 'override');
        Notification::assertSentTo($boss, InAppNotification::class, fn ($n) => str_contains($n->title, 'outside the zone') && str_contains($n->body, 'Delivery run'));
    }

    public function test_overtime_soon_alert_uses_the_pay_groups_hours_for_the_weekday_once(): void
    {
        PayGroup::defaultGroup()->update(['standard_hours_per_day' => 8, 'hours_by_weekday' => ['6' => 6]]);
        $boss = $this->staff(['attendance.view_team', 'attendance.manage']);
        $u = $this->staff([], $boss->id);
        // Saturday 7 March: 6 regular hours; threshold 30 min → alert from 5 h 30 in.
        $e = TimeEntry::factory()->create(['user_id' => $u->id, 'outlet_id' => $this->outlet->id, 'clock_in_at' => Carbon::parse('2026-03-07 08:00:00'), 'clock_out_at' => null, 'worked_minutes' => null, 'status' => 'active']);
        Carbon::setTestNow('2026-03-07 13:00:00');
        $this->artisan('attendance:auto-clock-out')->assertExitCode(0);
        Notification::assertNothingSentTo($boss);
        Carbon::setTestNow('2026-03-07 13:31:00');
        $this->artisan('attendance:auto-clock-out')->assertExitCode(0);
        Notification::assertSentTo($boss, InAppNotification::class, fn ($n) => str_contains($n->title, 'reaches overtime in') && $n->data['automation'] === 'overtime_soon');
        $this->assertNotNull($e->fresh()->overtime_alerted_at);
        $this->artisan('attendance:auto-clock-out')->assertExitCode(0);
        Notification::assertSentToTimes($boss, InAppNotification::class, 1);
    }

    public function test_document_expiring_follows_the_automation_switch_window_and_recipients(): void
    {
        $hr = $this->staff(['hr.edit']);
        $boss = $this->staff(['attendance.view_team', 'attendance.manage']);
        $u = $this->staff([], $boss->id);
        StaffDocument::create(['user_id' => $u->id, 'kind' => 'contract', 'title' => 'Contract', 'path' => 'p', 'expires_at' => now()->addDays(20)->toDateString()]);
        Automations::save('document_expiring', ['threshold' => 10]);
        Artisan::call('hr:document-expiry');
        Notification::assertNothingSent();                       // 20 days away, window 10
        Automations::save('document_expiring', ['threshold' => 30, 'recipients' => 'owner', 'extra_user_ids' => [$boss->id]]);
        Artisan::call('hr:document-expiry');
        Notification::assertSentTo($boss, InAppNotification::class, fn ($n) => $n->title === '1 staff document expiring');
        Notification::assertNothingSentTo($hr);
        Automations::save('document_expiring', ['enabled' => false]);
        Artisan::call('hr:document-expiry');
        Notification::assertSentToTimes($boss, InAppNotification::class, 1);
    }

    public function test_kiosk_is_set_up_by_a_manager_with_step_up_and_its_token_reaches_only_the_kiosk(): void
    {
        $boss = $this->staff(['attendance.view_team', 'attendance.manage']);
        Sanctum::actingAs($boss);
        $this->postJson('/api/v1/admin/attendance/kiosks', ['outlet_id' => $this->outlet->id, 'name' => 'Front door'])->assertStatus(403); // step-up first
        $this->stepUp($boss);
        $r = $this->postJson('/api/v1/admin/attendance/kiosks', ['outlet_id' => $this->outlet->id, 'name' => 'Front door'])->assertCreated();
        $token = $r->json('token');
        $this->assertNotEmpty($token);
        $this->getJson('/api/v1/admin/attendance/kiosks')->assertOk()->assertJsonPath('data.0.name', 'Front door')->assertJsonPath('data.0.outlet_id', $this->outlet->id);
        // The kiosk token: the board yes, the manager's hub no.
        $this->flushHeaders(); app('auth')->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/admin/kiosk/board')->assertOk()->assertJsonPath('kiosk.name', 'Front door');
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/admin/attendance/entries')->assertStatus(403);
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/admin/auth/me')->assertStatus(403);
        // A manager removes it; the tablet is refused next time.
        $id = $r->json('kiosk.id');
        Sanctum::actingAs($boss);
        $this->deleteJson("/api/v1/admin/attendance/kiosks/{$id}")->assertOk();
        $this->flushHeaders(); app('auth')->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/admin/kiosk/board')->assertStatus(401);
    }

    public function test_kiosk_punch_needs_the_persons_pin_keeps_a_photo_and_applies_the_shift_rules(): void
    {
        $boss = $this->staff(['attendance.view_team', 'attendance.manage']);
        $u = $this->staff([], $boss->id);
        $other = $this->staff([], null, Outlet::factory()->create(['is_active' => true]));
        Carbon::setTestNow('2026-03-02 08:00:00');
        Sanctum::actingAs($boss, ['kiosk']);
        $tokenRow = $boss->createToken("kiosk:{$this->outlet->id}:Door", ['kiosk'])->accessToken;
        $boss->withAccessToken($tokenRow);

        $board = $this->getJson('/api/v1/admin/kiosk/board')->assertOk();
        $row = collect($board->json('people'))->firstWhere('id', $u->id);
        $this->assertSame(['out', false], [$row['state'], $row['has_pin']]);
        $this->assertNull(collect($board->json('people'))->firstWhere('id', $other->id), 'another outlet\'s person is not on this board');

        $this->postJson('/api/v1/admin/kiosk/punch', ['user_id' => $u->id, 'pin' => '2580', 'action' => 'in'])->assertStatus(422)->assertJsonFragment(['message' => "{$u->first_name} has no PIN yet. Set one under Profile › Security on your own phone or computer."]);
        app(TerminalPin::class)->set($u, '2580');
        $this->postJson('/api/v1/admin/kiosk/punch', ['user_id' => $u->id, 'pin' => '1111', 'action' => 'in'])->assertStatus(422);
        $this->postJson('/api/v1/admin/kiosk/punch', ['user_id' => $other->id, 'pin' => '2580', 'action' => 'in'])->assertStatus(422);
        $this->postJson('/api/v1/admin/kiosk/punch', ['user_id' => $u->id, 'pin' => '2580', 'action' => 'in', 'photo' => $this->jpeg()])->assertCreated();
        $e = TimeEntry::where('user_id', $u->id)->first();
        $this->assertSame(['kiosk', 'active', $this->outlet->id], [$e->clock_in_method, $e->status, $e->outlet_id]);
        $this->assertNull($e->clock_in_latitude);
        Storage::disk('private')->assertExists($e->clock_in_photo_path);
        $this->postJson('/api/v1/admin/kiosk/punch', ['user_id' => $u->id, 'pin' => '2580', 'action' => 'in'])->assertStatus(422); // already in
        $this->assertSame('in', collect($this->getJson('/api/v1/admin/kiosk/board')->json('people'))->firstWhere('id', $u->id)['state']);

        Carbon::setTestNow('2026-03-02 12:30:00');
        $this->postJson('/api/v1/admin/kiosk/punch', ['user_id' => $u->id, 'pin' => '2580', 'action' => 'out', 'photo' => 'data:image/png;base64,AAAA'])->assertOk()->assertJsonFragment(['message' => "{$u->first_name} clocked out at 12:30. Worked 4 h 30 min."]);
        $e = $e->fresh();
        $this->assertSame(['completed', 270], [$e->status, $e->worked_minutes]);
        $this->assertNull($e->clock_out_photo_path, 'a PNG is not kept');

        // The manager sees the photo through a signed link; a stranger to the outlet does not.
        Sanctum::actingAs($boss);
        $link = $this->getJson("/api/v1/admin/attendance/entries/{$e->id}/photo/in")->assertOk()->json('url');
        $this->getJson("/api/v1/admin/attendance/entries/{$e->id}/photo/out")->assertStatus(404);
        $this->flushHeaders(); app('auth')->forgetGuards();
        $this->get($link)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(str_replace('signature=', 'signature=0', $link))->assertStatus(403);

        // Shift rules apply at the kiosk: refuse without a shift.
        AttendanceRules::save(['clock_in_without_shift' => 'refuse']);
        $boss->withAccessToken($tokenRow); Sanctum::actingAs($boss, ['kiosk']); $boss->withAccessToken($tokenRow);
        $this->postJson('/api/v1/admin/kiosk/punch', ['user_id' => $u->id, 'pin' => '2580', 'action' => 'in'])->assertStatus(422)->assertJsonFragment(['message' => 'You have no shift on the roster today. Ask your manager to add one before clocking in.']);

        // Photos go with the trails after the retention period.
        Carbon::setTestNow('2026-07-01 03:40:00');
        $this->artisan('attendance:prune-trails')->assertExitCode(0);
        $this->assertNull($e->fresh()->clock_in_photo_path);
        Storage::disk('private')->assertMissing("attendance/photos/{$e->id}-in.jpg");
    }

    public function test_message_audiences_are_the_clocked_in_and_todays_roster_in_my_outlets(): void
    {
        $boss = $this->staff(['attendance.view_team']);
        $a = $this->staff(); $b = $this->staff();
        $elsewhere = $this->staff([], null, $o2 = Outlet::factory()->create(['is_active' => true]));
        Carbon::setTestNow('2026-03-02 10:00:00');
        TimeEntry::factory()->create(['user_id' => $a->id, 'outlet_id' => $this->outlet->id, 'clock_in_at' => now()->subHour(), 'clock_out_at' => null, 'worked_minutes' => null, 'status' => 'active']);
        TimeEntry::factory()->create(['user_id' => $elsewhere->id, 'outlet_id' => $o2->id, 'clock_in_at' => now()->subHour(), 'clock_out_at' => null, 'worked_minutes' => null, 'status' => 'active']);
        Shift::factory()->published()->create(['outlet_id' => $this->outlet->id, 'user_id' => $b->id, 'date' => '2026-03-02', 'starts_at' => '14:00', 'ends_at' => '20:00']);
        Shift::factory()->published()->create(['outlet_id' => $this->outlet->id, 'user_id' => $a->id, 'date' => '2026-03-02', 'starts_at' => '09:00', 'ends_at' => '17:00']);
        Sanctum::actingAs($boss);
        $r = $this->getJson('/api/v1/admin/channels/audiences')->assertOk();
        $this->assertSame([$a->id], collect($r->json('audiences.0.people'))->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], collect($r->json('audiences.1.people'))->pluck('id')->all());
        Sanctum::actingAs($a);
        $this->getJson('/api/v1/admin/channels/audiences')->assertStatus(403);
    }
}
