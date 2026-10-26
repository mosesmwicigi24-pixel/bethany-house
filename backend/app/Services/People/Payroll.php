<?php

namespace App\Services\People;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PayGroup;
use App\Models\PayPeriod;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\PayStructure;
use App\Models\PublicHoliday;
use App\Models\StatutoryRateTable;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ActivityLogService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * The payroll arithmetic in one place (Bethany People PR 6). Every figure a
 * payslip shows is produced here from (a) the approved timesheet snapshot,
 * (b) the pay structure in force on the period end, (c) the statutory rows
 * in force on the period end, (d) the pay group's multipliers. Each is
 * stored on the line so the slip rebuilds from the line alone.
 *
 * Money is KES, rounded to the cent per component; totals are sums of
 * lines, never recomputed from rates.
 */
class Payroll
{
    public static function structureFor(User $user, Carbon $on): ?PayStructure
    {
        return PayStructure::where('user_id', $user->id)->where('effective_from', '<=', $on->toDateString())->orderByDesc('effective_from')->first();
    }

    /** Working days in the period (config attendance.working_days, holidays out) — the daily equivalent's divisor. */
    public static function workingDays(PayPeriod $period): int
    {
        $working  = config('attendance.working_days', [1, 2, 3, 4, 5, 6]);
        $holidays = PublicHoliday::whereBetween('date', [$period->starts_on->toDateString(), $period->ends_on->toDateString()])->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
        $n = 0;
        foreach (CarbonPeriod::create($period->starts_on, $period->ends_on) as $d) {
            if (in_array($d->dayOfWeekIso, $working, true) && !in_array($d->toDateString(), $holidays, true)) $n++;
        }
        return max(1, $n);
    }

    /** Days in service inside the period over days in the period, for a monthly salary. */
    public static function serviceFraction(User $user, PayPeriod $period): float
    {
        $user->loadMissing('employeeProfile');
        $start = $period->starts_on->copy(); $end = $period->ends_on->copy();
        $hire = $user->employeeProfile?->hire_date; $left = $user->employeeProfile?->end_date;
        $from = $hire && $hire->gt($start) ? $hire->copy() : $start;
        $to   = $left && $left->lt($end) ? $left->copy() : $end;
        if ($to->lt($from)) return 0.0;
        return round(($from->diffInDays($to) + 1) / ($start->diffInDays($end) + 1), 6);
    }

    /**
     * One person's line. $hours is the timesheet snapshot totals; $rates the
     * rows from StatutoryRateTable::inForce.
     */
    public static function computeLine(User $user, PayPeriod $period, PayGroup $group, PayStructure $s, array $hours, array $rates): array
    {
        $r2 = fn (float $v) => round($v, 2);
        $workingDays = self::workingDays($period);
        $stdHours = (float) $group->standard_hours_per_day;
        $base = (float) $s->base_amount;
        $items = [];

        // ── Basic and the hourly equivalent ──
        $fraction = self::serviceFraction($user, $period);
        if ($s->kind === 'monthly') {
            $basic = $r2($base * $fraction);
            $hourly = $base / ($workingDays * $stdHours);
            $daily  = $base / $workingDays;
            $items[] = ['label' => $fraction < 1 ? sprintf('Salary (%.0f%% of period in service)', $fraction * 100) : 'Salary', 'amount' => $basic, 'kind' => 'earning'];
        } elseif ($s->kind === 'daily') {
            $days = (int) $hours['days_worked'] + (float) $hours['paid_leave_days'];
            $basic = $r2($base * $days);
            $hourly = $base / $stdHours; $daily = $base;
            $items[] = ['label' => sprintf('%s day(s) × %s', rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.'), number_format($base, 2)), 'amount' => $basic, 'kind' => 'earning'];
        } else { // hourly
            $regularHours = $hours['regular_minutes'] / 60;
            $leaveHours = (float) $hours['paid_leave_days'] * $stdHours;
            $basic = $r2($base * ($regularHours + $leaveHours));
            $hourly = $base; $daily = $base * $stdHours;
            $items[] = ['label' => sprintf('%.2f h × %s', $regularHours, number_format($base, 2)), 'amount' => $r2($base * $regularHours), 'kind' => 'earning'];
            if ($leaveHours > 0) $items[] = ['label' => sprintf('Paid leave %.2f h', $leaveHours), 'amount' => $r2($base * $leaveHours), 'kind' => 'earning'];
        }

        $overtime = $r2($hours['overtime_minutes'] / 60 * $hourly * (float) $group->overtime_multiplier);
        $rest     = $r2($hours['rest_day_minutes'] / 60 * $hourly * (float) $group->rest_day_multiplier);
        $holiday  = $r2($hours['holiday_minutes'] / 60 * $hourly * (float) $group->holiday_multiplier);
        if ($overtime > 0) $items[] = ['label' => sprintf('Overtime %.2f h × %s', $hours['overtime_minutes'] / 60, (float) $group->overtime_multiplier), 'amount' => $overtime, 'kind' => 'earning'];
        if ($rest > 0)     $items[] = ['label' => sprintf('Rest days %.2f h × %s', $hours['rest_day_minutes'] / 60, (float) $group->rest_day_multiplier), 'amount' => $rest, 'kind' => 'earning'];
        if ($holiday > 0)  $items[] = ['label' => sprintf('Public holidays %.2f h × %s', $hours['holiday_minutes'] / 60, (float) $group->holiday_multiplier), 'amount' => $holiday, 'kind' => 'earning'];

        $allowTotal = 0.0; $allowNonTax = 0.0;
        foreach ($s->allowances ?? [] as $a) {
            $amt = $r2((float) ($a['amount'] ?? 0));
            if ($amt <= 0) continue;
            $allowTotal += $amt;
            if (empty($a['taxable'])) $allowNonTax += $amt;
            $items[] = ['label' => ($a['name'] ?? 'Allowance') . (empty($a['taxable']) ? ' (non-taxable)' : ''), 'amount' => $amt, 'kind' => 'earning'];
        }

        // Unpaid leave: a monthly salary loses the daily equivalent; daily and hourly simply never earned it.
        $unpaid = $s->kind === 'monthly' ? $r2((float) $hours['unpaid_leave_days'] * $daily) : 0.0;
        if ($unpaid > 0) $items[] = ['label' => sprintf('Unpaid leave %s day(s)', (float) $hours['unpaid_leave_days']), 'amount' => -$unpaid, 'kind' => 'earning'];

        $gross = max(0.0, $r2($basic + $overtime + $rest + $holiday + $allowTotal - $unpaid));

        // ── Statutory ──
        $nssfC = $rates['nssf']?->config ?? null; $shifC = $rates['shif']?->config ?? null; $hlC = $rates['housing_levy']?->config ?? null; $payeC = $rates['paye']?->config ?? null;
        $nssfEmp = $nssfC ? $r2(min($gross, (float) ($nssfC['tier2_limit'] ?? PHP_FLOAT_MAX)) * (float) $nssfC['employee_rate']) : 0.0;
        $nssfEr  = $nssfC ? $r2(min($gross, (float) ($nssfC['tier2_limit'] ?? PHP_FLOAT_MAX)) * (float) $nssfC['employer_rate']) : 0.0;
        $shif    = $shifC ? ($gross > 0 ? $r2(max((float) ($shifC['minimum'] ?? 0), $gross * (float) $shifC['rate'])) : 0.0) : 0.0;
        $hlEmp   = $hlC ? $r2($gross * (float) $hlC['employee_rate']) : 0.0;
        $hlEr    = $hlC ? $r2($gross * (float) $hlC['employer_rate']) : 0.0;

        $taxable = $gross - $allowNonTax;
        if ($rates['nssf']?->reduces_taxable_pay) $taxable -= $nssfEmp;
        if ($rates['shif']?->reduces_taxable_pay) $taxable -= $shif;
        if ($rates['housing_levy']?->reduces_taxable_pay) $taxable -= $hlEmp;
        $taxable = max(0.0, $r2($taxable));

        $paye = 0.0;
        if ($payeC) {
            $remaining = $taxable; $lower = 0.0; $tax = 0.0;
            foreach ($payeC['bands'] as $band) {
                $upto = $band['upto'] === null ? PHP_FLOAT_MAX : (float) $band['upto'];
                $slice = max(0.0, min($remaining, $upto - $lower));
                $tax += $slice * (float) $band['rate'];
                $remaining -= $slice; $lower = $upto;
                if ($remaining <= 0) break;
            }
            $paye = max(0.0, $r2($tax - (float) ($payeC['personal_relief'] ?? 0)));
        }

        foreach ([['PAYE', $paye], ['NSSF', $nssfEmp], ['SHIF', $shif], ['Housing Levy', $hlEmp]] as [$label, $amt]) {
            if ($amt > 0) $items[] = ['label' => $label, 'amount' => -$amt, 'kind' => 'deduction'];
        }

        $net = $r2($gross - $paye - $nssfEmp - $shif - $hlEmp);
        $employerCost = $r2($gross + $nssfEr + $hlEr);

        return [
            'structure' => ['id' => $s->id, 'kind' => $s->kind, 'base_amount' => $base, 'allowances' => $s->allowances ?? [], 'effective_from' => $s->effective_from->toDateString(),
                'hourly_equivalent' => $r2($hourly), 'working_days_in_period' => $workingDays, 'service_fraction' => $fraction,
                'multipliers' => ['overtime' => (float) $group->overtime_multiplier, 'rest_day' => (float) $group->rest_day_multiplier, 'holiday' => (float) $group->holiday_multiplier]],
            'hours' => $hours,
            'items' => $items,
            'basic' => $basic, 'overtime_pay' => $overtime, 'rest_day_pay' => $rest, 'holiday_pay' => $holiday, 'allowances' => $r2($allowTotal), 'unpaid_deduction' => $unpaid,
            'gross' => $gross, 'taxable' => $taxable, 'paye' => $paye, 'nssf_employee' => $nssfEmp, 'nssf_employer' => $nssfEr, 'shif' => $shif,
            'housing_employee' => $hlEmp, 'housing_employer' => $hlEr, 'other_deductions' => 0.0, 'net' => $net, 'employer_cost' => $employerCost,
        ];
    }

    /** Build or rebuild a draft run for a locked period. Returns [run, unpayable]. */
    public static function draft(PayPeriod $period, User $by, ?PayrollRun $existing = null): PayrollRun
    {
        abort_if(!$period->isLocked(), 422, 'Lock the pay period first; a run reads approved, locked timesheets.');
        $group = $period->group;
        $rates = StatutoryRateTable::inForce($period->ends_on);
        foreach (StatutoryRateTable::CODES as $code) abort_if(!$rates[$code], 422, "No {$code} rate table is in force for " . $period->ends_on->toDateString() . '.');

        $people = User::where('user_type', 'staff')->where('status', 'active')->with('employeeProfile')->get()
            ->filter(fn (User $u) => ($u->employeeProfile?->pay_group_id ?? PayGroup::defaultGroup()?->id) === $group->id);
        $sheets = Timesheet::where('pay_period_id', $period->id)->where('status', 'approved')->get()->keyBy('user_id');

        return DB::transaction(function () use ($period, $group, $rates, $people, $sheets, $by, $existing) {
            $run = $existing ?? new PayrollRun([
                'reference' => self::nextReference($period), 'pay_period_id' => $period->id, 'pay_group_id' => $group->id,
                'kind' => 'regular', 'status' => 'draft', 'created_by' => $by->id,
            ]);
            abort_if($run->status !== 'draft', 422, 'Only a draft recalculates.');
            $run->rates_used = collect($rates)->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'effective_from' => $r->effective_from->toDateString(), 'is_confirmed' => $r->is_confirmed, 'reduces_taxable_pay' => $r->reduces_taxable_pay, 'config' => $r->config])->values()->all();
            $run->save();
            $run->lines()->delete();

            $unpayable = []; $totals = array_fill_keys(PayrollRun::MONEY, 0.0); $n = 0;
            foreach ($people as $u) {
                $sheet = $sheets->get($u->id);
                $s = self::structureFor($u, $period->ends_on);
                if (!$sheet) { $unpayable[] = ['id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}"), 'why' => 'No approved timesheet']; continue; }
                if (!$s)     { $unpayable[] = ['id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}"), 'why' => 'No pay structure']; continue; }
                $hours = ['regular_minutes' => (int) $sheet->regular_minutes, 'overtime_minutes' => (int) $sheet->overtime_minutes, 'rest_day_minutes' => (int) $sheet->rest_day_minutes,
                    'holiday_minutes' => (int) $sheet->holiday_minutes, 'paid_leave_days' => (float) $sheet->paid_leave_days, 'unpaid_leave_days' => (float) $sheet->unpaid_leave_days, 'days_worked' => (int) $sheet->days_worked];
                $line = self::computeLine($u, $period, $group, $s, $hours, $rates);
                PayrollLine::create($line + ['payroll_run_id' => $run->id, 'user_id' => $u->id, 'timesheet_id' => $sheet->id]);
                foreach (PayrollRun::MONEY as $c) $totals[$c] += (float) $line[$c];
                $n++;
            }
            $run->fill(array_map(fn ($v) => round($v, 2), $totals) + ['people' => $n, 'unpayable' => $unpayable])->save();
            return $run->fresh();
        });
    }

    /** Freeze the run and post the Salaries expense. */
    public static function approve(PayrollRun $run, User $by): PayrollRun
    {
        abort_if($run->status !== 'draft', 422, 'Only a draft is approved.');
        abort_if($run->people === 0, 422, 'There is nobody to pay on this run.');
        $period = $run->period;
        $category = ExpenseCategory::where('code', 'SALARIES')->first();
        abort_if(!$category, 422, 'The Salaries expense category is missing.');

        return DB::transaction(function () use ($run, $by, $period, $category) {
            $expense = Expense::create([
                'title'          => "Payroll {$run->reference} · {$period->starts_on->format('j M')}–{$period->ends_on->format('j M Y')}",
                'description'    => "{$run->people} people. Gross {$run->gross}, employer NSSF {$run->nssf_employer}, employer Housing Levy {$run->housing_employer}. Posted by the payroll run; see Payroll › {$run->reference}.",
                'category_id'    => $category->id,
                'amount'         => $run->employer_cost,
                'currency_code'  => 'KES',
                'exchange_rate'  => 1,
                'amount_kes'     => $run->employer_cost,
                'expense_date'   => $period->ends_on->toDateString(),
                'payment_method' => 'other',
                'outlet_id'      => null,
                'status'         => 'approved',
                'submitted_by'   => $by->id, 'submitted_at' => now(),
                'approved_by'    => $by->id, 'approved_at' => now(),
                'created_by'     => $by->id,
                'notes'          => 'Labour cost of a payroll run. Net pay and statutory remittances are liabilities until paid.',
            ]);
            $run->update(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), 'expense_id' => $expense->id]);
            ActivityLogService::log('payroll_run_approved', $run, ['people' => $run->people, 'gross' => (string) $run->gross, 'net' => (string) $run->net, 'employer_cost' => (string) $run->employer_cost, 'expense_id' => $expense->id],
                "{$by->first_name} approved payroll {$run->reference}: {$run->people} people, employer cost {$run->employer_cost}");
            return $run->fresh();
        });
    }

    /** Record payment on lines; when all are paid the run and its expense are paid. */
    public static function pay(PayrollRun $run, User $by, array $payments): PayrollRun
    {
        abort_if(!in_array($run->status, ['approved', 'paid'], true), 422, 'Approve the run before paying it.');
        return DB::transaction(function () use ($run, $by, $payments) {
            foreach ($payments as $p) {
                $line = $run->lines()->whereKey($p['line_id'])->firstOrFail();
                if ($line->paid_at) continue;
                $line->update(['payment_method' => $p['payment_method'], 'payment_reference' => $p['payment_reference'] ?? null, 'paid_at' => now(), 'paid_by' => $by->id]);
            }
            if ($run->lines()->whereNull('paid_at')->doesntExist()) {
                $run->update(['status' => 'paid', 'paid_at' => now()]);
                if ($run->expense && $run->expense->status === 'approved') {
                    $run->expense->update(['status' => 'paid', 'paid_by' => $by->id, 'paid_at' => now()]);
                }
                ActivityLogService::log('payroll_run_paid', $run, [], "{$by->first_name} recorded payroll {$run->reference} as fully paid");
            }
            return $run->fresh();
        });
    }

    /**
     * Prove a run: lines sum to the run; net = gross − deductions on every
     * line; employer cost = gross + employer contributions; the posted expense
     * equals the employer cost. Returns the list of breaks (empty = ties).
     */
    public static function reconcile(PayrollRun $run): array
    {
        $breaks = [];
        $sum = array_fill_keys(PayrollRun::MONEY, 0.0);
        foreach ($run->lines as $l) {
            foreach (PayrollRun::MONEY as $c) $sum[$c] += (float) $l->{$c};
            $net = round((float) $l->gross - (float) $l->paye - (float) $l->nssf_employee - (float) $l->shif - (float) $l->housing_employee - (float) $l->other_deductions, 2);
            if (abs($net - (float) $l->net) > 0.005) $breaks[] = "line {$l->id}: net {$l->net} ≠ gross − deductions {$net}";
            $cost = round((float) $l->gross + (float) $l->nssf_employer + (float) $l->housing_employer, 2);
            if (abs($cost - (float) $l->employer_cost) > 0.005) $breaks[] = "line {$l->id}: employer cost {$l->employer_cost} ≠ {$cost}";
            $itemSum = round(array_sum(array_column($l->items ?? [], 'amount')), 2);
            if (abs($itemSum - (float) $l->net) > 0.005) $breaks[] = "line {$l->id}: payslip items sum {$itemSum} ≠ net {$l->net}";
        }
        foreach (PayrollRun::MONEY as $c) {
            if (abs(round($sum[$c], 2) - (float) $run->{$c}) > 0.005) $breaks[] = "run {$c} {$run->{$c}} ≠ sum of lines " . round($sum[$c], 2);
        }
        if ($run->status !== 'draft') {
            if (!$run->expense) $breaks[] = 'approved run has no expense';
            elseif (abs((float) $run->expense->amount_kes - (float) $run->employer_cost) > 0.005) $breaks[] = "expense {$run->expense->reference_number} {$run->expense->amount_kes} ≠ employer cost {$run->employer_cost}";
            elseif ($run->expense->status === 'paid' && $run->status !== 'paid') $breaks[] = 'expense is paid but the run is not';
        }
        return $breaks;
    }

    private static function nextReference(PayPeriod $period): string
    {
        $stem = 'PAY-' . $period->starts_on->format('Ym');
        $n = PayrollRun::where('reference', 'like', "{$stem}-%")->count() + 1;
        return sprintf('%s-%02d', $stem, $n);
    }
}
