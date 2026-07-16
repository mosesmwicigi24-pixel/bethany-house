<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency key for POS sale creation.
 *
 * Order #53 collected three identical payments one second apart from a single
 * double/triple-tapped button. recordPosPay is now guarded by a short
 * same-order/method/amount window (PR #112), which is safe there because a second
 * identical payment on the same order within seconds cannot be real.
 *
 * That reasoning does NOT transfer to creating a SALE. Two identical sales are
 * completely normal — it is a queue: the next customer buys the same item at the
 * same till moments later. Any content-based guess would eventually swallow a
 * real sale, and the risks are not symmetric: a duplicate order is visible and
 * fixable, a swallowed one is lost revenue and a customer who paid for nothing.
 *
 * So sales dedupe on an EXACT client-supplied key instead of a heuristic. Same
 * key = the same attempt arriving again; a genuine second sale carries a new key
 * and is never touched.
 *
 * The unique index is what actually enforces this: two concurrent submits both
 * miss the read-check, both insert, and the database rejects one — the caller
 * catches that and returns the winner's order. Without the index this would be
 * a race, not a guard.
 *
 * Partial (WHERE NOT NULL) because existing rows and any client that does not
 * send a key must stay writable — Postgres treats NULLs as distinct in a plain
 * unique index, but being explicit documents the intent and keeps the index
 * small.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('client_request_id', 64)->nullable()->after('order_number');
        });

        DB::statement(
            'CREATE UNIQUE INDEX orders_client_request_id_unique
             ON orders (client_request_id)
             WHERE client_request_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_client_request_id_unique');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('client_request_id');
        });
    }
};
