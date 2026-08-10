<?php

namespace Tests\Feature;

use App\Models\Expense;
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
 * GET /api/v1/admin/pdf/expenses/{id} rendered any expense in the group by id.
 *
 * #266 closed this IDOR on ExpenseController — show(), the receipt download,
 * summary(), budgets() and every mutation — but DocumentPdfController::expense()
 * resolved the very same record with a bare Expense::findOrFail($id) and handed
 * back a PDF of it: amount, vendor, submitter, line items and the full approval
 * trail. The route sits behind permission:expenses.view, which the default
 * outlet_manager role holds — exactly the role the scoping fences in — so the
 * PDF was a way straight around the JSON route's authorisation.
 *
 * Both controllers now share ScopesToAssignedOutlets, so there is one decision
 * rather than two copies to keep in step.
 */
class ExpensePdfScopingTest extends TestCase
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

    private function makeExpense(string $ref, int $categoryId, ?int $outletId, int $userId): Expense
    {
        return Expense::create([
            'reference_number' => $ref,
            'title'            => "{$ref} expense",
            'category_id'      => $categoryId,
            'amount'           => 1000,
            'currency_code'    => 'KES',
            'exchange_rate'    => 1,
            'amount_kes'       => 1000,
            'expense_date'     => '2026-07-01',
            'payment_method'   => 'cash',
            'outlet_id'        => $outletId,
            'created_by'       => $userId,
            'status'           => 'approved',
        ]);
    }

    private function assertRenderedPdf($response): void
    {
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    // ── The IDOR ──────────────────────────────────────────────────────────────

    public function test_outlet_manager_gets_403_for_another_outlets_expense_pdf_and_200_for_their_own(): void
    {
        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();

        $manager = $this->signInAs(['expenses.view'], ['outlet_manager']);
        $manager->outlets()->attach($outletA->id);

        $cat   = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT']);
        $mine  = $this->makeExpense('EXP-A', $cat->id, $outletA->id, $manager->id);
        $their = $this->makeExpense('EXP-B', $cat->id, $outletB->id, $manager->id);

        // Their own outlet still renders.
        $this->assertRenderedPdf($this->get("/api/v1/admin/pdf/expenses/{$mine->id}"));

        // Another outlet's does not — and the body is the 403, not a PDF.
        $res = $this->get("/api/v1/admin/pdf/expenses/{$their->id}");
        $res->assertForbidden();
        $this->assertStringNotContainsString('%PDF', $res->getContent());
    }

    public function test_outlet_manager_cannot_render_a_head_office_expense_pdf(): void
    {
        $outletA = Outlet::factory()->create();

        $manager = $this->signInAs(['expenses.view'], ['outlet_manager']);
        $manager->outlets()->attach($outletA->id);

        $cat        = ExpenseCategory::create(['name' => 'Legal', 'code' => 'LEGAL']);
        $headOffice = $this->makeExpense('EXP-HQ', $cat->id, null, $manager->id);

        // A NULL outlet_id is out of scope for a scoped manager, exactly as it
        // is excluded from their expense list.
        $this->get("/api/v1/admin/pdf/expenses/{$headOffice->id}")->assertForbidden();
    }

    public function test_unassigned_outlet_manager_gets_nothing_not_everything(): void
    {
        $outletA = Outlet::factory()->create();

        // No outlets()->attach() — an empty assignment must mean "nothing".
        $manager = $this->signInAs(['expenses.view'], ['outlet_manager']);

        $cat = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT']);
        $exp = $this->makeExpense('EXP-A', $cat->id, $outletA->id, $manager->id);

        $this->get("/api/v1/admin/pdf/expenses/{$exp->id}")->assertForbidden();
    }

    // ── Unrestricted roles ────────────────────────────────────────────────────

    public function test_admin_can_render_any_outlets_expense_pdf(): void
    {
        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();

        $admin = $this->signInAs(['expenses.view'], ['admin']);

        $cat        = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT']);
        $a          = $this->makeExpense('EXP-A',  $cat->id, $outletA->id, $admin->id);
        $b          = $this->makeExpense('EXP-B',  $cat->id, $outletB->id, $admin->id);
        $headOffice = $this->makeExpense('EXP-HQ', $cat->id, null,         $admin->id);

        $this->assertRenderedPdf($this->get("/api/v1/admin/pdf/expenses/{$a->id}"));
        $this->assertRenderedPdf($this->get("/api/v1/admin/pdf/expenses/{$b->id}"));
        $this->assertRenderedPdf($this->get("/api/v1/admin/pdf/expenses/{$headOffice->id}"));
    }

    public function test_super_admin_is_unrestricted_even_with_the_outlet_manager_role(): void
    {
        $outletA = Outlet::factory()->create();
        $outletB = Outlet::factory()->create();

        $user = $this->signInAs(['expenses.view'], ['outlet_manager', 'super_admin']);
        $user->outlets()->attach($outletA->id);

        $cat = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT']);
        $b   = $this->makeExpense('EXP-B', $cat->id, $outletB->id, $user->id);

        $this->assertRenderedPdf($this->get("/api/v1/admin/pdf/expenses/{$b->id}"));
    }

    public function test_a_non_outlet_scoped_role_such_as_finance_is_unrestricted(): void
    {
        $outletB = Outlet::factory()->create();

        // Not an outlet_manager, so not outlet-scoped — same rule the JSON
        // expense endpoints apply.
        $finance = $this->signInAs(['expenses.view'], ['accountant']);

        $cat = ExpenseCategory::create(['name' => 'Rent', 'code' => 'RENT']);
        $b   = $this->makeExpense('EXP-B', $cat->id, $outletB->id, $finance->id);

        $this->assertRenderedPdf($this->get("/api/v1/admin/pdf/expenses/{$b->id}"));
    }
}
