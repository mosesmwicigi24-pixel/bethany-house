<?php

namespace Tests\Feature;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\FreezesClockMidMonth;
use Tests\TestCase;

/**
 * ONE canonical expense definition across the whole reporting stack:
 *
 *   SPEND = status IN (approved, paid), summed on amount_kes, dated by
 *   expense_date (MetricEngine::EXPENSE_SPEND_STATUSES).
 *
 * The standing incident this pins: MetricEngine filtered expenses on
 * status = 'completed' — a status the expenses module has never written
 * (its vocabulary is draft | pending_approval | approved | rejected |
 * paid | cancelled). Every engine-backed expense figure — the Overview
 * financial block, earned P&L, budget-vs-actual, weekly cash flow, the
 * expense drill — was silently ZERO while ReportController's P&L showed
 * real opex. These tests make the surfaces agree, so a future drift on
 * any one of them goes red.
 */
class ExpenseConsistencyTest extends TestCase
{
    use RefreshDatabase;
    use FreezesClockMidMonth;

    private const SPEND = 5000.0;   // approved 2,000 + paid 3,000
    private const PENDING = 1000.0;

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('sales_manager', 'sanctum'));
        foreach (['reports.view', 'reports.financial'] as $perm) {
            $user->givePermissionTo(Permission::findOrCreate($perm, 'sanctum'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }

    /** One expense per status, all inside the current window. */
    private function seedExpenses(User $user): void
    {
        $outlet = Outlet::factory()->create();

        $categoryId = DB::table('expense_categories')->insertGetId([
            'name' => 'Rent & Utilities', 'code' => 'RENT', 'budget_monthly' => 20000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = [
            ['EXP-CONS-1', 'Pending rent',   'pending_approval', 1000],
            ['EXP-CONS-2', 'Approved rent',  'approved',         2000],
            ['EXP-CONS-3', 'Paid utilities', 'paid',             3000],
            ['EXP-CONS-4', 'Rejected claim', 'rejected',         4000],
            ['EXP-CONS-5', 'Draft note',     'draft',             500],
            ['EXP-CONS-6', 'Cancelled',      'cancelled',         600],
        ];
        foreach ($rows as [$ref, $title, $status, $amount]) {
            DB::table('expenses')->insert([
                'reference_number' => $ref, 'title' => $title,
                'category_id' => $categoryId,
                'amount' => $amount, 'amount_kes' => $amount, 'currency_code' => 'KES',
                'expense_date' => now()->format('Y-m-d'), 'status' => $status,
                'payment_method' => 'cash', 'created_by' => $user->id,
                'outlet_id' => $outlet->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_pnl_overview_and_intelligence_report_the_same_spend(): void
    {
        $this->seedExpenses($this->viewer());

        // P&L (ReportController::profitLoss) — operating expenses.
        $pl = $this->getJson('/api/v1/admin/reports/financial/profit-loss')->assertOk();
        $opex = (float) $pl->json('operating_expenses');

        // Overview financial block (MetricEngine::expenses via executive).
        $exec = $this->getJson('/api/v1/admin/reports/executive?period=this_month')->assertOk();
        $overview = (float) $exec->json('kpis.financial.expenses.current');

        // Earned P&L expense side (MetricEngine::earnedPnl).
        $fi = $this->getJson('/api/v1/admin/reports/financial-intelligence?period=this_month')->assertOk();
        $earnedPnl = (float) $fi->json('pnl.expenses');

        // The agreement IS the point: three surfaces, one number.
        $this->assertSame(self::SPEND, $opex);
        $this->assertSame(self::SPEND, $overview);
        $this->assertSame(self::SPEND, $earnedPnl);
    }

    public function test_pending_rejected_draft_and_cancelled_are_excluded_everywhere(): void
    {
        $this->seedExpenses($this->viewer());

        // Budget-vs-actual (MetricEngine::expensesByCategory).
        $fi = $this->getJson('/api/v1/admin/reports/financial-intelligence?period=this_month')->assertOk();
        $rent = collect($fi->json('expenses'))->firstWhere('category', 'Rent & Utilities');
        $this->assertNotNull($rent);
        $this->assertSame(self::SPEND, (float) $rent['spent']);
        $this->assertSame(2, (int) $rent['entries']);

        // Drill rows are the aggregate with the aggregation removed —
        // only the approved + paid rows, and they sum to the KPI.
        $drill = $this->getJson('/api/v1/admin/reports/drill/expenses?period=this_month')->assertOk();
        $rows = collect($drill->json('rows'));
        $this->assertSame(2, $rows->count());
        $this->assertSame(self::SPEND, (float) $rows->sum('amount'));

        // Overview KPI is 5,000 — not the 11,100 sum of all statuses.
        $exec = $this->getJson('/api/v1/admin/reports/executive?period=this_month')->assertOk();
        $this->assertSame(self::SPEND, (float) $exec->json('kpis.financial.expenses.current'));
    }

    public function test_cash_flow_outflows_count_approved_plus_paid(): void
    {
        // DECISION: outflows = approved + paid (the canonical SPEND set).
        // 'approved' is committed money even before the optional mark-paid
        // transition, and mark-paid adoption is inconsistent — 'paid'-only
        // would understate outflows for teams that never click it.
        $this->seedExpenses($this->viewer());

        // Monthly cash flow (EnhancedReportController::cashFlow).
        $cf = $this->getJson('/api/v1/admin/reports/financial/cash-flow')->assertOk();
        $this->assertSame(self::SPEND, (float) collect($cf->json('outflows'))->sum('outflow'));

        // Weekly cash flow (MetricEngine::cashFlowWeekly).
        $fi = $this->getJson('/api/v1/admin/reports/financial-intelligence?period=this_month')->assertOk();
        $this->assertSame(self::SPEND, (float) collect($fi->json('cash_flow'))->sum('out'));
    }

    public function test_expenses_tab_surfaces_money_awaiting_approval(): void
    {
        $this->seedExpenses($this->viewer());

        $res = $this->getJson('/api/v1/admin/reports/financial/expenses')->assertOk();

        $this->assertSame(1, $res->json('pending_approval.count'));
        $this->assertSame(self::PENDING, (float) $res->json('pending_approval.total'));
    }
}
