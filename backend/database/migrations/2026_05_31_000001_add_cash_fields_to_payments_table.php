<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Only add if not already present (safe to re-run)
            if (!Schema::hasColumn('payments', 'cash_received')) {
                $table->decimal('cash_received', 12, 2)->nullable()->after('phone_number')
                    ->comment('For cash payments: amount tendered by customer');
            }
            if (!Schema::hasColumn('payments', 'change_given')) {
                $table->decimal('change_given', 12, 2)->nullable()->after('cash_received')
                    ->comment('For cash payments: change returned to customer');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumnIfExists('cash_received');
            $table->dropColumnIfExists('change_given');
        });
    }
};