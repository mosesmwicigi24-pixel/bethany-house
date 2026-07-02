<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 3 - Shipment & Delivery Tracking
 *
 * Adds:
 *  1. order_shipments.tracking_token   - UUID used in the public /track/{token} URL
 *  2. order_shipments.carrier_tracking_url - external carrier deep-link (optional)
 *  3. shipment_tracking.is_public      - hide internal notes from the customer page
 *  4. shipment_tracking.added_by       - FK to users who added the event
 *
 * Backfills tracking_token for any existing shipments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            $table->string('tracking_token', 64)->nullable()->unique()->after('tracking_number');
            $table->string('carrier_tracking_url', 500)->nullable()->after('tracking_token');
        });

        // Backfill: give every existing shipment a unique token
        DB::table('order_shipments')->whereNull('tracking_token')->orderBy('id')->each(function ($row) {
            DB::table('order_shipments')
                ->where('id', $row->id)
                ->update(['tracking_token' => Str::uuid()->toString()]);
        });

        Schema::table('shipment_tracking', function (Blueprint $table) {
            // true = visible on the public tracking page; false = admin-only note
            $table->boolean('is_public')->default(true)->after('description');
            // Who added this event (nullable - legacy rows and auto events have no actor)
            $table->unsignedBigInteger('added_by')->nullable()->after('is_public');
            $table->foreign('added_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipment_tracking', function (Blueprint $table) {
            $table->dropForeign(['added_by']);
            $table->dropColumn(['is_public', 'added_by']);
        });

        Schema::table('order_shipments', function (Blueprint $table) {
            $table->dropColumn(['tracking_token', 'carrier_tracking_url']);
        });
    }
};