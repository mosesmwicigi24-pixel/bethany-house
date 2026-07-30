<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner-directed (2026-07-30): of the two "Chalice Cup Medium" listings —
 * kept apart in the duplicate merge because they are different price tiers —
 * the owner chose to retire the KES 4,000 standard one (7IM6K8KA4-D2C7,
 * slug chalice-cup-medium). The KES 12,000 hand-engraved gold-plated chalice
 * (7IM6K8KA4, slug golden-chalice-cup-medium) remains on sale.
 *
 * Archive-only as always: order history, stock and content stay intact and the
 * record can be resurrected in the admin. Its URL redirects to the survivor.
 */
return new class extends Migration
{
    private const RETIRE_SKU   = '7IM6K8KA4-D2C7';
    private const SURVIVOR_SKU = '7IM6K8KA4';

    public function up(): void
    {
        $retire   = DB::table('products')->where('sku', self::RETIRE_SKU)->whereNull('deleted_at')->first();
        $survivor = DB::table('products')->where('sku', self::SURVIVOR_SKU)->whereNull('deleted_at')->first();
        if (!$retire || $retire->status === 'archived') {
            return;
        }

        DB::table('products')->where('id', $retire->id)
            ->update(['status' => 'archived', 'is_featured' => false, 'updated_at' => now()]);

        if ($survivor) {
            DB::table('product_slug_redirects')->updateOrInsert(
                ['old_slug' => $retire->slug],
                ['product_id' => $survivor->id, 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('products')->where('sku', self::RETIRE_SKU)->where('status', 'archived')
            ->update(['status' => 'active']);
        DB::table('product_slug_redirects')->where('old_slug', 'chalice-cup-medium')->delete();
    }
};
