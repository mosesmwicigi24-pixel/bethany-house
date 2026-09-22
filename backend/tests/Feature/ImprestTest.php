<?php

namespace Tests\Feature;

use App\Mail\ImprestAlertMail;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ImprestAccount;
use App\Models\ImprestTransaction;
use App\Models\User;
use App\Notifications\ImprestNotification;
use App\Services\ImprestService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Imprest (owner decisions 2026-09-22): float KES 10,000; deduct when the
 * expense is recorded; anyone who can create expenses spends; nobody approves
 * an imprest expense they recorded; the custodian confirms what a top-up
 * delivered; alert below 20%.
 */
class ImprestTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;      // super admin
    private User $custodian;  // holds the box
    private User $clerk;      // records expenses, cannot approve
    private User $approver;   // finance
    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.owner_email' => 'owner@bethany.test']);
        $this->owner     = $this->user(['super_admin']);
        $this->custodian = $this->user([], ['expenses.view', 'expenses.create']);
        $this->clerk     = $this->user([], ['expenses.view', 'expenses.create']);
        $this->approver  = $this->user([], ['expenses.view', 'expenses.create', 'expenses.approve']);
        $this->category  = ExpenseCategory::create(['name' => 'Sales Office Expense', 'code' => 'SALES_OFFICE', 'requires_approval_above' => 3000, 'is_active' => true]);
    }

    private function user(array $roles = [], array $perms = []): User
    {
        $u = User::factory()->create(['status' => 'active']);
        foreach ($roles as $r) $u->assignRole(Role::findOrCreate($r, 'sanctum'));
        foreach ($perms as $p) $u->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $u;
    }

    private function openImprest(float $opening = 10000): ImprestAccount
    {
        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/v1/admin/expenses/imprest', [
            'name' => 'Petty cash — Sonalux Store', 'custodian_id' => $this->custodian->id,
            'float_amount' => 10000, 'opening_balance' => $opening,
        ])->assertCreated()->json('account.id');
        return ImprestAccount::findOrFail($id);
    }

    private function expense(User $as, float $amount, ?bool $useImprest = true, string $title = 'Delivery')
    {
        Sanctum::actingAs($as);
        return $this->postJson('/api/v1/admin/expenses', array_filter([
            'title' => $title, 'category_id' => $this->category->id, 'expense_date' => now()->toDateString(),
            'amount' => $amount, 'currency_code' => 'KES', 'payment_method' => 'cash', 'vendor_name' => 'Milka',
            'use_imprest' => $useImprest,
        ], fn ($v) => $v !== null));
    }

    private function balance(ImprestAccount $a): string
    {
        return (string) $a->fresh()->balance;
    }

    // ── before an imprest exists nothing changes ────────────────────────────

    public function test_without_an_imprest_expenses_work_exactly_as_before(): void
    {
        $this->expense($this->clerk, 400, null)->assertCreated()->assertJsonPath('expense.status', 'draft');
    }

    // ── setting up ──────────────────────────────────────────────────────────

    public function test_only_the_super_admin_sets_it_up_and_the_opening_cash_is_the_first_ledger_line(): void
    {
        Sanctum::actingAs($this->approver);
        $this->postJson('/api/v1/admin/expenses/imprest', ['name' => 'x', 'custodian_id' => $this->custodian->id,
            'float_amount' => 10000, 'opening_balance' => 5000])->assertStatus(403);

        $a = $this->openImprest(6500);
        $this->assertSame('6500.00', $this->balance($a));
        $this->assertDatabaseHas('imprest_transactions', ['imprest_account_id' => $a->id, 'type' => 'opening', 'amount' => 6500, 'balance_after' => 6500]);
    }

    // ── spending ────────────────────────────────────────────────────────────

    public function test_once_an_imprest_exists_every_expense_must_answer_the_question(): void
    {
        $this->openImprest();
        $this->expense($this->clerk, 400, null)->assertStatus(422)->assertJsonValidationErrors('use_imprest');
        $this->expense($this->clerk, 400, false)->assertCreated();
    }

    public function test_paying_from_imprest_takes_the_amount_off_when_recorded_and_sends_it_to_approval(): void
    {
        $a = $this->openImprest();

        $r = $this->expense($this->clerk, 400)->assertCreated();   // a clerk can spend (owner decision)

        $this->assertSame('pending_approval', $r->json('expense.status'), 'spent cash is never a quiet draft');
        $e = Expense::withoutViewerScope()->findOrFail($r->json('expense.id'));
        $this->assertSame($a->id, $e->imprest_account_id);
        $this->assertSame('9600.00', $this->balance($a));
        $this->assertDatabaseHas('imprest_transactions', ['expense_id' => $e->id, 'type' => 'expense', 'amount' => -400, 'balance_after' => 9600]);
    }

    public function test_the_imprest_cannot_be_overdrawn_and_a_refused_expense_is_not_saved(): void
    {
        $a = $this->openImprest(1000);
        $before = Expense::withoutViewerScope()->count();

        $this->expense($this->clerk, 1200)->assertStatus(422)
            ->assertJsonValidationErrors('use_imprest')
            ->assertJsonFragment(['The imprest has only KES 1000.00 left. Ask for a top-up, or pay this another way.']);

        $this->assertSame($before, Expense::withoutViewerScope()->count());
        $this->assertSame('1000.00', $this->balance($a));
    }

    public function test_cents_add_up_exactly(): void
    {
        $a = $this->openImprest(1000);
        $this->expense($this->clerk, 100.10)->assertCreated();
        $this->expense($this->clerk, 200.20)->assertCreated();
        $this->assertSame('699.70', $this->balance($a));
    }

    public function test_one_expense_is_debited_once_whatever_happens(): void
    {
        $a = $this->openImprest();
        $id = $this->expense($this->clerk, 400)->json('expense.id');
        $e = Expense::withoutViewerScope()->findOrFail($id);

        try {
            DB::transaction(fn () => app(ImprestService::class)->debitForExpense($e, $a->fresh(), $this->clerk));
            $this->fail('a second debit for the same expense must be refused');
        } catch (QueryException $ex) {
            $this->assertStringContainsString('imprest_one_debit_per_expense', $ex->getMessage());
        }
        $this->assertSame('9600.00', $this->balance($a));
    }

    public function test_the_ledger_cannot_be_edited(): void
    {
        $a = $this->openImprest();
        $row = ImprestTransaction::where('imprest_account_id', $a->id)->firstOrFail();

        try {
            DB::transaction(fn () => DB::table('imprest_transactions')->where('id', $row->id)->update(['amount' => 1]));
            $this->fail('UPDATE must be refused');
        } catch (QueryException $ex) {
            $this->assertStringContainsString('append-only', $ex->getMessage());
        }
        $this->expectException(\LogicException::class);
        $row->delete();
    }

    // ── approval ────────────────────────────────────────────────────────────

    public function test_nobody_approves_an_imprest_expense_they_recorded(): void
    {
        $this->openImprest();
        $id = $this->expense($this->approver, 400)->json('expense.id');

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/admin/expenses/{$id}/approve")->assertStatus(403);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/expenses/{$id}/approve")->assertOk();
    }

    // ── rejected / cancelled spends ─────────────────────────────────────────

    public function test_a_rejected_imprest_expense_waits_for_cash_returned_or_written_off(): void
    {
        Notification::fake();
        $a = $this->openImprest();
        $id = $this->expense($this->clerk, 400)->json('expense.id');

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/admin/expenses/{$id}/reject", ['reason' => 'No receipt'])->assertOk();
        $this->assertSame('pending', Expense::withoutViewerScope()->find($id)->imprest_resolution);
        $this->assertSame('9600.00', $this->balance($a), 'the cash does not come back by itself');
        Notification::assertSentTo($this->owner, ImprestNotification::class);

        Sanctum::actingAs($this->clerk);
        $this->deleteJson("/api/v1/admin/expenses/{$id}")->assertStatus(403);   // delete needs expenses.delete anyway…
        $deleter = $this->user([], ['expenses.view', 'expenses.delete']);
        Sanctum::actingAs($deleter);
        $this->deleteJson("/api/v1/admin/expenses/{$id}")->assertStatus(422);  // …and an unresolved spend cannot be deleted

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/expenses/{$id}/imprest-resolution", ['resolution' => 'returned', 'note' => 'Rider refunded'])->assertOk();
        $this->assertSame('10000.00', $this->balance($a));
        $this->postJson("/api/v1/admin/expenses/{$id}/imprest-resolution", ['resolution' => 'returned'])->assertStatus(422);  // once
    }

    public function test_a_written_off_spend_is_recorded_but_not_credited(): void
    {
        $a = $this->openImprest();
        $id = $this->expense($this->clerk, 400)->json('expense.id');
        Sanctum::actingAs($this->clerk);
        $this->postJson("/api/v1/admin/expenses/{$id}/cancel")->assertStatus(403);   // cancel needs expenses.edit
        $editor = $this->user([], ['expenses.view', 'expenses.edit']);
        Sanctum::actingAs($editor);
        $this->postJson("/api/v1/admin/expenses/{$id}/cancel")->assertOk();

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/expenses/{$id}/imprest-resolution", ['resolution' => 'written_off'])->assertOk();
        $this->assertSame('9600.00', $this->balance($a));
        $this->assertSame('written_off', Expense::withoutViewerScope()->find($id)->imprest_resolution);
    }

    public function test_an_imprest_expenses_amount_cannot_change_after_the_cash_left(): void
    {
        $this->openImprest();
        $id = $this->expense($this->clerk, 400)->json('expense.id');
        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/admin/expenses/{$id}/reject", ['reason' => 'Wrong category']);

        $editor = $this->user([], ['expenses.view', 'expenses.edit']);
        Sanctum::actingAs($editor);
        $this->putJson("/api/v1/admin/expenses/{$id}", ['amount' => 450])->assertStatus(422);
        $this->putJson("/api/v1/admin/expenses/{$id}", ['title' => 'Delivery (Kilimani)'])->assertOk();
    }

    // ── low balance ─────────────────────────────────────────────────────────

    public function test_the_custodian_is_told_once_when_it_runs_below_twenty_percent(): void
    {
        Notification::fake();
        $a = $this->openImprest(2500);

        $this->expense($this->clerk, 400)->assertCreated();   // 2100: above 2000
        Notification::assertNotSentTo($this->custodian, ImprestNotification::class);

        $this->expense($this->clerk, 200)->assertCreated();   // 1900: below
        $this->expense($this->clerk, 100)->assertCreated();   // 1800: still below — no second alert
        Notification::assertSentToTimes($this->custodian, ImprestNotification::class, 1);
        $this->assertNotNull($a->fresh()->low_balance_notified_at);
    }

    // ── topping up ──────────────────────────────────────────────────────────

    public function test_ask_send_receive_and_the_balance_rises_only_on_receipt(): void
    {
        Notification::fake();
        Mail::fake();
        $a = $this->openImprest(1500);

        Sanctum::actingAs($this->clerk);   // anyone who records expenses can ask
        $req = $this->postJson("/api/v1/admin/expenses/imprest/{$a->id}/topups", ['reason' => 'Deliveries this week'])
            ->assertCreated()->json('request');
        $this->assertSame('8500.00', $req['requested_amount'], 'suggested = back to the float');
        Notification::assertSentTo($this->owner, ImprestNotification::class);
        Mail::assertQueued(ImprestAlertMail::class, fn ($m) => $m->hasTo('owner@bethany.test'));
        $this->postJson("/api/v1/admin/expenses/imprest/{$a->id}/topups")->assertStatus(422);   // one open at a time

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/admin/expenses/imprest/topups/{$req['uuid']}/send", ['amount' => 8500, 'method' => 'mpesa'])->assertStatus(403);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/expenses/imprest/topups/{$req['uuid']}/send",
            ['amount' => 8500, 'method' => 'mpesa', 'reference' => 'SIL3K9XQ2P'])->assertOk();
        $this->assertSame('1500.00', $this->balance($a), 'sent is not received');
        Notification::assertSentTo($this->custodian, ImprestNotification::class);

        // What the custodian's screen reads: who asked, who sent, and that it is theirs to confirm.
        Sanctum::actingAs($this->custodian);
        $view = $this->getJson('/api/v1/admin/expenses/imprest')->assertOk()->json('accounts.0');
        $this->assertSame('sent', $view['open_topup']['status']);
        $this->assertSame($this->owner->first_name, $view['open_topup']['sender']['first_name']);
        $this->assertSame($this->clerk->first_name, $view['open_topup']['requester']['first_name']);
        $this->assertTrue($view['you_are_custodian']);

        Sanctum::actingAs($this->owner);   // not the custodian
        $this->postJson("/api/v1/admin/expenses/imprest/topups/{$req['uuid']}/receive", ['amount' => 8500])->assertStatus(422);

        Sanctum::actingAs($this->custodian);
        $this->postJson("/api/v1/admin/expenses/imprest/topups/{$req['uuid']}/receive", ['amount' => 8000, 'note' => 'M-Pesa fee'])->assertOk();
        $this->assertSame('9500.00', $this->balance($a));
        Mail::assertQueued(ImprestAlertMail::class, fn ($m) => str_contains($m->heading, 'differs'));
        $this->postJson("/api/v1/admin/expenses/imprest/topups/{$req['uuid']}/receive", ['amount' => 8000])->assertStatus(422);   // once
        $this->assertNull($a->fresh()->low_balance_notified_at, 're-armed for the next time it runs low');
    }

    public function test_the_super_admin_can_load_without_a_request_and_the_custodian_still_confirms(): void
    {
        $a = $this->openImprest(0);
        Sanctum::actingAs($this->owner);
        $uuid = $this->postJson("/api/v1/admin/expenses/imprest/{$a->id}/topups/direct", ['amount' => 10000, 'method' => 'cash'])
            ->assertCreated()->json('request.uuid');
        $this->assertSame('0.00', $this->balance($a));

        Sanctum::actingAs($this->custodian);
        $this->postJson("/api/v1/admin/expenses/imprest/topups/{$uuid}/receive", ['amount' => 10000])->assertOk();
        $this->assertSame('10000.00', $this->balance($a));
    }

    public function test_a_decline_needs_a_reason_and_a_requester_can_cancel_their_own(): void
    {
        Notification::fake();
        $a = $this->openImprest(1000);
        Sanctum::actingAs($this->clerk);
        $uuid = $this->postJson("/api/v1/admin/expenses/imprest/{$a->id}/topups")->json('request.uuid');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/expenses/imprest/topups/{$uuid}/decline")->assertStatus(422);
        $this->postJson("/api/v1/admin/expenses/imprest/topups/{$uuid}/decline", ['reason' => 'Wait for month end'])->assertOk();

        Sanctum::actingAs($this->clerk);
        $uuid2 = $this->postJson("/api/v1/admin/expenses/imprest/{$a->id}/topups")->assertCreated()->json('request.uuid');
        $this->postJson("/api/v1/admin/expenses/imprest/topups/{$uuid2}/cancel")->assertOk();
    }

    // ── counting the cash ───────────────────────────────────────────────────

    public function test_a_cash_count_that_differs_waits_for_the_super_admin_then_corrects_the_books(): void
    {
        Mail::fake();
        $a = $this->openImprest(5000);

        $viewer = $this->user([], ['expenses.view']);   // can look, can't record expenses
        Sanctum::actingAs($viewer);
        $this->postJson("/api/v1/admin/expenses/imprest/{$a->id}/counts", ['counted_amount' => 5000])->assertStatus(403);

        Sanctum::actingAs($this->clerk);   // anyone who records expenses may count
        $this->postJson("/api/v1/admin/expenses/imprest/{$a->id}/counts", ['counted_amount' => 5000])->assertCreated()
            ->assertJsonPath('count.status', 'approved');   // matches: nothing to decide

        Sanctum::actingAs($this->custodian);
        $countId = $this->postJson("/api/v1/admin/expenses/imprest/{$a->id}/counts", ['counted_amount' => 4850, 'note' => 'KES 150 short'])
            ->assertCreated()->assertJsonPath('count.status', 'pending')->json('count.id');
        $this->assertSame('5000.00', $this->balance($a), 'nothing moves until the super admin decides');

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/admin/expenses/imprest/counts/{$countId}/decide", ['approve' => true, 'note' => 'Coins miscounted earlier'])->assertOk();
        $this->assertSame('4850.00', $this->balance($a));
        $this->assertDatabaseHas('imprest_transactions', ['cash_count_id' => $countId, 'type' => 'count_adjustment', 'amount' => -150]);
    }

    public function test_the_expense_list_filters_by_imprest(): void
    {
        $this->openImprest(10000);
        $spent = $this->expense($this->clerk, 700, true, 'Taxi')->assertCreated()->json('expense.id');
        $other = $this->expense($this->clerk, 900, false, 'Airtime')->assertCreated()->json('expense.id');

        Sanctum::actingAs($this->owner);
        $ids = fn (string $f) => collect($this->getJson("/api/v1/admin/expenses?imprest={$f}")->assertOk()->json('expenses.data'))->pluck('id')->all();
        $this->assertSame([$spent], $ids('yes'));
        $this->assertSame([$other], $ids('no'));
        $this->assertSame([], $ids('unresolved'));

        $this->postJson("/api/v1/admin/expenses/{$spent}/reject", ['reason' => 'Not a business trip'])->assertOk();
        $this->assertSame([$spent], $ids('unresolved'));
        $this->getJson('/api/v1/admin/expenses?imprest=maybe')->assertStatus(422);
    }

    // ── who reads the book ──────────────────────────────────────────────────

    public function test_the_statement_is_for_the_custodian_approvers_and_the_super_admin(): void
    {
        $a = $this->openImprest();
        Sanctum::actingAs($this->clerk);
        $this->getJson('/api/v1/admin/expenses/imprest')->assertOk()->assertJsonPath('accounts.0.balance', '10000.00');
        $this->getJson("/api/v1/admin/expenses/imprest/{$a->id}/statement")->assertStatus(403);

        foreach ([$this->custodian, $this->approver, $this->owner] as $u) {
            Sanctum::actingAs($u);
            $this->getJson("/api/v1/admin/expenses/imprest/{$a->id}/statement")->assertOk();
        }
    }

    public function test_the_morning_digest_reports_the_imprest(): void
    {
        Mail::fake();
        $this->openImprest(1500);
        $this->artisan('audit:daily-digest', ['--date' => now()->toDateString()])->assertSuccessful();
        Mail::assertSent(\App\Mail\AuditDigestMail::class, fn ($m) => $m->data['imprest']->count() === 1 && $m->data['imprest'][0]['low'] === true);
    }
}
