<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ApprovalSignature;
use App\Models\Expense;
use App\Models\ExpenseApproval;
use App\Models\ExpenseCategory;
use App\Models\Outlet;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Support\ReportingCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Bulk actions on the Expenses list (owner request 2026-10-03): select one or
 * several expenses and approve, reject, request changes or mark them paid.
 *
 * Every item goes down the same path its single-item action takes, so every
 * rule still applies to each one: the approval engine's bands, maker ≠
 * checker, Phase 4A's outlet boundary. Never all-or-nothing — each item is
 * reported on its own.
 */
class ExpenseBulkActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $fm;
    private User $accountant;
    private Outlet $here;
    private Outlet $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        Artisan::call('permission:sync');
        ReportingCurrency::forget();

        $this->owner      = $this->user('super_admin');
        $this->fm         = $this->user('finance_manager');
        $this->accountant = $this->user('accountant');
        $this->here       = Outlet::factory()->create();
        $this->elsewhere  = Outlet::factory()->create();
    }

    private function user(string ...$roles): User
    {
        $u = User::factory()->create(['status' => 'active']);
        foreach ($roles as $r) {
            $u->assignRole(Role::findOrCreate($r, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $u->fresh();
    }

    /** An outlet manager at $this->here whom the owner has given the expense band. */
    private function approvingOutletManager(): User
    {
        Role::findByName('outlet_manager', 'sanctum')->givePermissionTo('expenses.approve');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $om = $this->user('outlet_manager');
        $om->outlets()->attach($this->here->id);

        return $om->fresh();
    }

    /** Recorded through the API, so it is submitted to the engine as in production. */
    private function expense(User $as, float $amount, ?Outlet $outlet = null): Expense
    {
        $category = ExpenseCategory::firstOrCreate(['code' => 'SALES_OFFICE'],
            ['name' => 'Sales Office Expense', 'requires_approval_above' => 3000, 'is_active' => true]);
        Sanctum::actingAs($as);
        $id = $this->postJson('/api/v1/admin/expenses', [
            'title' => 'Printer', 'category_id' => $category->id, 'expense_date' => now()->toDateString(),
            'amount' => $amount, 'currency_code' => 'KES', 'payment_method' => 'cash', 'vendor_name' => 'Milka',
            'outlet_id' => $outlet?->id,
        ])->assertCreated()->json('expense.id');

        return Expense::withoutViewerScope()->findOrFail($id);
    }

    private function latest(int $expenseId): ApprovalRequest
    {
        return ApprovalRequest::where('event', 'expense')->where('approvable_id', $expenseId)->orderByDesc('version')->firstOrFail();
    }

    private function bulk(User $as, string $action, array $ids, ?string $reason = null)
    {
        Sanctum::actingAs($as);

        return $this->postJson('/api/v1/admin/expenses/bulk', array_filter([
            'ids' => $ids, 'action' => $action, 'reason' => $reason,
        ], fn ($v) => $v !== null));
    }

    private function row(array $results, int $id): array
    {
        return collect($results)->firstWhere('id', $id) ?? $this->fail("No result for expense {$id}");
    }

    // ── approve ─────────────────────────────────────────────────────────────

    public function test_bulk_approve_runs_every_rule_on_every_item_and_reports_each(): void
    {
        $om = $this->approvingOutletManager();

        $approvable = $this->expense($this->accountant, 5000, $this->here);
        $own        = $this->expense($om, 4000, $this->here);
        $big        = $this->expense($this->accountant, 60000, $this->here);
        $outOfScope = $this->expense($this->accountant, 5000, $this->elsewhere);
        $missing    = 999999;

        $res = $this->bulk($om, 'approve', [$approvable->id, $own->id, $big->id, $outOfScope->id, $missing])
            ->assertOk()
            ->assertJsonPath('action', 'approve')
            ->assertJsonPath('summary.requested', 5)
            ->assertJsonPath('summary.succeeded', 2)
            ->assertJsonPath('summary.failed', 3);
        $results = $res->json('results');

        // Results come back in the order asked.
        $this->assertSame([$approvable->id, $own->id, $big->id, $outOfScope->id, $missing], array_column($results, 'id'));

        // (a) approvable: approved outright.
        $a = $this->row($results, $approvable->id);
        $this->assertTrue($a['ok']);
        $this->assertSame('approved', $a['status_after']);
        $this->assertSame('APPROVED', $a['code']);
        $this->assertSame($approvable->reference_number, $a['reference']);
        $this->assertSame('approved', $approvable->fresh()->status);

        // (b) the caller recorded it: maker ≠ checker, nothing signed.
        $b = $this->row($results, $own->id);
        $this->assertFalse($b['ok']);
        $this->assertSame('SELF_APPROVAL', $b['code']);
        $this->assertSame('pending_approval', $b['status_after']);
        $this->assertStringContainsString('someone else must approve it', $b['message']);
        $this->assertSame(0, $this->latest($own->id)->signatures()->count());

        // (c) above the caller's band: he signs only his band; it waits for the owner.
        $c = $this->row($results, $big->id);
        $this->assertTrue($c['ok']);
        $this->assertSame('SIGNED_AWAITING_NEXT_BAND', $c['code']);
        $this->assertSame('pending_approval', $c['status_after']);
        $this->assertStringContainsString('owner', strtolower($c['message']));
        $this->assertSame('pending_approval', $big->fresh()->status);
        $r = $this->latest($big->id);
        $this->assertSame(ApprovalRequest::PENDING, $r->status);
        $this->assertSame(1, $r->signatures()->where('decision', ApprovalSignature::APPROVED)->count());
        $this->assertSame(2, (int) $r->current_band);

        // (d) another outlet's expense, and an id that does not exist: the
        // same "not found", nothing about either leaked.
        foreach ([$outOfScope->id, $missing] as $id) {
            $d = $this->row($results, $id);
            $this->assertFalse($d['ok']);
            $this->assertSame('NOT_FOUND', $d['code']);
            $this->assertNull($d['reference']);
            $this->assertNull($d['status_after']);
        }
        $this->assertSame($this->row($results, $outOfScope->id)['message'], $this->row($results, $missing)['message']);
        $this->assertSame('pending_approval', $outOfScope->fresh()->status);
        $this->assertSame(0, $this->latest($outOfScope->id)->signatures()->count());

        // The owner then signs the top band of (c) through the same endpoint.
        $this->bulk($this->owner, 'approve', [$big->id])->assertOk()
            ->assertJsonPath('results.0.ok', true)
            ->assertJsonPath('results.0.status_after', 'approved');
    }

    public function test_a_band_the_caller_does_not_hold_is_refused_with_who_must_sign_first(): void
    {
        // A super admin may not stand in for finance on a request whose top
        // band needs him — he would spend the one signature it needs from him.
        $big = $this->expense($this->accountant, 60000);

        $row = $this->bulk($this->owner, 'approve', [$big->id])->assertOk()->json('results.0');
        $this->assertFalse($row['ok']);
        $this->assertSame('NOT_YOUR_BAND', $row['code']);
        $this->assertStringContainsString('finance', strtolower($row['message']));
        $this->assertSame(0, $this->latest($big->id)->signatures()->count());
    }

    public function test_an_item_not_waiting_for_approval_is_refused_in_plain_words(): void
    {
        $draft = $this->expense($this->accountant, 400);   // under the category limit: a draft

        $row = $this->bulk($this->fm, 'approve', [$draft->id])->assertOk()->json('results.0');
        $this->assertFalse($row['ok']);
        $this->assertSame('NOT_PENDING', $row['code']);
        $this->assertSame('draft', $row['status_after']);
        $this->assertSame('draft', $draft->fresh()->status);
    }

    // ── reject ──────────────────────────────────────────────────────────────

    public function test_bulk_reject_needs_a_reason_and_then_rejects_each(): void
    {
        $one = $this->expense($this->accountant, 5000);
        $two = $this->expense($this->accountant, 6000);

        $this->bulk($this->fm, 'reject', [$one->id, $two->id])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->bulk($this->fm, 'reject', [$one->id], '   ')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('pending_approval', $one->fresh()->status);

        $this->bulk($this->fm, 'reject', [$one->id, $two->id], 'No receipt')->assertOk()
            ->assertJsonPath('summary.succeeded', 2);
        $this->assertSame('rejected', $one->fresh()->status);
        $this->assertSame('No receipt', $one->fresh()->rejection_reason);
        $this->assertSame(ApprovalRequest::REJECTED, $this->latest($two->id)->status);
    }

    // ── request changes ─────────────────────────────────────────────────────

    public function test_request_changes_returns_it_to_the_maker_and_a_resubmission_is_a_new_linked_version(): void
    {
        $e  = $this->expense($this->accountant, 5000);
        $v1 = $this->latest($e->id);

        Sanctum::actingAs($this->fm);
        $this->postJson("/api/v1/admin/expenses/{$e->id}/request-changes", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/expenses/{$e->id}/request-changes", ['reason' => 'Attach the receipt'])
            ->assertOk()
            ->assertJsonPath('expense.status', 'changes_requested');

        // It left approval: the open request is withdrawn, not approved or rejected.
        $e->refresh();
        $this->assertSame('changes_requested', $e->status);
        $v1->refresh();
        $this->assertSame(ApprovalRequest::CANCELLED, $v1->status);
        $this->assertStringContainsString('Attach the receipt', (string) $v1->rejected_reason);
        $this->assertSame($this->fm->id, (int) $v1->decided_by);
        $this->assertNull(app(\App\Services\Approvals\ApprovalEngine::class)->openRequest('expense', $e));

        // The note is kept on the expense's history and the maker is told.
        $this->assertTrue(ExpenseApproval::where('expense_id', $e->id)->where('action', 'changes_requested')
            ->where('comments', 'Attach the receipt')->where('approver_id', $this->fm->id)->exists());
        Notification::assertSentTo($this->accountant, InAppNotification::class,
            fn (InAppNotification $n) => str_contains($n->title . ' ' . $n->body, 'Attach the receipt'));
        $this->assertDatabaseHas('activity_log', ['event' => 'expense_changes_requested', 'subject_id' => $e->id, 'causer_id' => $this->fm->id]);
        $this->assertDatabaseHas('activity_log', ['event' => 'approval_changes_requested', 'subject_id' => $e->id, 'causer_id' => $this->fm->id]);

        // Nobody can sign it while it is back with its maker.
        $this->postJson("/api/v1/admin/expenses/{$e->id}/approve")->assertStatus(422);

        // The maker edits it and resubmits through the ordinary submit flow.
        Sanctum::actingAs($this->accountant);
        $this->putJson("/api/v1/admin/expenses/{$e->id}", ['amount' => 5200])->assertOk();
        $this->postJson("/api/v1/admin/expenses/{$e->id}/submit")->assertOk();

        $v2 = $this->latest($e->id);
        $this->assertSame(2, $v2->version);
        $this->assertSame($v1->id, (int) $v2->supersedes_id);
        $this->assertSame(ApprovalRequest::PENDING, $v2->status);
        $this->assertSame(5200.0, (float) $v2->amount);
        $this->assertSame('pending_approval', $e->fresh()->status);

        // And it is approved as the new version.
        Sanctum::actingAs($this->fm);
        $this->postJson("/api/v1/admin/expenses/{$e->id}/approve")->assertOk();
        $this->assertSame('approved', $e->fresh()->status);
    }

    public function test_bulk_request_changes_applies_only_to_items_waiting_for_approval(): void
    {
        $pending  = $this->expense($this->accountant, 5000);
        $approved = $this->expense($this->accountant, 5000);
        Sanctum::actingAs($this->fm);
        $this->postJson("/api/v1/admin/expenses/{$approved->id}/approve")->assertOk();

        $this->bulk($this->fm, 'request_changes', [$pending->id])->assertStatus(422)->assertJsonValidationErrors('reason');

        $results = $this->bulk($this->fm, 'request_changes', [$pending->id, $approved->id], 'Wrong category')
            ->assertOk()->json('results');

        $p = $this->row($results, $pending->id);
        $this->assertTrue($p['ok']);
        $this->assertSame('changes_requested', $p['status_after']);
        $this->assertSame(ApprovalRequest::CANCELLED, $this->latest($pending->id)->status);

        $a = $this->row($results, $approved->id);
        $this->assertFalse($a['ok']);
        $this->assertSame('NOT_PENDING', $a['code']);
        $this->assertSame('approved', $approved->fresh()->status);
    }

    public function test_only_someone_who_may_decide_it_now_may_request_changes(): void
    {
        $e = $this->expense($this->accountant, 5000);

        // The accountant holds no expense band: refused at the door.
        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/v1/admin/expenses/{$e->id}/request-changes", ['reason' => 'x'])->assertForbidden();

        // An outlet-bound approver does not reach another outlet's expense.
        $om  = $this->approvingOutletManager();
        $far = $this->expense($this->accountant, 5000, $this->elsewhere);
        Sanctum::actingAs($om);
        $this->postJson("/api/v1/admin/expenses/{$far->id}/request-changes", ['reason' => 'x'])->assertNotFound();
        $this->assertSame('pending_approval', $far->fresh()->status);
        $this->assertSame(ApprovalRequest::PENDING, $this->latest($far->id)->status);
    }

    // ── mark paid ───────────────────────────────────────────────────────────

    public function test_bulk_mark_paid_pays_only_approved_items(): void
    {
        $approved = $this->expense($this->accountant, 5000);
        $pending  = $this->expense($this->accountant, 5000);
        Sanctum::actingAs($this->fm);
        $this->postJson("/api/v1/admin/expenses/{$approved->id}/approve")->assertOk();

        $results = $this->bulk($this->fm, 'mark_paid', [$approved->id, $pending->id])->assertOk()->json('results');

        $this->assertTrue($this->row($results, $approved->id)['ok']);
        $this->assertSame('paid', $this->row($results, $approved->id)['status_after']);
        $this->assertSame('paid', $approved->fresh()->status);
        $this->assertSame($this->fm->id, (int) $approved->fresh()->paid_by);

        $p = $this->row($results, $pending->id);
        $this->assertFalse($p['ok']);
        $this->assertSame('NOT_APPROVED', $p['code']);
        $this->assertSame('pending_approval', $pending->fresh()->status);
    }

    // ── the request itself ──────────────────────────────────────────────────

    public function test_the_request_is_validated_and_permission_gated(): void
    {
        $e = $this->expense($this->accountant, 5000);

        $this->bulk($this->fm, 'approve', range(1, 101))->assertStatus(422)->assertJsonValidationErrors('ids');
        $this->bulk($this->fm, 'approve', [])->assertStatus(422)->assertJsonValidationErrors('ids');
        $this->bulk($this->fm, 'delete', [$e->id])->assertStatus(422)->assertJsonValidationErrors('action');
        $this->bulk($this->fm, 'approve', ['abc'])->assertStatus(422)->assertJsonValidationErrors('ids.0');

        // Same gate as the single actions: expenses.approve.
        foreach (['approve', 'reject', 'request_changes', 'mark_paid'] as $action) {
            $this->bulk($this->accountant, $action, [$e->id], 'x')->assertForbidden();
        }
        $this->assertSame('pending_approval', $e->fresh()->status);

        // Exactly 100 is allowed.
        $this->bulk($this->fm, 'approve', array_merge([$e->id], range(900001, 900099)))->assertOk()
            ->assertJsonPath('summary.requested', 100)
            ->assertJsonPath('summary.succeeded', 1);
    }
}
