<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the single attachment_path/attachment_name columns on
 * order_shipments and shipment_tracking with a proper one-to-many
 * attachments table. Each attachment now carries its own is_public flag,
 * so staff can attach several files to a shipment or a tracking event and
 * choose which ones the customer can see on the public tracking page.
 *
 * attachable_type is either:
 *   'shipment' — attached directly to the order_shipments row
 *   'tracking' — attached to a specific shipment_tracking row
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type', 20); // 'shipment' | 'tracking'
            $table->unsignedBigInteger('attachable_id'); // order_shipments.id OR shipment_tracking.id
            // Kept for convenience so we never need a join just to know which
            // shipment an attachment belongs to (tracking attachments are one
            // hop away from order_shipments via shipment_tracking.shipment_id).
            $table->unsignedBigInteger('shipment_id');
            $table->string('path');
            $table->string('original_name');
            // Customer-visible on the public tracking page when true.
            // Defaults to false - staff must explicitly opt each file in,
            // same as the existing is_public default behaviour on tracking events.
            $table->boolean('is_public')->default(false);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id']);
            $table->index('shipment_id');

            $table->foreign('shipment_id')->references('id')->on('order_shipments')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });

        // ── Migrate existing single attachments into the new table ──────────
        // Existing attachments had no visibility flag at all - they were only
        // ever served through an authenticated admin-only route, so they were
        // never customer-facing. Default them to is_public = false to preserve
        // that behaviour exactly; staff can flip them on afterward if desired.

        if (Schema::hasColumn('order_shipments', 'attachment_path')) {
            $shipments = DB::table('order_shipments')
                ->whereNotNull('attachment_path')
                ->select('id', 'attachment_path', 'attachment_name')
                ->get();

            foreach ($shipments as $s) {
                DB::table('shipment_attachments')->insert([
                    'attachable_type' => 'shipment',
                    'attachable_id'   => $s->id,
                    'shipment_id'     => $s->id,
                    'path'            => $s->attachment_path,
                    'original_name'   => $s->attachment_name ?? basename($s->attachment_path),
                    'is_public'       => false,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }

        if (Schema::hasColumn('shipment_tracking', 'attachment_path')) {
            $events = DB::table('shipment_tracking')
                ->whereNotNull('attachment_path')
                ->select('id', 'shipment_id', 'attachment_path', 'attachment_name')
                ->get();

            foreach ($events as $e) {
                DB::table('shipment_attachments')->insert([
                    'attachable_type' => 'tracking',
                    'attachable_id'   => $e->id,
                    'shipment_id'     => $e->shipment_id,
                    'path'            => $e->attachment_path,
                    'original_name'   => $e->attachment_name ?? basename($e->attachment_path),
                    'is_public'       => false,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }

        // Drop the old single-attachment columns now that data has been copied.
        if (Schema::hasColumn('order_shipments', 'attachment_path')) {
            Schema::table('order_shipments', function (Blueprint $table) {
                $table->dropColumn(['attachment_path', 'attachment_name']);
            });
        }

        if (Schema::hasColumn('shipment_tracking', 'attachment_path')) {
            Schema::table('shipment_tracking', function (Blueprint $table) {
                $table->dropColumn(['attachment_path', 'attachment_name']);
            });
        }

        // ── Tracking event description becomes optional ─────────────────────
        // Previously required at the DB level in some seed data; make sure the
        // column itself allows null so the "optional public description" rule
        // can be enforced purely in the request validation layer.
        if (Schema::hasColumn('shipment_tracking', 'description')) {
            Schema::table('shipment_tracking', function (Blueprint $table) {
                $table->text('description')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Re-add the columns so the down migration is reversible.
        Schema::table('order_shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('order_shipments', 'attachment_path')) {
                $table->string('attachment_path')->nullable();
                $table->string('attachment_name')->nullable();
            }
        });

        Schema::table('shipment_tracking', function (Blueprint $table) {
            if (!Schema::hasColumn('shipment_tracking', 'attachment_path')) {
                $table->string('attachment_path')->nullable();
                $table->string('attachment_name')->nullable();
            }
        });

        // Best-effort: copy the first public-or-not attachment of each type
        // back onto the parent row before dropping the table.
        $shipmentAttachments = DB::table('shipment_attachments')
            ->where('attachable_type', 'shipment')
            ->orderBy('id')
            ->get()
            ->groupBy('attachable_id');

        foreach ($shipmentAttachments as $shipmentId => $rows) {
            $first = $rows->first();
            DB::table('order_shipments')->where('id', $shipmentId)->update([
                'attachment_path' => $first->path,
                'attachment_name' => $first->original_name,
            ]);
        }

        $trackingAttachments = DB::table('shipment_attachments')
            ->where('attachable_type', 'tracking')
            ->orderBy('id')
            ->get()
            ->groupBy('attachable_id');

        foreach ($trackingAttachments as $trackingId => $rows) {
            $first = $rows->first();
            DB::table('shipment_tracking')->where('id', $trackingId)->update([
                'attachment_path' => $first->path,
                'attachment_name' => $first->original_name,
            ]);
        }

        Schema::dropIfExists('shipment_attachments');
    }
};