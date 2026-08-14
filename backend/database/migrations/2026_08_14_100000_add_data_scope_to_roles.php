<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every role a data scope, and backfills all of them to 'all'.
 *
 * 'all' is exactly today's behaviour, so this migration changes nothing on its
 * own. That is the point: the column and the query-layer enforcement land and
 * are proven inert, and narrowing a role afterwards is a one-line change whose
 * effect appears in the scope-matrix test rather than in production.
 *
 * Also indexes orders.created_by. Once a role is scoped to 'own', every list,
 * export and search that role runs filters on that column, and it has carried a
 * foreign key but no index since it was added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('data_scope', 16)->default('all')->after('guard_name');
        });

        // Explicit rather than relying on the default, so existing rows are
        // unambiguous even if the default is ever changed.
        DB::table('roles')->update(['data_scope' => 'all']);

        Schema::table('orders', function (Blueprint $table) {
            $table->index('created_by', 'orders_created_by_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_created_by_index');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('data_scope');
        });
    }
};
