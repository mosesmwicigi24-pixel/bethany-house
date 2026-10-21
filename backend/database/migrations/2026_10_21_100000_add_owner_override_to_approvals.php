<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner override of maker ≠ checker (owner, 2026-10-21; App\Support\OwnerOverride).
 *
 * When the owner account signs an approval it raised itself — allowed for the
 * actions OwnerOverride lists, after a fresh step-up — the signature and the
 * expense's approval row say so, so the exception is visible wherever the
 * record is shown or exported. Default false: every existing row was signed
 * by someone other than its maker (MakerChecker refused everything else).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_signatures', function (Blueprint $table) {
            $table->boolean('owner_override')->default(false)->after('reason');
        });
        Schema::table('expense_approvals', function (Blueprint $table) {
            $table->boolean('owner_override')->default(false)->after('step');
        });
    }

    public function down(): void
    {
        Schema::table('approval_signatures', fn (Blueprint $t) => $t->dropColumn('owner_override'));
        Schema::table('expense_approvals', fn (Blueprint $t) => $t->dropColumn('owner_override'));
    }
};
