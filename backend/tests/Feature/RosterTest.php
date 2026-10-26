<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\Shift;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Bethany People PR 3 — shifts, the weekly roster, open shifts and swaps. */
class RosterTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $shop;
    private Outlet $other;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        $this->shop  = Outlet::factory()->create();
        $this->other = Outlet::factory()->create();
    }

    private function staff(array $permissions = [], ?Outlet $outlet = null): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        if ($permissions) {
            $role = Role::findOrCreate('probe_' . uniqid(), 'sanctum');
            foreach ($permissions as $p) $role->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
            $u->assignRole($role);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
        DB::table('outlet_user')->insert(['user_id' => $u->id, 'outlet_id' => ($outlet ?? $this->shop)->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        return $u->fresh();
    }

    private function manager(): User
    {
        return $this->staff(['attendance.view_team', 'roster.manage']);
    }

    private function monday(int $weeksAhead = 1): string
    {
        return now()->addWeeks($weeksAhead)->startOfWeek()->toDateString();
    }

    public function test_week_is_scoped_and_lists_members_shifts_and_holidays(): void
    {
        $a = $this->staff(); $this->staff([], $this->other);
        Shift::factory()->create(['outlet_id' => $this->shop->id, 'user_id' => $a->id, 'date' => $this->monday()]);
        Shift::factory()->open()->create(['outlet_id' => $this->shop->id, 'date' => $this->monday(), 'status' => 'published']);
        Shift::factory()->create(['outlet_id' => $this->shop->id, 'user_id' => $a->id, 'date' => $this->monday(), 'starts_at' => '18:00', 'ends_at' => '20:00', 'status' => 'cancelled']);

        Sanctum::actingAs($this->staff(['attendance.view_team']));
        $r = $this->getJson('/api/v1/admin/roster?outlet_id=' . $this->shop->id . '&week=' . $this->monday())->assertOk();
        $this->assertSame($this->monday(), $r->json('week_start'));
        $this->assertCount(7, $r->json('days'));
        $this->assertCount(2, $r->json('shifts'), 'cancelled shifts are not shown');
        $this->assertSame(1, $r->json('open_count'));
        $this->assertSame(1, $r->json('draft_count'));
        $this->assertEquals(9, $r->json("hours_by_user.{$a->id}"));
        $this->assertFalse($r->json('can_manage'));
        $this->assertTrue(collect($r->json('members'))->pluck('id')->contains($a->id));

        $this->getJson('/api/v1/admin/roster?outlet_id=' . $this->other->id)->assertStatus(403);
        Sanctum::actingAs($this->staff());
        $this->getJson('/api/v1/admin/roster?outlet_id=' . $this->shop->id)->assertStatus(403);
    }

    public function test_manager_drafts_refuses_overlap_and_publishes_with_one_notice_per_person(): void
    {
        $a = $this->staff();
        $m = $this->manager();
        Sanctum::actingAs($m);
        $base = ['outlet_id' => $this->shop->id, 'user_id' => $a->id, 'date' => $this->monday()];

        $this->postJson('/api/v1/admin/roster/shifts', $base + ['starts_at' => '08:00', 'ends_at' => '13:00'])->assertStatus(201)->assertJsonPath('shift.status', 'draft');
        $this->postJson('/api/v1/admin/roster/shifts', $base + ['starts_at' => '12:00', 'ends_at' => '17:00'])->assertStatus(422);
        $this->postJson('/api/v1/admin/roster/shifts', $base + ['starts_at' => '13:00', 'ends_at' => '17:00'])->assertStatus(201);
        $this->postJson('/api/v1/admin/roster/shifts', $base + ['starts_at' => '09:00', 'ends_at' => '09:00'])->assertStatus(422);
        // Not a member of the outlet.
        $stranger = $this->staff([], $this->other);
        $this->postJson('/api/v1/admin/roster/shifts', $base + ['user_id' => $stranger->id, 'starts_at' => '08:00', 'ends_at' => '10:00'])->assertStatus(422);
        // Overnight: 22:00–06:00 is 8 hours and ends next day.
        $r = $this->postJson('/api/v1/admin/roster/shifts', ['outlet_id' => $this->shop->id, 'date' => $this->monday(), 'starts_at' => '22:00', 'ends_at' => '06:00'])->assertStatus(201);
        $this->assertSame(480, $r->json('shift.minutes'));
        $this->assertTrue($r->json('shift.overnight'));

        Notification::assertNothingSent();
        $p = $this->postJson('/api/v1/admin/roster/publish', ['outlet_id' => $this->shop->id, 'week' => $this->monday()])->assertOk();
        $this->assertSame(3, $p->json('published'));
        $this->assertSame(0, Shift::where('status', 'draft')->count());
        Notification::assertSentTo($a, InAppNotification::class, fn ($n) => str_contains($n->title, '2 shifts'));
        Notification::assertCount(1);

        // Publishing again does nothing.
        $this->assertSame(0, $this->postJson('/api/v1/admin/roster/publish', ['outlet_id' => $this->shop->id, 'week' => $this->monday()])->json('published'));
    }

    public function test_staff_cannot_write_the_roster(): void
    {
        Sanctum::actingAs($this->staff(['attendance.view_team']));
        $this->postJson('/api/v1/admin/roster/shifts', ['outlet_id' => $this->shop->id, 'date' => $this->monday(), 'starts_at' => '08:00', 'ends_at' => '12:00'])->assertStatus(403);
        $this->postJson('/api/v1/admin/roster/publish', ['outlet_id' => $this->shop->id])->assertStatus(403);
    }

    public function test_editing_and_cancelling_a_published_shift_tells_the_people_concerned(): void
    {
        $a = $this->staff(); $b = $this->staff();
        $s = Shift::factory()->published()->create(['outlet_id' => $this->shop->id, 'user_id' => $a->id, 'date' => $this->monday()]);
        Sanctum::actingAs($this->manager());

        $this->putJson("/api/v1/admin/roster/shifts/{$s->id}", ['user_id' => $b->id])->assertOk();
        Notification::assertSentTo($a, InAppNotification::class, fn ($n) => $n->title === 'A shift was taken off your roster');
        Notification::assertSentTo($b, InAppNotification::class, fn ($n) => $n->title === 'You have a new shift');

        $this->deleteJson("/api/v1/admin/roster/shifts/{$s->id}")->assertOk()->assertJsonPath('shift.status', 'cancelled');
        Notification::assertSentTo($b, InAppNotification::class, fn ($n) => $n->title === 'Shift cancelled');
        $this->assertDatabaseHas('shifts', ['id' => $s->id, 'status' => 'cancelled']);

        // A draft is simply deleted.
        $d = Shift::factory()->create(['outlet_id' => $this->shop->id, 'user_id' => $a->id, 'date' => $this->monday(2)]);
        $this->deleteJson("/api/v1/admin/roster/shifts/{$d->id}")->assertOk();
        $this->assertDatabaseMissing('shifts', ['id' => $d->id]);
    }

    public function test_copy_week_is_idempotent_and_opens_shifts_of_people_who_left(): void
    {
        $a = $this->staff(); $gone = $this->staff();
        Shift::factory()->published()->create(['outlet_id' => $this->shop->id, 'user_id' => $a->id, 'date' => $this->monday(1)]);
        Shift::factory()->published()->create(['outlet_id' => $this->shop->id, 'user_id' => $gone->id, 'date' => $this->monday(1), 'starts_at' => '13:00', 'ends_at' => '18:00']);
        Shift::factory()->create(['outlet_id' => $this->shop->id, 'user_id' => $a->id, 'date' => $this->monday(1), 'starts_at' => '19:00', 'ends_at' => '21:00', 'status' => 'cancelled']);
        DB::table('outlet_user')->where('user_id', $gone->id)->delete();

        Sanctum::actingAs($this->manager());
        $r = $this->postJson('/api/v1/admin/roster/copy-week', ['outlet_id' => $this->shop->id, 'from_week' => $this->monday(1), 'to_week' => $this->monday(2)])->assertOk();
        $this->assertSame(2, $r->json('copied'), 'cancelled shifts are not copied');
        $copies = Shift::where('date', $this->monday(2))->get();
        $this->assertSame(['draft', 'draft'], $copies->pluck('status')->all());
        $this->assertTrue($copies->contains(fn ($s) => $s->user_id === null), 'the leaver\'s shift became open');

        $this->assertSame(0, $this->postJson('/api/v1/admin/roster/copy-week', ['outlet_id' => $this->shop->id, 'from_week' => $this->monday(1), 'to_week' => $this->monday(2)])->json('copied'));
        $this->postJson('/api/v1/admin/roster/copy-week', ['outlet_id' => $this->shop->id, 'from_week' => $this->monday(1), 'to_week' => $this->monday(1)])->assertStatus(422);
    }

    public function test_me_sees_own_published_shifts_and_claims_an_open_one_once(): void
    {
        $me = $this->staff(); $rival = $this->staff();
        Shift::factory()->published()->create(['outlet_id' => $this->shop->id, 'user_id' => $me->id, 'date' => now()->toDateString(), 'starts_at' => '23:00', 'ends_at' => '23:30']);
        Shift::factory()->create(['outlet_id' => $this->shop->id, 'user_id' => $me->id, 'date' => $this->monday(2)]); // draft: invisible to me
        $open = Shift::factory()->published()->open()->create(['outlet_id' => $this->shop->id, 'date' => $this->monday(), 'starts_at' => '09:00', 'ends_at' => '12:00']);
        $elsewhere = Shift::factory()->published()->open()->create(['outlet_id' => $this->other->id, 'date' => $this->monday()]);

        Sanctum::actingAs($me);
        $r = $this->getJson('/api/v1/admin/me/shifts')->assertOk();
        $this->assertCount(1, $r->json('shifts'));
        $this->assertNotNull($r->json('today'));
        $this->assertSame([$open->id], collect($r->json('open_shifts'))->pluck('id')->all());

        $this->postJson("/api/v1/admin/me/shifts/{$elsewhere->id}/claim")->assertStatus(403);
        $this->postJson("/api/v1/admin/me/shifts/{$open->id}/claim")->assertOk();
        $this->assertSame($me->id, $open->fresh()->user_id);

        Sanctum::actingAs($rival);
        $this->postJson("/api/v1/admin/me/shifts/{$open->id}/claim")->assertStatus(422);
    }

    public function test_swap_request_goes_to_managers_and_the_decision_moves_the_shift(): void
    {
        $me = $this->staff(); $col = $this->staff(); $m = $this->manager();
        $s = Shift::factory()->published()->create(['outlet_id' => $this->shop->id, 'user_id' => $me->id, 'date' => $this->monday()]);

        Sanctum::actingAs($col);
        $this->postJson("/api/v1/admin/me/shifts/{$s->id}/swap")->assertStatus(403);

        Sanctum::actingAs($me);
        $this->postJson("/api/v1/admin/me/shifts/{$s->id}/swap", ['to_user_id' => $me->id])->assertStatus(422);
        $r = $this->postJson("/api/v1/admin/me/shifts/{$s->id}/swap", ['to_user_id' => $col->id, 'note' => 'Clinic that morning'])->assertStatus(201);
        Notification::assertSentTo($m, InAppNotification::class, fn ($n) => str_contains($n->title, 'asks to swap'));
        $this->postJson("/api/v1/admin/me/shifts/{$s->id}/swap", ['to_user_id' => $col->id])->assertStatus(422);
        $this->assertCount(1, $this->getJson('/api/v1/admin/me/shifts')->json('pending_swaps'));

        Sanctum::actingAs($m);
        $this->assertSame($r->json('request.id'), $this->getJson('/api/v1/admin/roster/swaps')->json('data.0.id'));
        $this->postJson("/api/v1/admin/roster/swaps/{$r->json('request.id')}/approve")->assertOk();
        $this->assertSame($col->id, $s->fresh()->user_id);
        $this->assertSame('approved', ShiftSwapRequest::find($r->json('request.id'))->status);
        Notification::assertSentTo($me, InAppNotification::class, fn ($n) => $n->title === 'Swap approved');
        Notification::assertSentTo($col, InAppNotification::class, fn ($n) => $n->title === 'You have a new shift');
        $this->postJson("/api/v1/admin/roster/swaps/{$r->json('request.id')}/approve")->assertStatus(422);

        // Decline path, and withdraw.
        $s2 = Shift::factory()->published()->create(['outlet_id' => $this->shop->id, 'user_id' => $me->id, 'date' => $this->monday(2)]);
        Sanctum::actingAs($me);
        $r2 = $this->postJson("/api/v1/admin/me/shifts/{$s2->id}/swap")->assertStatus(201);
        Sanctum::actingAs($m);
        $this->postJson("/api/v1/admin/roster/swaps/{$r2->json('request.id')}/decline", ['note' => 'Nobody free'])->assertOk();
        $this->assertSame($me->id, $s2->fresh()->user_id);
        Sanctum::actingAs($me);
        $r3 = $this->postJson("/api/v1/admin/me/shifts/{$s2->id}/swap")->assertStatus(201);
        $this->deleteJson("/api/v1/admin/me/swaps/{$r3->json('request.id')}")->assertOk();
        $this->assertSame('withdrawn', ShiftSwapRequest::find($r3->json('request.id'))->status);
    }

    public function test_reminder_fires_once_an_hour_ahead(): void
    {
        $me = $this->staff();
        $soon = now()->addMinutes(40);
        $s = Shift::factory()->published()->create(['outlet_id' => $this->shop->id, 'user_id' => $me->id, 'date' => $soon->toDateString(), 'starts_at' => $soon->format('H:i'), 'ends_at' => $soon->copy()->addHours(4)->format('H:i')]);
        $far = now()->addHours(5);
        Shift::factory()->published()->create(['outlet_id' => $this->shop->id, 'user_id' => $me->id, 'date' => $far->toDateString(), 'starts_at' => $far->format('H:i'), 'ends_at' => $far->copy()->addHours(2)->format('H:i')]);

        Artisan::call('roster:remind');
        Notification::assertSentTo($me, InAppNotification::class, fn ($n) => str_starts_with($n->title, 'Shift at'));
        Notification::assertCount(1);
        $this->assertNotNull($s->fresh()->reminded_at);
        Artisan::call('roster:remind');
        Notification::assertCount(1);
    }
}
