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
use App\Models\StaffCommission;
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
 * Commission on top of salary (owner, 2026-11-03/04): finance records it, a
 * super admin approves it, the next pay run pays it, and paying the line
 * brings the person's balance back to zero. PAYE on commission is a switch,
 * off for now.
 *
 * Hand-worked, KES 60,000 a month + KES 5,000 commission = gross 65,000,
 * government deductions on (2026 rows):
 *   NSSF 6% × 65,000 = 3,900 · SHIF 2.75% = 1,787.50 · Housing Levy 1.5% = 975
 *   PAYE off on commission → taxable 65,000 − 5,000 − 3,900 − 1,787.50 − 975 = 53,337.50
 *     PAYE 2,400 + 2,083.25 + 21,004.50 × 30% = 10,784.60 − 2,400 relief = 8,384.60
 *     net 65,000 − 8,384.60 − 3,900 − 1,787.50 − 975 = 49,952.90
 *   PAYE on → the 5,000 is taxed at the 30% band: PAYE 9,884.60, net 48,452.90
 */
class CommissionsTest extends TestCase
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

    private function superAdmin(): User
    {
        $u = $this->staff();
        $u->assignRole('super_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $u->fresh();
    }

    private function record(User $fin, User $who, string $ref, float $amount, string $on = '2026-03-20'): int
    {
        Sanctum::actingAs($fin);
        if ($fin->isSuperAdmin()) $this->stepUp($fin);
        return (int) $this->postJson('/api/v1/admin/payroll/commissions', ['user_id' => $who->id, 'transaction_ref' => $ref, 'amount' => $amount, 'earned_on' => $on])
            ->assertCreated()->json('commission.id');
    }

    private function approveAs(User $boss, int $id): void
    {
        Sanctum::actingAs($boss);
        $this->stepUp($boss);
        $this->postJson("/api/v1/admin/payroll/commissions/{$id}/approve")->assertOk();
    }

    public function test_finance_waits_for_a_super_admin_but_a_super_admin_does_not_wait(): void
    {
        $fin = $this->staff(['payroll.view', 'payroll.run']);
        $boss = $this->superAdmin(); $boss2 = $this->superAdmin();
        $u = $this->staff();
        DB::table('orders')->insert(['order_number' => 'ORD-7781', 'status' => 'pending', 'payment_status' => 'pending', 'currency_code' => 'KES',
            'subtotal' => 0, 'total_amount' => 0, 'created_at' => now(), 'updated_at' => now()]);

        // Finance records: it waits, and finance cannot approve it.
        $id = $this->record($fin, $u, 'ord-7781', 5000);
        $this->assertSame('recorded', StaffCommission::find($id)->status);
        $this->assertNotNull(StaffCommission::find($id)->order_id, 'a hub order number links to its order');
        $this->postJson('/api/v1/admin/payroll/commissions', ['user_id' => $u->id, 'transaction_ref' => 'ORD-7781', 'amount' => 900, 'earned_on' => '2026-03-21'])
            ->assertStatus(422);                                                   // same transaction twice
        $this->stepUp($fin);
        $this->postJson("/api/v1/admin/payroll/commissions/{$id}/approve")->assertStatus(403);
        $this->approveAs($boss, $id);
        $this->assertSame('approved', StaffCommission::find($id)->status);

        // A super admin records and it is approved at once — behind a step-up.
        Sanctum::actingAs($boss2);
        $this->postJson('/api/v1/admin/payroll/commissions', ['user_id' => $u->id, 'transaction_ref' => 'QX91AB', 'amount' => 1000, 'earned_on' => '2026-03-20'])
            ->assertStatus(403)->assertJsonPath('code', 'step_up_required');
        $this->stepUp($boss2);
        $own = $this->postJson('/api/v1/admin/payroll/commissions', ['user_id' => $u->id, 'transaction_ref' => 'QX91AB', 'amount' => 1000, 'earned_on' => '2026-03-20'])
            ->assertCreated()->assertJsonPath('commission.status', 'approved')->json('commission.id');
        $this->assertSame($boss2->id, StaffCommission::find($own)->approved_by);

        // A commission paid to the super admin themselves still needs another super admin.
        $mine = $this->record($boss, $boss, 'SELF-1', 800);
        $this->assertSame('recorded', StaffCommission::find($mine)->status);
        $this->postJson("/api/v1/admin/payroll/commissions/{$mine}/approve")->assertStatus(403);
        $this->approveAs($boss2, $mine);
        $this->assertDatabaseHas('activity_log', ['event' => 'commission_approved']);
    }

    public function test_commission_is_paid_on_top_of_salary_without_paye_and_clears_the_balance(): void
    {
        $fin = $this->staff(['payroll.view', 'payroll.run']);
        $boss = $this->superAdmin();
        $u = $this->staff();
        StatutoryEnrolment::create(['user_id' => $u->id, 'effective_from' => '2026-01-01', 'applies' => true, 'reason' => 'Permanent']);
        $march = $this->period('2026-03-10');
        $this->onPayroll($u, $march);

        $paid  = $this->record($fin, $u, 'ORD-1001', 5000);
        $this->approveAs($boss, $paid);
        $this->record($fin, $u, 'ORD-1002', 700);                                  // recorded, not approved: waits
        $later = $this->record($fin, $u, 'ORD-1003', 400, '2026-04-01');           // earned after March: next run
        $this->approveAs($boss, $later);

        $run = $this->draftRun($fin, $march);
        $l = $this->line($run, $u);
        $this->assertEquals(65000, (float) $l->gross);
        $this->assertEquals(3900, (float) $l->nssf_employee);
        $this->assertEquals(1787.50, (float) $l->shif);
        $this->assertEquals(975, (float) $l->housing_employee);
        $this->assertEquals(53337.50, (float) $l->taxable);
        $this->assertEquals(8384.60, (float) $l->paye);
        $this->assertEquals(49952.90, (float) $l->net);
        $this->assertContains('Commission · ORD-1001 (no PAYE)', collect($l->items)->pluck('label')->all());
        $this->assertSame([$paid], $l->commissions);

        // People & pay: owed 5,400 approved (5,000 + 400); 700 awaiting approval.
        $row = collect($this->getJson('/api/v1/admin/payroll/people')->json('data'))->firstWhere('id', $u->id);
        $this->assertEquals(['owed' => 5400, 'pending' => 700], $row['commission']);

        $this->stepUp($fin);
        $this->postJson("/api/v1/admin/payroll/runs/{$run->id}/approve")->assertOk();
        $this->assertSame($l->id, StaffCommission::find($paid)->payroll_line_id);
        $this->postJson("/api/v1/admin/payroll/commissions/{$paid}/void", ['reason' => 'Mistake'])->assertStatus(422);   // on a payslip now

        $this->postJson("/api/v1/admin/payroll/runs/{$run->id}/pay", ['lines' => [['line_id' => $l->id, 'payment_method' => 'mpesa', 'paid_on' => '2026-04-02']]])->assertOk();
        $c = StaffCommission::find($paid);
        $this->assertSame('paid', $c->status);
        $this->assertSame('2026-04-02', $c->paid_at->toDateString());
        $row = collect($this->getJson('/api/v1/admin/payroll/people')->json('data'))->firstWhere('id', $u->id);
        $this->assertEquals(['owed' => 400, 'pending' => 700], $row['commission'], 'March commission paid; April waits for its run');

        $this->artisan('payroll:reconcile')->assertExitCode(0);
    }

    public function test_the_paye_switch_is_the_super_admins_and_taxes_commission_when_on(): void
    {
        $fin = $this->staff(['payroll.view', 'payroll.run']);
        $boss = $this->superAdmin();
        $u = $this->staff();
        StatutoryEnrolment::create(['user_id' => $u->id, 'effective_from' => '2026-01-01', 'applies' => true, 'reason' => 'Permanent']);
        $march = $this->period('2026-03-10');
        $this->onPayroll($u, $march);
        $this->approveAs($boss, $this->record($fin, $u, 'ORD-2001', 5000));

        Sanctum::actingAs($fin); $this->stepUp($fin);
        $this->postJson('/api/v1/admin/payroll/commissions/paye', ['applies' => true])->assertStatus(403);
        Sanctum::actingAs($boss); $this->stepUp($boss);
        $this->postJson('/api/v1/admin/payroll/commissions/paye', ['applies' => true])->assertOk()->assertJsonPath('paye_applies', true);

        $l = $this->line($this->draftRun($fin, $march), $u);
        $this->assertEquals(58337.50, (float) $l->taxable);
        $this->assertEquals(9884.60, (float) $l->paye);                            // 1,500 more: 5,000 at 30%
        $this->assertEquals(48452.90, (float) $l->net);
        $this->assertContains('Commission · ORD-2001', collect($l->items)->pluck('label')->all());
    }

    public function test_a_voided_commission_is_never_paid_and_a_contract_hire_gets_it_gross(): void
    {
        $fin = $this->staff(['payroll.view', 'payroll.run']);
        $boss = $this->superAdmin();
        $u = $this->staff();                                                       // contract: no deductions
        $march = $this->period('2026-03-10');
        $this->onPayroll($u, $march);
        $keep = $this->record($fin, $u, 'QK55', 3000);
        $drop = $this->record($fin, $u, 'QK56', 2000);
        $this->approveAs($boss, $keep); $this->approveAs($boss, $drop);

        Sanctum::actingAs($fin);
        $this->postJson("/api/v1/admin/payroll/commissions/{$drop}/void", [])->assertStatus(422);              // reason required
        $this->postJson("/api/v1/admin/payroll/commissions/{$drop}/void", ['reason' => 'Order cancelled'])->assertOk();

        $l = $this->line($this->draftRun($fin, $march), $u);
        $this->assertEquals(63000, (float) $l->gross);
        $this->assertEquals(63000, (float) $l->net, 'contract: commission paid gross');
        $this->assertSame([$keep], $l->commissions);

        // A commission voided after the draft was built refuses the approval until recalculated.
        $run = PayrollRun::first();
        StaffCommission::whereKey($keep)->update(['status' => 'void', 'void_reason' => 'x']);
        $this->stepUp($fin);
        $this->postJson("/api/v1/admin/payroll/runs/{$run->id}/approve")->assertStatus(422);
        $this->assertSame('draft', $run->fresh()->status);
    }
}
