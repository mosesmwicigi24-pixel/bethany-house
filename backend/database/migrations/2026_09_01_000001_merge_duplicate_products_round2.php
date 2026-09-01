<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Merge three more genuine duplicate product pairs (owner-directed, 2026-09-01).
 *
 * Found while investigating why Neema, mid-live-broadcast, answered every
 * question with the price of a children's book: it had quoted product 137,
 * "102 childrens stories" — a row with NO stock, NO images and not one sale in
 * its life, sitting beside the real book (109) that has 49 in stock and sells.
 * A phantom duplicate is exactly what a fuzzy match latches onto.
 *
 * Survivors chosen from live data, not from the names:
 *
 *  1. 109 "102 Favorite Children's Bible stories" absorbs 137 "102 childrens
 *     stories". 109: 49 in stock, 3 images, sold 26 Aug. 137: nothing, ever.
 *     Note: the surviving row prices ZMW 300 where the phantom said 210.
 *
 *  2. 73 "Double sided stole" absorbs 101 "Double Sided Stoles" — the same
 *     product pluralised. 73 holds ALL 98 units of stock and the sales history;
 *     101 has never sold. 101's 3 images are copied across.
 *
 *  3. 11 "Pectoral Cross" absorbs 112 "Pectoral Cross" — identical name and
 *     price, both selling. This is the one real judgement call: 11 has the
 *     longer history (17 order lines vs 11) and the stock (9 vs 1) and a
 *     meaningful slug (pectoral-cross-gold vs the generic "cross"), so it
 *     survives; 112's 4 images come with it.
 *
 * DELIBERATELY NOT MERGED — my matcher flagged them, the evidence says no:
 * Cassock vs Cassock Set, the four collar shirts, NIV Bible vs NIV Application
 * Bible, and every size/colour/material pair (Small vs Large Chalice, Silver vs
 * Gold tray, Single vs Double stole). Left for the owner: "Eliad Anointing Oil"
 * vs "Anointing oil", "Horn" vs "Anointing horn", "White Cassock" vs "White
 * Princes Cassock" — each may be a brand or style distinction only he knows.
 *
 * Archive-based like the 2026-07-30 merge, never deleted: 28 order lines hang
 * off the pectoral-cross pair alone. Order history, stock rows and content stay
 * intact, the admin can resurrect any record, and each loser's slug lands in
 * product_slug_redirects so old links and in-flight carts resolve to the
 * survivor.
 *
 * Each loser's NAME is carried onto the survivor as an alias, so a customer —
 * or Neema — still matching the old wording lands on the real product instead
 * of finding nothing.
 */
return new class extends Migration
{
    /** [survivor_id => loser_id] */
    private const MERGES = [
        109 => 137,   // the children's book
        73  => 101,   // double sided stole
        11  => 112,   // pectoral cross
    ];

    public function up(): void
    {
        foreach (self::MERGES as $survivorId => $loserId) {
            $survivor = $this->liveProduct($survivorId);
            $loser    = $this->liveProduct($loserId);
            if (!$survivor || !$loser) {
                continue;                       // already merged, or gone — re-run safe
            }

            $this->copyProductImages($loser->id, $survivor->id);
            $this->carryNameAsAlias($loser, $survivor);
            $this->archiveInto($loser, $survivor->id);
        }
    }

    public function down(): void
    {
        foreach (self::MERGES as $survivorId => $loserId) {
            DB::table('products')->where('id', $loserId)
                ->update(['status' => 'active', 'updated_at' => now()]);
            $slug = DB::table('products')->where('id', $loserId)->value('slug');
            if ($slug) {
                DB::table('product_slug_redirects')->where('old_slug', $slug)->delete();
            }
        }
    }

    private function liveProduct(int $id): ?object
    {
        $p = DB::table('products')->where('id', $id)->whereNull('deleted_at')->first();

        return ($p && $p->status !== 'archived') ? $p : null;
    }

    private function archiveInto(object $loser, int $survivorId): void
    {
        DB::table('products')->where('id', $loser->id)
            ->update(['status' => 'archived', 'is_featured' => false, 'updated_at' => now()]);
        DB::table('product_slug_redirects')->updateOrInsert(
            ['old_slug' => $loser->slug],
            ['product_id' => $survivorId, 'created_at' => now()],
        );
    }

    /** The loser's images the survivor lacks (matched by url), non-primary, appended. */
    private function copyProductImages(int $loserId, int $survivorId): void
    {
        $have = DB::table('product_images')->where('product_id', $survivorId)
            ->pluck('image_url')->all();
        $maxSort = (int) DB::table('product_images')->where('product_id', $survivorId)->max('sort_order');
        $rows = DB::table('product_images')->where('product_id', $loserId)
            ->whereNull('product_variant_id')->orderBy('sort_order')->get();
        foreach ($rows as $img) {
            if (in_array($img->image_url, $have, true)) {
                continue;
            }
            DB::table('product_images')->insert([
                'product_id'    => $survivorId,
                'image_url'     => $img->image_url,
                'thumbnail_url' => $img->thumbnail_url,
                'alt_text'      => $img->alt_text,
                'is_primary'    => false,
                'sort_order'    => ++$maxSort,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
            $have[] = $img->image_url;
        }
    }

    /**
     * Keep the loser's wording findable. Someone who asks for "102 childrens
     * stories" must still be understood — and now land on the row that has
     * stock, images and a price we stand behind.
     */
    private function carryNameAsAlias(object $loser, object $survivor): void
    {
        $loserName = DB::table('product_translations')->where('product_id', $loser->id)
            ->value('name');
        $aliases = json_decode($survivor->aliases ?? '[]', true) ?: [];
        $aliases = array_merge($aliases, json_decode($loser->aliases ?? '[]', true) ?: []);
        if ($loserName) {
            $aliases[] = $loserName;
        }
        $merged = array_values(array_unique(array_filter($aliases)));
        DB::table('products')->where('id', $survivor->id)
            ->update(['aliases' => json_encode($merged), 'updated_at' => now()]);
    }
};
