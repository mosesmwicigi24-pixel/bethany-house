<?php

namespace Tests\Feature;

use App\Models\AdvanceRepayment;
use App\Models\EmployeeProfile;
use App\Models\Outlet;
use App\Models\PayGroup;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\PayStructure;
use App\Models\StaffAdvance;
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
 * Bethany People PR 7 — advances repaid through payroll, payout files and
 * statutory return CSVs. Figures reuse the PR 6 fixture: monthly 60,000 nets
 * 45,311.65 before any advance.
 */
class AdvancesAndReturnsTest extends TestCase
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

    private function onPayroll(User $u, PayPeriod $p, float $monthly = 60000): void
    {
        Timesheet::create(['pay_period_id' => $p->id, 'user_id' => $u->id, 'status' => 'approved', 'approved_at' => now(), 'snapshot' => true,
            'regular_minutes' => 25 * 480, 'overtime_minutes' => 0, 'rest_day_minutes' => 0, 'holiday_minutes' => 0, 'paid_leave_days' => 0, 'unpaid_leave_days' => 0, 'days_worked' => 25]);
        PayStructure::create(['user_id' => $u->id, 'effective_from' => '2026-01-01', 'kind' => 'monthly', 'base_amount' => $monthly, 'currency_code' => 'KES']);
    }

    private function paidAdvance(User $u, float $amount, string $kind = 'advance', ?float $instalment = null): StaffAdvance
    {
        return StaffAdvance::create(['user_id' => $u->id, 'kind' => $kind, 'amount' => $amount, 'instalment' => $instalment ?? $amount, 'balance' => $amount, 'reason' => 'test',
            'status' => 'paid', 'paid_at' => now()->subDays(10), 'payment_method' => 'cash']);
    }

    public function test_request_approve_payout_then_payroll_takes_the_instalments_and_settles(): void
    {
        $fin = $this->staff(['payroll.view', 'payroll.run']);
        $u = $this->staff();

        Sanctum::actingAs($u);
        $this->postJson('/api/v1/admin/me/advances', ['kind' => 'loan', 'amount' => 30000, 'instalment' => 20000, 'reason' => 'School fees'])->assertCreated()->assertJsonPath('advance.status', 'requested');
        $this->postJson('/api/v1/admin/me/advances', ['kind' => 'advance', 'amount' => 1000, 'reason' => 'again'])->assertStatus(422); // one waiting at a time
        $id = StaffAdvance::where('user_id', $u->id)->value('id');
        $this->getJson('/api/v1/admin/me/advances')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($fin);
        $this->postJson("/api/v1/admin/payroll/advances/{$id}/approve")->assertStatus(403)->assertJsonPath('code', 'step_up_required');
        $this->stepUp($fin);
        $this->postJson("/api/v1/admin/payroll/advances/{$id}/payout", ['payment_method' => 'mpesa'])->assertStatus(422); // not approved yet
        $this->postJson("/api/v1/admin/payroll/advances/{$id}/approve")->assertOk()->assertJsonPath('advance.status', 'approved');
        $this->postJson("/api/v1/admin/payroll/advances/{$id}/payout", ['payment_method' => 'mpesa', 'payment_reference' => 'QAB12'])->assertOk()->assertJsonPath('advance.status', 'paid');
        $this->postJson("/api/v1/admin/payroll/advances/{$id}/payout", ['payment_method' => 'cash'])->assertStatus(422); // once

        // March run: 20,000 instalment comes off net (45,311.65 → 25,311.65); gross, taxable and the expense do not move.
        $p = $this->lockedMarch(); $this->onPayroll($u, $p);
        $run = Payroll::draft($p, $fin);
        $l = $run->lines()->first();
        $this->assertEquals(20000, $l->other_deductions);
        $this->assertEquals(25311.65, $l->net);
        $this->assertEquals(60000, $l->gross);
        $this->assertEquals(64500, $l->employer_cost);
        $this->assertSame($id, $l->deductions[0]['advance_id']);
        $this->assertSame([], Payroll::reconcile($run->fresh(['lines'])));
        $this->assertSame(0, AdvanceRepayment::count()); // a draft takes nothing yet

        $run = Payroll::approve($run, $fin);
        $this->assertSame(1, AdvanceRepayment::count());
        $a = StaffAdvance::find($id);
        $this->assertEquals(10000, $a->balance);
        $this->assertSame('paid', $a->status);
        Payroll::recordRepayments($run, $fin); // a retry is a no-op
        $this->assertSame(1, AdvanceRepayment::count());
        $this->assertEquals(10000, $a->fresh()->balance);

        // Cash repayment of the rest settles it; over-repayment is refused.
        $this->postJson("/api/v1/admin/payroll/advances/{$id}/repay", ['amount' => 10001])->assertStatus(422);
        $this->postJson("/api/v1/admin/payroll/advances/{$id}/repay", ['amount' => 10000, 'reference' => 'cash'])->assertOk()->assertJsonPath('advance.status', 'settled')->assertJsonPath('advance.balance', 0);

        // April run takes nothing more.
        $p2 = PayGroup::defaultGroup()->periodFor(Carbon::parse('2026-04-10')); $p2->update(['status' => 'locked']);
        Timesheet::create(['pay_period_id' => $p2->id, 'user_id' => $u->id, 'status' => 'approved', 'approved_at' => now(), 'snapshot' => true, 'regular_minutes' => 480, 'days_worked' => 1]);
        $this->assertEquals(0, Payroll::draft($p2->fresh(), $fin)->lines()->first()->other_deductions);
        $this->getJson('/api/v1/admin/payroll/advances')->assertOk()->assertJsonPath('open_balance', 0);
    }

    public function test_instalments_never_take_more_than_net_and_nobody_approves_their_own(): void
    {
        $fin = $this->staff(['payroll.view', 'payroll.run']);
        $u = $this->staff();
        $p = $this->lockedMarch(); $this->onPayroll($u, $p, 12000); // net 12,000 − 720 − 330 − 180 = 10,770 (PAYE 0)
        $this->paidAdvance($u, 8000);                 // oldest: takes 8,000
        $this->paidAdvance($u, 50000, 'loan', 5000);  // then 2,770 of its 5,000 — the rest waits
        $run = Payroll::draft($p, $fin);
        $l = $run->lines()->first();
        $this->assertEquals(10770, $l->other_deductions);
        $this->assertEquals(0, $l->net);
        $this->assertEquals([8000, 2770], array_column($l->deductions, 'amount'));
        $this->assertSame([], Payroll::reconcile($run->fresh(['lines'])));
        Payroll::approve($run, $fin);
        $this->assertEquals([0, 47230], StaffAdvance::orderBy('id')->pluck('balance')->map(fn ($b) => (float) $b)->all());
        $this->assertSame(['settled', 'paid'], StaffAdvance::orderBy('id')->pluck('status')->all());

        // Own request cannot be self-approved.
        Sanctum::actingAs($fin); $this->stepUp($fin);
        $this->postJson('/api/v1/admin/me/advances', ['kind' => 'advance', 'amount' => 500, 'reason' => 'mine'])->assertCreated();
        $own = StaffAdvance::where('user_id', $fin->id)->value('id');
        $this->postJson("/api/v1/admin/payroll/advances/{$own}/approve")->assertStatus(422);
        $this->postJson("/api/v1/admin/payroll/advances/{$own}/decline", ['note' => 'no'])->assertOk();
    }

    public function test_payout_files_and_statutory_returns(): void
    {
        $fin = $this->staff(['payroll.view', 'payroll.run']);
        $m = $this->staff([], ['pay_method' => 'mpesa', 'mpesa_phone' => '0712 345 678', 'kra_pin' => 'A001', 'nssf_no' => 'N1', 'shif_no' => 'S1', 'national_id_no' => '111']);
        $b = $this->staff([], ['pay_method' => 'bank', 'bank_name' => 'KCB', 'bank_branch' => 'Moi Ave', 'bank_account_no' => '0099', 'bank_account_name' => 'B Person', 'kra_pin' => 'A002']);
        $noPhone = $this->staff([], ['pay_method' => 'mpesa']);
        $p = $this->lockedMarch();
        foreach ([$m, $b, $noPhone] as $u) $this->onPayroll($u, $p);
        $run = Payroll::draft($p, $fin);

        Sanctum::actingAs($fin); $this->stepUp($fin);
        $this->get("/api/v1/admin/payroll/runs/{$run->id}/payout?method=mpesa")->assertStatus(422); // draft
        $run = Payroll::approve($run, $fin);

        $r = $this->getJson("/api/v1/admin/payroll/runs/{$run->id}/payout-readiness")->assertOk();
        $this->assertSame(1, $r->json('mpesa.ready'));
        $this->assertSame(1, $r->json('bank.ready'));
        $this->assertCount(1, $r->json('mpesa.missing'));

        $csv = $this->get("/api/v1/admin/payroll/runs/{$run->id}/payout?method=mpesa")->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $body = $csv->getContent();
        $this->assertStringContainsString('0712345678,45311.65,' . $run->reference, $body);
        $this->assertSame(2, substr_count($body, "\n")); // header + one row
        $bank = $this->get("/api/v1/admin/payroll/runs/{$run->id}/payout?method=bank")->assertOk()->getContent();
        $this->assertStringContainsString('"B Person",KCB,"Moi Ave",0099,45311.65', $bank);

        // Paid lines drop out of the file.
        Payroll::pay($run, $fin, [['line_id' => $run->lines()->where('user_id', $m->id)->value('id'), 'payment_method' => 'mpesa']]);
        $this->get("/api/v1/admin/payroll/runs/{$run->id}/payout?method=mpesa")->assertStatus(422);

        // Returns: one line per person on runs ending in March; empty month refused; viewer without payroll.run refused.
        $paye = $this->get('/api/v1/admin/payroll/returns?month=2026-03&kind=paye')->assertOk()->getContent();
        $this->assertStringContainsString('A001,60000.00,53850.00,3600.00,1650.00,900.00,8538.35', $paye);
        $this->assertSame(4, substr_count($paye, "\n"));
        $nssf = $this->get('/api/v1/admin/payroll/returns?month=2026-03&kind=nssf')->assertOk()->getContent();
        $this->assertStringContainsString('111,N1,60000.00,3600.00,3600.00,7200.00', $nssf);
        $this->get('/api/v1/admin/payroll/returns?month=2026-03&kind=shif')->assertOk();
        $this->get('/api/v1/admin/payroll/returns?month=2026-03&kind=housing_levy')->assertOk();
        $this->get('/api/v1/admin/payroll/returns?month=2026-02&kind=paye')->assertStatus(422);
        $viewer = $this->staff(['payroll.view']);
        Sanctum::actingAs($viewer); $this->stepUp($viewer);
        $this->get('/api/v1/admin/payroll/returns?month=2026-03&kind=paye')->assertStatus(403);
        $this->get("/api/v1/admin/payroll/runs/{$run->id}/payout?method=bank")->assertStatus(403);
    }
}
