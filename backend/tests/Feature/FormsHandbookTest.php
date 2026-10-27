<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\EmployeeProfile;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Outlet;
use App\Models\Post;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Support\Automations;
use App\Support\AttendanceRules;
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
 * Bethany People C4 part 1 — checklists and forms (the seeded four, what is
 * due today, submitting with photos and a signature, skipped items, late,
 * missed by the job, the manager's overview, templates), the handbook (search,
 * must-read riding the feed, drafts) and shift templates.
 */
class FormsHandbookTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('private');
        Artisan::call('permission:sync');
        Carbon::setTestNow('2026-03-10 08:00:00'); // a Tuesday
        $this->shop = Outlet::factory()->create(['is_active' => true]);
        AttendanceRules::save(['working_days' => [1, 2, 3, 4, 5, 6]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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
        EmployeeProfile::factory()->create(['user_id' => $u->id, 'hire_date' => '2024-01-01']);
        return $u->fresh();
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(200, 80); imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255)); imageline($im, 10, 60, 190, 20, imagecolorallocate($im, 0, 0, 0));
        ob_start(); imagepng($im); return 'data:image/png;base64,' . base64_encode(ob_get_clean());
    }

    private function opening(): FormTemplate { return FormTemplate::where('name', 'Opening checklist')->firstOrFail(); }
    private function closing(): FormTemplate { return FormTemplate::where('name', 'Closing checklist')->firstOrFail(); }

    public function test_the_four_forms_are_seeded_and_the_catalogue_grants_forms_manage(): void
    {
        $this->assertSame(['Closing checklist', 'Damage report', 'Incident report', 'Opening checklist'], FormTemplate::orderBy('name')->pluck('name')->all());
        $this->assertSame('09:00', substr((string) $this->opening()->due_time, 0, 5));
        foreach (['admin', 'outlet_manager', 'production_manager'] as $r) $this->assertTrue(Role::findByName($r, 'sanctum')->hasPermissionTo('forms.manage'), $r);
        $this->assertFalse(Role::findByName('pos_clerk', 'sanctum')->hasPermissionTo('forms.manage'));
        $this->assertSame(30, Automations::get('checklist_missed')['threshold']);
    }

    public function test_today_lists_what_is_due_and_a_checklist_is_done_once_with_photo_and_number(): void
    {
        $u = $this->staff(); $boss = $this->staff(['forms.manage', 'attendance.manage']);
        Sanctum::actingAs($u);
        $today = $this->getJson('/api/v1/admin/forms/today')->assertOk()->assertJsonPath('can_manage', false);
        $rows = collect($today->json('data'));
        $this->assertCount(4, $rows);
        $this->assertSame('due', $rows->firstWhere('template.name', 'Opening checklist')['state']);
        $this->assertSame('available', $rows->firstWhere('template.name', 'Incident report')['state']);
        // Sunday: no checklists, reports still there.
        $this->assertCount(2, $this->getJson('/api/v1/admin/forms/today?date=2026-03-08')->json('data'));

        $t = $this->opening();
        $r = $this->post('/api/v1/admin/forms/submissions', ['template_id' => $t->id, 'outlet_id' => $this->shop->id,
            'answers' => json_encode(['lights' => true, 'float' => '5000', 'till' => true, 'floor' => true]), 'photo_display' => UploadedFile::fake()->image('display.jpg', 400, 300)], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('submitted', $r->json('submission.status'));
        $this->assertSame(0, $r->json('submission.skipped_required'));
        $this->assertSame(['display'], $r->json('submission.photo_keys'));
        $id = $r->json('submission.id');
        $this->assertCount(1, Storage::disk('private')->allFiles("forms/{$id}"));
        $this->assertEquals(5000, FormSubmission::find($id)->answers['float']);
        Notification::assertNothingSent();                                             // clean and on time: nobody is bothered
        $this->assertSame('submitted', collect($this->getJson('/api/v1/admin/forms/today')->json('data'))->firstWhere('template.name', 'Opening checklist')['state']);
        $this->postJson('/api/v1/admin/forms/submissions', ['template_id' => $t->id, 'outlet_id' => $this->shop->id, 'answers' => '{}'])->assertStatus(422); // once a day
        // The photo: a signed link for the submitter and for the manager; nothing for a stranger.
        $link = $this->getJson("/api/v1/admin/forms/submissions/{$id}/file/display")->assertOk()->json('url');
        Sanctum::actingAs($boss);
        $this->getJson("/api/v1/admin/forms/submissions/{$id}")->assertOk()->assertJsonPath('submission.answers.lights', true);
        Sanctum::actingAs($this->staff([], Outlet::factory()->create(['is_active' => true])));
        $this->getJson("/api/v1/admin/forms/submissions/{$id}")->assertStatus(403);
        $this->flushHeaders(); app('auth')->forgetGuards();
        $this->get($link)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertDatabaseHas('activity_log', ['event' => 'form_submitted']);
    }

    public function test_skipped_required_items_and_lateness_are_flagged_and_the_managers_told(): void
    {
        $u = $this->staff(); $boss = $this->staff(['forms.manage', 'attendance.manage']);
        Carbon::setTestNow('2026-03-10 20:15:00'); // closing due 19:30
        Sanctum::actingAs($u);
        $t = $this->closing();
        $this->postJson('/api/v1/admin/forms/submissions', ['template_id' => $t->id, 'outlet_id' => $this->shop->id, 'answers' => json_encode(['till_closed' => true, 'cash' => '12000'])])->assertStatus(422); // needs a signature
        $r = $this->postJson('/api/v1/admin/forms/submissions', ['template_id' => $t->id, 'outlet_id' => $this->shop->id, 'answers' => json_encode(['till_closed' => true, 'cash' => '12000', 'doors' => true]), 'signature' => $this->png()])->assertCreated();
        $this->assertSame(['late', 2, true, true], [$r->json('submission.status'), $r->json('submission.skipped_required'), $r->json('submission.flagged'), $r->json('submission.has_signature')]);
        Notification::assertSentTo($boss, InAppNotification::class, fn ($n) => str_contains($n->title, '2 items skipped') && $n->data['automation'] === 'checklist_missed');
        Notification::assertNothingSentTo($u);
        $sig = $this->getJson("/api/v1/admin/forms/submissions/{$r->json('submission.id')}/file/signature")->assertOk()->json('url');
        $this->flushHeaders(); app('auth')->forgetGuards();
        $this->get($sig)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_a_report_reaches_the_managers_at_once_and_can_be_sent_any_number_of_times(): void
    {
        $u = $this->staff(); $boss = $this->staff(['forms.manage']);
        Sanctum::actingAs($u);
        $t = FormTemplate::where('name', 'Damage report')->firstOrFail();
        $this->post('/api/v1/admin/forms/submissions', ['template_id' => $t->id, 'outlet_id' => $this->shop->id, 'answers' => json_encode(['item' => 'Fitting room mirror', 'how' => 'Fell off the wall', 'cost' => '4500']), 'photo_photo' => UploadedFile::fake()->image('m.jpg')], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('submission.status', 'submitted')->assertJsonPath('submission.flagged', true);
        $this->post('/api/v1/admin/forms/submissions', ['template_id' => $t->id, 'outlet_id' => $this->shop->id, 'answers' => json_encode(['item' => 'Steamer', 'how' => 'Dropped']), 'photo_photo' => UploadedFile::fake()->image('s.jpg')], ['Accept' => 'application/json'])->assertCreated();
        Notification::assertSentToTimes($boss, InAppNotification::class, 2);
        Notification::assertSentTo($boss, InAppNotification::class, fn ($n) => str_starts_with($n->title, "Damage report from {$u->first_name}") && str_contains($n->body, 'Fitting room mirror'));
        $this->assertCount(2, $this->getJson('/api/v1/admin/forms/mine')->json('data'));
    }

    public function test_a_missed_checklist_is_recorded_once_by_the_job_and_turns_late_when_done(): void
    {
        $u = $this->staff(); $boss = $this->staff(['forms.manage', 'attendance.manage']);
        Carbon::setTestNow('2026-03-10 09:20:00');
        $this->artisan('forms:remind')->assertExitCode(0);
        $this->assertSame(0, FormSubmission::count(), 'inside the 30-minute grace');
        Carbon::setTestNow('2026-03-10 09:31:00');
        $this->artisan('forms:remind')->assertExitCode(0);
        $this->artisan('forms:remind')->assertExitCode(0);
        $missed = FormSubmission::all();
        $this->assertCount(1, $missed);
        $this->assertSame(['missed', null], [$missed[0]->status, $missed[0]->user_id]);
        Notification::assertSentToTimes($boss, InAppNotification::class, 1);
        Notification::assertSentTo($boss, InAppNotification::class, fn ($n) => str_starts_with($n->title, 'Opening checklist not done at'));
        Sanctum::actingAs($u);
        $this->assertSame('missed', collect($this->getJson('/api/v1/admin/forms/today')->json('data'))->firstWhere('template.name', 'Opening checklist')['state']);
        $this->postJson('/api/v1/admin/forms/submissions', ['template_id' => $this->opening()->id, 'outlet_id' => $this->shop->id, 'answers' => json_encode(['lights' => true, 'float' => '5000', 'till' => true, 'floor' => true])])->assertCreated()->assertJsonPath('submission.status', 'late');
        $this->assertSame(1, FormSubmission::count(), 'the missed row became the late one');
        // Off: the job records nothing new; an outlet-specific template applies to its outlet only.
        Automations::save('checklist_missed', ['enabled' => false]);
        $other = Outlet::factory()->create(['is_active' => true]);
        Carbon::setTestNow('2026-03-10 20:30:00');
        $this->artisan('forms:remind')->assertExitCode(0);
        $this->assertSame(1 + 2, FormSubmission::where('status', 'missed')->count(), 'closing missed at both outlets is still recorded; nobody is told');
        Notification::assertSentToTimes($boss, InAppNotification::class, 1);
    }

    public function test_the_overview_and_the_submissions_list_are_scoped_to_the_managers_outlets(): void
    {
        $u = $this->staff(); $boss = $this->staff(['forms.manage']);
        $other = Outlet::factory()->create(['is_active' => true]); $far = $this->staff(['forms.manage'], $other);
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/admin/forms/submissions', ['template_id' => $this->opening()->id, 'outlet_id' => $this->shop->id, 'answers' => json_encode(['lights' => true, 'float' => '100', 'till' => true])])->assertCreated();
        $this->getJson('/api/v1/admin/forms/overview')->assertStatus(403);
        Sanctum::actingAs($boss);
        $ov = $this->getJson('/api/v1/admin/forms/overview')->assertOk()->json('outlets');
        $this->assertCount(1, $ov);
        $this->assertSame(['done' => 1, 'late' => 0, 'missed' => 0, 'pending' => 1, 'skipped' => 1, 'reports' => 0], $ov[0]['summary']);
        $this->assertSame('submitted', $ov[0]['checklists'][0]['state']);
        $this->assertCount(1, $this->getJson('/api/v1/admin/forms/submissions?status=flagged')->json('data'));
        Sanctum::actingAs($far);
        $this->assertCount(0, $this->getJson('/api/v1/admin/forms/submissions')->json('data'));
        $this->getJson("/api/v1/admin/forms/submissions?outlet_id={$this->shop->id}")->assertStatus(403);
    }

    public function test_templates_are_edited_by_forms_manage_and_retiring_keeps_the_history(): void
    {
        $boss = $this->staff(['forms.manage']); $u = $this->staff();
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/admin/forms/templates', ['name' => 'x', 'kind' => 'report', 'items' => [['key' => 'a', 'label' => 'A', 'type' => 'text']]])->assertStatus(403);
        Sanctum::actingAs($boss);
        $this->postJson('/api/v1/admin/forms/templates', ['name' => 'Fridge temperature', 'kind' => 'checklist', 'schedule' => 'daily', 'due_time' => '10:00', 'weekdays' => [1, 3, 5], 'outlet_id' => $this->shop->id,
            'items' => [['key' => 'temp', 'label' => 'Temperature °C', 'type' => 'number', 'required' => true], ['key' => 'temp', 'label' => 'dup', 'type' => 'check']]])->assertStatus(422);
        $id = $this->postJson('/api/v1/admin/forms/templates', ['name' => 'Fridge temperature', 'kind' => 'checklist', 'schedule' => 'daily', 'due_time' => '10:00', 'weekdays' => [1, 3, 5], 'outlet_id' => $this->shop->id,
            'items' => [['key' => 'temp', 'label' => 'Temperature °C', 'type' => 'number', 'required' => true]]])->assertCreated()->json('template.id');
        $this->assertCount(5, $this->getJson('/api/v1/admin/forms/templates')->json('data'));
        Sanctum::actingAs($u);
        $this->assertNull(collect($this->getJson('/api/v1/admin/forms/today')->json('data'))->firstWhere('template.name', 'Fridge temperature'), 'Tuesday is not one of its days');
        $this->assertNotNull(collect($this->getJson('/api/v1/admin/forms/today?date=2026-03-11')->json('data'))->firstWhere('template.name', 'Fridge temperature'));
        $this->postJson('/api/v1/admin/forms/submissions', ['template_id' => $id, 'outlet_id' => $this->shop->id, 'for_date' => '2026-03-09', 'answers' => json_encode(['temp' => '4'])])->assertCreated();
        Sanctum::actingAs($boss);
        $this->putJson("/api/v1/admin/forms/templates/{$id}", ['due_time' => '11:30'])->assertOk()->assertJsonPath('template.due_time', '11:30');
        $this->deleteJson("/api/v1/admin/forms/templates/{$id}")->assertOk();
        $this->assertCount(4, $this->getJson('/api/v1/admin/forms/templates')->json('data'));
        $this->assertSame('Fridge temperature', $this->getJson('/api/v1/admin/forms/submissions?from=2026-03-09&to=2026-03-09')->json('data.0.template.name'), 'the submission still names its form');
    }

    public function test_handbook_articles_are_searchable_and_a_must_read_rides_the_feed(): void
    {
        $boss = $this->staff(['updates.post']); $u = $this->staff();
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/admin/handbook', ['title' => 'x', 'body' => 'y'])->assertStatus(403);
        Sanctum::actingAs($boss);
        $draft = $this->postJson('/api/v1/admin/handbook', ['title' => 'Returns policy', 'body' => 'Customers may return unworn garments within 7 days with the receipt.', 'category' => 'Sales'])->assertCreated()->json('article');
        $this->assertNull($draft['published_at']);
        $pol = $this->postJson('/api/v1/admin/handbook', ['title' => 'Cash handling', 'body' => "Count the float blind. Never leave the till open.\nBank every evening.", 'category' => 'Till', 'must_read' => true, 'publish' => true])->assertCreated()->json('article');
        $this->assertNotNull($pol['post_id']);
        $post = Post::find($pol['post_id']);
        $this->assertSame(['Policy: Cash handling', true], [$post->title, $post->must_read]);
        Notification::assertSentTo($u, InAppNotification::class, fn ($n) => $n->title === 'Must read: Policy: Cash handling');
        $how = $this->postJson('/api/v1/admin/handbook', ['title' => 'How to steam silk', 'body' => 'Low heat, from the inside, never touching.', 'category' => 'Workshop', 'publish' => true])->assertCreated()->json('article');
        $this->assertNull($how['post_id']);
        // Staff: published only; search; opening the policy marks the feed post read; the must-read waits for the acknowledgement.
        Sanctum::actingAs($u);
        $list = $this->getJson('/api/v1/admin/handbook')->assertOk()->assertJsonPath('can_edit', false)->json('data');
        $this->assertSame(['Cash handling', 'How to steam silk'], array_column($list, 'title'));
        $this->assertSame(['How to steam silk'], array_column($this->getJson('/api/v1/admin/handbook?q=silk')->json('data'), 'title'));
        $this->getJson("/api/v1/admin/handbook/{$draft['id']}")->assertStatus(404);
        $this->assertSame(1, $this->getJson('/api/v1/admin/updates')->json('must_read_pending'));
        $this->getJson("/api/v1/admin/handbook/{$pol['id']}")->assertOk()->assertJsonPath('article.body', $pol['body'] ?? "Count the float blind. Never leave the till open.\nBank every evening.");
        $this->assertNotNull(collect($this->getJson('/api/v1/admin/handbook')->json('data'))->firstWhere('id', $pol['id'])['read_at']);
        $this->postJson("/api/v1/admin/updates/{$pol['post_id']}/acknowledge")->assertOk()->assertJsonPath('must_read_pending', 0);
        $this->assertNotNull(collect($this->getJson('/api/v1/admin/handbook')->json('data'))->firstWhere('id', $pol['id'])['acknowledged_at']);
        // Unpublishing takes the post with it; removing too.
        Sanctum::actingAs($boss);
        $this->putJson("/api/v1/admin/handbook/{$pol['id']}", ['publish' => false])->assertOk()->assertJsonPath('article.post_id', null);
        $this->assertNull(Post::find($pol['post_id']));
        $this->deleteJson("/api/v1/admin/handbook/{$how['id']}")->assertOk();
        $this->assertSame(0, Article::count() - 2 + 1 - 1, 'two left, one a draft');
        $this->assertCount(2, $this->getJson('/api/v1/admin/handbook')->json('data'), 'the manager sees the draft and the unpublished one');
    }

    public function test_shift_templates_are_listed_and_managed_by_roster_manage(): void
    {
        $boss = $this->staff(['attendance.view_team', 'roster.manage']); $u = $this->staff(['attendance.view_team']);
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/admin/roster/templates', ['name' => 'Morning', 'starts_at' => '08:00', 'ends_at' => '14:00'])->assertStatus(403);
        Sanctum::actingAs($boss);
        $id = $this->postJson('/api/v1/admin/roster/templates', ['name' => 'Morning', 'starts_at' => '08:00', 'ends_at' => '14:00', 'label' => 'Shop floor'])->assertCreated()->json('template.id');
        $this->postJson('/api/v1/admin/roster/templates', ['name' => 'Evening', 'starts_at' => '14:00', 'ends_at' => '19:30', 'outlet_id' => $this->shop->id])->assertCreated();
        $this->postJson('/api/v1/admin/roster/templates', ['name' => 'Bad', 'starts_at' => '08:00', 'ends_at' => '08:00'])->assertStatus(422);
        $rows = $this->getJson("/api/v1/admin/roster/templates?outlet_id={$this->shop->id}")->assertOk()->json('data');
        $this->assertSame(['Morning', 'Evening'], array_column($rows, 'name'));
        $this->assertSame('08:00', $rows[0]['starts_at']);
        $this->deleteJson("/api/v1/admin/roster/templates/{$id}")->assertOk();
        $this->assertCount(1, $this->getJson("/api/v1/admin/roster/templates?outlet_id={$this->shop->id}")->json('data'));
    }
}
