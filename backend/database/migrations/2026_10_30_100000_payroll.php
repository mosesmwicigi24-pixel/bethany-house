<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bethany People, PR 6 — payroll (owner, 24 Oct 2026; plan approved 26 Oct).
 *
 * pay_structures: what a person is paid, dated. kind monthly (a salary for
 * the period, prorated by days in service), daily (a rate per day worked and
 * per paid leave day) or hourly (a rate per regular hour). Allowances are
 * named lines, each taxable or not. KES only in this version.
 *
 * statutory_rate_tables: PAYE bands and relief, NSSF, SHIF and the Housing
 * Levy, each row dated by effective_from and never edited in place: a change
 * is a new row. The seeded 2026 rows come from third-party payroll guides,
 * NOT from KRA/NSSF/SHA publications, and carry is_confirmed = false until
 * the owner confirms them. `reduces_taxable_pay` is the policy switch whose
 * answer the sources disagree on (SHIF and the levy); it is data, not code.
 *
 * payroll_runs / payroll_lines: a run is a locked pay period for one pay
 * group. A draft recomputes freely; approval FREEZES every line (minutes,
 * structure, rates and every figure are stored on the line) and posts one
 * Expense in the Salaries category for the employer cost. Paying records
 * method and reference per line; when every line is paid the run and its
 * expense are paid. A mistake after approval is an adjustment run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('effective_from');
            $table->string('kind', 10); // monthly | daily | hourly
            $table->decimal('base_amount', 12, 2);
            $table->string('currency_code', 3)->default('KES');
            $table->json('allowances')->nullable(); // [{name, amount, taxable}]
            $table->string('notes', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'effective_from']);
        });

        Schema::create('statutory_rate_tables', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20); // paye | nssf | shif | housing_levy
            $table->date('effective_from');
            $table->json('config');
            $table->boolean('reduces_taxable_pay')->default(false);
            $table->string('source', 300)->nullable();
            $table->boolean('is_confirmed')->default(false);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['code', 'effective_from']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 24)->unique();
            $table->foreignId('pay_period_id')->constrained('pay_periods');
            $table->foreignId('pay_group_id')->constrained('pay_groups');
            $table->string('kind', 12)->default('regular'); // regular | adjustment
            $table->string('status', 12)->default('draft');  // draft | approved | paid
            $table->unsignedSmallInteger('people')->default(0);
            foreach (['gross', 'taxable', 'paye', 'nssf_employee', 'nssf_employer', 'shif', 'housing_employee', 'housing_employer', 'other_deductions', 'net', 'employer_cost'] as $c) {
                $table->decimal($c, 14, 2)->default(0);
            }
            $table->json('rates_used')->nullable();     // the statutory rows the run was computed with
            $table->json('unpayable')->nullable();      // people in the group with no structure or no approved sheet
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['pay_group_id', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX payroll_runs_one_regular_per_period ON payroll_runs (pay_period_id) WHERE kind = 'regular'");

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('timesheet_id')->nullable()->constrained('timesheets')->nullOnDelete();
            $table->json('structure');          // the pay structure as used
            $table->json('hours');              // minutes and days from the timesheet snapshot
            $table->json('items');              // every line on the payslip: {label, amount, kind}
            foreach (['basic', 'overtime_pay', 'rest_day_pay', 'holiday_pay', 'allowances', 'unpaid_deduction', 'gross', 'taxable',
                      'paye', 'nssf_employee', 'nssf_employer', 'shif', 'housing_employee', 'housing_employer', 'other_deductions', 'net', 'employer_cost'] as $c) {
                $table->decimal($c, 12, 2)->default(0);
            }
            $table->string('payment_method', 16)->nullable();   // mpesa | bank | cash
            $table->string('payment_reference', 80)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 300)->nullable();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'user_id']);
            $table->index('user_id');
        });

        $now = now();
        $src = 'Seeded from 2026 third-party payroll guides (not KRA/NSSF/SHA publications) — confirm before the first real run';
        DB::table('statutory_rate_tables')->insert([
            ['code' => 'paye', 'effective_from' => '2026-01-01', 'reduces_taxable_pay' => false, 'source' => $src, 'is_confirmed' => false, 'created_at' => $now, 'updated_at' => $now,
             'config' => json_encode(['bands' => [['upto' => 24000, 'rate' => 0.10], ['upto' => 32333, 'rate' => 0.25], ['upto' => 500000, 'rate' => 0.30], ['upto' => 800000, 'rate' => 0.325], ['upto' => null, 'rate' => 0.35]], 'personal_relief' => 2400])],
            ['code' => 'nssf', 'effective_from' => '2026-01-01', 'reduces_taxable_pay' => true, 'source' => $src, 'is_confirmed' => false, 'created_at' => $now, 'updated_at' => $now,
             'config' => json_encode(['employee_rate' => 0.06, 'employer_rate' => 0.06, 'tier1_limit' => 9000, 'tier2_limit' => 108000])],
            ['code' => 'shif', 'effective_from' => '2026-01-01', 'reduces_taxable_pay' => true, 'source' => $src . '; deductibility per Tax Laws (Amendment) Act 2024 — sources disagree', 'is_confirmed' => false, 'created_at' => $now, 'updated_at' => $now,
             'config' => json_encode(['rate' => 0.0275, 'minimum' => 300])],
            ['code' => 'housing_levy', 'effective_from' => '2026-01-01', 'reduces_taxable_pay' => true, 'source' => $src . '; deductibility per Tax Laws (Amendment) Act 2024 — sources disagree', 'is_confirmed' => false, 'created_at' => $now, 'updated_at' => $now,
             'config' => json_encode(['employee_rate' => 0.015, 'employer_rate' => 0.015])],
        ]);

        if (!DB::table('expense_categories')->where('code', 'SALARIES')->exists()) {
            DB::table('expense_categories')->insert([
                'name' => 'Salaries & wages', 'code' => 'SALARIES', 'description' => 'Payroll runs post here: gross pay plus employer NSSF and Housing Levy (People PR 6).',
                'color' => '#6b4bd6', 'icon' => 'people', 'is_active' => true, 'is_tax_deductible' => true, 'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
        DB::statement('DROP INDEX IF EXISTS payroll_runs_one_regular_per_period');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('statutory_rate_tables');
        Schema::dropIfExists('pay_structures');
    }
};
