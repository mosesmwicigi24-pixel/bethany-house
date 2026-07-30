<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Merge the three genuine duplicate product pairs, archiving the extras
 * (owner-directed, 2026-07-30). Survivors were chosen from live data:
 *
 *  1. Communion Wafer Bread 200PCs (CWIZIB1ZL) absorbs "200 pcs of Bread"
 *     (GEN-PO-001): identical product, identical KES 400 price. The survivor
 *     keeps the wafer-family name/slug; the loser's images are copied over.
 *     The survivor's three junk variants (triplicated "250Pcs" rows that
 *     contradict the 200-piece product) are deactivated.
 *  2. Canon Gown (M3BXRUQJC, KES 13,000 + a real colour variant) absorbs the
 *     confused CLE-S-001 record (named Canon Gown but carrying stole copy and
 *     a stole-level KES 2,500 price). The survivor takes the clean canon-gown
 *     slug; nothing else is copied from the confused record.
 *  3. White Princes Cassock (CLE-PCB-001, 31 variants/3 images) absorbs
 *     Princes Cassock - White (CLE-PC-001, 10 variants/2 images): same
 *     product, same price. Pleat-combination variants the survivor lacks are
 *     copied across (new "-M" SKUs; their prices and images follow), then the
 *     loser is archived.
 *
 * Deliberately NOT merged: the two Chalice Cup Mediums — live data shows two
 * real price tiers (KES 12,000 hand-engraved vs KES 4,000 standard, distinct
 * images), not duplicates.
 *
 * Everything is archive-based (status='archived'), never deleted: order
 * history, stock rows and content stay intact and the owner can resurrect any
 * record in the admin. Losers' slugs land in product_slug_redirects so old
 * URLs and in-flight carts resolve to the survivor.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Wafer bread 200s ─────────────────────────────────────────────
        $survivor = $this->findActive('CWIZIB1ZL');
        $loser    = $this->findActive('GEN-PO-001');
        if ($survivor && $loser) {
            $this->copyProductImages($loser->id, $survivor->id);
            $this->mergeAliases($loser, $survivor);
            // Junk variants: "Communion Bread 250Pcs" ×3 on a 200-piece product.
            DB::table('product_variants')
                ->where('product_id', $survivor->id)
                ->where('variant_name', 'ILIKE', '%250%')
                ->update(['is_active' => false, 'updated_at' => now()]);
            $this->archiveInto($loser, $survivor->id);
        }

        // ── 2. Canon Gown ───────────────────────────────────────────────────
        $survivor = $this->findActive('M3BXRUQJC');
        $loser    = $this->findActive('CLE-S-001');
        if ($survivor && $loser) {
            $this->mergeAliases($loser, $survivor);
            $this->archiveInto($loser, $survivor->id);
            // Free the clean slug for the survivor (archived rows still hold
            // the unique index), then claim it.
            if ($loser->slug === 'canon-gown' && $survivor->slug !== 'canon-gown') {
                DB::table('products')->where('id', $loser->id)
                    ->update(['slug' => 'canon-gown-archived', 'updated_at' => now()]);
                DB::table('product_slug_redirects')->updateOrInsert(
                    ['old_slug' => $survivor->slug],
                    ['product_id' => $survivor->id, 'created_at' => now()],
                );
                DB::table('products')->where('id', $survivor->id)
                    ->update(['slug' => 'canon-gown', 'updated_at' => now()]);
            }
        }

        // ── 3. White Princes Cassock ────────────────────────────────────────
        $survivor = $this->findActive('CLE-PCB-001');
        $loser    = $this->findActive('CLE-PC-001');
        if ($survivor && $loser) {
            $this->copyMissingVariants($loser->id, $survivor->id);
            $this->copyProductImages($loser->id, $survivor->id);
            $this->mergeAliases($loser, $survivor);
            $this->archiveInto($loser, $survivor->id);
        }

        // A redirect must never shadow a slug that is live again.
        $live = DB::table('products')->whereNull('deleted_at')
            ->where('status', 'active')->pluck('slug')->all();
        DB::table('product_slug_redirects')->whereIn('old_slug', $live)->delete();
    }

    public function down(): void
    {
        // Best-effort resurrection of the archived records; copied "-M"
        // variants and copied images are left in place (harmless data).
        foreach (['GEN-PO-001', 'CLE-S-001', 'CLE-PC-001'] as $sku) {
            DB::table('products')->where('sku', $sku)->where('status', 'archived')
                ->update(['status' => 'active']);
        }
        $gown = DB::table('products')->where('sku', 'CLE-S-001')->first();
        if ($gown && $gown->slug === 'canon-gown-archived') {
            $survivor = DB::table('products')->where('sku', 'M3BXRUQJC')->first();
            if ($survivor && $survivor->slug === 'canon-gown') {
                DB::table('products')->where('id', $survivor->id)->update(['slug' => 'canon-gown-catholic']);
                DB::table('products')->where('id', $gown->id)->update(['slug' => 'canon-gown']);
            }
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function findActive(string $sku): ?object
    {
        $p = DB::table('products')->where('sku', $sku)->whereNull('deleted_at')->first();
        return ($p && $p->status !== 'archived') ? $p : null; // re-run safe
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

    /** Copy the loser's product-level images the survivor doesn't already have
        (matched by image_url); copies arrive non-primary, after existing. */
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

    /** Copy the loser's active variants the survivor lacks (matched by
        variant_name), with new "-M" SKUs; their prices and images follow. */
    private function copyMissingVariants(int $loserId, int $survivorId): void
    {
        $haveNames = DB::table('product_variants')->where('product_id', $survivorId)
            ->pluck('variant_name')->map(fn ($n) => mb_strtolower(trim((string) $n)))->all();

        $variants = DB::table('product_variants')->where('product_id', $loserId)
            ->where('is_active', true)->get();
        foreach ($variants as $v) {
            $key = mb_strtolower(trim((string) $v->variant_name));
            if ($key === '' || in_array($key, $haveNames, true)) {
                continue;
            }
            $newSku = mb_substr($v->sku, 0, 98) . '-M';
            if (DB::table('product_variants')->where('sku', $newSku)->exists()) {
                continue;
            }
            $newId = DB::table('product_variants')->insertGetId([
                'product_id'   => $survivorId,
                'sku'          => $newSku,
                'variant_name' => $v->variant_name,
                'attributes'   => $v->attributes,
                'weight'       => $v->weight,
                'is_default'   => false,
                'is_active'    => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
            foreach (DB::table('product_prices')->where('product_variant_id', $v->id)->get() as $price) {
                DB::table('product_prices')->insert([
                    'product_id'         => $survivorId,
                    'product_variant_id' => $newId,
                    'currency_code'      => $price->currency_code,
                    'regular_price'      => $price->regular_price,
                    'sale_price'         => $price->sale_price,
                    'cost_price'         => $price->cost_price,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
            }
            foreach (DB::table('product_images')->where('product_variant_id', $v->id)->get() as $img) {
                DB::table('product_images')->insert([
                    'product_id'         => $survivorId,
                    'product_variant_id' => $newId,
                    'image_url'          => $img->image_url,
                    'thumbnail_url'      => $img->thumbnail_url,
                    'alt_text'           => $img->alt_text,
                    'is_primary'         => $img->is_primary,
                    'sort_order'         => $img->sort_order,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
            }
            $haveNames[] = $key;
        }
    }

    /** Union the loser's search aliases into the survivor. */
    private function mergeAliases(object $loser, object $survivor): void
    {
        $a = json_decode($loser->aliases ?? '[]', true) ?: [];
        $b = json_decode($survivor->aliases ?? '[]', true) ?: [];
        $merged = array_values(array_unique(array_merge($b, $a)));
        if ($merged !== $b) {
            DB::table('products')->where('id', $survivor->id)
                ->update(['aliases' => json_encode($merged), 'updated_at' => now()]);
        }
    }
};
