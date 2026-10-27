<?php

namespace Tests\Feature;

use App\Models\EmployeeProfile;
use App\Models\Outlet;
use App\Models\PayGroup;
use App\Models\PayPeriod;
use App\Models\PayStructure;
use App\Models\Post;
use App\Models\Timesheet;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\People\Payroll;
use App\Support\Automations;
use App\Support\Recognition;
use Carbon\Carbon;
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
use Tests\TestCase;

/**
 * Bethany People C3 — the Updates feed: posting and who may, must-read
 * acknowledgements and who has not read, scheduled posts, photos, comments,
 * recognitions on the feed and on the file with points payroll turns into
 * a line, celebrations from the file, events with RSVP, outlet audiences.
 */
class UpdatesTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('private');
        Artisan::call('permission:sync');
        Carbon::setTestNow('2026-03-10 09:00:00');
        $this->shop = Outlet::factory()->create(['is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(array $permissions = [], array $profile = [], ?Outlet $outlet = null): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        if ($permissions) {
            $role = Role::findOrCreate('probe_' . uniqid(), 'sanctum');
            foreach ($permissions as $p) $role->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
            $u->assignRole($role);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
        DB::table('outlet_user')->insert(['user_id' => $u->id, 'outlet_id' => ($outlet ?? $this->shop)->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        EmployeeProfile::factory()->create($profile + ['user_id' => $u->id, 'hire_date' => '2024-01-01']);
        return $u->fresh();
    }

    public function test_the_catalogue_grants_updates_post_to_the_managers(): void
    {
        foreach (['admin', 'outlet_manager', 'production_manager'] as $r) $this->assertTrue(Role::findByName($r, 'sanctum')->hasPermissionTo('updates.post'), $r);
        $this->assertFalse(Role::findByName('pos_clerk', 'sanctum')->hasPermissionTo('updates.post'));
    }

    public function test_a_manager_posts_a_must_read_everyone_is_told_and_the_manager_sees_who_has_not_acknowledged(): void
    {
        $boss = $this->staff(['updates.post']);
        $a = $this->staff(); $b = $this->staff();
        Sanctum::actingAs($boss);
        $r = $this->postJson('/api/v1/admin/updates', ['kind' => 'update', 'title' => 'New closing time', 'body' => 'From Monday we close at 19:00.', 'must_read' => true])->assertCreated();
        $id = $r->json('post.id');
        $this->assertSame(true, $r->json('post.must_read'));
        Notification::assertSentTo($a, InAppNotification::class, fn ($n) => $n->title === 'Must read: New closing time');
        Notification::assertSentTo($b, InAppNotification::class);
        Notification::assertNothingSentTo($boss);
        $this->assertDatabaseHas('activity_log', ['event' => 'update_posted']);

        // Staff: the feed shows it with one pending must-read; opening marks it read; the button acknowledges.
        Sanctum::actingAs($a);
        $feed = $this->getJson('/api/v1/admin/updates')->assertOk()->assertJsonPath('must_read_pending', 1)->assertJsonPath('can_post', false);
        $this->assertNull($feed->json('data.0.me.read_at'));
        $this->assertArrayNotHasKey('readers', $this->getJson("/api/v1/admin/updates/{$id}")->json('post'));
        $this->postJson("/api/v1/admin/updates/{$id}/read")->assertOk();
        $this->postJson("/api/v1/admin/updates/{$id}/acknowledge")->assertOk()->assertJsonPath('must_read_pending', 0);
        $this->postJson("/api/v1/admin/updates/{$id}/acknowledge")->assertOk(); // twice is fine
        $this->getJson("/api/v1/admin/updates/{$id}/readers")->assertStatus(403);
        $this->putJson("/api/v1/admin/updates/{$id}", ['title' => 'x'])->assertStatus(403);

        // The manager: who read, who acknowledged, who is pending.
        Sanctum::actingAs($boss);
        $readers = $this->getJson("/api/v1/admin/updates/{$id}/readers")->assertOk()->json('readers');
        $this->assertSame(2, $readers['audience']);
        $this->assertSame([$a->id], array_column($readers['acknowledged'], 'id'));
        $this->assertSame([$b->id], array_column($readers['pending'], 'id'));
        $this->assertArrayHasKey('readers', $this->getJson("/api/v1/admin/updates/{$id}")->json('post'));
        $this->deleteJson("/api/v1/admin/updates/{$id}")->assertOk();
        Sanctum::actingAs($a);
        $this->getJson("/api/v1/admin/updates/{$id}")->assertStatus(404);
    }

    public function test_staff_cannot_post_updates_but_may_recognise_a_colleague_without_points(): void
    {
        $a = $this->staff(); $b = $this->staff();
        Sanctum::actingAs($a);
        $this->postJson('/api/v1/admin/updates', ['kind' => 'update', 'title' => 'Hello'])->assertStatus(403);
        $this->postJson('/api/v1/admin/updates', ['kind' => 'recognition', 'recipient_id' => $b->id, 'body' => 'Stayed late to finish the order', 'points' => 5])->assertStatus(422);
        $this->postJson('/api/v1/admin/updates', ['kind' => 'recognition', 'recipient_id' => $a->id, 'body' => 'me'])->assertStatus(422);
        $this->postJson('/api/v1/admin/updates', ['kind' => 'recognition', 'recipient_id' => $b->id, 'body' => 'Stayed late to finish the order'])->assertCreated()->assertJsonPath('post.points', null);
        Notification::assertSentTo($b, InAppNotification::class, fn ($n) => str_contains($n->title, 'recognised you'));
        Notification::assertNothingSentTo($a);
    }

    public function test_recognition_points_show_on_the_file_and_become_a_payslip_line_only_at_a_set_value(): void
    {
        PayGroup::query()->update(['period_end_day' => null]);
        config(['attendance.working_days' => [1, 2, 3, 4, 5, 6]]);
        $boss = $this->staff(['updates.post', 'hr.view', 'attendance.view_team']);
        $u = $this->staff();
        Sanctum::actingAs($boss);
        $this->postJson('/api/v1/admin/updates', ['kind' => 'recognition', 'recipient_id' => $u->id, 'body' => 'Perfect stock count', 'points' => 10])->assertCreated()->assertJsonPath('post.points', 10);
        $this->postJson('/api/v1/admin/updates', ['kind' => 'recognition', 'recipient_id' => $u->id, 'body' => 'Helped at the till', 'points' => 5])->assertCreated();
        $file = $this->getJson("/api/v1/admin/people/{$u->id}")->assertOk()->json('person');
        $this->assertSame(15, $file['recognition_points']);
        $this->assertCount(2, $file['recognitions']);
        $this->assertSame('Helped at the till', $file['recognitions'][0]['body']);
        Sanctum::actingAs($u);
        $this->assertSame(15, $this->getJson('/api/v1/admin/me/file')->assertOk()->json('person.recognition_points'));

        // Payroll: nothing while a point is worth nothing; a taxable line once it is.
        $p = PayGroup::defaultGroup()->periodFor(Carbon::parse('2026-03-10'));
        $p->update(['status' => 'locked', 'locked_at' => now()]);
        Timesheet::create(['pay_period_id' => $p->id, 'user_id' => $u->id, 'status' => 'approved', 'approved_at' => now(), 'snapshot' => true, 'regular_minutes' => 25 * 480, 'overtime_minutes' => 0, 'rest_day_minutes' => 0, 'holiday_minutes' => 0, 'paid_leave_days' => 0, 'unpaid_leave_days' => 0, 'days_worked' => 25]);
        PayStructure::create(['user_id' => $u->id, 'effective_from' => '2026-01-01', 'kind' => 'monthly', 'base_amount' => 60000, 'currency_code' => 'KES']);
        $this->assertSame([], Payroll::recognitionExtras($u, $p->fresh()));
        $run = Payroll::draft($p->fresh(), $boss);
        $l = $run->lines()->first();
        $this->assertEquals(60000, $l->gross);
        $this->assertSame(0, count(array_filter($l->items, fn ($i) => str_starts_with($i['label'], 'Recognition'))));

        Sanctum::actingAs($this->staff(['attendance.view_team', 'settings.edit']));
        $this->putJson('/api/v1/admin/attendance/recognition-points', ['kes' => 200])->assertOk()->assertJsonPath('recognition_point_kes', 200);
        $this->assertSame(200.0, Recognition::pointValue());
        $run->lines()->delete(); $run->forceDelete();                        // one regular run per period: redraft from scratch
        $this->assertSame([['label' => 'Recognition 15 points × 200.00', 'amount' => 3000.0]], Payroll::recognitionExtras($u, $p->fresh()));
        $run = Payroll::draft($p->fresh(), $boss);
        $l = $run->lines()->first();
        $this->assertEquals(63000, $l->gross);
        $this->assertEquals(60000, $l->basic);
        $this->assertGreaterThan(53850, (float) $l->taxable, 'the points are taxable');
        $this->assertContains(['label' => 'Recognition 15 points × 200.00', 'amount' => 3000, 'kind' => 'earning'], $l->items);
        $this->assertSame([], Payroll::reconcile($run->fresh(['lines', 'expense'])));
        // A recognition published in another period is not in this one.
        Post::where('kind', 'recognition')->update(['published_at' => Carbon::parse('2026-04-02')]);
        $this->assertSame([], Payroll::recognitionExtras($u, $p->fresh()));
    }

    public function test_scheduled_posts_go_out_on_time_and_not_before(): void
    {
        $boss = $this->staff(['updates.post']);
        $a = $this->staff();
        Sanctum::actingAs($boss);
        $r = $this->postJson('/api/v1/admin/updates', ['kind' => 'update', 'title' => 'Stock take Friday', 'publish_at' => '2026-03-12 07:00:00'])->assertCreated();
        $this->assertNull($r->json('post.published_at'));
        $this->assertSame(1, $this->getJson('/api/v1/admin/updates')->json('scheduled'));
        $this->assertCount(1, $this->getJson('/api/v1/admin/updates/scheduled')->json('data'));
        Notification::assertNothingSentTo($a);
        Sanctum::actingAs($a);
        $this->assertCount(0, $this->getJson('/api/v1/admin/updates')->json('data'));
        $this->getJson('/api/v1/admin/updates/scheduled')->assertStatus(403);
        $this->artisan('updates:publish')->assertExitCode(0);
        Notification::assertNothingSentTo($a);
        Carbon::setTestNow('2026-03-12 07:05:00');
        $this->artisan('updates:publish')->assertExitCode(0);
        Notification::assertSentTo($a, InAppNotification::class, fn ($n) => $n->title === 'Stock take Friday');
        $this->assertCount(1, $this->getJson('/api/v1/admin/updates')->json('data'));
        $this->artisan('updates:publish')->assertExitCode(0);
        Notification::assertSentToTimes($a, InAppNotification::class, 1);
    }

    public function test_a_photo_is_kept_on_the_private_disk_and_served_through_a_signed_link(): void
    {
        $boss = $this->staff(['updates.post']);
        $a = $this->staff();
        $stranger = $this->staff([], [], Outlet::factory()->create(['is_active' => true]));
        Sanctum::actingAs($boss);
        $r = $this->post('/api/v1/admin/updates', ['kind' => 'update', 'title' => 'New window display', 'outlet_id' => $this->shop->id, 'photo' => UploadedFile::fake()->image('display.jpg', 640, 480)], ['Accept' => 'application/json'])->assertCreated();
        $id = $r->json('post.id');
        $this->assertTrue($r->json('post.has_photo'));
        $this->assertCount(1, Storage::disk('private')->allFiles('updates'));
        Notification::assertSentTo($a, InAppNotification::class);
        Notification::assertNothingSentTo($stranger);
        Sanctum::actingAs($a);
        $link = $this->getJson("/api/v1/admin/updates/{$id}/photo")->assertOk()->json('url');
        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/admin/updates/{$id}")->assertStatus(404);
        $this->getJson("/api/v1/admin/updates/{$id}/photo")->assertStatus(404);
        $this->flushHeaders(); app('auth')->forgetGuards();
        $this->get($link)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(str_replace('signature=', 'signature=0', $link))->assertStatus(403);
    }

    public function test_comments_tell_the_author_and_can_be_switched_off(): void
    {
        $boss = $this->staff(['updates.post']);
        $a = $this->staff();
        Sanctum::actingAs($boss);
        $open = $this->postJson('/api/v1/admin/updates', ['kind' => 'update', 'title' => 'Open'])->json('post.id');
        $closed = $this->postJson('/api/v1/admin/updates', ['kind' => 'update', 'title' => 'Closed', 'comments_enabled' => false])->json('post.id');
        Sanctum::actingAs($a);
        $c = $this->postJson("/api/v1/admin/updates/{$open}/comments", ['body' => 'Great news'])->assertCreated()->json('comment.id');
        Notification::assertSentTo($boss, InAppNotification::class, fn ($n) => str_contains($n->title, 'commented'));
        $this->postJson("/api/v1/admin/updates/{$closed}/comments", ['body' => 'x'])->assertStatus(422);
        $this->assertSame(1, $this->getJson("/api/v1/admin/updates/{$open}")->json('post.comments_count'));
        $this->assertSame('Great news', $this->getJson("/api/v1/admin/updates/{$open}")->json('post.comments.0.body'));
        Sanctum::actingAs($this->staff());
        $this->deleteJson("/api/v1/admin/updates/{$open}/comments/{$c}")->assertStatus(403);
        Sanctum::actingAs($a);
        $this->deleteJson("/api/v1/admin/updates/{$open}/comments/{$c}")->assertOk();
    }

    public function test_events_take_an_rsvp_and_count_it(): void
    {
        $boss = $this->staff(['updates.post']);
        $a = $this->staff(); $b = $this->staff();
        Sanctum::actingAs($boss);
        $this->postJson('/api/v1/admin/updates', ['kind' => 'event', 'title' => 'Fire drill'])->assertStatus(422);
        $id = $this->postJson('/api/v1/admin/updates', ['kind' => 'event', 'title' => 'Fire drill', 'event_at' => '2026-03-14 10:00:00', 'event_place' => 'Car park'])->assertCreated()->json('post.id');
        Notification::assertSentTo($a, InAppNotification::class, fn ($n) => str_starts_with($n->title, 'Event: Fire drill'));
        Sanctum::actingAs($a);
        $this->postJson("/api/v1/admin/updates/{$id}/rsvp", ['answer' => 'perhaps'])->assertStatus(422);
        $this->postJson("/api/v1/admin/updates/{$id}/rsvp", ['answer' => 'yes'])->assertOk()->assertJsonPath('rsvp.yes', 1);
        $this->postJson("/api/v1/admin/updates/{$id}/rsvp", ['answer' => 'maybe'])->assertOk()->assertJsonPath('rsvp.yes', 0)->assertJsonPath('rsvp.maybe', 1);
        $this->assertSame('maybe', $this->getJson('/api/v1/admin/updates')->json('data.0.me.rsvp'));
        Sanctum::actingAs($b);
        $this->postJson("/api/v1/admin/updates/{$id}/rsvp", ['answer' => 'yes'])->assertOk();
        Sanctum::actingAs($boss);
        $readers = $this->getJson("/api/v1/admin/updates/{$id}/readers")->json('readers');
        $this->assertSame(['yes' => 1, 'no' => 0, 'maybe' => 1], $readers['rsvp']);
        $this->assertSame(1, $this->getJson('/api/v1/admin/updates')->json('data.0.rsvp_yes_count'));
        $upd = $this->postJson('/api/v1/admin/updates', ['kind' => 'update', 'title' => 'Not an event'])->json('post.id');
        $this->postJson("/api/v1/admin/updates/{$upd}/rsvp", ['answer' => 'yes'])->assertStatus(422);
    }

    public function test_celebrations_come_from_the_file_once_a_year_and_can_be_switched_off(): void
    {
        $bday = $this->staff([], ['date_of_birth' => '1990-03-10']);
        $anniv = $this->staff([], ['hire_date' => '2023-03-10']);
        $new = $this->staff([], ['hire_date' => '2026-03-10']);          // joined today: no anniversary yet
        $gone = $this->staff([], ['date_of_birth' => '1985-03-10']); $gone->update(['status' => 'inactive']);
        $leap = $this->staff([], ['date_of_birth' => '1992-02-29']);
        $this->artisan('people:celebrations')->assertExitCode(0);
        $posts = Post::where('kind', 'celebration')->get();
        $this->assertCount(2, $posts);
        $this->assertSame("Happy birthday, {$bday->first_name}! 🎂", $posts->firstWhere('recipient_id', $bday->id)->title);
        $this->assertSame('3 years at Bethany House 🎉', $posts->firstWhere('recipient_id', $anniv->id)->title);
        $this->assertNull($posts->firstWhere('recipient_id', $new->id));
        $this->artisan('people:celebrations')->assertExitCode(0);
        $this->assertSame(2, Post::where('kind', 'celebration')->count(), 'once a year');
        Notification::assertNothingSent();
        Sanctum::actingAs($new);
        $this->assertSame('celebration', $this->getJson('/api/v1/admin/updates')->json('data.0.kind'));
        // 29 February birthdays are posted on the 28th in other years.
        $this->artisan('people:celebrations', ['--date' => '2026-02-28'])->assertExitCode(0);
        $this->assertNotNull(Post::where('celebration_key', "birthday:{$leap->id}:2026")->first());
        Automations::save('celebrations', ['enabled' => false]);
        $this->artisan('people:celebrations', ['--date' => '2026-03-10'])->assertExitCode(0);
        $this->assertSame(3, Post::where('kind', 'celebration')->count());
        $this->assertFalse(Automations::get('celebrations')['has_recipients']);
    }

    public function test_an_outlet_post_reaches_that_outlet_and_outlets_all_access(): void
    {
        $other = Outlet::factory()->create(['is_active' => true]);
        $boss = $this->staff(['updates.post']);
        $here = $this->staff(); $there = $this->staff([], [], $other);
        $hq = $this->staff(['outlets.all_access'], [], $other);
        Sanctum::actingAs($boss);
        $id = $this->postJson('/api/v1/admin/updates', ['kind' => 'update', 'title' => 'Shop only', 'outlet_id' => $this->shop->id])->assertCreated()->json('post.id');
        Notification::assertSentTo($here, InAppNotification::class);
        Notification::assertNothingSentTo($there);
        Sanctum::actingAs($there);
        $this->assertCount(0, $this->getJson('/api/v1/admin/updates')->json('data'));
        Sanctum::actingAs($hq);
        $this->assertCount(1, $this->getJson('/api/v1/admin/updates')->json('data'));
        Sanctum::actingAs($boss);
        $this->assertSame(1, $this->getJson("/api/v1/admin/updates/{$id}/readers")->json('readers.audience'));
    }
}
