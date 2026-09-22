<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Imprest — a petty-cash float (owner decisions 2026-09-22).
 *
 *   - A standard float (KES 10,000) held by a custodian.
 *   - Every expense asks "Pay from imprest?"; yes takes the amount off the
 *     moment the expense is recorded — that is when the cash leaves the box.
 *   - When it runs low (below 20% of the float) anyone who records expenses
 *     can ask for a top-up; the super admin sends the money and says so; the
 *     balance rises only when the CUSTODIAN confirms what actually arrived.
 *   - Nobody approves an imprest expense they recorded.
 *
 * imprest_transactions is the ledger: every shilling in or out is one row with
 * the balance after it. It is append-only at the database — UPDATE and DELETE
 * raise (the audit trail's trigger function). A mistake is corrected by a new
 * row, never by editing an old one. TRUNCATE is left alone so Database → full
 * wipe can still reset business data; expenses reference it RESTRICT, so a
 * force-deleted expense cannot take its money trail with it.
 *
 * imprest_accounts.balance is a cache of the ledger, written in the same
 * transaction as each row, under a row lock (ImprestService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imprest_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->foreignId('custodian_id')->constrained('users');
            $table->char('currency_code', 3)->default('KES');
            $table->decimal('float_amount', 12, 2);
            $table->unsignedTinyInteger('low_balance_percent')->default(20);
            $table->decimal('balance', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('low_balance_notified_at')->nullable();
            $table->foreignId('opened_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('imprest_topup_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('imprest_account_id')->constrained('imprest_accounts');
            // pending → sent (super admin says the money went) → received (custodian
            // confirms what arrived; the ledger credit happens here); or declined /
            // cancelled. A direct load by the super admin starts at sent.
            $table->string('status', 20)->index();
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->decimal('requested_amount', 12, 2)->nullable();
            $table->decimal('balance_at_request', 12, 2)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users');
            $table->decimal('sent_amount', 12, 2)->nullable();
            $table->string('sent_method', 20)->nullable();
            $table->string('sent_reference', 100)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('sent_note')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->decimal('received_amount', 12, 2)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('receipt_note')->nullable();
            $table->foreignId('declined_by')->nullable()->constrained('users');
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('imprest_cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('imprest_account_id')->constrained('imprest_accounts');
            $table->decimal('counted_amount', 12, 2);
            $table->decimal('book_balance', 12, 2);
            $table->decimal('variance', 12, 2);              // counted − book
            $table->text('note')->nullable();
            $table->foreignId('counted_by')->constrained('users');
            $table->string('status', 20)->index();           // pending | approved | rejected
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
        });

        Schema::create('imprest_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('imprest_account_id')->constrained('imprest_accounts');
            // opening | top_up | expense | expense_adjustment | cash_returned | count_adjustment
            $table->string('type', 30);
            $table->decimal('amount', 12, 2);                // + in, − out
            $table->decimal('balance_after', 12, 2);
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->restrictOnDelete();
            $table->foreignId('topup_request_id')->nullable()->constrained('imprest_topup_requests')->restrictOnDelete();
            $table->foreignId('cash_count_id')->nullable()->constrained('imprest_cash_counts')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['imprest_account_id', 'id']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('imprest_account_id')->nullable()->after('payment_reference')
                ->constrained('imprest_accounts')->restrictOnDelete();
            // For an imprest expense that is rejected or cancelled after the cash
            // left: pending → returned (cash back in the box, credited) or
            // written_off (it is gone; recorded, not credited).
            $table->string('imprest_resolution', 20)->nullable()->after('imprest_account_id');
            $table->foreignId('imprest_resolved_by')->nullable()->after('imprest_resolution')->constrained('users');
            $table->timestamp('imprest_resolved_at')->nullable()->after('imprest_resolved_by');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // One debit per expense, however many times "Save" is pressed.
        DB::statement("CREATE UNIQUE INDEX imprest_one_debit_per_expense ON imprest_transactions (expense_id) WHERE type = 'expense'");
        // One ledger credit per top-up.
        DB::statement("CREATE UNIQUE INDEX imprest_one_credit_per_topup ON imprest_transactions (topup_request_id) WHERE type = 'top_up'");
        // One adjustment per approved cash count.
        DB::statement("CREATE UNIQUE INDEX imprest_one_adjustment_per_count ON imprest_transactions (cash_count_id) WHERE type = 'count_adjustment'");

        // The ledger is append-only (function from 2026_09_21_000001).
        DB::unprepared('DROP TRIGGER IF EXISTS imprest_transactions_append_only ON imprest_transactions');
        DB::unprepared('CREATE TRIGGER imprest_transactions_append_only BEFORE UPDATE OR DELETE ON imprest_transactions
                        FOR EACH ROW EXECUTE FUNCTION audit_append_only()');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS imprest_transactions_append_only ON imprest_transactions');
        }
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('imprest_resolved_by');
            $table->dropConstrainedForeignId('imprest_account_id');
            $table->dropColumn(['imprest_resolution', 'imprest_resolved_at']);
        });
        Schema::dropIfExists('imprest_transactions');
        Schema::dropIfExists('imprest_cash_counts');
        Schema::dropIfExists('imprest_topup_requests');
        Schema::dropIfExists('imprest_accounts');
    }
};
