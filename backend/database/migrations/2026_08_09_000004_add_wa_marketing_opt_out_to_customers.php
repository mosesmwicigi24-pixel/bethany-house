<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-customer opt-out from WhatsApp MARKETING messages (reorder pings).
 * Transactional messages (order lookup codes, receipts) are unaffected —
 * this flag only gates business-initiated marketing templates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('wa_marketing_opt_out')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('wa_marketing_opt_out');
        });
    }
};
