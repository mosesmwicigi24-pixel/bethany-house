<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_registers', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('outlet_id');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['outlet_id', 'user_id', 'status'], 'idx_register_outlet_user_status');
        });

        // Back-fill from opened_by
        DB::statement('UPDATE cash_registers SET user_id = opened_by WHERE user_id IS NULL AND opened_by IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('cash_registers', function (Blueprint $table) {
            $table->dropIndex('idx_register_outlet_user_status');
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};