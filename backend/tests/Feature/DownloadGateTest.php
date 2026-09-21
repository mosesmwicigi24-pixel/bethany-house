<?php

namespace Tests\Feature;

use App\Jobs\SendDownloadCopyToOwner;
use App\Mail\AuditDigestMail;
use App\Mail\DownloadApprovalRequestMail;
use App\Mail\DownloadCopyMail;
use App\Models\DownloadApprover;
use App\Models\DownloadRequest;
use App\Models\User;
use App\Notifications\DownloadApprovalRequiredNotification;
use App\Notifications\DownloadDecisionNotification;
use App\Services\Downloads\DownloadPolicy;
use App\Services\Downloads\ExportWatermark;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Download approval (owner decision 2026-09-21): every file needs the owner's
 * approval — or a manager he delegates to — except invoices, quotations and
 * receipts; the owner gets a silent copy; nothing slips past unlisted.
 */
class DownloadGateTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.owner_account_email' => 'owner@bethany.test', 'audit.owner_email' => 'copies@bethany.test']);
        Storage::fake('local');
        $this->owner = $this->staff(['super_admin'], [], 'owner@bethany.test');

        // A download endpoint nobody listed — must be treated as gated.
        Route::middleware(['api', 'auth:sanctum'])->get('api/v1/admin/_test/unlisted.csv', fn () => response(
            "a,b\n1,2\n", 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="unlisted.csv"']));
        Route::middleware(['api', 'auth:sanctum'])->get('api/v1/admin/_test/streamed.csv', fn () => response()->stream(
            function () { echo "x,y\n"; echo "3,4\n"; }, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="streamed.csv"']));
        Route::middleware(['api', 'auth:sanctum'])->get('api/v1/admin/_test/screen', fn () => response()->json(['data' => [1, 2]]));
    }

    private function staff(array $roles = [], array $perms = [], ?string $email = null): User
    {
        $u = User::factory()->create(array_filter(['status' => 'active', 'email' => $email]));
        foreach ($roles as $r) $u->assignRole(Role::findOrCreate($r, 'sanctum'));
        foreach ($perms as $p) $u->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $u;
    }

    private function clerk(): User
    {
        return $this->staff([], ['orders.view']);
    }

    private function enforce(bool $on = true): void
    {
        config(['audit.downloads.enforce' => $on]);
    }

    /** Hold a clerk's export and return the held uuid. */
    private function held(User $clerk, string $url = '/api/v1/admin/orders/export?status=pending'): string
    {
        $this->enforce();
        Sanctum::actingAs($clerk);
        $r = $this->getJson($url)->assertStatus(403)->assertJsonPath('code', 'download_approval_required');
        return $r->json('held');
    }

    // ── classification ──────────────────────────────────────────────────────

    public function test_the_rules_classify_every_kind(): void
    {
        $p = app(DownloadPolicy::class);
        $this->assertSame('exempt', $p->declared('DocumentPdfController@invoice'));
        $this->assertSame('exempt', $p->declared('DocumentPdfController@quotation'));
        $this->assertSame('exempt', $p->declared('DocumentPdfController@receipt'));
        $this->assertSame('gated', $p->declared('DocumentPdfController@purchaseOrder'));
        $this->assertSame('gated', $p->declared('DocumentPdfController@shipment'), 'waybills need approval (owner kept the default)');
        $this->assertSame('view', $p->declared('PaymentApprovalController@serveProof'));
        $this->assertSame('never_attach', $p->declared('DatabaseManagementController@backupsDownload'));
        $this->assertNull($p->declared('OrderController@index'));
    }

    // ── recording only (the gate ships switched off) ────────────────────────

    public function test_while_not_enforcing_a_download_goes_through_tagged_archived_and_copied(): void
    {
        Queue::fake();
        $this->enforce(false);
        $clerk = $this->clerk();
        Sanctum::actingAs($clerk);

        $r = $this->get('/api/v1/admin/orders/export')->assertOk();

        $dr = DownloadRequest::where('user_id', $clerk->id)->sole();
        $this->assertTrue($dr->shadow);
        $this->assertSame(DownloadRequest::DOWNLOADED, $dr->status);
        $this->assertMatchesRegularExpression('/^BH-EXP-\d{6}$/', $dr->export_id);
        $this->assertSame($dr->export_id, $r->headers->get('X-Export-Id'));
        $this->assertStringContainsString("__{$dr->export_id}.csv", $r->headers->get('Content-Disposition'));
        $this->assertNotNull($dr->archive_path);
        Storage::disk('local')->assertExists($dr->archive_path);
        $this->assertSame(hash('sha256', $r->getContent()), $dr->file_sha256);
        Queue::assertPushed(SendDownloadCopyToOwner::class, fn ($j) => $j->downloadRequestId === $dr->id);
        $this->assertDatabaseHas('activity_log', ['event' => 'download_completed', 'subject_id' => $dr->id]);
    }

    public function test_a_streamed_export_is_archived_as_it_streams(): void
    {
        Queue::fake();
        $clerk = $this->clerk();
        Sanctum::actingAs($clerk);

        $body = $this->get('/api/v1/admin/_test/streamed.csv')->assertOk()->streamedContent();

        $dr = DownloadRequest::where('user_id', $clerk->id)->sole();
        $this->assertSame("x,y\n3,4\n", Storage::disk('local')->get($dr->archive_path));
        $this->assertSame(hash('sha256', $body), $dr->file_sha256);
    }

    public function test_the_payments_export_is_a_csv_the_server_builds_and_the_gate_sees(): void
    {
        Queue::fake();
        $finance = $this->staff([], ['payments.view', 'payments.transactions']);
        Sanctum::actingAs($finance);

        $r = $this->get('/api/v1/admin/payment-transactions/export?start_date=2026-01-01&status=paid')->assertOk();

        $this->assertStringStartsWith('text/csv', (string) $r->headers->get('Content-Type'));
        $this->assertStringContainsString('#,Reference,Order,Customer,Method,Amount,Currency,Status,Date', $r->getContent());
        $dr = DownloadRequest::where('user_id', $finance->id)->sole();
        $this->assertSame(['start_date' => '2026-01-01', 'status' => 'paid'], $dr->payload);
        $this->assertStringContainsString($dr->export_id, (string) $r->headers->get('Content-Disposition'));
    }

    // ── customer documents ──────────────────────────────────────────────────

    public function test_invoices_are_never_held_renamed_or_emailed_one_by_one(): void
    {
        Queue::fake();
        $this->enforce();
        $order = \App\Models\Order::factory()->create();
        $clerk = $this->clerk();
        Sanctum::actingAs($clerk);

        $r = $this->get("/api/v1/admin/pdf/orders/{$order->id}/invoice")->assertOk();

        $this->assertNull($r->headers->get('X-Export-Id'));
        $this->assertStringNotContainsString('BH-EXP', (string) $r->headers->get('Content-Disposition'));
        $dr = DownloadRequest::where('user_id', $clerk->id)->sole();
        $this->assertSame('exempt', $dr->category);
        $this->assertNull($dr->archive_path, 'customer documents are fingerprinted, not archived');
        Queue::assertNotPushed(SendDownloadCopyToOwner::class);   // they go in the daily digest
    }

    // ── holding ─────────────────────────────────────────────────────────────

    public function test_when_enforcing_a_clerks_export_is_held_and_nothing_leaves(): void
    {
        Queue::fake();
        $clerk = $this->clerk();
        $uuid = $this->held($clerk);

        $dr = DownloadRequest::where('uuid', $uuid)->sole();
        $this->assertSame(DownloadRequest::HELD, $dr->status);
        $this->assertSame(['status' => 'pending'], $dr->payload);
        $this->assertNull($dr->archive_path);
        Queue::assertNotPushed(SendDownloadCopyToOwner::class);
    }

    public function test_an_unlisted_download_endpoint_fails_closed(): void
    {
        $this->enforce();
        Sanctum::actingAs($this->clerk());

        $this->get('/api/v1/admin/_test/unlisted.csv')->assertStatus(403)->assertJsonPath('code', 'download_approval_required');
        $this->getJson('/api/v1/admin/_test/screen')->assertOk()->assertJsonPath('data', [1, 2]);   // a screen is untouched
    }

    public function test_the_route_permission_still_decides_before_the_gate(): void
    {
        $this->enforce();
        Sanctum::actingAs($this->staff());   // no orders.view

        $this->getJson('/api/v1/admin/orders/export')->assertStatus(403)->assertJsonMissingPath('held');
    }

    public function test_customers_are_not_gated(): void
    {
        $this->enforce();
        Sanctum::actingAs(User::factory()->customer()->create());
        $this->get('/api/v1/admin/_test/unlisted.csv');
        $this->assertSame(0, DownloadRequest::count());
    }

    // ── asking, deciding, taking ────────────────────────────────────────────

    public function test_the_full_journey_ask_approve_take_once(): void
    {
        Queue::fake();
        Mail::fake();
        Notification::fake();
        $clerk = $this->clerk();
        $uuid = $this->held($clerk);

        $this->postJson('/api/v1/admin/downloads/requests', ['held' => $uuid, 'reason' => 'Month-end reconciliation'])
            ->assertCreated();
        Notification::assertSentTo($this->owner, DownloadApprovalRequiredNotification::class);
        Mail::assertQueued(DownloadApprovalRequestMail::class, fn ($m) => $m->hasTo('copies@bethany.test'));

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/downloads/requests/{$uuid}/approve", ['note' => 'ok'])->assertOk();
        Notification::assertSentTo($clerk, DownloadDecisionNotification::class);

        Sanctum::actingAs($clerk);
        $t = $this->postJson("/api/v1/admin/downloads/requests/{$uuid}/token")->assertOk()->json();

        $r = $this->get('/api/v1/admin/orders/export?status=pending', [$t['header'] => $t['token']])->assertOk();
        $dr = DownloadRequest::where('uuid', $uuid)->sole();
        $this->assertSame(DownloadRequest::DOWNLOADED, $dr->status);
        $this->assertSame($this->owner->id, $dr->decided_by);
        $this->assertSame($dr->export_id, $r->headers->get('X-Export-Id'));
        Queue::assertPushed(SendDownloadCopyToOwner::class);

        // Single use.
        $this->get('/api/v1/admin/orders/export?status=pending', [$t['header'] => $t['token']])
            ->assertStatus(403)->assertJsonPath('code', 'download_token_invalid');
    }

    public function test_an_approval_opens_only_what_was_approved_and_only_for_its_requester(): void
    {
        Notification::fake();
        Mail::fake();
        $clerk = $this->clerk();
        $uuid = $this->held($clerk);
        $this->postJson('/api/v1/admin/downloads/requests', ['held' => $uuid, 'reason' => 'Needed for audit']);
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/downloads/requests/{$uuid}/approve");
        Sanctum::actingAs($clerk);
        $t = $this->postJson("/api/v1/admin/downloads/requests/{$uuid}/token")->json();

        // Different filters than approved.
        $this->get('/api/v1/admin/orders/export?status=delivered', [$t['header'] => $t['token']])->assertStatus(403);

        // Someone else holding the link.
        $t2 = $this->postJson("/api/v1/admin/downloads/requests/{$uuid}/token")->json();
        Sanctum::actingAs($this->clerk());
        $this->get('/api/v1/admin/orders/export?status=pending', [$t2['header'] => $t2['token']])->assertStatus(403);

        // Expired.
        Sanctum::actingAs($clerk);
        $t3 = $this->postJson("/api/v1/admin/downloads/requests/{$uuid}/token")->json();
        $this->travel(31)->minutes();
        $this->get('/api/v1/admin/orders/export?status=pending', [$t3['header'] => $t3['token']])->assertStatus(403);
    }

    public function test_the_other_super_admin_cannot_approve_unless_the_owner_delegates(): void
    {
        Notification::fake();
        Mail::fake();
        $clerk = $this->clerk();
        $uuid = $this->held($clerk);
        $this->postJson('/api/v1/admin/downloads/requests', ['held' => $uuid, 'reason' => 'Stock count']);

        $staffAdmin = $this->staff(['super_admin']);   // every permission via Gate::before — still not an approver
        Sanctum::actingAs($staffAdmin);
        $this->postJson("/api/v1/admin/downloads/requests/{$uuid}/approve")->assertStatus(403);
        $this->postJson('/api/v1/admin/downloads/approvers', ['user_id' => $staffAdmin->id])->assertStatus(403);

        Sanctum::actingAs($this->owner);
        $manager = $this->staff();
        $this->postJson('/api/v1/admin/downloads/approvers', ['user_id' => $manager->id])->assertCreated();

        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/admin/downloads/requests/{$uuid}/approve")->assertOk();
    }

    public function test_nobody_approves_their_own_request(): void
    {
        Notification::fake();
        Mail::fake();
        $manager = $this->staff([], ['orders.view']);
        DownloadApprover::create(['user_id' => $manager->id, 'assigned_by' => $this->owner->id]);

        $uuid = $this->held($manager);
        $this->postJson('/api/v1/admin/downloads/requests', ['held' => $uuid, 'reason' => 'My own export']);
        $this->postJson("/api/v1/admin/downloads/requests/{$uuid}/approve")->assertStatus(403);
    }

    public function test_a_denial_needs_a_reason_and_closes_the_request(): void
    {
        Notification::fake();
        Mail::fake();
        $clerk = $this->clerk();
        $uuid = $this->held($clerk);
        $this->postJson('/api/v1/admin/downloads/requests', ['held' => $uuid, 'reason' => 'Curious']);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/downloads/requests/{$uuid}/deny")->assertStatus(422);
        $this->postJson("/api/v1/admin/downloads/requests/{$uuid}/deny", ['note' => 'Not needed'])->assertOk();

        Sanctum::actingAs($clerk);
        $this->postJson("/api/v1/admin/downloads/requests/{$uuid}/token")->assertStatus(422);
    }

    public function test_the_owners_own_downloads_pass_and_are_logged_not_emailed(): void
    {
        Queue::fake();
        $this->enforce();
        Sanctum::actingAs($this->owner);

        $this->get('/api/v1/admin/orders/export')->assertOk();

        $dr = DownloadRequest::where('user_id', $this->owner->id)->sole();
        $this->assertSame(DownloadRequest::AUTO, $dr->status);
        Queue::assertNotPushed(SendDownloadCopyToOwner::class);
        $this->assertDatabaseHas('activity_log', ['event' => 'download_completed', 'subject_id' => $dr->id]);
    }

    // ── the owner's copy and digest ─────────────────────────────────────────

    public function test_the_owners_copy_attaches_the_file_but_never_a_database_backup(): void
    {
        Storage::disk('local')->put('download-archive/x.csv', "a,b\n");
        $dr = DownloadRequest::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->clerk()->id, 'method' => 'GET',
            'path' => '/x', 'label' => 'Orders export', 'category' => 'gated', 'status' => 'downloaded',
            'archive_path' => 'download-archive/x.csv', 'file_bytes' => 4, 'file_name' => 'x__BH-EXP-000001.csv',
            'content_type' => 'text/csv', 'export_id' => 'BH-EXP-000001', 'downloaded_at' => now(),
        ]);
        $this->assertCount(1, (new DownloadCopyMail($dr))->attachments());

        $dr->forceFill(['category' => 'never_attach'])->save();
        $this->assertCount(0, (new DownloadCopyMail($dr->fresh()))->attachments());

        Mail::fake();
        (new SendDownloadCopyToOwner($dr->id))->handle();
        Mail::assertSent(DownloadCopyMail::class, fn ($m) => $m->hasTo('copies@bethany.test'));
        $this->assertNotNull($dr->fresh()->owner_notified_at);

        (new SendDownloadCopyToOwner($dr->id))->handle();   // never twice
        Mail::assertSent(DownloadCopyMail::class, 1);
    }

    public function test_the_morning_digest_reaches_the_owner(): void
    {
        Mail::fake();
        $this->artisan('audit:daily-digest', ['--date' => now()->toDateString()])->assertSuccessful();
        Mail::assertSent(AuditDigestMail::class, fn ($m) => $m->hasTo('copies@bethany.test'));
    }

    public function test_a_gated_pdf_carries_its_export_id_and_downloader(): void
    {
        $clerk = $this->clerk();
        Sanctum::actingAs($clerk);
        request()->attributes->set('download_export_id', 'BH-EXP-000042');
        request()->setUserResolver(fn () => $clerk);

        $html = ExportWatermark::apply('<html><body><p>Report</p></body></html>');

        $this->assertStringContainsString('BH-EXP-000042', $html);
        $this->assertStringContainsString('Confidential', $html);
    }
}
