<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds attachment support to:
 *  - order_shipments       (dispatch document / waybill attached at creation)
 *  - shipment_tracking     (photo proof at each tracking event)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Attachment on the shipment itself (e.g. waybill, dispatch note)
        Schema::table('order_shipments', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('notes');
            $table->string('attachment_name')->nullable()->after('attachment_path');
        });

        // Attachment on each tracking event (e.g. photo proof of delivery)
        Schema::table('shipment_tracking', function (Blueprint $table) {
            $table->string('attachment_path')->nullable()->after('description');
            $table->string('attachment_name')->nullable()->after('attachment_path');
        });
    }

    public function down(): void
    {
        Schema::table('order_shipments', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_name']);
        });

        Schema::table('shipment_tracking', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_name']);
        });
    }
};