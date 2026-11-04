<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Commission on top of salary (owner, 2026-11-03/04): finance records a
 * commission against a transaction; a super admin approves it; the next pay
 * run adds every approved commission to the person's pay; when that line is
 * paid the commission is paid and their balance goes back to zero.
 *
 *   recorded → approved → paid      (void with a reason instead of deleting)
 *
 * payroll_line_id ties a commission to the payslip that paid it, set when
 * the run is approved, so a commission is never paid twice. One commission
 * per person per transaction while it stands.
 *
 * Whether PAYE is charged on commission is the setting
 * payroll.commission_paye — off for now (owner, 2026-11-04).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('transaction_ref', 80);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('earned_on');
            $table->string('note', 500)->nullable();
            $table->string('status', 20)->default('recorded'); // recorded | approved | paid | void
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('payroll_line_id')->nullable()->constrained('payroll_lines')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX staff_commissions_one_per_transaction ON staff_commissions (user_id, lower(transaction_ref)) WHERE status <> 'void'");

        Schema::table('payroll_lines', function (Blueprint $table) {
            $table->json('commissions')->nullable();
        });

        DB::table('settings')->insertOrIgnore([
            'key' => 'payroll.commission_paye', 'value' => '0', 'data_type' => 'boolean', 'group' => 'payroll',
            'is_public' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('payroll_lines', fn (Blueprint $table) => $table->dropColumn('commissions'));
        Schema::dropIfExists('staff_commissions');
        DB::table('settings')->where('key', 'payroll.commission_paye')->delete();
    }
};
