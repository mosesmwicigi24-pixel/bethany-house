<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bethany People, PR 1 — clock in on the phone (owner, 24 Oct 2026).
 *
 * The time clock has existed server-side since June with no phone screen.
 * This adds what a phone punch carries that the browser could not before:
 *
 *  - accuracy: the phone's own uncertainty in metres, stored beside the fix.
 *  - recorded_at: the phone's clock when the punch was made offline and sent
 *    later; clock_in_at/clock_out_at hold that time, created_at the receipt.
 *  - flags: why a manager should look (accuracy_low, edge, sent_late,
 *    mock_location, auto_clock_out). Status stays `active` while open so the
 *    one-active-per-user index keeps working; it becomes `flagged` on close.
 *  - device_id: the named sign-in device (devices table) the punch came from.
 *  - auto_closed_at: when the nightly job closed a forgotten shift.
 *
 * Geofence: the owner chose 50 m with an accuracy gate over 20 m (a phone
 * near a building is often 20–40 m uncertain). The column default moves from
 * 100 to 50, and rows still sitting at the untouched default 100 are moved to
 * 50. Any outlet can be set to another radius in Settings › Outlets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->decimal('clock_in_accuracy_meters', 8, 2)->nullable()->after('clock_in_distance_meters');
            $table->decimal('clock_out_accuracy_meters', 8, 2)->nullable()->after('clock_out_distance_meters');
            $table->timestamp('clock_in_recorded_at')->nullable()->after('clock_in_at');
            $table->timestamp('clock_out_recorded_at')->nullable()->after('clock_out_at');
            $table->json('flags')->nullable()->after('flagged_reason');
            $table->foreignId('device_id')->nullable()->after('device_info')->constrained('devices')->nullOnDelete();
            $table->string('app_version', 40)->nullable()->after('device_id');
            $table->timestamp('auto_closed_at')->nullable()->after('corrected_by');
        });

        Schema::table('outlets', function (Blueprint $table) {
            $table->unsignedInteger('geofence_radius_meters')->nullable()->default(50)->change();
        });
        DB::table('outlets')->where('geofence_radius_meters', 100)->update(['geofence_radius_meters' => 50]);
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->unsignedInteger('geofence_radius_meters')->nullable()->default(100)->change();
        });
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
            $table->dropColumn([
                'clock_in_accuracy_meters', 'clock_out_accuracy_meters', 'clock_in_recorded_at',
                'clock_out_recorded_at', 'flags', 'app_version', 'auto_closed_at',
            ]);
        });
    }
};
