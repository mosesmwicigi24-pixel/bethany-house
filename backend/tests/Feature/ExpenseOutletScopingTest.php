<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseBudget;
use App\Models\ExpenseCategory;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Audit fix: ExpenseController scoped outlet managers by `$user->outlet_id`, a
 * column users don't have (outlet assignment is the many-to-many outlet_user
 * pivot), so the scoping was broken. It now scopes by the assigned outlets.
 *
 * Follow-up (this file's second half): the role restriction existed ONLY in
 * index(). show(), the receipt download, summary(), budgets(), categories()
 * and every mutation resolved an id straight out of the table, so an outlet
 * manager could read — and edit, cancel or delete — any expense in the group
 * by id, and could pull group-wide financial totals. One helper,
 * ExpenseController::assignedOutletIdsOrNull(), now backs every path.
 */
class ExpenseOutletScopingTest extends TestCase
{
    use RefreshDatabase;

    private function signInAs(array $permissions, array $roles): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
        }
        foreach ($roles as $r) {
            $user->assignRole(Role::findOrCreate($r, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeExpense(
        string $ref,
        int $categoryId,
        int $outletId,
        int $userId,
        string $status = 'approved',
        float $amount = 1000,
    ): Expense {
        return Expense::create([
            'reference_number' => $ref,
            'title'            => "{$ref} expense",
            'category_id'      => $categoryId,
            'amount'           => $amount,
            'currency_code'    => 'KES',
            'exchange_rate'    => 1,
            'amount_kes'       => $amount,
            'expense_date'     => '2026-07-01',
            'payment_method'   => 'cash',
            'outlet_id'        => $outletId,
            'created_by'       => $userId,
            'status'           => $status,
        ]);
    }

    /** The July window every summary assertion below uses. */
    private const PERIOD = 'start_date=2026-07-01&end_date=2026-07-31';

    public function test_outlet_manager_sees_only_assigned_outlet_expenses(): void
    {
        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();

        $manager = $this->signInAs(['expenses.view'], ['outlet_manager']);
        $manager->outlets()->attach($outletA->id);

        $cat = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT']);
        $this->makeExpense('EXP-A', $cat->id, $outletA->id, $manager->id);
        $this->makeExpense('EXP-B', $cat->id, $outletB->id, $manager->id);

        $res = $this->getJson('/api/v1/admin/expenses');

        $res->assertOk();
        $res->assertJsonFragment(['reference_number' => 'EXP-A']);
        $res->assertJsonMissing(['reference_number' => 'EXP-B']);
    }

    public function test_admin_is_not_outlet_scoped(): void
    {
        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();

        $admin = $this->signInAs(['expenses.view'], ['admin']);

        $cat = ExpenseCategory::create(['name' => 'Utilities', 'code' => 'UTIL']);
        $this->makeExpense('EXP-A', $cat->id, $outletA->id, $admin->id);
        $this->makeExpense('EXP-B', $cat->id, $outletB->id, $admin->id);

        $res = $this->getJson('/api/v1/admin/expenses');

        $res->assertOk();
        $res->assertJsonFragment(['reference_number' => 'EXP-A']);
        $res->assertJsonFragment(['reference_number' => 'EXP-B']);
    }

    // ── show() — the IDOR ─────────────────────────────────────────────────────

    public function test_outlet_manager_cannot_show_an_expense_from_an_unassigned_outlet(): void
    {
        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();

        $manager = $this->signInAs(['expenses.view'], ['outlet_manager']);
        $manager->outlets()->attach($outletA->id);

        $cat  = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT']);
        $mine = $this->makeExpense('EXP-A', $cat->id, $outletA->id, $manager->id);
        $ours = $this->makeExpense('EXP-B', $cat->id, $outletB->id, $manager->id);

        // Their own outlet still resolves.
        $this->getJson("/api/v1/admin/expenses/{$mine->id}")
            ->assertOk()
            ->assertJsonPath('expense.reference_number', 'EXP-A');

        // Another outlet's expense does not — the list already hid it.
        $this->getJson("/api/v1/admin/expenses/{$ours->id}")->assertForbidden();
    }

    public function test_outlet_manager_cannot_show_a_head_office_expense(): void
    {
        $outletA = Outlet::factory()->create();

        $manager = $this->signInAs(['expenses.view'], ['outlet_manager']);
        $manager->outlets()->attach($outletA->id);

        $cat = ExpenseCategory::create(['name' => 'Legal', 'code' => 'LEGAL']);
        $headOffice = Expense::create([
            'reference_number' => 'EXP-HQ',
            'title'            => 'Head office retainer',
            'category_id'      => $cat->id,
            'amount'           => 9000,
            'currency_code'    => 'KES',
            'exchange_rate'    => 1,
            'amount_kes'       => 9000,
            'expense_date'     => '2026-07-01',
            'payment_method'   => 'bank_transfer',
            'outlet_id'        => null,
            'created_by'       => $manager->id,
            'status'           => 'approved',
        ]);

        // outlet_id NULL is outside an outlet manager's scope, exactly as it
        // is excluded from their list query.
        $this->getJson("/api/v1/admin/expenses/{$headOffice->id}")->assertForbidden();
    }

    public function test_outlet_manager_cannot_download_a_receipt_from_an_unassigned_outlet(): void
    {
        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();

        $manager = $this->signInAs(['expenses.view'], ['outlet_manager']);
        $manager->outlets()->attach($outletA->id);

        $cat  = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT']);
        $mine = $this->makeExpense('EXP-A', $cat->id, $outletA->id, $manager->id);
        $ours = $this->makeExpense('EXP-B', $cat->id, $outletB->id, $manager->id);

        // Neither has a receipt attached: in scope that is a 404 ("no receipt"),
        // out of scope it must stop at the scope check instead.
        $this->getJson("/api/v1/admin/expenses/{$mine->id}/receipt")->assertNotFound();
        $this->getJson("/api/v1/admin/expenses/{$ours->id}/receipt")->assertForbidden();
    }

    // ── mutations — worse than reading ────────────────────────────────────────

    public function test_outlet_manager_cannot_edit_cancel_or_delete_another_outlets_expense(): void
    {
        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();

        $manager = $this->signInAs(
            ['expenses.view', 'expenses.create', 'expenses.edit', 'expenses.delete'],
            ['outlet_manager'],
        );
        $manager->outlets()->attach($outletA->id);

        $cat   = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT']);
        $mine  = $this->makeExpense('EXP-A', $cat->id, $outletA->id, $manager->id);
        $theirs = $this->makeExpense('EXP-B', $cat->id, $outletB->id, $manager->id, 'draft');

        $this->putJson("/api/v1/admin/expenses/{$theirs->id}", ['title' => 'Hijacked'])
            ->assertForbidden();
        $this->postJson("/api/v1/admin/expenses/{$theirs->id}/cancel")->assertForbidden();
        $this->postJson("/api/v1/admin/expenses/{$theirs->id}/submit")->assertForbidden();
        $this->deleteJson("/api/v1/admin/expenses/{$theirs->id}")->assertForbidden();

        $this->assertSame('draft', $theirs->fresh()->status);
        $this->assertSame('EXP-B expense', $theirs->fresh()->title);

        // Their own outlet's expense is still fully actionable.
        $this->postJson("/api/v1/admin/expenses/{$mine->id}/cancel")->assertOk();
    }

    public function test_outlet_manager_cannot_book_an_expense_against_another_outlet(): void
    {
        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();

        $manager = $this->signInAs(['expenses.view', 'expenses.create'], ['outlet_manager']);
        $manager->outlets()->attach($outletA->id);

        $cat = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT']);

        $payload = [
            'title'          => 'Not my outlet',
            'category_id'    => $cat->id,
            'expense_date'   => '2026-07-01',
            'amount'         => 500,
            'currency_code'  => 'KES',
            'payment_method' => 'cash',
        ];

        $this->postJson('/api/v1/admin/expenses', $payload + ['outlet_id' => $outletB->id])
            ->assertForbidden();
        $this->postJson('/api/v1/admin/expenses', $payload + ['outlet_id' => $outletA->id])
            ->assertCreated();
    }

    // ── summary() / budgets() — group-wide money ──────────────────────────────

    public function test_summary_covers_only_assigned_outlets(): void
    {
        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();

        $manager = $this->signInAs(['expenses.view'], ['outlet_manager']);
        $manager->outlets()->attach($outletA->id);

        $cat = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT']);
        $this->makeExpense('EXP-A', $cat->id, $outletA->id, $manager->id, 'approved', 1000);
        $this->makeExpense('EXP-B', $cat->id, $outletB->id, $manager->id, 'approved', 7000);
        $this->makeExpense('EXP-C', $cat->id, $outletB->id, $manager->id, 'pending_approval', 500);

        $res = $this->getJson('/api/v1/admin/expenses/summary?' . self::PERIOD)->assertOk();

        // 1,000 — their outlet — not the group's 8,000.
        $this->assertSame(1000.0, (float) $res->json('totals.total_amount'));
        $this->assertSame(1, (int) $res->json('totals.total_count'));
        $this->assertSame(1000.0, (float) collect($res->json('by_category'))->sum('total'));
        $this->assertSame(1000.0, (float) collect($res->json('monthly_trend'))->sum('total'));
        // Another outlet's pending queue is not their business either.
        $this->assertSame(0, (int) $res->json('pending.count'));
    }

    public function test_summary_rejects_an_out_of_scope_outlet_filter(): void
    {
        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();

        $manager = $this->signInAs(['expenses.view'], ['outlet_manager']);
        $manager->outlets()->attach($outletA->id);

        $this->getJson("/api/v1/admin/expenses/summary?outlet_id={$outletB->id}")->assertForbidden();
        $this->getJson("/api/v1/admin/expenses/summary?outlet_id={$outletA->id}")->assertOk();
    }

    public function test_budgets_cover_only_assigned_outlets(): void
    {
        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();

        $manager = $this->signInAs(['expenses.view'], ['outlet_manager']);
        $manager->outlets()->attach($outletA->id);

        $cat = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT']);
        foreach ([[$outletA->id, 11000], [$outletB->id, 22000], [null, 33000]] as [$outletId, $amount]) {
            ExpenseBudget::create([
                'category_id'     => $cat->id,
                'outlet_id'       => $outletId,
                'period_type'     => 'monthly',
                'period_year'     => 2026,
                'period_number'   => 7,
                'budgeted_amount' => $amount,
                'currency_code'   => 'KES',
                'created_by'      => $manager->id,
            ]);
        }

        $res = $this->getJson('/api/v1/admin/expenses/budgets')->assertOk();

        $budgets = collect($res->json('budgets'));
        $this->assertCount(1, $budgets);
        $this->assertSame($outletA->id, (int) $budgets->first()['outlet_id']);
    }

    public function test_category_spend_figures_are_scoped_too(): void
    {
        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();

        $manager = $this->signInAs(['expenses.view'], ['outlet_manager']);
        $manager->outlets()->attach($outletA->id);

        $cat = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT', 'is_active' => true]);
        Expense::create([
            'reference_number' => 'EXP-NOW-A', 'title' => 'Mine', 'category_id' => $cat->id,
            'amount' => 1000, 'amount_kes' => 1000, 'currency_code' => 'KES', 'exchange_rate' => 1,
            'expense_date' => now()->format('Y-m-d'), 'payment_method' => 'cash',
            'outlet_id' => $outletA->id, 'created_by' => $manager->id, 'status' => 'approved',
        ]);
        Expense::create([
            'reference_number' => 'EXP-NOW-B', 'title' => 'Theirs', 'category_id' => $cat->id,
            'amount' => 7000, 'amount_kes' => 7000, 'currency_code' => 'KES', 'exchange_rate' => 1,
            'expense_date' => now()->format('Y-m-d'), 'payment_method' => 'cash',
            'outlet_id' => $outletB->id, 'created_by' => $manager->id, 'status' => 'approved',
        ]);

        $res = $this->getJson('/api/v1/admin/expenses/categories')->assertOk();
        $rent = collect($res->json('categories'))->firstWhere('code', 'RENT');

        $this->assertNotNull($rent);
        $this->assertSame(1000.0, (float) $rent['current_month_spend']);
    }

    // ── admin stays unrestricted ──────────────────────────────────────────────

    public function test_admin_is_not_scoped_on_show_summary_or_budgets(): void
    {
        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();

        $admin = $this->signInAs(['expenses.view'], ['admin']);

        $cat = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT']);
        $a = $this->makeExpense('EXP-A', $cat->id, $outletA->id, $admin->id, 'approved', 1000);
        $b = $this->makeExpense('EXP-B', $cat->id, $outletB->id, $admin->id, 'approved', 7000);

        ExpenseBudget::create([
            'category_id' => $cat->id, 'outlet_id' => $outletB->id,
            'period_type' => 'monthly', 'period_year' => 2026, 'period_number' => 7,
            'budgeted_amount' => 22000, 'currency_code' => 'KES', 'created_by' => $admin->id,
        ]);

        $this->getJson("/api/v1/admin/expenses/{$a->id}")->assertOk();
        $this->getJson("/api/v1/admin/expenses/{$b->id}")->assertOk();

        $summary = $this->getJson('/api/v1/admin/expenses/summary?' . self::PERIOD)->assertOk();
        $this->assertSame(8000.0, (float) $summary->json('totals.total_amount'));

        $this->assertCount(1, $this->getJson('/api/v1/admin/expenses/budgets')->assertOk()->json('budgets'));
    }

    public function test_super_admin_is_not_scoped_even_with_the_outlet_manager_role(): void
    {
        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();

        // A super admin who also happens to hold outlet_manager keeps the
        // unrestricted view — same precedence index() has always used.
        $user = $this->signInAs(['expenses.view'], ['outlet_manager', 'super_admin']);
        $user->outlets()->attach($outletA->id);

        $cat = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT']);
        $b   = $this->makeExpense('EXP-B', $cat->id, $outletB->id, $user->id);

        $this->getJson("/api/v1/admin/expenses/{$b->id}")->assertOk();
    }

    // ── fail closed, not open ─────────────────────────────────────────────────

    public function test_unassigned_outlet_manager_gets_nothing_not_everything(): void
    {
        $outletA = Outlet::factory()->create();

        // No outlets attached at all.
        $manager = $this->signInAs(['expenses.view'], ['outlet_manager']);

        $cat = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT']);
        $expense = $this->makeExpense('EXP-A', $cat->id, $outletA->id, $manager->id, 'approved', 4200);

        ExpenseBudget::create([
            'category_id' => $cat->id, 'outlet_id' => $outletA->id,
            'period_type' => 'monthly', 'period_year' => 2026, 'period_number' => 7,
            'budgeted_amount' => 11000, 'currency_code' => 'KES', 'created_by' => $manager->id,
        ]);

        $list = $this->getJson('/api/v1/admin/expenses')->assertOk();
        $this->assertSame(0, (int) $list->json('expenses.total'));
        $this->assertSame(0, (int) $list->json('stats.total_count'));

        $this->getJson("/api/v1/admin/expenses/{$expense->id}")->assertForbidden();

        $summary = $this->getJson('/api/v1/admin/expenses/summary?' . self::PERIOD)->assertOk();
        $this->assertSame(0.0, (float) $summary->json('totals.total_amount'));

        $this->assertCount(0, $this->getJson('/api/v1/admin/expenses/budgets')->assertOk()->json('budgets'));
    }
}
