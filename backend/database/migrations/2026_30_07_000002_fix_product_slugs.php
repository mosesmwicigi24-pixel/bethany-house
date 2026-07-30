<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product slug cleanup — 36 slugs renamed to match what each product actually
 * IS (name-derived), in two tiers applied in order:
 *
 *   Tier 1 — slugs describing a DIFFERENT product (lying URLs): the Mitre lived
 *   at the-carry-along-bible, the Anointing horn at cincture-belt, wafer counts
 *   swapped, etc. Renaming them vacates those slugs.
 *   Tier 2 — junk random-SKU suffixes and over-generic slugs, cleaned to the
 *   name-derived form the moment Tier 1 frees it (white-cassock-fbu30que0 →
 *   white-cassock). 9 of the vacated slugs are immediately re-owned by the
 *   product a shopper always expected there, so those URLs self-heal.
 *
 * The 27 old slugs that die get rows in product_slug_redirects (old_slug →
 * product_id). ProductController::show() and the storefront checkout resolve
 * through that table, so bookmarked links, Google results and in-flight carts
 * keep working. SF-* seeded slugs are untouched (curated overlay + CMS product
 * pages key on them). Renames are ordered — validated collision-free against
 * the live catalog — and each step re-checks occupancy at runtime (a
 * soft-deleted or draft product could hold a target slug), skipping safely
 * rather than failing the deploy.
 */
return new class extends Migration
{
    /** [sku, expected old slug, new slug] — applied strictly in order. */
    private const RENAMES = [
        ['COM-PO-001', '1000-pieces-of-bread', 'communion-wafer-bread-500pcs'], // name says 500PCS, slug said 1000
        ['KU5ZE5ZFY', 'holy-communion-bread-500pcs-ku5ze5zfy', 'holy-communion-bread-1000pcs'], // name says 1000 Pcs, slug said 500 + junk suffix
        ['CWIZIB1ZL', 'communion-bread-250pcs-cwizib1zl', 'communion-wafer-bread-200pcs'], // name says 200PCs, slug said 250 + junk suffix
        ['UE81UOJY5', 'trail-product-ue81uojy5', 'ordination-gown'], // 'trail-product' test junk for the Ordination Gown
        ['RONG11JY7-7176', 'cincture-belt', 'anointing-horn'], // product is the Anointing horn
        ['FBU30QUE0-499E', 'white-cassock', 'eliad-anointing-oil'], // product is Eliad Anointing Oil
        ['98UBCKKHD-F3FF', 'anointing-oil-eliad-olive-oil-750ml', 'round-collar-shirt'], // product is the Round Collar Shirt
        ['GOW-C-001-D71C', 'chasuble-1', 'purple-red-cope'], // product is the Purple Red Cope
        ['ML8FMKZQL-7661', 'custom-designed-dress', 'silver-bread-tray'], // product is the Silver Bread Tray
        ['CLF-001', 'the-365-day-childrens-bible', 'self-print-stoles'], // product is Self Print Stoles
        ['537OU8Q5Z-E4C6', 'cincture-rope', 'apostolic-ring'], // product is the Apostolic Ring
        ['XYULGK29K-5D98', 'silver-communion-cups', 'golden-communion-tray'], // product is the Golden Communion Tray
        ['OCJN841JK-8CD6', 'biblia-yangu-kubwa-niipendayo', 'tallit-prayer-shawl-large'], // product is the Large Prayer Shawl / Tallit
        ['XZIQU3RFM-8032', 'executive-water-bottle', 'nkjv-giant-print'], // product is the NKJV Giant Print Bible
        ['YX9PKFQQ5-FDB7-SHIRT', 'glass-cups', 'straight-collar-shirt'], // product is the Straight Collar Shirt
        ['CVB-0010', 'the-carry-along-bible', 'mitre'], // product is the Mitre
        ['843GLV9RC-D69A', 'preaching-gown-1', 'silver-communion-tray'], // product is the Silver Communion Tray
        ['CAC-001-01', 'the-carry-along-bible-9co393v3r', 'straight-collar'], // product is the Straight Collar
        ['CLE-PCB-001', 'princes-cassock-blue', 'white-princes-cassock'], // name says White, slug said blue
        ['FBU30QUE0', 'white-cassock-fbu30que0', 'white-cassock'], // clean form freed by the oil rename
        ['1HNCF393W', 'the-365-day-childrens-bible-1hncf393w', 'the-365-day-childrens-bible'], // clean form freed by the stoles rename
        ['OCJN841JK', 'biblia-yangu-kubwa-niipendayo-ocjn841jk', 'biblia-yangu-kubwa-niipendayo'], // clean form freed by the tallit rename
        ['XZIQU3RFM', 'executive-water-bottle-xziqu3rfm', 'executive-water-bottle'], // clean form freed by the NKJV rename
        ['YX9PKFQQ5', 'glass-cups-yx9pkfqq5', 'glass-cups'], // clean form freed by the shirt rename
        ['843GLV9RC', 'preaching-gown-843glv9rc', 'preaching-gown'], // junk suffix; bare form free (and matches the storefront's curated pick)
        ['RONG11JY7', 'cincture-belt-rong11jy7', 'cincture-belt'], // clean form freed by the horn rename
        ['537OU8Q5Z', 'cincture-rope-537ou8q5z', 'cincture-rope'], // clean form freed by the ring rename
        ['ML8FMKZQL', 'custom-designed-dress-ml8fmkzql', 'custom-designed-dress'], // clean form freed by the tray rename
        ['COM-SCC-001', 'silver-communion-cups-1', 'silver-communion-cups'], // clean form freed by the tray rename
        ['OUTOIDPWY', 'tallit-prayer-shawl-medium-outoidpwy', 'tallit-prayer-shawl-medium'], // junk suffix; clean form free; matches the S/M/L family
        ['CSMHIJ6R8', 'pectoral-cross-csmhij6r8', 'pectoral-cross-gold'], // junk suffix; own copy says Golden; distinct from SF pectoral-cross
        ['7IM6K8KA4', 'chalice-cup-medium-7im6k8ka4', 'golden-chalice-cup-medium'], // junk suffix; own copy says golden brass; distinct from chalice-cup-medium
        ['CLE-R-001', 'ring-1', 'bishops-ring'], // own copy says Bishopric Rings; distinct from apostolic-ring
        ['VES-SR-001', 'staffcroziersheherd-rod', 'staff-crozier-shepherd-rod'], // mangled + typo 'sheherd'
        ['CLE-S-009', 'stole', 'single-sided-stole'], // name-derived; 'stole' too generic beside 4 other stole products
        ['M3BXRUQJC', 'canon-gown-m3bxruqjc', 'canon-gown-catholic'], // junk suffix; own copy says Catholic Fathers; distinct from canon-gown
    ];

    public function up(): void
    {
        if (!Schema::hasTable('product_slug_redirects')) {
            Schema::create('product_slug_redirects', function ($table) {
                $table->id();
                $table->string('old_slug')->unique();
                $table->unsignedBigInteger('product_id');
                $table->timestamp('created_at')->nullable();
                $table->index('product_id');
            });
        }

        foreach (self::RENAMES as [$sku, $from, $to]) {
            $product = DB::table('products')->where('sku', $sku)->whereNull('deleted_at')->first();
            if (!$product || $product->slug !== $from) {
                continue; // absent in this environment, or already renamed
            }
            // Occupancy check includes soft-deleted rows — the unique index does.
            $occupied = DB::table('products')->where('slug', $to)->where('id', '!=', $product->id)->exists();
            if ($occupied) {
                error_log("slug-cleanup: target '{$to}' occupied — {$sku} left at '{$from}'");
                continue;
            }

            DB::table('products')->where('id', $product->id)->update([
                'slug'       => $to,
                'updated_at' => now(),
            ]);

            DB::table('product_slug_redirects')->updateOrInsert(
                ['old_slug' => $from],
                ['product_id' => $product->id, 'created_at' => now()],
            );
        }

        // An old slug re-owned by another live product must NOT redirect away
        // from it (9 vacated slugs are re-claimed by the right product above).
        $live = DB::table('products')->whereNull('deleted_at')->pluck('slug')->all();
        DB::table('product_slug_redirects')->whereIn('old_slug', $live)->delete();
    }

    public function down(): void
    {
        foreach (array_reverse(self::RENAMES) as [$sku, $from, $to]) {
            $product = DB::table('products')->where('sku', $sku)->whereNull('deleted_at')->first();
            if (!$product || $product->slug !== $to) {
                continue;
            }
            $occupied = DB::table('products')->where('slug', $from)->where('id', '!=', $product->id)->exists();
            if ($occupied) {
                continue;
            }
            DB::table('products')->where('id', $product->id)->update(['slug' => $from]);
            DB::table('product_slug_redirects')->where('old_slug', $from)->delete();
        }
    }
};
