<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log of MANUAL win-back outreach — a staff member reaching a dormant
 * customer by WhatsApp or phone from the Win-back tab.
 *
 * One row per contact attempt. The row is what turns the win-back list from
 * a static report into a measured engine: recovery attribution ("did they
 * order within 30 days of us reaching out?") joins orders back to these
 * rows, and the 24h dedupe stops two clerks logging the same call twice.
 *
 * phone is stored CANONICAL (E.164 digits, no '+', via App\Support\Phone) —
 * the same convention as replenishment_pings — so an outreach logged from a
 * row keyed on 0722… still matches orders captured as +254722….
 *
 * revenue_365_at_contact / days_quiet_at_contact snapshot what the engine
 * knew at the moment of contact: the trailing-365 metrics keep moving, but
 * "we called a KES 80,000/yr customer at 75 days quiet" should not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('win_back_outreach', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('phone', 32)->nullable();
            $table->string('name', 200);
            // whatsapp | call | other
            $table->string('channel', 20);
            $table->foreignId('contacted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('revenue_365_at_contact', 12, 2)->nullable();
            $table->integer('days_quiet_at_contact')->nullable();
            $table->timestamps();

            // Dedupe ("logged in the last 24h?") and latest-outreach lookups.
            $table->index(['phone', 'created_at']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('win_back_outreach');
    }
};
