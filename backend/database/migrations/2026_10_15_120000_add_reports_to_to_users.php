<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a staff member reports to (owner, 2026-10-15): "People who are line
 * managers should only see what they have done, or those who are under them."
 *
 * Nullable, no backfill: until someone is given a manager, every view is
 * exactly what it was. A line manager's own-scoped view (orders, quotations,
 * expenses, production tasks — ViewerScope) widens to her DIRECT reports
 * only; nothing else reads this column. Deleting a manager clears the link
 * rather than the report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('reports_to')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
            $table->index('reports_to');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reports_to');
        });
    }
};
