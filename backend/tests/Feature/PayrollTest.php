<?php

namespace Tests\Feature;

use App\Models\EmployeeProfile;
use App\Models\Expense;
use App\Models\Outlet;
use App\Models\PayGroup;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\PayStructure;
use App\Models\PublicHoliday;
use App\Models\StatutoryRateTable;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\People\Payroll;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\StepsUp;
use Tests\TestCase;

/**
 * Bethany People PR 6 — payroll, from an independent fixture.
 *
 * March 2026 (Mon 2 … Tue 31): 26 working days Mon–Sat, less the test
 * holiday on Wed 4 Mar = 25. Every expected figure below is worked by hand
 * from the seeded 2026 tables, not from the service's own formula:
 *
 *   Monthly 60,000, no OT:
 *     NSSF 6% × 60,000 = 3,600 (each side)   SHIF 2.75% × 60,000 = 1,650
 *     Levy 1.5% × 60,000 = 900 (each side)
 *     Taxable = 60,000 − 3,600 − 1,650 − 900 = 53,850
 *     PAYE = 24,000×10% + 8,333×25% + 21,517×30% − 2,400
 *          = 2,400 + 2,083.25 + 6,455.10 − 2,400 = 8,538.35
 *     Net = 60,000 − 8,538.35 − 3,600 − 1,650 − 900 = 45,311.65
 *     Employer cost = 60,000 + 3,600 + 900 = 64,500
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;
    use StepsUp;

    private Outlet $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Artisan::call('permission:sync');
        config(['attendance.working_days' => [1, 2, 3, 4, 5, 6]]);
        Carbon::setTestNow('2026-04-02 12:00:00');
        $this->shop = Outlet::factory()->create();
        PublicHoliday::create(['date' => '2026-03-04', 'name' => 'Test Day', 'country' => 'KE']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(array $permissions = [], array $profile = []): User
    {
        $u = User::factory()->create(['status' => 'active', 'user_type' => 'staff']);
        if ($permissions) {
            $role = Role::findOrCreate('probe_' . uniqid(), 'sanctum');
            foreach ($permissions as $p) $role->givePermissionTo(Permission::findOrCreate($p, 'sanctum'));
            $u->assignRole($role);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
        DB::table('outlet_user')->insert(['user_id' => $u->id, 'outlet_id' => $this->shop->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        EmployeeProfile::factory()->create($profile + ['user_id' => $u->id, 'hire_date' => '2024-01-01']);
        return $u->fresh();
    }

    private function lockedMarch(): PayPeriod
    {
        $p = PayGroup::defaultGroup()->periodFor(Carbon::parse('2026-03-10'));
        $p->update(['status' => 'locked', 'locked_at' => now()]);
        return $p->fresh();
    }

    private function approvedSheet(User $u, PayPeriod $p, array $hours = []): Timesheet
    {
        return Timesheet::create(['pay_period_id' => $p->id, 'user_id' => $u->id, 'status' => 'approved', 'approved_at' => now(), 'snapshot' => true] + $hours + [
            'regular_minutes' => 25 * 480, 'overtime_minutes' => 0, 'rest_day_minutes' => 0, 'holiday_minutes' => 0, 'paid_leave_days' => 0, 'unpaid_leave_days' => 0, 'days_worked' => 25,
        ]);
    }

    private function structure(User $u, string $kind, float $base, array $allowances = [], string $from = '2026-01-01'): PayStructure
    {
        return PayStructure::create(['user_id' => $u->id, 'effective_from' => $from, 'kind' => $kind, 'base_amount' => $base, 'currency_code' => 'KES', 'allowances' => $allowances ?: null]);
    }

    public function test_monthly_salary_statutory_deductions_match_the_hand_worked_figures(): void
    {
        $u = $this->staff();
        $p = $this->lockedMarch();
        $this->approvedSheet($u, $p);
        $this->structure($u, 'monthly', 60000);

        $run = Payroll::draft($p, $u);
        $this->assertSame(1, $run->people);
        $l = $run->lines()->first();
        $this->assertEquals(60000, $l->basic);
        $this->assertEquals(3600, $l->nssf_employee);
        $this->assertEquals(3600, $l->nssf_employer);
        $this->assertEquals(1650, $l->shif);
        $this->assertEquals(900, $l->housing_employee);
        $this->assertEquals(53850, $l->taxable);
        $this->assertEquals(8538.35, $l->paye);
        $this->assertEquals(45311.65, $l->net);
        $this->assertEquals(64500, $l->employer_cost);
        $this->assertEquals(45311.65, $run->net);
        $this->assertSame([], Payroll::reconcile($run->fresh(['lines', 'expense'])));
        $this->assertSame('PAY-202603-01', $run->reference);
        $this->assertFalse($run->rates_used[0]['is_confirmed']);
    }

    public function test_low_pay_zero_pay_and_paye_relief_floor(): void
    {
        // 12,000 a month: PAYE 1,200 − 2,400 → 0 (never negative); SHIF floor 300 > 2.75% × 12,000 = 330? no: 330 > 300 so 330.
        // 8,000 a month: SHIF 2.75% × 8,000 = 220 → floor 300.
        $p = $this->lockedMarch();
        $a = $this->staff(); $this->approvedSheet($a, $p); $this->structure($a, 'monthly', 12000);
        $b = $this->staff(); $this->approvedSheet($b, $p); $this->structure($b, 'monthly', 8000);
        $c = $this->staff(); $this->approvedSheet($c, $p, ['regular_minutes' => 0, 'days_worked' => 0]); $this->structure($c, 'hourly', 250); // no hours: nothing earned
        $run = Payroll::draft($p, $a);
        $la = $run->lines()->where('user_id', $a->id)->first();
        $this->assertEquals(0, $la->paye);
        $this->assertEquals(330, $la->shif);
        $lb = $run->lines()->where('user_id', $b->id)->first();
        $this->assertEquals(300, $lb->shif);
        $lc = $run->lines()->where('user_id', $c->id)->first();
        $this->assertEquals(0, $lc->gross);
        $this->assertEquals(0, $lc->shif);
        $this->assertEquals(0, $lc->net);
        $this->assertSame([], Payroll::reconcile($run->fresh(['lines'])));
    }

    public function test_hourly_overtime_allowances_unpaid_leave_and_prorated_starter(): void
    {
        $p = $this->lockedMarch();
        // Hourly 200/h: 160 h regular + 10 h OT ×1.5 + 4 h holiday ×2 → 32,000 + 3,000 + 1,600 = 36,600; a 2,000 non-taxable lunch allowance.
        $h = $this->staff(); $this->approvedSheet($h, $p, ['regular_minutes' => 160 * 60, 'overtime_minutes' => 600, 'holiday_minutes' => 240, 'days_worked' => 21]);
        $this->structure($h, 'hourly', 200, [['name' => 'Lunch', 'amount' => 2000, 'taxable' => false]]);
        // Monthly 50,000 with 2 unpaid days: deduction 2 × 50,000/25 = 4,000 → gross 46,000.
        $m = $this->staff(); $this->approvedSheet($m, $p, ['unpaid_leave_days' => 2, 'days_worked' => 23]); $this->structure($m, 'monthly', 50000);
        // Starter hired 16 Mar on 31,000: 16/31 of the month = 16,000.
        $s = $this->staff([], ['hire_date' => '2026-03-16']); $this->approvedSheet($s, $p, ['regular_minutes' => 14 * 480, 'days_worked' => 14]); $this->structure($s, 'monthly', 31000);

        $run = Payroll::draft($p, $h);
        $lh = $run->lines()->where('user_id', $h->id)->first();
        $this->assertEquals(32000, $lh->basic);
        $this->assertEquals(3000, $lh->overtime_pay);
        $this->assertEquals(1600, $lh->holiday_pay);
        $this->assertEquals(2000, $lh->allowances);
        $this->assertEquals(38600, $lh->gross);
        // NSSF 6% × 38,600 = 2,316; SHIF 1,061.50; levy 579; taxable = 38,600 − 2,000 − 2,316 − 1,061.50 − 579 = 32,643.50
        $this->assertEquals(32643.5, $lh->taxable);
        $lm = $run->lines()->where('user_id', $m->id)->first();
        $this->assertEquals(4000, $lm->unpaid_deduction);
        $this->assertEquals(46000, $lm->gross);
        $ls = $run->lines()->where('user_id', $s->id)->first();
        $this->assertEquals(16000, $ls->basic);
        $this->assertSame([], Payroll::reconcile($run->fresh(['lines'])));
    }

    public function test_run_needs_a_locked_period_an_approved_sheet_and_a_structure(): void
    {
        $p = PayGroup::defaultGroup()->periodFor(Carbon::parse('2026-03-10'));
        $u = $this->staff();
        try { Payroll::draft($p, $u); $this->fail('open period accepted'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }

        $p = $this->lockedMarch();
        $noSheet = $this->staff(); $this->structure($noSheet, 'monthly', 30000);
        $noPay = $this->staff(); $this->approvedSheet($noPay, $p);
        Timesheet::create(['pay_period_id' => $p->id, 'user_id' => $u->id, 'status' => 'submitted', 'regular_minutes' => 100]); $this->structure($u, 'monthly', 30000);
        $run = Payroll::draft($p, $u);
        $this->assertSame(0, $run->people);
        $this->assertEqualsCanonicalizing(['No pay structure', 'No approved timesheet', 'No approved timesheet'], array_column($run->unpayable, 'why'));
    }

    public function test_approve_posts_one_expense_pays_per_line_and_is_idempotent(): void
    {
        $fin = $this->staff(['payroll.view', 'payroll.run']);
        $viewer = $this->staff(['payroll.view']);
        $p = $this->lockedMarch();
        $this->approvedSheet($fin, $p); $this->structure($fin, 'monthly', 60000);
        $this->approvedSheet($viewer, $p); $this->structure($viewer, 'monthly', 40000);

        Sanctum::actingAs($viewer);
        $this->postJson('/api/v1/admin/payroll/runs', ['pay_period_id' => $p->id])->assertStatus(403);

        Sanctum::actingAs($fin);
        $r = $this->postJson('/api/v1/admin/payroll/runs', ['pay_period_id' => $p->id])->assertCreated();
        $id = $r->json('run.id');
        $this->assertSame(2, $r->json('run.people'));
        // Drafting again refreshes the same draft rather than making a second run.
        $this->postJson('/api/v1/admin/payroll/runs', ['pay_period_id' => $p->id])->assertOk()->assertJsonPath('run.id', $id);
        $this->assertSame(1, PayrollRun::count());

        // Approve needs a step-up.
        $this->postJson("/api/v1/admin/payroll/runs/{$id}/approve")->assertStatus(403)->assertJsonPath('code', 'step_up_required');
        $this->stepUp($fin);
        $this->postJson("/api/v1/admin/payroll/runs/{$id}/approve")->assertOk()->assertJsonPath('run.status', 'approved');
        $run = PayrollRun::find($id);
        $expense = Expense::withoutViewerScope()->find($run->expense_id); // company-wide: outside an outlet-scoped viewer's list
        $this->assertNotNull($expense);
        $this->assertSame('approved', $expense->status);
        $this->assertEquals(64500 + 43000, $expense->amount_kes); // 40,000 + 2,400 + 600 = 43,000
        $this->assertSame('2026-03-31', $expense->expense_date->toDateString());
        $this->assertSame('SALARIES', $expense->category->code);
        $this->assertSame(1, Expense::withoutViewerScope()->count());
        // Second approve is refused; a second regular run for the period is refused.
        $this->postJson("/api/v1/admin/payroll/runs/{$id}/approve")->assertStatus(422);
        $this->postJson('/api/v1/admin/payroll/runs', ['pay_period_id' => $p->id])->assertStatus(422);
        $this->deleteJson("/api/v1/admin/payroll/runs/{$id}")->assertStatus(422);
        $this->assertSame(1, Expense::withoutViewerScope()->count());

        // Pay one line, then the other; the run and expense flip to paid only when all are paid.
        $lines = $run->lines()->orderBy('user_id')->pluck('id')->all();
        $this->postJson("/api/v1/admin/payroll/runs/{$id}/pay", ['lines' => [['line_id' => $lines[0], 'payment_method' => 'mpesa', 'payment_reference' => 'QX1']]])->assertOk()->assertJsonPath('run.status', 'approved');
        $this->assertSame('approved', $expense->fresh()->status);
        $this->postJson("/api/v1/admin/payroll/runs/{$id}/pay", ['lines' => [['line_id' => $lines[0], 'payment_method' => 'cash'], ['line_id' => $lines[1], 'payment_method' => 'bank', 'payment_reference' => 'TT9']]])->assertOk()->assertJsonPath('run.status', 'paid');
        $this->assertSame('mpesa', $run->lines()->find($lines[0])->payment_method); // the retry did not overwrite
        $this->assertSame('paid', $expense->fresh()->status);
        $this->assertSame($fin->id, $expense->fresh()->paid_by);

        $this->getJson("/api/v1/admin/payroll/runs/{$id}")->assertOk()->assertJsonPath('run.reconciles', true)->assertJsonPath('run.expense.status', 'paid');
        $this->artisan('payroll:reconcile')->assertExitCode(0);
    }

    public function test_payslips_are_the_persons_own_behind_step_up_and_rates_are_dated_rows(): void
    {
        $fin = $this->staff(['payroll.view', 'payroll.run']);
        $other = $this->staff();
        $p = $this->lockedMarch();
        $this->approvedSheet($other, $p); $this->structure($other, 'monthly', 25000);
        $run = Payroll::approve(Payroll::draft($p, $fin), $fin);
        $line = $run->lines()->first();

        Sanctum::actingAs($other);
        $this->getJson('/api/v1/admin/me/payslips')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.run.reference', $run->reference);
        $this->get("/api/v1/admin/me/payslips/{$line->id}/pdf")->assertStatus(403);
        $this->stepUp($other);
        $this->get("/api/v1/admin/me/payslips/{$line->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // Not somebody else's.
        Sanctum::actingAs($fin); $this->stepUp($fin);
        $this->getJson('/api/v1/admin/me/payslips')->assertOk()->assertJsonCount(0, 'data');
        $this->get("/api/v1/admin/me/payslips/{$line->id}/pdf")->assertStatus(404);
        $this->get("/api/v1/admin/payroll/runs/{$run->id}/lines/{$line->id}/payslip")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Rates: a new dated row for 2027 does not touch 2026 runs; confirm marks the seeded row.
        $this->getJson('/api/v1/admin/payroll/rates')->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('data.0.is_confirmed', false);
        $this->postJson('/api/v1/admin/payroll/rates', ['code' => 'shif', 'effective_from' => '2027-01-01', 'config' => ['rate' => 0.03, 'minimum' => 300], 'reduces_taxable_pay' => false, 'source' => 'test'])->assertCreated();
        $this->assertSame(0.0275, StatutoryRateTable::inForce(Carbon::parse('2026-12-31'))['shif']->config['rate']);
        $this->assertSame(0.03, StatutoryRateTable::inForce(Carbon::parse('2027-01-01'))['shif']->config['rate']);
        $this->postJson('/api/v1/admin/payroll/rates', ['code' => 'paye', 'effective_from' => '2027-01-01', 'config' => ['bands' => []], 'reduces_taxable_pay' => false])->assertStatus(422);
        $seeded = StatutoryRateTable::where('code', 'paye')->first();
        $this->postJson("/api/v1/admin/payroll/rates/{$seeded->id}/confirm")->assertOk()->assertJsonPath('rate.is_confirmed', true);
        $this->assertSame($fin->id, $seeded->fresh()->confirmed_by);
        $this->assertEquals(25000, $run->fresh()->gross); // frozen

        // Pay structure on the file: hr.view_sensitive + step-up to read; hr.edit to write; dated rows.
        $this->getJson("/api/v1/admin/people/{$other->id}/pay")->assertStatus(403);
        $hr = $this->staff(['hr.view', 'hr.view_sensitive', 'hr.edit']);
        Sanctum::actingAs($hr);
        $this->getJson("/api/v1/admin/people/{$other->id}/pay")->assertStatus(403)->assertJsonPath('code', 'step_up_required');
        $this->stepUp($hr);
        $this->getJson("/api/v1/admin/people/{$other->id}/pay")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('can_edit', true);
        $this->postJson("/api/v1/admin/people/{$other->id}/pay", ['effective_from' => '2026-04-01', 'kind' => 'monthly', 'base_amount' => 28000])->assertCreated();
        $this->postJson("/api/v1/admin/people/{$other->id}/pay", ['effective_from' => '2026-04-01', 'kind' => 'monthly', 'base_amount' => 29000])->assertStatus(422);
        $this->assertEquals(25000, Payroll::structureFor($other, Carbon::parse('2026-03-31'))->base_amount);
        $this->assertEquals(28000, Payroll::structureFor($other, Carbon::parse('2026-04-01'))->base_amount);
    }
}
