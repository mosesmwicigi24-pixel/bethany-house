<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One field was answering two questions. order_type said 'online' about both a
 * customer checking out on the website unaided AND a staff member converting a
 * quotation for a bishop — so the Online Orders screen showed self-service
 * orders "Served by Priscilla", and the quoted pipeline had no home at all.
 *
 * Two new columns split the questions apart (order_type itself is untouched —
 * the public storefront lookup returns it, and nothing external moves):
 *
 *   sales_bucket    the staff queue the order belongs to — HOW it becomes money
 *                     till    walk-in, paid at the counter
 *                     web     self-service storefront checkout
 *                     chat    sold in a conversation (WhatsApp/Messenger/IG)
 *                     quoted  quotation → invoice → payable order (sales desk)
 *
 *   source_channel  WHERE the customer came from — walk_in | website |
 *                   whatsapp | messenger | instagram | phone | email.
 *                   Nullable: history whose acquisition we never recorded says
 *                   so with NULL rather than a guess.
 *
 * Backfill is deterministic, measured on production before writing (536 rows):
 *   - 12 orders sit in quotations.converted_order_id            → quoted
 *   - 'online' with a staff creator (all 12 = the same rows)    → quoted
 *   - 'online' with no creator: genuine storefront               → web (13)
 *   - 'whatsapp', or any order in a WhatsApp-channel outlet      → chat (90)
 *   - 'pos' elsewhere                                            → till (421)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'sales_bucket')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('sales_bucket', 10)->nullable()->index();
                $table->string('source_channel', 20)->nullable();
            });
        }

        // Order matters: the quoted rule must win over the online-split rules,
        // so it runs last and overwrites. Each statement is a full-set update
        // filtered on sales_bucket IS NULL (or the quoted override), so a
        // re-run converges.
        DB::statement("UPDATE orders SET sales_bucket = 'till',
                        source_channel = COALESCE(source_channel, 'walk_in')
                       WHERE sales_bucket IS NULL AND order_type = 'pos'");

        DB::statement("UPDATE orders SET sales_bucket = 'chat',
                        source_channel = COALESCE(source_channel, 'whatsapp')
                       WHERE (sales_bucket IS NULL OR sales_bucket = 'till')
                         AND (order_type = 'whatsapp'
                              OR outlet_id IN (SELECT id FROM outlets WHERE sales_channel = 'whatsapp'))");

        DB::statement("UPDATE orders SET sales_bucket = CASE WHEN created_by IS NULL THEN 'web' ELSE 'quoted' END,
                        source_channel = CASE WHEN created_by IS NULL THEN COALESCE(source_channel, 'website') ELSE source_channel END
                       WHERE sales_bucket IS NULL AND order_type = 'online'");

        DB::statement("UPDATE orders SET sales_bucket = 'quoted'
                       WHERE id IN (SELECT converted_order_id FROM quotations WHERE converted_order_id IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['sales_bucket', 'source_channel']);
        });
    }
};
