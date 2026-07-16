<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EoD reports were write-only.
 *
 * The table held report_date, order_notes, sentiments and submitted_at — no read
 * state, no acknowledgement, nowhere to reply. A clerk submitted into a void: she
 * could not tell whether anyone had read it, and an owner reading it at 22:52 had
 * nowhere to put a question. Priscilla's 15 Jul report asks, in prose, for three
 * receipts to be adjusted and one voided — a request with no channel to travel on.
 *
 * Two additions:
 *   - acknowledged_at/_by — the countersignature. The shift-handover norm (a
 *     manager signing off a Lightspeed/Square shift report, an aviation tech log).
 *     It is an audit fact, not a conversation: it says "seen", never "answered".
 *   - eod_report_comments — the conversation, kept ON the record rather than in
 *     chat, so in six months the report still explains itself and an auditor sees
 *     the question next to the number it was about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_register_eod_reports', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->foreign('acknowledged_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('eod_report_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('eod_report_id');
            $table->unsignedBigInteger('user_id');
            $table->text('body');
            $table->timestamps();

            $table->foreign('eod_report_id')
                ->references('id')->on('cash_register_eod_reports')
                ->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // Every read is "the thread for this report, oldest first".
            $table->index(['eod_report_id', 'id'], 'idx_eod_comments_report');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eod_report_comments');

        Schema::table('cash_register_eod_reports', function (Blueprint $table) {
            $table->dropForeign(['acknowledged_by']);
            $table->dropColumn(['acknowledged_at', 'acknowledged_by']);
        });
    }
};
