<?php

namespace Tests\Feature;

use App\Models\EmployeeProfile;
use App\Models\Outlet;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\InAppNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Bethany People C5 — the staff directory (each person's own switches) and to-dos (own, given, the team's). */
class DirectoryTodosTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        Carbon::setTestNow('2026-03-10 10:00:00');
        $this->shop = Outlet::factory()->create(['is_active' => true]);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function staff(array $permissions = [], array $profile = [], ?Outlet $outlet = null): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff', 'phone' => '+2547' . random_int(10000000, 99999999)]);
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

    public function test_the_directory_shows_colleagues_and_each_person_controls_phone_email_and_birthday(): void
    {
        $a = $this->staff([], ['job_title' => 'Senior Tailor', 'department' => 'Workshop', 'date_of_birth' => '1990-03-10']);
        $b = $this->staff([], ['job_title' => 'Cutter', 'department' => 'Workshop']);
        $gone = $this->staff(); $gone->update(['status' => 'inactive']);
        Sanctum::actingAs($b);
        $r = $this->getJson('/api/v1/admin/directory')->assertOk();
        $rows = collect($r->json('data'));
        $this->assertSame([$a->id, $b->id], $rows->pluck('id')->sort()->values()->all());
        $card = $rows->firstWhere('id', $a->id);
        $this->assertSame(['Senior Tailor', 'Workshop', $a->phone, $a->email, null], [$card['job_title'], $card['department'], $card['phone'], $card['email'], $card['birthday']]);
        $this->assertSame(['Workshop'], $r->json('departments'));
        $this->assertSame(['Senior Tailor'], collect($this->getJson('/api/v1/admin/directory?q=tailor')->json('data'))->pluck('job_title')->all());
        // A hides the phone and shows the birthday; B sees that; A still sees their own phone.
        Sanctum::actingAs($a);
        $this->putJson('/api/v1/admin/me/directory', ['show_phone' => false, 'show_birthday' => true])->assertOk()->assertJsonPath('me.show_phone', false)->assertJsonPath('me.show_birthday', true);
        $this->assertSame($a->phone, collect($this->getJson('/api/v1/admin/directory')->json('data'))->firstWhere('id', $a->id)['phone']);
        Sanctum::actingAs($b);
        $card = collect($this->getJson('/api/v1/admin/directory')->json('data'))->firstWhere('id', $a->id);
        $this->assertSame([null, $a->email, '10 Mar'], [$card['phone'], $card['email'], $card['birthday']]);
        $this->assertDatabaseHas('activity_log', ['event' => 'directory_visibility_changed']);
    }

    public function test_todos_are_own_or_given_by_a_manager_in_their_outlets_and_the_giver_hears_when_done(): void
    {
        $boss = $this->staff(['attendance.view_team']); $u = $this->staff(); $peer = $this->staff();
        $far = $this->staff(['attendance.view_team'], [], Outlet::factory()->create(['is_active' => true]));
        Sanctum::actingAs($u);
        $own = $this->postJson('/api/v1/admin/todos', ['title' => 'Order more thread', 'due_at' => '2026-03-11 12:00:00'])->assertCreated()->json('todo');
        $this->postJson('/api/v1/admin/todos', ['title' => 'x', 'user_id' => $peer->id])->assertStatus(403);
        $this->getJson('/api/v1/admin/todos?scope=team')->assertStatus(403);
        Sanctum::actingAs($far);
        $this->postJson('/api/v1/admin/todos', ['title' => 'x', 'user_id' => $u->id])->assertStatus(403);
        Sanctum::actingAs($boss);
        $this->assertSame([$u->id, $peer->id], collect($this->getJson('/api/v1/admin/todos/people')->json('data'))->pluck('id')->sort()->values()->all());
        $given = $this->postJson('/api/v1/admin/todos', ['title' => 'Dress the window', 'details' => 'The blue set.', 'due_at' => '2026-03-09 17:00:00', 'user_id' => $u->id])->assertCreated()->json('todo');
        Notification::assertSentTo($u, InAppNotification::class, fn ($n) => $n->title === 'To do: Dress the window');
        $this->assertCount(1, $this->getJson('/api/v1/admin/todos?scope=given')->json('data'));
        $team = $this->getJson('/api/v1/admin/todos?scope=team')->assertOk()->json('data');
        $this->assertSame(['Dress the window', 'Order more thread'], array_column($team, 'title'), 'overdue first, then by due date');
        Sanctum::actingAs($u);
        $mine = $this->getJson('/api/v1/admin/todos')->assertOk()->assertJsonPath('open', 2)->assertJsonPath('overdue', 1)->assertJsonPath('can_give', false);
        $this->assertTrue($mine->json('data.0.overdue'));
        $this->patchJson("/api/v1/admin/todos/{$given['id']}", ['title' => 'renamed'])->assertOk()->assertJsonPath('todo.title', 'Dress the window'); // the giver's words stay
        $this->patchJson("/api/v1/admin/todos/{$given['id']}", ['status' => 'cancelled'])->assertStatus(403);
        $this->patchJson("/api/v1/admin/todos/{$given['id']}", ['status' => 'done', 'done_note' => 'Blue set is up.'])->assertOk()->assertJsonPath('todo.status', 'done');
        Notification::assertSentTo($boss, InAppNotification::class, fn ($n) => str_contains($n->title, 'did: Dress the window') && $n->body === 'Blue set is up.');
        $this->deleteJson("/api/v1/admin/todos/{$given['id']}")->assertStatus(403);
        $this->deleteJson("/api/v1/admin/todos/{$own['id']}")->assertOk();
        $this->assertSame(0, $this->getJson('/api/v1/admin/todos')->json('open'));
        $this->assertCount(1, $this->getJson('/api/v1/admin/todos?status=done')->json('data'));
        Sanctum::actingAs($peer);
        $this->patchJson("/api/v1/admin/todos/{$given['id']}", ['status' => 'open'])->assertStatus(403);
        Sanctum::actingAs($boss);
        $this->patchJson("/api/v1/admin/todos/{$given['id']}", ['status' => 'open'])->assertOk();
        $this->patchJson("/api/v1/admin/todos/{$given['id']}", ['status' => 'cancelled'])->assertOk();
        Notification::assertSentTo($u, InAppNotification::class, fn ($n) => str_starts_with($n->title, 'No longer needed'));
        $this->assertSame('cancelled', Todo::find($given['id'])->status);
    }
}
