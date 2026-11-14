<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Course;
use App\Models\EmployeeProfile;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Outlet;
use App\Models\Post;
use App\Models\SigningRequest;
use App\Models\Todo;
use App\Models\User;
use App\Support\AttendanceRules;
use App\Support\StarterContent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The first content of the staff pages (owner, 2026-11-14), and shop
 * checklists no longer falling on the WhatsApp desk.
 */
class PeopleStarterContentTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $shop;
    private Outlet $chat;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        Carbon::setTestNow('2026-11-17 12:00:00'); // a Tuesday, after both checklists' grace
        AttendanceRules::save(['working_days' => [1, 2, 3, 4, 5, 6]]);
        $this->shop = Outlet::factory()->create(['is_active' => true, 'sales_channel' => 'pos']);
        $this->chat = Outlet::factory()->create(['is_active' => true, 'sales_channel' => 'whatsapp']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(?Outlet $outlet = null, array $attrs = []): User
    {
        $u = User::factory()->create($attrs + ['status' => 'active', 'user_type' => 'staff']);
        DB::table('outlet_user')->insert(['user_id' => $u->id, 'outlet_id' => ($outlet ?? $this->shop)->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        EmployeeProfile::factory()->create(['user_id' => $u->id]);
        return $u->fresh();
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->staff();
        $before = [Article::count(), Course::count(), Post::count(), Todo::count(), SigningRequest::count(), json_encode(FormTemplate::orderBy('id')->pluck('items'))];

        $this->artisan('people:starter-content')->expectsOutputToContain('DRY RUN')->expectsOutputToContain('nothing was written')->assertExitCode(0);

        $this->assertSame($before, [Article::count(), Course::count(), Post::count(), Todo::count(), SigningRequest::count(), json_encode(FormTemplate::orderBy('id')->pluck('items'))]);
    }

    public function test_apply_needs_an_actor(): void
    {
        $this->artisan('people:starter-content --apply')->assertExitCode(1);
        $this->assertSame(0, Article::count());
    }

    public function test_apply_fills_every_page_once(): void
    {
        $owner = $this->staff();
        $a = $this->staff();
        $b = $this->staff($this->chat);
        $this->staff(null, ['status' => 'inactive']);        // left: gets nothing

        $this->artisan("people:starter-content --apply --actor={$owner->email}")->assertExitCode(0);

        // Handbook: every article published; the must-read ones ride the feed as policy posts.
        $articles = StarterContent::articles();
        $this->assertSame(count($articles), Article::whereNotNull('published_at')->count());
        $mustRead = count(array_filter($articles, fn ($x) => $x['must_read']));
        $this->assertSame($mustRead, Article::whereNotNull('post_id')->count());
        $this->assertSame($mustRead, Post::where('title', 'like', 'Policy: %')->where('must_read', true)->count());

        // Training: published, with lessons and questions whose answers point at an option.
        $this->assertSame(count(StarterContent::courses()), Course::whereNotNull('published_at')->count());
        foreach (Course::with('questions')->get() as $c) {
            $this->assertGreaterThan(0, $c->lessons()->count());
            foreach ($c->questions as $q) $this->assertArrayHasKey($q->answer, $q->options);
        }

        // Updates: one per tab the owner can fill (recognition needs a named person).
        $this->assertSame(1, Post::where('kind', 'update')->where('title', 'Welcome to the Bethany House hub')->where('must_read', true)->count());
        $event = Post::where('kind', 'event')->firstOrFail();
        $this->assertSame('2026-11-29 09:00', $event->event_at->format('Y-m-d H:i'));    // first Sunday of Advent
        $this->assertSame(1, Post::where('kind', 'celebration')->where('celebration_key', StarterContent::CELEBRATION_KEY)->count());
        $this->assertSame(0, Post::where('kind', 'recognition')->count());

        // To sign and to-dos: every active staff member, the inactive one nobody.
        $toSign = count(array_filter($articles, fn ($x) => $x['sign']));
        foreach ([$owner, $a, $b] as $u) {
            $this->assertSame($toSign, SigningRequest::where('user_id', $u->id)->where('status', 'pending')->count());
            $this->assertSame(count(StarterContent::todos()), Todo::where('user_id', $u->id)->where('status', 'open')->count());
        }
        $this->assertSame(3 * $toSign, SigningRequest::count());
        $req = SigningRequest::first();
        $this->assertSame(hash('sha256', $req->body), $req->source_hash);          // signs exactly what the article says
        $this->assertSame($this->chat->id, Todo::where('user_id', $b->id)->value('outlet_id'));

        // Checklists: the installed ones gain their items; the chat desk gets its own.
        $opening = FormTemplate::where('name', 'Opening checklist')->first();
        $this->assertContains('orders', array_column($opening->items, 'key'));
        $this->assertSame(1, FormTemplate::where('outlet_id', $this->chat->id)->where('name', 'Chat desk: start of day')->count());

        // A second run adds nothing.
        $counts = [Article::count(), Course::count(), Post::count(), Todo::count(), SigningRequest::count(), FormTemplate::count(), count($opening->fresh()->items)];
        $this->artisan("people:starter-content --apply --actor={$owner->id}")->assertExitCode(0);
        $this->assertSame($counts, [Article::count(), Course::count(), Post::count(), Todo::count(), SigningRequest::count(), FormTemplate::count(), count($opening->fresh()->items)]);
    }

    public function test_a_recognition_is_only_given_to_a_named_person_for_a_stated_reason(): void
    {
        $owner = $this->staff();
        $tailor = $this->staff();

        $this->artisan("people:starter-content --apply --actor={$owner->id} --recognise={$tailor->id}")->assertExitCode(0);
        $this->assertSame(0, Post::where('kind', 'recognition')->count());     // no reason, no post

        $this->artisan("people:starter-content --apply --actor={$owner->id} --recognise={$owner->id} --recognise-for=Thanks")->assertExitCode(0);
        $this->assertSame(0, Post::where('kind', 'recognition')->count());     // not yourself

        $this->artisan('people:starter-content', ['--apply' => true, '--actor' => (string) $owner->id, '--recognise' => (string) $tailor->id, '--recognise-for' => 'Thank you for finishing the Advent vestments early.'])->assertExitCode(0);
        $this->assertSame(1, Post::where('kind', 'recognition')->where('recipient_id', $tailor->id)->count());
    }

    public function test_a_checklist_the_team_has_edited_is_left_alone(): void
    {
        $owner = $this->staff();
        $opening = FormTemplate::where('name', 'Opening checklist')->first();
        $mine = [['key' => 'ours', 'label' => 'Our own first item', 'type' => 'check', 'required' => true]];
        $opening->forceFill(['items' => $mine, 'updated_by' => $owner->id])->save();

        $this->artisan("people:starter-content --apply --actor={$owner->id}")->assertExitCode(0);

        $this->assertSame($mine, $opening->fresh()->items);
    }

    public function test_shop_checklists_no_longer_fall_on_the_whatsapp_desk(): void
    {
        $chatty = $this->staff($this->chat);
        Sanctum::actingAs($chatty);

        // The desk sees reports it can file, but not the shop's opening and closing.
        $names = collect($this->getJson('/api/v1/admin/forms/today')->assertOk()->json('data'))->pluck('template.name')->all();
        $this->assertNotContains('Opening checklist', $names);
        $this->assertNotContains('Closing checklist', $names);
        $this->assertContains('Incident report', $names);

        // …and the job no longer marks the desk as having missed them.
        $this->artisan('forms:remind')->assertExitCode(0);
        $this->assertSame(0, FormSubmission::where('outlet_id', $this->chat->id)->where('status', 'missed')->count());
        $this->assertGreaterThan(0, FormSubmission::where('outlet_id', $this->shop->id)->where('status', 'missed')->count());

        // A checklist pinned to the desk applies there.
        $t = FormTemplate::create(['name' => 'Chat desk: start of day', 'kind' => 'checklist', 'outlet_id' => $this->chat->id, 'schedule' => 'daily', 'due_time' => '09:30',
            'items' => [['key' => 'x', 'label' => 'X', 'type' => 'check', 'required' => true]], 'requires_signature' => false, 'active' => true]);
        $this->assertTrue($t->appliesTo($this->chat));
        $this->assertFalse($t->appliesTo($this->shop));
        $names = collect($this->getJson('/api/v1/admin/forms/today')->json('data'))->pluck('template.name')->all();
        $this->assertContains('Chat desk: start of day', $names);
    }
}
