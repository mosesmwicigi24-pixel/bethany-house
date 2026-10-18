<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Currency plan, Step 2 (owner, 2026-10-18): one till, every currency apart.
 *
 * A till at a Kenyan outlet rings a US customer in dollars; until now the
 * dollars were added into the shilling drawer as if they were shillings
 * (USD 40 + KES 1,500 = "KES 1,540"). From here a till keeps ONE BALANCE ROW
 * PER CURRENCY it has touched (cash_register_balances): its own float and
 * expected cash, its own count, its own variance. Nothing is ever added
 * across currencies.
 *
 * The register's existing money columns stay exactly what they were, now
 * meaning the till's HOME currency (cash_registers.currency_code): every
 * existing reader, the finalized-till lock trigger and the tests keep
 * working, and TillDrawer writes the home row and the columns together.
 *
 * ADDITIVE and history-safe:
 *  - cash_register_transactions.currency_code: the currency of each ledger
 *    movement. NULL on rows written before this migration means "the
 *    register's own currency" (COALESCE in every reader); backfilled from
 *    the order where the movement has one, else the register.
 *  - a home-currency balance row for every register, copied from its columns;
 *  - for tills still OPEN, a row per foreign currency seen in their ledger,
 *    rebuilt from the ledger (float 0) so the drawer can be counted apart.
 *    Finalized tills are history: their foreign movements stay in the ledger
 *    and are corrected in Step 6, never rewritten here.
 *  - till_discrepancies: one per till AND currency (was one per till).
 *  - till_reconciliations / till_corrections: the currency they speak of.
 *
 * A balance row of a finalized till is locked the way the register is: the
 * model refuses, and on Postgres a trigger refuses underneath.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_transactions', function (Blueprint $table) {
            $table->string('currency_code', 3)->nullable()->after('amount');
            $table->index(['cash_register_id', 'currency_code'], 'crt_register_currency_idx');
        });

        // The movement's currency: the order's where it has one, else the register's.
        DB::statement(<<<'SQL'
            UPDATE cash_register_transactions t
               SET currency_code = UPPER(COALESCE(
                       (SELECT o.currency_code FROM orders o WHERE o.id = t.order_id),
                       r.currency_code, 'KES'))
              FROM cash_registers r
             WHERE r.id = t.cash_register_id
               AND t.currency_code IS NULL
        SQL);

        Schema::create('cash_register_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->constrained('cash_registers')->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->boolean('is_home')->default(false);
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->decimal('expected_cash', 12, 2)->default(0);      // running, from movements
            $table->decimal('actual_cash', 12, 2)->nullable();        // the count, once submitted
            $table->decimal('expected_cash_at_count', 12, 2)->nullable();
            $table->decimal('expected_cash_running_at_count', 12, 2)->nullable();
            $table->decimal('variance', 12, 2)->nullable();
            $table->string('variance_class', 16)->nullable();         // balanced | over | short
            $table->json('denomination_count')->nullable();
            $table->decimal('total_sales', 12, 2)->default(0);
            $table->decimal('total_cash_sales', 12, 2)->default(0);
            $table->decimal('total_card_sales', 12, 2)->default(0);
            $table->decimal('total_mpesa_sales', 12, 2)->default(0);
            $table->decimal('total_refunds', 12, 2)->default(0);
            $table->unsignedInteger('transaction_count')->default(0);
            $table->timestamps();

            $table->unique(['cash_register_id', 'currency_code'], 'crb_register_currency_unique');
        });

        // The home row: every register's columns as they stand.
        DB::statement(<<<'SQL'
            INSERT INTO cash_register_balances
                (cash_register_id, currency_code, is_home, opening_balance, expected_cash, actual_cash,
                 expected_cash_at_count, expected_cash_running_at_count, variance, variance_class, denomination_count,
                 total_sales, total_cash_sales, total_card_sales, total_mpesa_sales, total_refunds, transaction_count,
                 created_at, updated_at)
            SELECT id, UPPER(COALESCE(currency_code, 'KES')), TRUE,
                   COALESCE(opening_balance, 0), COALESCE(expected_cash, 0),
                   CASE WHEN status = 'open' THEN NULL ELSE actual_cash END,
                   expected_cash_at_count, expected_cash_running_at_count, variance, variance_class, denomination_count,
                   COALESCE(total_sales, 0), COALESCE(total_cash_sales, 0), COALESCE(total_card_sales, 0),
                   COALESCE(total_mpesa_sales, 0), COALESCE(total_refunds, 0), COALESCE(transaction_count, 0),
                   COALESCE(opened_at, created_at, NOW()), NOW()
              FROM cash_registers
        SQL);

        // Open tills: a row per foreign currency their ledger has moved, rebuilt
        // from the ledger. Cash expected = sales + cash in − refunds − voids − paid-outs
        // in that currency; the float is 0 (a foreign float is typed at opening from now on).
        DB::statement(<<<'SQL'
            INSERT INTO cash_register_balances
                (cash_register_id, currency_code, is_home, opening_balance, expected_cash,
                 total_cash_sales, total_sales, total_refunds, transaction_count, created_at, updated_at)
            SELECT t.cash_register_id, t.currency_code, FALSE, 0,
                   GREATEST(0, SUM(CASE
                       WHEN t.transaction_type IN ('sale', 'cash_in') THEN ABS(t.amount)
                       WHEN t.transaction_type IN ('refund', 'void', 'cash_out') THEN -ABS(t.amount)
                       ELSE 0 END)),
                   SUM(CASE WHEN t.transaction_type = 'sale' THEN ABS(t.amount) ELSE 0 END),
                   SUM(CASE WHEN t.transaction_type = 'sale' THEN ABS(t.amount) ELSE 0 END),
                   SUM(CASE WHEN t.transaction_type = 'refund' THEN ABS(t.amount) ELSE 0 END),
                   SUM(CASE WHEN t.transaction_type = 'sale' THEN 1 ELSE 0 END),
                   NOW(), NOW()
              FROM cash_register_transactions t
              JOIN cash_registers r ON r.id = t.cash_register_id
             WHERE r.status = 'open'
               AND (t.payment_method IS NULL OR t.payment_method = 'cash')
               AND t.currency_code <> UPPER(COALESCE(r.currency_code, 'KES'))
             GROUP BY t.cash_register_id, t.currency_code
        SQL);

        // One discrepancy per till and currency.
        Schema::table('till_discrepancies', function (Blueprint $table) {
            $table->dropUnique(['cash_register_id']);
            $table->unique(['cash_register_id', 'currency_code'], 'till_discrepancies_register_currency_unique');
        });

        Schema::table('till_reconciliations', function (Blueprint $table) {
            $table->string('currency_code', 3)->nullable()->after('cash_register_id');
        });
        Schema::table('till_corrections', function (Blueprint $table) {
            $table->string('currency_code', 3)->nullable()->after('cash_register_id');
        });
        DB::statement('UPDATE till_reconciliations x SET currency_code = UPPER(COALESCE(r.currency_code, \'KES\')) FROM cash_registers r WHERE r.id = x.cash_register_id AND x.currency_code IS NULL');
        DB::statement('UPDATE till_corrections x SET currency_code = UPPER(COALESCE(r.currency_code, \'KES\')) FROM cash_registers r WHERE r.id = x.cash_register_id AND x.currency_code IS NULL');

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // A balance row of a finalized till is part of the locked batch.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_register_balance_lock() RETURNS trigger AS $$
            DECLARE
                locked_at timestamp;
            BEGIN
                SELECT finalized_at INTO locked_at FROM cash_registers
                 WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.cash_register_id ELSE NEW.cash_register_id END;
                IF locked_at IS NULL THEN
                    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'cash register % is finalized; its balances cannot change', OLD.cash_register_id
                    USING ERRCODE = 'insufficient_privilege';
            END
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('DROP TRIGGER IF EXISTS cash_register_balances_lock ON cash_register_balances');
        DB::unprepared('CREATE TRIGGER cash_register_balances_lock BEFORE UPDATE OR DELETE ON cash_register_balances
                        FOR EACH ROW EXECUTE FUNCTION cash_register_balance_lock()');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS cash_register_balances_lock ON cash_register_balances');
            DB::unprepared('DROP FUNCTION IF EXISTS cash_register_balance_lock()');
        }

        Schema::table('till_corrections', fn (Blueprint $t) => $t->dropColumn('currency_code'));
        Schema::table('till_reconciliations', fn (Blueprint $t) => $t->dropColumn('currency_code'));

        Schema::table('till_discrepancies', function (Blueprint $table) {
            $table->dropUnique('till_discrepancies_register_currency_unique');
            $table->unique(['cash_register_id']);
        });

        Schema::dropIfExists('cash_register_balances');

        Schema::table('cash_register_transactions', function (Blueprint $table) {
            $table->dropIndex('crt_register_currency_idx');
            $table->dropColumn('currency_code');
        });
    }
};
