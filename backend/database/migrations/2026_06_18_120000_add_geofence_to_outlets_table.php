<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds geofencing config to outlets. Outlets already have latitude/
     * longitude (used for shipping/pickup) - this just adds the radius
     * within which a Time Clock clock-in is accepted for that location.
     *
     * Null radius = geofencing disabled for that outlet (clock-ins allowed
     * from anywhere). This lets you roll out geofencing outlet-by-outlet
     * instead of all-or-nothing.
     */
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->unsignedInteger('geofence_radius_meters')
                ->nullable()
                ->default(100)
                ->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn('geofence_radius_meters');
        });
    }
};
