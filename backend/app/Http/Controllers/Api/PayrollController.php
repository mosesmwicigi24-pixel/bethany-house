<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeProfile;
use App\Models\PayPeriod;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\PayStructure;
use App\Models\StatutoryRateTable;
use App\Models\User;
use App\Notifications\InAppNotification;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Services\People\Payroll;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Payroll (Bethany People PR 6). payroll.view reads runs, lines, rates and
 * payslips; payroll.run drafts, approves (step-up), pays (step-up) and
 * manages the rate tables. A person reads their own payslips behind step-up.
 * Pay structures sit on the employee file behind hr.view_sensitive + step-up.
 */
class PayrollController extends Controller
{
    // ── Runs ────────────────────────────────────────────────────────────────

    /** GET /payroll/runs?year= */
    public function index(Request $request): JsonResponse
    {
        $year = (int) ($request->validate(['year' => 'nullable|integer'])['year'] ?? 0) ?: now()->year;
        $runs = PayrollRun::with(['period', 'group:id,name', 'approvedBy:id,first_name,last_name'])
            ->whereHas('period', fn ($q) => $q->whereYear('starts_on', $year))->orderByDesc('created_at')->get();
        $lockedWithoutRun = PayPeriod::with('group:id,name')->where('status', 'locked')->whereYear('starts_on', $year)
            ->whereDoesntHave('runs')->orderByDesc('starts_on')->get();
        $rates = StatutoryRateTable::inForce(now());

        return response()->json([
            'year'   => $year,
            'runs'   => $runs->map(fn ($r) => $this->run($r))->values(),
            'ready_periods' => $lockedWithoutRun->map(fn ($p) => ['id' => $p->id, 'group' => $p->group?->name, 'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on->toDateString()])->values(),
            'rates_unconfirmed' => collect($rates)->filter(fn ($r) => $r && !$r->is_confirmed)->keys()->values(),
            'can_run' => $request->user()->can('payroll.run'),
        ]);
    }

    /** POST /payroll/runs {pay_period_id} — draft (or refresh the existing draft). */
    public function store(Request $request): JsonResponse
    {
        $period = PayPeriod::with('group')->findOrFail($request->validate(['pay_period_id' => 'required|exists:pay_periods,id'])['pay_period_id']);
        $existing = PayrollRun::where('pay_period_id', $period->id)->where('kind', 'regular')->first();
        if ($existing && $existing->status !== 'draft') {
            abort(422, "Run {$existing->reference} is already {$existing->status}; a correction is an adjustment run.");
        }
        $run = Payroll::draft($period, $request->user(), $existing);

        return response()->json(['message' => $existing ? 'Draft recalculated.' : 'Draft run created.', 'run' => $this->run($run->load(['period', 'group', 'lines.user']), true)], $existing ? 200 : 201);
    }

    /** GET /payroll/runs/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $run = PayrollRun::with(['period', 'group', 'lines.user:id,first_name,last_name,email', 'approvedBy:id,first_name,last_name', 'expense:id,reference_number,status,amount_kes'])->findOrFail($id);
        $breaks = Payroll::reconcile($run);

        return response()->json(['run' => $this->run($run, true) + ['reconciles' => empty($breaks), 'breaks' => $breaks]]);
    }

    /** POST /payroll/runs/{id}/recalculate — draft only. */
    public function recalculate(Request $request, int $id): JsonResponse
    {
        $run = PayrollRun::with('period.group')->findOrFail($id);
        $run = Payroll::draft($run->period, $request->user(), $run);

        return response()->json(['message' => 'Draft recalculated.', 'run' => $this->run($run->load(['period', 'group', 'lines.user']), true)]);
    }

    /** DELETE /payroll/runs/{id} — draft only. */
    public function destroy(int $id): JsonResponse
    {
        $run = PayrollRun::findOrFail($id);
        abort_if($run->status !== 'draft', 422, 'An approved run is never deleted; use an adjustment run.');
        $run->delete();

        return response()->json(['message' => 'Draft discarded.']);
    }

    /** POST /payroll/runs/{id}/approve — payroll.run + step.up. */
    public function approve(Request $request, int $id): JsonResponse
    {
        $run = PayrollRun::with(['period', 'lines'])->findOrFail($id);
        $run = Payroll::approve($run, $request->user());
        $breaks = Payroll::reconcile($run->load('lines'));
        abort_if($breaks, 500, 'The run was approved but does not reconcile: ' . implode('; ', $breaks));

        foreach ($run->lines as $line) {
            $u = $line->user;
            if ($u && $u->status === 'active') {
                NotificationService::deliver($u, new InAppNotification(title: 'Your payslip is ready', body: "Payslip for {$run->period->starts_on->format('j M')}–{$run->period->ends_on->format('j M')}.", actionUrl: '/me/payslips', icon: 'people'));
            }
        }

        return response()->json(['message' => "Payroll {$run->reference} approved and posted to Expenses.", 'run' => $this->run($run->load(['period', 'group', 'lines.user', 'approvedBy', 'expense']), true)]);
    }

    /** POST /payroll/runs/{id}/pay {lines:[{line_id, payment_method, payment_reference?}]} — payroll.run + step.up. */
    public function pay(Request $request, int $id): JsonResponse
    {
        $run = PayrollRun::with('lines')->findOrFail($id);
        $v = $request->validate([
            'lines'                     => 'required|array|min:1',
            'lines.*.line_id'           => 'required|integer',
            'lines.*.payment_method'    => 'required|in:mpesa,bank,cash',
            'lines.*.payment_reference' => 'nullable|string|max:80',
        ]);
        $run = Payroll::pay($run, $request->user(), $v['lines']);

        return response()->json(['message' => $run->status === 'paid' ? 'Run fully paid; the expense is marked paid.' : 'Payments recorded.', 'run' => $this->run($run->load(['period', 'group', 'lines.user', 'expense']), true)]);
    }

    /** GET /payroll/runs/{id}/lines/{line}/payslip — PDF (payroll.view). */
    public function payslip(Request $request, int $id, int $line)
    {
        $l = PayrollLine::with(['run.period', 'run.group', 'user'])->where('payroll_run_id', $id)->findOrFail($line);
        abort_if($l->run->status === 'draft', 422, 'A draft has no payslip yet.');
        return $this->payslipPdf($l);
    }

    // ── Payout files and statutory returns (People PR 7) ───────────────────

    /** GET /payroll/runs/{id}/payout?method=mpesa|bank — payroll.run + step.up, gated. Unpaid lines whose file carries the details. */
    public function payoutFile(Request $request, int $id)
    {
        $run = PayrollRun::with('period')->findOrFail($id);
        abort_if($run->status === 'draft', 422, 'Approve the run first.');
        $method = $request->validate(['method' => 'required|in:mpesa,bank'])['method'];
        $built = Payroll::payoutRows($run, $method);
        abort_if(empty($built['rows']), 422, 'Nobody unpaid is set to ' . ($method === 'mpesa' ? 'M-Pesa' : 'bank') . ' with their details on file.');
        ActivityLogService::log('payout_file_built', $run, ['method' => $method, 'rows' => count($built['rows'])], "{$request->user()->first_name} built the {$method} payout file for {$run->reference}");

        $header = $method === 'mpesa' ? ['name', 'phone', 'amount', 'reference'] : ['account_name', 'bank', 'branch', 'account_no', 'amount', 'reference'];
        $rows = array_map(fn ($r) => $method === 'mpesa' ? [$r['name'], $r['phone'], $r['amount'], $r['reference']] : [$r['account_name'], $r['bank'], $r['branch'], $r['account_no'], $r['amount'], $r['reference']], $built['rows']);

        return $this->csv("payout-{$method}-{$run->reference}.csv", $header, $rows);
    }

    /** GET /payroll/runs/{id}/payout-readiness — payroll.view: who is in each file and who is missing details. */
    public function payoutReadiness(int $id): JsonResponse
    {
        $run = PayrollRun::findOrFail($id);
        $out = [];
        foreach (['mpesa', 'bank'] as $m) {
            $b = Payroll::payoutRows($run, $m);
            $out[$m] = ['ready' => count($b['rows']), 'total' => number_format(array_sum(array_map(fn ($r) => (float) $r['amount'], $b['rows'])), 2, '.', ''), 'missing' => $b['missing']];
        }
        $cash = $run->lines()->with('user.employeeProfile')->whereNull('paid_at')->get()->filter(fn ($l) => ($l->user?->employeeProfile?->pay_method ?? null) === 'cash')->count();
        $none = $run->lines()->with('user.employeeProfile')->whereNull('paid_at')->get()->filter(fn ($l) => !($l->user?->employeeProfile?->pay_method ?? null))->map(fn ($l) => trim("{$l->user?->first_name} {$l->user?->last_name}"))->values();

        return response()->json($out + ['cash' => $cash, 'no_pay_method' => $none]);
    }

    /**
     * GET /payroll/returns?month=YYYY-MM&kind=paye|nssf|shif|housing_levy — payroll.run + step.up, gated.
     * Working CSVs over every approved/paid run whose period ends in the month.
     * Column layouts are the Hub's own, NOT the authorities' upload templates
     * (UNKNOWN — NEEDS VERIFICATION against KRA P10, NSSF and SHA formats).
     */
    public function returns(Request $request)
    {
        $v = $request->validate(['month' => 'required|date_format:Y-m', 'kind' => 'required|in:paye,nssf,shif,housing_levy']);
        $start = Carbon::createFromFormat('Y-m', $v['month'])->startOfMonth(); $end = $start->copy()->endOfMonth();
        $runs = PayrollRun::whereIn('status', ['approved', 'paid'])->whereHas('period', fn ($q) => $q->whereBetween('ends_on', [$start->toDateString(), $end->toDateString()]))->pluck('id');
        $lines = PayrollLine::with(['user.employeeProfile', 'run'])->whereIn('payroll_run_id', $runs)->orderBy('user_id')->get();
        abort_if($lines->isEmpty(), 422, 'No approved run ends in that month.');
        ActivityLogService::log('statutory_return_built', null, ['kind' => $v['kind'], 'month' => $v['month'], 'lines' => $lines->count()], "{$request->user()->first_name} built the {$v['kind']} return for {$v['month']}");

        $m = fn ($x) => number_format((float) $x, 2, '.', '');
        [$header, $rows] = match ($v['kind']) {
            'paye' => [['run', 'employee', 'kra_pin', 'gross_pay', 'taxable_pay', 'nssf', 'shif', 'housing_levy', 'paye'],
                $lines->map(fn ($l) => [$l->run->reference, $this->name($l), $l->user?->employeeProfile?->kra_pin, $m($l->gross), $m($l->taxable), $m($l->nssf_employee), $m($l->shif), $m($l->housing_employee), $m($l->paye)])],
            'nssf' => [['run', 'employee', 'national_id', 'nssf_no', 'gross_pay', 'employee_contribution', 'employer_contribution', 'total'],
                $lines->map(fn ($l) => [$l->run->reference, $this->name($l), $l->user?->employeeProfile?->national_id_no, $l->user?->employeeProfile?->nssf_no, $m($l->gross), $m($l->nssf_employee), $m($l->nssf_employer), $m((float) $l->nssf_employee + (float) $l->nssf_employer)])],
            'shif' => [['run', 'employee', 'national_id', 'shif_no', 'gross_pay', 'contribution'],
                $lines->map(fn ($l) => [$l->run->reference, $this->name($l), $l->user?->employeeProfile?->national_id_no, $l->user?->employeeProfile?->shif_no, $m($l->gross), $m($l->shif)])],
            'housing_levy' => [['run', 'employee', 'national_id', 'kra_pin', 'gross_pay', 'employee_levy', 'employer_levy', 'total'],
                $lines->map(fn ($l) => [$l->run->reference, $this->name($l), $l->user?->employeeProfile?->national_id_no, $l->user?->employeeProfile?->kra_pin, $m($l->gross), $m($l->housing_employee), $m($l->housing_employer), $m((float) $l->housing_employee + (float) $l->housing_employer)])],
        };

        return $this->csv("{$v['kind']}-return-{$v['month']}.csv", $header, $rows->all());
    }

    private function name(PayrollLine $l): string
    {
        return trim(($l->user?->first_name ?? '') . ' ' . ($l->user?->last_name ?? ''));
    }

    private function csv(string $name, array $header, array $rows)
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header);
        foreach ($rows as $r) fputcsv($out, $r);
        rewind($out); $csv = stream_get_contents($out); fclose($out);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}\"", 'Cache-Control' => 'no-cache, no-store']);
    }

    // ── Me ──────────────────────────────────────────────────────────────────

    /** GET /me/payslips */
    public function myPayslips(Request $request): JsonResponse
    {
        $lines = PayrollLine::with(['run.period', 'run.group:id,name'])->where('user_id', $request->user()->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'paid']))->orderByDesc('created_at')->limit(36)->get();

        return response()->json(['data' => $lines->map(fn ($l) => $this->line($l, mine: true))->values()]);
    }

    /** GET /me/payslips/{line}/pdf — step.up */
    public function myPayslipPdf(Request $request, int $line)
    {
        $l = PayrollLine::with(['run.period', 'run.group', 'user'])->where('user_id', $request->user()->id)->findOrFail($line);
        abort_if($l->run->status === 'draft', 404);
        return $this->payslipPdf($l);
    }

    // ── Pay structures (on the employee file) ───────────────────────────────

    /** GET /people/{user}/pay — hr.view_sensitive + step.up */
    public function structures(Request $request, int $user): JsonResponse
    {
        $person = User::where('user_type', 'staff')->findOrFail($user);
        ActivityLogService::log('pay_structure_viewed', $person, [], "{$request->user()->first_name} viewed {$person->first_name}'s pay structure");
        $rows = PayStructure::where('user_id', $person->id)->orderByDesc('effective_from')->get();

        return response()->json(['data' => $rows->map(fn ($s) => $this->structure($s))->values(), 'can_edit' => $request->user()->can('hr.edit')]);
    }

    /** POST /people/{user}/pay — hr.edit + hr.view_sensitive + step.up. A new dated row; nothing is edited in place. */
    public function storeStructure(Request $request, int $user): JsonResponse
    {
        $person = User::where('user_type', 'staff')->findOrFail($user);
        $v = $request->validate([
            'effective_from'       => 'required|date',
            'kind'                 => 'required|in:monthly,daily,hourly',
            'base_amount'          => 'required|numeric|min:0|max:99999999',
            'allowances'           => 'nullable|array|max:10',
            'allowances.*.name'    => 'required|string|max:40',
            'allowances.*.amount'  => 'required|numeric|min:0|max:9999999',
            'allowances.*.taxable' => 'sometimes|boolean',
            'notes'                => 'nullable|string|max:300',
        ]);
        if (PayStructure::where('user_id', $person->id)->where('effective_from', $v['effective_from'])->exists()) {
            return response()->json(['message' => 'A structure already starts on that date; pick another date.'], 422);
        }
        $s = PayStructure::create($v + ['user_id' => $person->id, 'currency_code' => 'KES', 'created_by' => $request->user()->id]);
        ActivityLogService::log('pay_structure_set', $person, ['kind' => $s->kind, 'effective_from' => $s->effective_from->toDateString()],
            "{$request->user()->first_name} set {$person->first_name}'s pay ({$s->kind}) from {$s->effective_from->toDateString()}");

        return response()->json(['message' => 'Pay structure saved.', 'structure' => $this->structure($s)], 201);
    }

    // ── Statutory rate tables ───────────────────────────────────────────────

    /** GET /payroll/rates (payroll.view) */
    public function rates(): JsonResponse
    {
        return response()->json(['data' => StatutoryRateTable::orderBy('code')->orderByDesc('effective_from')->get()->map(fn ($r) => $this->rate($r))->values()]);
    }

    /** POST /payroll/rates {code, effective_from, config, reduces_taxable_pay, source?} — payroll.run + step.up; a new dated row. */
    public function storeRate(Request $request): JsonResponse
    {
        $v = $request->validate([
            'code'                => 'required|in:paye,nssf,shif,housing_levy',
            'effective_from'      => 'required|date',
            'config'              => 'required|array',
            'reduces_taxable_pay' => 'required|boolean',
            'source'              => 'nullable|string|max:300',
        ]);
        $this->validateConfig($v['code'], $v['config']);
        if (StatutoryRateTable::where('code', $v['code'])->where('effective_from', $v['effective_from'])->exists()) {
            return response()->json(['message' => 'A row for that date exists; pick another effective date.'], 422);
        }
        $r = StatutoryRateTable::create($v + ['is_confirmed' => true, 'confirmed_by' => $request->user()->id, 'confirmed_at' => now(), 'created_by' => $request->user()->id]);
        ActivityLogService::log('statutory_rate_added', $r, $v, "{$request->user()->first_name} added a {$v['code']} rate table from {$v['effective_from']}");

        return response()->json(['message' => 'Rate table added.', 'rate' => $this->rate($r)], 201);
    }

    /** POST /payroll/rates/{id}/confirm — payroll.run + step.up: "I have checked this against the authority." */
    public function confirmRate(Request $request, int $id): JsonResponse
    {
        $r = StatutoryRateTable::findOrFail($id);
        $r->update(['is_confirmed' => true, 'confirmed_by' => $request->user()->id, 'confirmed_at' => now()]);
        ActivityLogService::log('statutory_rate_confirmed', $r, [], "{$request->user()->first_name} confirmed the {$r->code} rate table from {$r->effective_from->toDateString()}");

        return response()->json(['message' => 'Confirmed.', 'rate' => $this->rate($r)]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function validateConfig(string $code, array $c): void
    {
        $ok = match ($code) {
            'paye'         => isset($c['bands']) && is_array($c['bands']) && count($c['bands']) > 0 && isset($c['personal_relief']),
            'nssf'         => isset($c['employee_rate'], $c['employer_rate'], $c['tier2_limit']),
            'shif'         => isset($c['rate']),
            'housing_levy' => isset($c['employee_rate'], $c['employer_rate']),
        };
        abort_if(!$ok, 422, "The {$code} table is missing a required figure.");
    }

    private function payslipPdf(PayrollLine $l)
    {
        $run = $l->run; $u = $l->user; $p = $run->period;
        $kes = fn ($v) => number_format((float) $v, 2);
        $rows = '';
        foreach ($l->items as $it) {
            $rows .= '<tr><td>' . e($it['label']) . '</td><td class="r">' . ($it['amount'] < 0 ? '(' . $kes(-$it['amount']) . ')' : $kes($it['amount'])) . '</td></tr>';
        }
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>
            body{font-family:Helvetica,Arial,sans-serif;font-size:12px;color:#222;margin:28px}
            h1{font-size:18px;margin:0}.muted{color:#777}table{width:100%;border-collapse:collapse;margin-top:14px}
            td,th{padding:6px 8px;border-bottom:1px solid #ddd;text-align:left}.r{text-align:right}.tot td{font-weight:bold;border-top:2px solid #222}
            .grid td{border:none;padding:2px 8px 2px 0}.box{margin-top:18px;padding:10px;background:#f6f3ef;border-radius:6px}
        </style></head><body>
        <table class="grid"><tr><td><h1>Bethany House</h1><div class="muted">Payslip · ' . e($run->reference) . '</div></td>
        <td class="r"><b>' . e(trim("{$u->first_name} {$u->last_name}")) . '</b><br>' . e($p->starts_on->format('j M Y')) . ' – ' . e($p->ends_on->format('j M Y')) . '<br><span class="muted">' . e($run->group?->name) . ' · ' . e(ucfirst($l->structure['kind'] ?? '')) . '</span></td></tr></table>
        <table><tr><th>Item</th><th class="r">KES</th></tr>' . $rows . '
        <tr class="tot"><td>Gross pay</td><td class="r">' . $kes($l->gross) . '</td></tr>
        <tr class="tot"><td>Net pay</td><td class="r">' . $kes($l->net) . '</td></tr></table>
        <div class="box">Hours: regular ' . $this->hm($l->hours['regular_minutes'] ?? 0) . ', overtime ' . $this->hm($l->hours['overtime_minutes'] ?? 0) . ', rest days ' . $this->hm($l->hours['rest_day_minutes'] ?? 0) . ', holidays ' . $this->hm($l->hours['holiday_minutes'] ?? 0)
        . '; paid leave ' . (float) ($l->hours['paid_leave_days'] ?? 0) . ' d; unpaid leave ' . (float) ($l->hours['unpaid_leave_days'] ?? 0) . ' d; days worked ' . (int) ($l->hours['days_worked'] ?? 0) . '.<br>
        Taxable pay ' . $kes($l->taxable) . '. Employer contributions (not deducted from you): NSSF ' . $kes($l->nssf_employer) . ', Housing Levy ' . $kes($l->housing_employer) . '.'
        . ($l->paid_at ? '<br>Paid ' . e($l->paid_at->format('j M Y')) . ' by ' . e($l->payment_method) . ($l->payment_reference ? ' · ' . e($l->payment_reference) : '') : '<br>Not yet paid.') . '</div>
        <p class="muted" style="margin-top:18px">Computed from the approved timesheet and the pay structure in force on ' . e($p->ends_on->format('j M Y')) . '; statutory rates as recorded on run ' . e($run->reference) . '.</p>
        </body></html>';
        $html = \App\Services\Downloads\ExportWatermark::apply($html);
        $pdf = Pdf::loadHTML($html)->setPaper('A4', 'portrait')->setOption('defaultFont', 'Helvetica');
        $name = 'payslip-' . $run->reference . '-' . $u->id . '.pdf';

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$name}\"", 'Cache-Control' => 'no-cache, no-store']);
    }

    private function hm(int $m): string
    {
        return intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'm' : '');
    }

    private function run(PayrollRun $r, bool $withLines = false): array
    {
        $out = [
            'id' => $r->id, 'reference' => $r->reference, 'kind' => $r->kind, 'status' => $r->status, 'people' => $r->people,
            'period' => $r->period ? ['id' => $r->period->id, 'starts_on' => $r->period->starts_on->toDateString(), 'ends_on' => $r->period->ends_on->toDateString(), 'status' => $r->period->status] : null,
            'group' => $r->group ? ['id' => $r->group->id, 'name' => $r->group->name] : null,
            'totals' => collect(PayrollRun::MONEY)->mapWithKeys(fn ($c) => [$c => (float) $r->{$c}])->all(),
            'rates_used' => $r->rates_used, 'unpayable' => $r->unpayable ?? [],
            'expense' => $r->relationLoaded('expense') && $r->expense ? ['id' => $r->expense->id, 'reference' => $r->expense->reference_number, 'status' => $r->expense->status, 'amount_kes' => (float) $r->expense->amount_kes] : ($r->expense_id ? ['id' => $r->expense_id] : null),
            'approved_by' => $r->relationLoaded('approvedBy') && $r->approvedBy ? trim("{$r->approvedBy->first_name} {$r->approvedBy->last_name}") : null,
            'approved_at' => $r->approved_at?->toIso8601String(), 'paid_at' => $r->paid_at?->toIso8601String(), 'created_at' => $r->created_at?->toIso8601String(), 'notes' => $r->notes,
        ];
        if ($withLines) $out['lines'] = $r->lines->map(fn ($l) => $this->line($l))->values();
        return $out;
    }

    private function line(PayrollLine $l, bool $mine = false): array
    {
        $out = [
            'id' => $l->id, 'user' => $l->relationLoaded('user') && $l->user ? ['id' => $l->user->id, 'name' => trim("{$l->user->first_name} {$l->user->last_name}")] : ['id' => $l->user_id],
            'hours' => $l->hours, 'items' => $l->items, 'deductions' => $l->deductions ?? [], 'structure' => ['kind' => $l->structure['kind'] ?? null, 'base_amount' => $l->structure['base_amount'] ?? null, 'hourly_equivalent' => $l->structure['hourly_equivalent'] ?? null, 'service_fraction' => $l->structure['service_fraction'] ?? null],
            'payment_method' => $l->payment_method, 'payment_reference' => $l->payment_reference, 'paid_at' => $l->paid_at?->toIso8601String(), 'notes' => $l->notes,
        ];
        foreach (PayrollLine::MONEY as $c) $out[$c] = (float) $l->{$c};
        if ($mine && $l->relationLoaded('run')) {
            $out['run'] = ['id' => $l->run->id, 'reference' => $l->run->reference, 'status' => $l->run->status, 'starts_on' => $l->run->period?->starts_on->toDateString(), 'ends_on' => $l->run->period?->ends_on->toDateString(), 'group' => $l->run->group?->name];
        }
        return $out;
    }

    private function structure(PayStructure $s): array
    {
        return ['id' => $s->id, 'effective_from' => $s->effective_from->toDateString(), 'kind' => $s->kind, 'base_amount' => (float) $s->base_amount, 'currency_code' => $s->currency_code,
            'allowances' => $s->allowances ?? [], 'notes' => $s->notes, 'created_at' => $s->created_at?->toIso8601String()];
    }

    private function rate(StatutoryRateTable $r): array
    {
        return ['id' => $r->id, 'code' => $r->code, 'effective_from' => $r->effective_from->toDateString(), 'config' => $r->config, 'reduces_taxable_pay' => $r->reduces_taxable_pay,
            'source' => $r->source, 'is_confirmed' => $r->is_confirmed, 'confirmed_at' => $r->confirmed_at?->toIso8601String()];
    }
}
