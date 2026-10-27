<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Course;
use App\Models\EmployeeProfile;
use App\Models\Outlet;
use App\Models\PayGroup;
use App\Models\PayStructure;
use App\Models\PublicHoliday;
use App\Models\Shift;
use App\Models\SigningRequest;
use App\Models\StaffDocument;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Support\AttendanceRules;
use App\Support\LabourCost;
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
use Tests\TestCase;

/**
 * Bethany People C4 part 2 — document signing (a request on a document, an
 * article or text; signing files a record PDF on the employee file;
 * declining with a note; cancelling), courses (lessons, the quiz, pass mark,
 * completion on the file, the required count on the Me page, progress for
 * the author) and labour cost on the roster (payroll.view only, the maths).
 */
class SigningCoursesTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('private');
        Artisan::call('permission:sync');
        Carbon::setTestNow('2026-03-10 10:00:00');
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

    public function test_hr_asks_for_a_signature_on_a_document_the_person_signs_and_the_record_is_filed(): void
    {
        $hr = $this->staff(['hr.view', 'hr.edit']); $u = $this->staff();
        Storage::disk('private')->put("people/{$u->id}/documents/contract.pdf", '%PDF-1.4 contract');
        $doc = StaffDocument::create(['user_id' => $u->id, 'kind' => 'contract', 'title' => 'Employment contract', 'disk' => 'private', 'path' => "people/{$u->id}/documents/contract.pdf", 'mime_type' => 'application/pdf', 'original_name' => 'contract.pdf', 'uploaded_by' => $hr->id]);
        Sanctum::actingAs($u);
        $this->postJson("/api/v1/admin/people/{$u->id}/signing", ['kind' => 'document', 'staff_document_id' => $doc->id])->assertStatus(403);
        Sanctum::actingAs($hr);
        $this->postJson("/api/v1/admin/people/{$u->id}/signing", ['kind' => 'document', 'staff_document_id' => 999])->assertStatus(422);
        $r = $this->postJson("/api/v1/admin/people/{$u->id}/signing", ['kind' => 'document', 'staff_document_id' => $doc->id, 'due_at' => '2026-03-15'])->assertCreated();
        $id = $r->json('request.id');
        $this->assertSame(hash('sha256', '%PDF-1.4 contract'), SigningRequest::find($id)->source_hash);
        Notification::assertSentTo($u, InAppNotification::class, fn ($n) => $n->title === 'Please sign: Employment contract');
        $this->assertCount(1, $this->getJson("/api/v1/admin/people/{$u->id}/signing")->assertOk()->json('data'));

        Sanctum::actingAs($u);
        $mine = $this->getJson('/api/v1/admin/me/sign')->assertOk()->assertJsonPath('pending', 1);
        $this->assertSame('pending', $mine->json('data.0.status'));
        $show = $this->getJson("/api/v1/admin/me/sign/{$id}")->assertOk();
        $this->assertNotEmpty($show->json('request.document_link.url'), 'the signer may read the document');
        $this->postJson("/api/v1/admin/me/sign/{$id}/sign", ['signature' => 'data:image/jpeg;base64,xx'])->assertStatus(422);
        $this->postJson("/api/v1/admin/me/sign/{$id}/sign", ['signature' => $this->png()])->assertOk()->assertJsonPath('request.status', 'signed');
        $req = SigningRequest::find($id);
        $this->assertNotNull($req->signed_document_id);
        $filed = StaffDocument::find($req->signed_document_id);
        $this->assertSame(['signed', 'Signed: Employment contract', $u->id], [$filed->kind, $filed->title, $filed->user_id]);
        Storage::disk('private')->assertExists($filed->path);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('private')->get($filed->path));
        Storage::disk('private')->assertExists("people/{$u->id}/signatures/signing-{$id}.png");
        Notification::assertSentTo($hr, InAppNotification::class, fn ($n) => str_contains($n->title, 'signed "Employment contract"'));
        $this->assertDatabaseHas('activity_log', ['event' => 'document_signed']);
        $this->postJson("/api/v1/admin/me/sign/{$id}/sign", ['signature' => $this->png()])->assertStatus(422); // once
        // On the file: the signed record among the documents, and the request in the signing list.
        $file = $this->getJson('/api/v1/admin/me/file')->assertOk()->json('person');
        $this->assertContains('Signed: Employment contract', array_column($file['documents'], 'title'));
        $this->assertSame('signed', $file['signing'][0]['status']);
        Sanctum::actingAs($hr);
        $this->getJson("/api/v1/admin/people/{$u->id}/documents/{$filed->id}")->assertOk();
    }

    public function test_an_article_or_text_can_be_signed_declined_with_a_note_or_cancelled_and_is_scoped_to_hrs_outlets(): void
    {
        $hr = $this->staff(['hr.view', 'hr.edit', 'updates.post']); $u = $this->staff();
        $far = $this->staff(['hr.view', 'hr.edit'], Outlet::factory()->create(['is_active' => true]));
        Sanctum::actingAs($hr);
        $art = $this->postJson('/api/v1/admin/handbook', ['title' => 'Code of conduct', 'body' => 'Be kind. Be on time.', 'publish' => true])->json('article');
        $a = $this->postJson("/api/v1/admin/people/{$u->id}/signing", ['kind' => 'article', 'article_id' => $art['id']])->assertCreated()->json('request.id');
        $t = $this->postJson("/api/v1/admin/people/{$u->id}/signing", ['kind' => 'text', 'title' => 'Key holder undertaking', 'body' => 'I hold the shop keys and will not copy them.'])->assertCreated()->json('request.id');
        $c = $this->postJson("/api/v1/admin/people/{$u->id}/signing", ['kind' => 'text', 'title' => 'Oops', 'body' => 'wrong person'])->assertCreated()->json('request.id');
        $this->postJson("/api/v1/admin/people/{$u->id}/signing", ['kind' => 'text', 'title' => 'No words'])->assertStatus(422);
        $this->deleteJson("/api/v1/admin/people/{$u->id}/signing/{$c}")->assertOk();
        Sanctum::actingAs($far);
        $this->getJson("/api/v1/admin/people/{$u->id}/signing")->assertStatus(403);
        Sanctum::actingAs($u);
        $this->assertSame(2, $this->getJson('/api/v1/admin/me/sign')->json('pending'));
        $this->assertSame('Be kind. Be on time.', $this->getJson("/api/v1/admin/me/sign/{$a}")->json('request.body'));
        $this->postJson("/api/v1/admin/me/sign/{$a}/sign", ['signature' => $this->png()])->assertOk();
        $this->postJson("/api/v1/admin/me/sign/{$t}/decline", ['note' => ''])->assertStatus(422);
        $this->postJson("/api/v1/admin/me/sign/{$t}/decline", ['note' => 'I do not hold the keys any more.'])->assertOk()->assertJsonPath('request.status', 'declined');
        Notification::assertSentTo($hr, InAppNotification::class, fn ($n) => str_contains($n->title, 'declined') && $n->body === 'I do not hold the keys any more.');
        $this->postJson("/api/v1/admin/me/sign/{$c}/sign", ['signature' => $this->png()])->assertStatus(422); // cancelled
        $this->assertSame(0, $this->getJson('/api/v1/admin/me/sign')->json('pending'));
        $this->assertSame(1, StaffDocument::where('user_id', $u->id)->where('kind', 'signed')->count());
    }

    public function test_a_course_is_read_lesson_by_lesson_the_quiz_needs_the_pass_mark_and_completion_reaches_the_file(): void
    {
        $boss = $this->staff(['updates.post', 'hr.view']); $u = $this->staff();
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/admin/courses', ['title' => 'x', 'lessons' => [['title' => 'a', 'body' => 'b']]])->assertStatus(403);
        Sanctum::actingAs($boss);
        $this->postJson('/api/v1/admin/courses', ['title' => 'Till basics', 'lessons' => [['title' => 'Opening', 'body' => '…']], 'questions' => [['question' => 'Q', 'options' => ['A', 'B'], 'answer' => 5]]])->assertStatus(422);
        $draft = $this->postJson('/api/v1/admin/courses', ['title' => 'Draft course', 'lessons' => [['title' => 'One', 'body' => 'x']]])->assertCreated()->json('course');
        $this->assertNull($draft['published_at']);
        $c = $this->postJson('/api/v1/admin/courses', ['title' => 'Till basics', 'description' => 'How the till works.', 'required' => true, 'pass_mark' => 70, 'publish' => true,
            'lessons' => [['title' => 'Opening the till', 'body' => 'Count the float blind.'], ['title' => 'Taking M-Pesa', 'body' => 'Confirm on the phone.']],
            'questions' => [['question' => 'The float is counted…', 'options' => ['After the first sale', 'Blind, before the first sale', 'Never'], 'answer' => 1],
                ['question' => 'An M-Pesa payment is confirmed…', 'options' => ['On the customer\'s word', 'On the phone'], 'answer' => 1],
                ['question' => 'The drawer stays…', 'options' => ['Open', 'Closed'], 'answer' => 1]]])->assertCreated()->json('course');
        Notification::assertSentTo($u, InAppNotification::class, fn ($n) => $n->title === 'Training to complete: Till basics');
        Notification::assertNothingSentTo($boss);  // the author knows
        Sanctum::actingAs($u);
        $list = $this->getJson('/api/v1/admin/courses')->assertOk()->assertJsonPath('to_do', 1)->assertJsonPath('can_edit', false);
        $this->assertSame(['Till basics'], array_column($list->json('data'), 'title'), 'drafts are not for staff');
        $show = $this->getJson("/api/v1/admin/courses/{$c['id']}")->assertOk();
        $this->assertArrayNotHasKey('answer', $show->json('course.questions.0'), 'answers are withheld');
        $lessons = $show->json('course.lessons'); $qs = $show->json('course.questions');
        $this->postJson("/api/v1/admin/courses/{$c['id']}/quiz", ['answers' => [$qs[0]['id'] => 1]])->assertStatus(422); // lessons first
        $this->postJson("/api/v1/admin/courses/{$c['id']}/lessons/{$lessons[0]['id']}/done")->assertOk()->assertJsonPath('all_read', false);
        $this->postJson("/api/v1/admin/courses/{$c['id']}/lessons/{$lessons[1]['id']}/done")->assertOk()->assertJsonPath('all_read', true);
        $fail = $this->postJson("/api/v1/admin/courses/{$c['id']}/quiz", ['answers' => [$qs[0]['id'] => 0, $qs[1]['id'] => 1, $qs[2]['id'] => 0]])->assertOk();
        $this->assertSame([33, false, 1], [$fail->json('score'), $fail->json('passed'), $fail->json('attempts')]);
        $this->assertSame([$qs[0]['id'], $qs[2]['id']], $fail->json('wrong'));
        $pass = $this->postJson("/api/v1/admin/courses/{$c['id']}/quiz", ['answers' => [$qs[0]['id'] => 1, $qs[1]['id'] => 1, $qs[2]['id'] => 0]])->assertOk();
        $this->assertSame([67, false], [$pass->json('score'), $pass->json('passed')]);
        $pass = $this->postJson("/api/v1/admin/courses/{$c['id']}/quiz", ['answers' => [$qs[0]['id'] => 1, $qs[1]['id'] => 1, $qs[2]['id'] => 1]])->assertOk();
        $this->assertSame([100, true, 3], [$pass->json('score'), $pass->json('passed'), $pass->json('attempts')]);
        $this->postJson("/api/v1/admin/courses/{$c['id']}/quiz", ['answers' => [$qs[0]['id'] => 1]])->assertStatus(422); // already passed
        $this->assertSame(0, $this->getJson('/api/v1/admin/courses')->json('to_do'));
        $this->assertDatabaseHas('activity_log', ['event' => 'course_completed']);
        $file = $this->getJson('/api/v1/admin/me/file')->json('person');
        $this->assertSame(['Till basics', true, 100], [$file['courses'][0]['title'], $file['courses'][0]['passed'], $file['courses'][0]['best_score']]);
        // The author: who has done what; editing keeps progress; removing keeps the file's history.
        Sanctum::actingAs($boss);
        $pr = $this->getJson("/api/v1/admin/courses/{$c['id']}/progress")->assertOk();
        $this->assertSame(1, $pr->json('passed'));
        $this->assertTrue(collect($pr->json('data'))->firstWhere('id', $u->id)['passed']);
        $this->putJson("/api/v1/admin/courses/{$c['id']}", ['title' => 'Till basics v2', 'lessons' => [['title' => 'Opening the till', 'body' => 'Count the float blind, always.'], ['title' => 'Taking M-Pesa', 'body' => 'Confirm on the phone.'], ['title' => 'Closing', 'body' => 'Count again.']]])->assertOk()->assertJsonPath('course.lessons_count', 3);
        $this->assertSame(2, \App\Models\CourseProgress::where('user_id', $u->id)->first()->lessons_done ? count(\App\Models\CourseProgress::where('user_id', $u->id)->first()->lessons_done) : 0, 'lessons already read stay read');
        $this->assertArrayHasKey('answer', $this->getJson("/api/v1/admin/courses/{$c['id']}")->json('course.questions.0'), 'the author sees the answers');
    }

    public function test_labour_cost_on_the_roster_is_for_payroll_view_and_follows_the_pay_structure(): void
    {
        PayGroup::defaultGroup()->update(['standard_hours_per_day' => 8]);
        PublicHoliday::create(['date' => '2026-03-04', 'name' => 'Test Day', 'country' => 'KE']);
        $fin = $this->staff(['attendance.view_team', 'payroll.view']); $mgr = $this->staff(['attendance.view_team']);
        $monthly = $this->staff(); $hourly = $this->staff(); $daily = $this->staff(); $none = $this->staff();
        PayStructure::create(['user_id' => $monthly->id, 'effective_from' => '2026-01-01', 'kind' => 'monthly', 'base_amount' => 52000, 'currency_code' => 'KES']);
        PayStructure::create(['user_id' => $hourly->id, 'effective_from' => '2026-01-01', 'kind' => 'hourly', 'base_amount' => 300, 'currency_code' => 'KES']);
        PayStructure::create(['user_id' => $daily->id, 'effective_from' => '2026-01-01', 'kind' => 'daily', 'base_amount' => 2000, 'currency_code' => 'KES']);
        foreach ([$monthly, $hourly, $daily, $none] as $p) Shift::factory()->published()->create(['outlet_id' => $this->shop->id, 'user_id' => $p->id, 'date' => '2026-03-10', 'starts_at' => '08:00', 'ends_at' => '16:00']);
        // March 2026, Mon–Sat working (5 Sundays out) and one holiday: 25 working days. Monthly: 52000 / 25 / 8 = 260/h.
        $this->assertSame(25, LabourCost::workingDaysInMonth(Carbon::parse('2026-03-10')));
        $this->assertSame(260.0, LabourCost::hourlyRate($monthly, Carbon::parse('2026-03-10')));
        $this->assertSame(300.0, LabourCost::hourlyRate($hourly, Carbon::parse('2026-03-10')));
        $this->assertSame(250.0, LabourCost::hourlyRate($daily, Carbon::parse('2026-03-10')));
        $this->assertNull(LabourCost::hourlyRate($none, Carbon::parse('2026-03-10')));
        Sanctum::actingAs($mgr);
        $this->assertNull($this->getJson("/api/v1/admin/roster?outlet_id={$this->shop->id}&week=2026-03-09")->assertOk()->json('cost'), 'no money without payroll.view');
        Sanctum::actingAs($fin);
        $cost = $this->getJson("/api/v1/admin/roster?outlet_id={$this->shop->id}&week=2026-03-09")->assertOk()->json('cost');
        $this->assertSame(6480.0, (float) $cost['total']);                         // 8 h × (260 + 300 + 250)
        $this->assertSame(6480.0, (float) $cost['by_day']['2026-03-10']);
        $this->assertSame(2400.0, (float) $cost['by_person'][$hourly->id]);
        $this->assertSame(1, $cost['uncosted_shifts']);
        $this->assertStringContainsString('Estimate', $cost['basis']);
    }
}
