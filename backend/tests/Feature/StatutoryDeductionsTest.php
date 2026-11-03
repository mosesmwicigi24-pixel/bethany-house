<?php

namespace Tests\Feature;

use App\Models\EmployeeProfile;
use App\Models\Expense;
use App\Models\Outlet;
use App\Models\PayGroup;
use App\Models\PayPeriod;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\PayStructure;
use App\Models\StatutoryEnrolment;
use App\Models\Timesheet;
use App\Models\User;
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
 * Government deductions per person and the real paid date (owner,
 * 2026-11-03): staff are on contracts, so PAYE, NSSF, SHIF and the Housing
 * Levy apply only once accounts add them; when someone becomes permanent,
 * one switch adds all four from a date.
 *
 * Figures are worked by hand. KES 60,000 a month, 2026 rows:
 *   NSSF 6% of 60,000 = 3,600 · SHIF 2.75% = 1,650 · Housing Levy 1.5% = 900
 *   taxable 60,000 − 3,600 − 1,650 − 900 = 53,850
 *   PAYE 24,000×10% + 8,333×25% + 21,517×30% = 10,938.35 − 2,400 relief = 8,538.35
 *   net 60,000 − 8,538.35 − 3,600 − 1,650 − 900 = 45,311.65
 *   employer cost 60,000 + 3,600 + 900 = 64,500
 */
class StatutoryDeductionsTest extends TestCase
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
        PayGroup::query()->update(['period_end_day' => null]);
        Carbon::setTestNow('2026-04-02 12:00:00');
        $this->shop = Outlet::factory()->create();
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

    private function period(string $day): PayPeriod
    {
        $p = PayGroup::defaultGroup()->periodFor(Carbon::parse($day));
        $p->update(['status' => 'locked', 'locked_at' => now()]);
        return $p->fresh();
    }

    private function onPayroll(User $u, PayPeriod $p, float $monthly = 60000): void
    {
        Timesheet::create(['pay_period_id' => $p->id, 'user_id' => $u->id, 'status' => 'approved', 'approved_at' => now(), 'snapshot' => true,
            'regular_minutes' => 25 * 480, 'overtime_minutes' => 0, 'rest_day_minutes' => 0, 'holiday_minutes' => 0, 'paid_leave_days' => 0, 'unpaid_leave_days' => 0, 'days_worked' => 25]);
        PayStructure::firstOrCreate(['user_id' => $u->id, 'effective_from' => '2026-01-01'], ['kind' => 'monthly', 'base_amount' => $monthly, 'currency_code' => 'KES']);
    }

    private function draftRun(User $fin, PayPeriod $p): PayrollRun
    {
        Sanctum::actingAs($fin);
        $id = $this->postJson('/api/v1/admin/payroll/runs', ['pay_period_id' => $p->id])->assertSuccessful()->json('run.id');
        return PayrollRun::findOrFail($id);
    }

    private function line(PayrollRun $run, User $u): PayrollLine
    {
        return $run->lines()->where('user_id', $u->id)->firstOrFail();
    }

    public function test_a_contract_hire_is_paid_gross_with_no_government_deductions(): void
    {
        $fin = $this->staff(['payroll.view', 'payroll.run']);
        $contract = $this->staff();
        $march = $this->period('2026-03-10');
        $this->onPayroll($contract, $march);

        $l = $this->line($this->draftRun($fin, $march), $contract);
        foreach (['paye', 'nssf_employee', 'nssf_employer', 'shif', 'housing_employee', 'housing_employer'] as $c) {
            $this->assertEquals(0, (float) $l->{$c}, $c);
        }
        $this->assertEquals(60000, (float) $l->gross);
        $this->assertEquals(60000, (float) $l->net, 'net = gross');
        $this->assertEquals(60000, (float) $l->employer_cost);
        $this->assertFalse($l->structure['statutory']);
        $this->assertSame([], collect($l->items)->where('kind', 'deduction')->values()->all());
    }

    public function test_adding_deductions_applies_all_four_from_the_date_with_hand_worked_figures(): void
    {
        $fin = $this->staff(['payroll.view', 'payroll.run']);
        $made = $this->staff();
        $march = $this->period('2026-03-10');
        $this->onPayroll($made, $march);

        Sanctum::actingAs($fin);
        $this->postJson("/api/v1/admin/payroll/people/{$made->id}/deductions", ['applies' => true, 'effective_from' => '2026-03-01', 'reason' => 'Made permanent'])
            ->assertStatus(403)->assertJsonPath('code', 'step_up_required');
        $this->stepUp($fin);
        $this->postJson("/api/v1/admin/payroll/people/{$made->id}/deductions", ['applies' => true, 'effective_from' => '2026-03-01', 'reason' => 'Made permanent'])->assertCreated();

        $l = $this->line($this->draftRun($fin, $march), $made);
        $this->assertEquals(3600, (float) $l->nssf_employee);
        $this->assertEquals(3600, (float) $l->nssf_employer);
        $this->assertEquals(1650, (float) $l->shif);
        $this->assertEquals(900, (float) $l->housing_employee);
        $this->assertEquals(53850, (float) $l->taxable);
        $this->assertEquals(8538.35, (float) $l->paye);
        $this->assertEquals(45311.65, (float) $l->net);
        $this->assertEquals(64500, (float) $l->employer_cost);
        $this->assertTrue($l->structure['statutory']);
    }

    public function test_stopping_deductions_leaves_the_approved_payslip_as_it_was(): void
    {
        $fin = $this->staff(['payroll.view', 'payroll.run']);
        $u = $this->staff();
        StatutoryEnrolment::create(['user_id' => $u->id, 'effective_from' => '2026-01-01', 'applies' => true, 'reason' => 'Permanent']);
        $march = $this->period('2026-03-10');
        $this->onPayroll($u, $march);
        $run = $this->draftRun($fin, $march);
        $this->stepUp($fin);
        $this->postJson("/api/v1/admin/payroll/runs/{$run->id}/approve")->assertOk();

        // Back into the approved March run: refused.
        $this->postJson("/api/v1/admin/payroll/people/{$u->id}/deductions", ['applies' => false, 'effective_from' => '2026-03-15', 'reason' => 'Back to contract'])->assertStatus(422);
        // From April: accepted, and March is untouched.
        $this->postJson("/api/v1/admin/payroll/people/{$u->id}/deductions", ['applies' => false, 'effective_from' => '2026-04-01', 'reason' => 'Back to contract'])->assertCreated();
        $this->assertEquals(8538.35, (float) $this->line($run->fresh(), $u)->paye);

        $april = $this->period('2026-04-10');
        $this->onPayroll($u, $april);
        $this->assertEquals(0, (float) $this->line($this->draftRun($fin, $april), $u)->paye);
    }

    public function test_the_switch_needs_a_reason_a_real_change_and_payroll_rights(): void
    {
        $fin = $this->staff(['payroll.view', 'payroll.run']);
        $viewer = $this->staff(['payroll.view']);
        $u = $this->staff();

        Sanctum::actingAs($viewer);
        $this->stepUp($viewer);
        $this->postJson("/api/v1/admin/payroll/people/{$u->id}/deductions", ['applies' => true, 'effective_from' => '2026-04-01', 'reason' => 'x x x'])->assertStatus(403);

        Sanctum::actingAs($fin);
        $this->stepUp($fin);
        $this->postJson("/api/v1/admin/payroll/people/{$u->id}/deductions", ['applies' => true, 'effective_from' => '2026-04-01'])->assertStatus(422);
        $this->postJson("/api/v1/admin/payroll/people/{$u->id}/deductions", ['applies' => false, 'effective_from' => '2026-04-01', 'reason' => 'Contract'])
            ->assertStatus(422)->assertJsonPath('message', 'Government deductions already do not apply from that date.');
        $this->postJson("/api/v1/admin/payroll/people/{$u->id}/deductions", ['applies' => true, 'effective_from' => '2026-04-01', 'reason' => 'Permanent'])->assertCreated();
        $this->assertDatabaseHas('activity_log', ['event' => 'statutory_deductions_added', 'subject_id' => $u->id]);
    }

    public function test_returns_list_only_people_deductions_applied_to(): void
    {
        $fin = $this->staff(['payroll.view', 'payroll.run']);
        $perm = $this->staff(); $contract = $this->staff();
        StatutoryEnrolment::create(['user_id' => $perm->id, 'effective_from' => '2026-01-01', 'applies' => true, 'reason' => 'Permanent']);
        $march = $this->period('2026-03-10');
        $this->onPayroll($perm, $march); $this->onPayroll($contract, $march);
        $run = $this->draftRun($fin, $march);
        $this->stepUp($fin);
        $this->postJson("/api/v1/admin/payroll/runs/{$run->id}/approve")->assertOk();

        $csv = $this->get('/api/v1/admin/payroll/returns?month=2026-03&kind=paye')->assertOk()->getContent();
        $this->assertSame(2, substr_count(trim($csv), "\n") + 1, 'header + the one enrolled person');
        $this->assertStringContainsString('8538.35', $csv);
    }

    public function test_payment_records_the_day_the_money_went_out(): void
    {
        $fin = $this->staff(['payroll.view', 'payroll.run']);
        $u = $this->staff();
        $march = $this->period('2026-03-10');
        $this->onPayroll($u, $march);
        $run = $this->draftRun($fin, $march);
        $this->stepUp($fin);
        $this->postJson("/api/v1/admin/payroll/runs/{$run->id}/approve")->assertOk();
        $line = $this->line($run, $u)->id;

        Carbon::setTestNow('2026-04-06 09:00:00');
        $this->stepUp($fin);
        $pay = fn ($on) => $this->postJson("/api/v1/admin/payroll/runs/{$run->id}/pay", ['lines' => [['line_id' => $line, 'payment_method' => 'mpesa', 'payment_reference' => 'QAB12', 'paid_on' => $on]]]);
        $pay('2026-04-07')->assertStatus(422);           // not in the future
        $pay('2026-04-01')->assertStatus(422);           // not before the run was approved
        $pay('2026-04-03')->assertOk()->assertJsonPath('run.status', 'paid');

        $this->assertSame('2026-04-03', PayrollLine::find($line)->paid_at->toDateString());
        $this->assertSame('2026-04-03', $run->fresh()->paid_at->toDateString());
        $this->assertSame('2026-04-03', Expense::withoutViewerScope()->find($run->fresh()->expense_id)->paid_at->toDateString());
    }

    public function test_people_and_pay_lists_salary_deductions_and_last_payment(): void
    {
        $fin = $this->staff(['payroll.view', 'payroll.run']);
        $u = $this->staff();
        $march = $this->period('2026-03-10');
        $this->onPayroll($u, $march, 45000);
        StatutoryEnrolment::create(['user_id' => $u->id, 'effective_from' => '2026-05-01', 'applies' => true, 'reason' => 'Permanent from May']);
        $run = $this->draftRun($fin, $march);
        $this->stepUp($fin);
        $this->postJson("/api/v1/admin/payroll/runs/{$run->id}/approve")->assertOk();
        $this->postJson("/api/v1/admin/payroll/runs/{$run->id}/pay", ['lines' => [['line_id' => $this->line($run, $u)->id, 'payment_method' => 'cash']]])->assertOk();

        $row = collect($this->getJson('/api/v1/admin/payroll/people')->assertOk()->json('data'))->firstWhere('id', $u->id);
        $this->assertSame(['kind' => 'monthly', 'base_amount' => 45000, 'effective_from' => '2026-01-01'], $row['salary']);
        $this->assertFalse($row['deductions']['applies']);
        $this->assertSame(['applies' => true, 'from' => '2026-05-01'], $row['deductions']['next']);
        $this->assertEquals(45000, $row['last_paid']['net']);
        $this->assertSame('2026-04-02', $row['last_paid']['paid_on']);
    }
}
