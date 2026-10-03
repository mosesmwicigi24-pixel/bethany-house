<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role hardening, Phase 4B part 1 — the till lifecycle (plan §17, phase4_spec 4B).
 *
 *   open (float counted blind) → counted (operator's blind count) →
 *   closed + finalized_at (an outlet manager, not the operator, verifies) →
 *   reconciled by the accountant against the payments ledger, next day.
 *
 * A finalized till is never reopened and its money never changes: finance opens
 * a linked correction instead (till_corrections), and the original stays as it
 * was counted.
 *
 * ADDITIVE ONLY. Production has registers that were opened weeks ago and never
 * counted (owner's note: KSh 1.7m / 1.57m uncounted). Nothing here touches an
 * existing row's values: every new column is nullable and starts NULL, so a
 * register opened before this migration reads as legacy (lifecycle_version
 * NULL) and still closes through the new flow. Closed legacy rows keep
 * finalized_at NULL — they were closed, never verified, and the screens say so
 * rather than pretending otherwise.
 *
 * The lock is enforced twice: CashRegister refuses the change (409 at the API),
 * and on Postgres a trigger refuses it underneath, so no raw DB::table() write
 * can move a finalized till's money either.
 */
return new class extends Migration
{
    /** The columns a finalized till may never change. Mirrors CashRegister::LOCKED_FIELDS. */
    private const LOCKED = [
        'outlet_id', 'status', 'currency_code',
        'opening_balance', 'closing_balance', 'expected_cash', 'actual_cash',
        'total_sales', 'total_cash_sales', 'total_card_sales', 'total_mpesa_sales', 'total_refunds',
        'transaction_count', 'denomination_count', 'opened_at', 'closed_at',
        'lifecycle_version', 'expected_cash_at_count', 'expected_cash_running_at_count',
        'variance', 'variance_class', 'variance_reason', 'finalized_at',
    ];

    public function up(): void
    {
        Schema::table('cash_registers', function (Blueprint $table) {
            // NULL = opened before the lifecycle existed (legacy); 1 = opened through it.
            $table->unsignedSmallInteger('lifecycle_version')->nullable();
            // The opener's blind float against the last count at this outlet. Logged, never edited.
            $table->unsignedBigInteger('previous_register_id')->nullable();
            $table->decimal('float_vs_previous_close', 12, 2)->nullable();
            // Frozen when the operator submits the count. The ledger figure is the
            // basis; the running column is kept beside it so a disagreement shows.
            $table->decimal('expected_cash_at_count', 12, 2)->nullable();
            $table->decimal('expected_cash_running_at_count', 12, 2)->nullable();
            $table->decimal('variance', 12, 2)->nullable();
            $table->string('variance_class', 16)->nullable();   // balanced | over | short
            $table->text('variance_reason')->nullable();
            // Verification. No FK: a user row going away must not try to rewrite a locked till.
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->text('verification_notes')->nullable();

            $table->index('finalized_at');
            $table->index('verified_by');
        });

        // Any non-zero variance at finalization. Part 2 hands the ones awaiting
        // approval to the Phase 3B ApprovalEngine (approval_request_id is that seam).
        Schema::create('till_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->unique()->constrained('cash_registers')->restrictOnDelete();
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->unsignedBigInteger('raised_by')->nullable();      // the verifier who finalized
            $table->decimal('amount', 12, 2);                         // signed: counted − expected
            $table->string('direction', 8);                           // over | short
            $table->string('currency_code', 3)->default('KES');
            $table->decimal('expected_cash', 12, 2);
            $table->decimal('counted_cash', 12, 2);
            // Ledger expected − running expected, when they disagree (legacy registers).
            $table->decimal('expected_basis_mismatch', 12, 2)->nullable();
            $table->text('reason');
            // logged (|amount| ≤ 100) | awaiting_approval (> 100). Part 2 adds the outcomes.
            $table->string('status', 32);
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'outlet_id']);
        });

        // The accountant's independent check, next day. Append-only: a second
        // look is a second row.
        Schema::create('till_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->constrained('cash_registers')->restrictOnDelete();
            $table->unsignedBigInteger('reconciled_by')->nullable();
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('till_cash_sales', 12, 2);     // cash the till's ledger says it took
            $table->decimal('payments_cash', 12, 2);       // cash the payments ledger says was taken for those orders
            $table->decimal('difference', 12, 2);          // till − payments
            $table->unsignedInteger('missing_from_till_count')->default(0);
            $table->decimal('missing_from_till_amount', 12, 2)->default(0);
            $table->string('status', 16);                  // matched | mismatch
            $table->boolean('flagged_for_finance')->default(false);
            $table->json('details')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['cash_register_id', 'id']);
            $table->index(['flagged_for_finance', 'created_at']);
        });

        // Finance's correction of a finalized till: a new record pointing at the
        // original, with its reason. The original is never touched.
        Schema::create('till_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->constrained('cash_registers')->restrictOnDelete();
            $table->unsignedBigInteger('opened_by')->nullable();
            $table->text('reason');
            $table->decimal('original_actual_cash', 12, 2)->nullable();
            $table->decimal('original_expected_cash', 12, 2)->nullable();
            $table->decimal('original_variance', 12, 2)->nullable();
            $table->decimal('corrected_actual_cash', 12, 2)->nullable();
            $table->decimal('corrected_expected_cash', 12, 2)->nullable();
            $table->decimal('corrected_variance', 12, 2)->nullable();
            // recorded; part 2 routes the ones above the discrepancy band for approval.
            $table->string('status', 32)->default('recorded');
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->timestamps();

            $table->index('cash_register_id');
        });

        // A paid order is never silently rewritten: an edit of its lines leaves
        // this record of what it was, what it became, who and why.
        Schema::create('order_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('kind', 32)->default('line_edit');
            $table->text('reason');
            $table->decimal('amount_paid', 12, 2);
            $table->decimal('old_total', 12, 2);
            $table->decimal('new_total', 12, 2);
            $table->json('before');
            $table->json('after');
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $locked = implode(', ', array_map(fn ($c) => "NEW.{$c}", self::LOCKED));
        $old    = implode(', ', array_map(fn ($c) => "OLD.{$c}", self::LOCKED));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION cash_register_finalized_lock() RETURNS trigger AS \$\$
            BEGIN
                IF OLD.finalized_at IS NULL THEN
                    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'cash register % is finalized and cannot be deleted', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
                IF ROW({$locked}) IS DISTINCT FROM ROW({$old}) THEN
                    RAISE EXCEPTION 'cash register % is finalized; its money fields cannot change', OLD.id
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
                RETURN NEW;
            END
            \$\$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('DROP TRIGGER IF EXISTS cash_registers_finalized_lock ON cash_registers');
        DB::unprepared('CREATE TRIGGER cash_registers_finalized_lock BEFORE UPDATE OR DELETE ON cash_registers
                        FOR EACH ROW EXECUTE FUNCTION cash_register_finalized_lock()');

        // Rows whose facts are fixed but whose workflow columns (named as
        // trigger arguments) part 2 will move. DELETE is refused outright.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION till_record_guard() RETURNS trigger AS $$
            DECLARE
                o jsonb;
                n jsonb;
                c text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION '% rows cannot be deleted', TG_TABLE_NAME
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
                o := to_jsonb(OLD);
                n := to_jsonb(NEW);
                FOREACH c IN ARRAY TG_ARGV LOOP
                    o := o - c;
                    n := n - c;
                END LOOP;
                IF o IS DISTINCT FROM n THEN
                    RAISE EXCEPTION 'only the workflow columns of % may change', TG_TABLE_NAME
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS till_discrepancies_guard ON till_discrepancies');
        DB::unprepared("CREATE TRIGGER till_discrepancies_guard BEFORE UPDATE OR DELETE ON till_discrepancies
                        FOR EACH ROW EXECUTE FUNCTION till_record_guard('status', 'approval_request_id',
                        'resolved_by', 'resolved_at', 'resolution_notes', 'updated_at')");

        DB::unprepared('DROP TRIGGER IF EXISTS till_corrections_guard ON till_corrections');
        DB::unprepared("CREATE TRIGGER till_corrections_guard BEFORE UPDATE OR DELETE ON till_corrections
                        FOR EACH ROW EXECUTE FUNCTION till_record_guard('status', 'approval_request_id', 'updated_at')");

        // Fully append-only (function from 2026_09_21_000001).
        DB::unprepared('DROP TRIGGER IF EXISTS till_reconciliations_append_only ON till_reconciliations');
        DB::unprepared('CREATE TRIGGER till_reconciliations_append_only BEFORE UPDATE OR DELETE ON till_reconciliations
                        FOR EACH ROW EXECUTE FUNCTION audit_append_only()');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS cash_registers_finalized_lock ON cash_registers');
            DB::unprepared('DROP TRIGGER IF EXISTS till_discrepancies_guard ON till_discrepancies');
            DB::unprepared('DROP TRIGGER IF EXISTS till_corrections_guard ON till_corrections');
            DB::unprepared('DROP TRIGGER IF EXISTS till_reconciliations_append_only ON till_reconciliations');
            DB::unprepared('DROP FUNCTION IF EXISTS cash_register_finalized_lock()');
            DB::unprepared('DROP FUNCTION IF EXISTS till_record_guard()');
        }

        Schema::dropIfExists('order_corrections');
        Schema::dropIfExists('till_corrections');
        Schema::dropIfExists('till_reconciliations');
        Schema::dropIfExists('till_discrepancies');

        Schema::table('cash_registers', function (Blueprint $table) {
            $table->dropIndex(['finalized_at']);
            $table->dropIndex(['verified_by']);
            $table->dropColumn([
                'lifecycle_version', 'previous_register_id', 'float_vs_previous_close',
                'expected_cash_at_count', 'expected_cash_running_at_count', 'variance', 'variance_class',
                'variance_reason', 'verified_by', 'finalized_at', 'verification_notes',
            ]);
        });
    }
};
