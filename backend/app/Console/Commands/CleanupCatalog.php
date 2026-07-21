<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Services\ActivityLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off, re-runnable catalog hygiene: messy names, slug/name mismatches, and
 * duplicate product rows surfaced during the storefront-overlay reconciliation.
 *
 * PREVIEW BY DEFAULT — nothing is written unless --apply is passed. Start with a
 * plain run to see the audit against real data, then apply the pieces you want:
 *
 *   # 1. See the truth first (safe, read-only):
 *   php artisan catalog:cleanup
 *
 *   # 2. Normalise messy names (trim/collapse spaces + known typo fixes):
 *   php artisan catalog:cleanup --fix-names           # preview
 *   php artisan catalog:cleanup --fix-names --apply    # write
 *
 *   # 3. Archive a duplicate row (non-destructive: status=archived + soft-delete,
 *   #    every reference is preserved — pick the loser from the audit's ref counts):
 *   php artisan catalog:cleanup --archive=48,75 --apply
 *
 *   # 4. Slug corrections — CHANGES THE STOREFRONT OVERLAY MERGE KEY, coordinate first:
 *   php artisan catalog:cleanup --fix-slugs --apply
 *
 * Archiving (not deleting/merging) is the deliberate choice for duplicates: a
 * product is referenced by 16 tables (orders, production, inventory, carts,
 * quotations, POs…). Archiving hides the row from active/storefront lists while
 * leaving all of that history intact and reversible; merging would have to
 * repoint every reference and is destructive.
 */
class CleanupCatalog extends Command
{
    protected $signature = 'catalog:cleanup
                            {--apply          : Write changes. Without this flag the command only previews.}
                            {--fix-names      : Normalise messy names (trim/collapse spaces + known typo fixes).}
                            {--titlecase      : Also Title-Case names that are entirely lower-case (implies --fix-names).}
                            {--fix-slugs      : Apply slug corrections (see class docblock — coordinate with the storefront).}
                            {--fix-mismatches : Rename mis-named rows to their true identity + reassign categories (NAME_OVERRIDES / CATEGORY_ASSIGN).}
                            {--merge=*        : Merge duplicate products. Format keeper:loser (e.g. 6:33). Repeatable.}
                            {--archive=       : Comma-separated product IDs to archive (duplicate rows).}';

    protected $description = 'Audit and clean catalog data: messy names, slug/name mismatches, duplicate rows.';

    /**
     * Exact, case-insensitive name corrections (compared after whitespace is
     * collapsed). Ambiguous names (e.g. "Devai", "Refiller") are deliberately
     * left out — they are reported for a human to decide rather than guessed.
     */
    private const NAME_FIXES = [
        'she[herd rod'               => 'Shepherd Rod',
        'staff/crozier/she[herd rod' => 'Staff / Crozier / Shepherd Rod',
        'staff/crozier/shepherd rod' => 'Staff / Crozier / Shepherd Rod',
        'silver communion cups'      => 'Silver Communion Cups',
        'skull cap'                  => 'Skull Cap',
        'plastic cups'               => 'Plastic Cups',
        'aluminium tray'             => 'Aluminium Tray',
        'alb'                        => 'Alb',
        'bib'                        => 'Bib',
    ];

    /**
     * Slug corrections keyed by product id. Changing a hub slug changes the key
     * the storefront overlay merges on, so this is gated behind --fix-slugs.
     */
    private const SLUG_FIXES = [
        // id 50: slug says silver-communion-cups but the product is "Golden Communion Tray".
        50 => 'golden-communion-tray',
    ];

    /**
     * True-identity renames for rows whose name was overwritten with a
     * different product's name (the slug is the reliable identity). Confirmed
     * by the shop owner. Applied under --fix-mismatches.
     */
    private const NAME_OVERRIDES = [
        48 => 'Chalice Cup Medium',  // slug chalice-cup-medium — mis-named "Pectoral Cross"
        37 => 'Horn',                // slug horn — mis-named "Stole"
        39 => 'Canon Gown',          // slug canon-gown — mis-named "Stole"
    ];

    /** Category reassignments keyed by product id → category name (resolved at runtime). */
    private const CATEGORY_ASSIGN = [
        11 => 'Vestments',           // the genuine Pectoral Cross
    ];

    /** IDs the reconciliation flagged — always shown in the audit. */
    private const FLAGGED = [6, 7, 11, 33, 37, 39, 48, 50, 75, 95];

    /**
     * Tables that record a product's HISTORY/allocations by a plain product_id
     * (no per-product uniqueness) — safe to repoint wholesale during a merge.
     */
    private const MERGE_REPOINT_TABLES = [
        'order_items', 'production_orders', 'quotation_items',
        'purchase_order_items', 'inventory_transfer_items',
    ];

    public function handle(): int
    {
        $apply     = (bool) $this->option('apply');
        $titlecase = (bool) $this->option('titlecase');
        $fixNames  = (bool) $this->option('fix-names') || $titlecase;
        $fixSlugs  = (bool) $this->option('fix-slugs');
        $archiveIds = collect(explode(',', (string) $this->option('archive')))
            ->map(fn ($s) => (int) trim($s))->filter()->unique()->values();

        $this->newLine();
        $this->info('━━ Catalog cleanup ' . ($apply ? '(APPLY — writing changes)' : '(preview only — pass --apply to write)') . ' ━━');

        $this->auditFlagged();
        $this->auditDuplicates();

        $fixNames ? $this->doNameFixes($apply, $titlecase)
                  : $this->comment('Name fixes: skipped — pass --fix-names to preview/apply.');

        $fixSlugs ? $this->doSlugFixes($apply)
                  : $this->comment('Slug fixes: skipped — pass --fix-slugs (note storefront overlay coupling).');

        if ($this->option('fix-mismatches')) {
            $this->doFixMismatches($apply);
        }

        foreach ((array) $this->option('merge') as $pair) {
            $this->doMerge((string) $pair, $apply);
        }

        if ($archiveIds->isNotEmpty()) {
            $this->doArchive($archiveIds, $apply);
        }

        if (!$apply) {
            $this->newLine();
            $this->comment('Preview only — nothing written. Re-run with --apply to commit.');
        }

        return self::SUCCESS;
    }

    // ── Audit ─────────────────────────────────────────────────────────────────

    private function auditFlagged(): void
    {
        $products = Product::withTrashed()
            ->with(['translations' => fn ($q) => $q->where('language_code', 'en')])
            ->whereIn('id', self::FLAGGED)
            ->orderBy('id')
            ->get();

        $rows = $products->map(function (Product $p) {
            [$orders, $prod, $stock] = $this->refCounts($p->id);
            return [
                $p->id,
                $p->trashed() ? 'deleted' : $p->status,
                $p->slug,
                $this->nameOf($p) ?: '—',
                $p->published_at ? 'yes' : 'no',
                $orders, $prod, $stock,
            ];
        })->all();

        $this->newLine();
        $this->line('<comment>Flagged rows</comment> (orders / prod = referencing rows, stock = on-hand):');
        $this->table(['ID', 'Status', 'Slug', 'Name (en)', 'Pub', 'Orders', 'Prod', 'Stock'], $rows);
    }

    private function auditDuplicates(): void
    {
        $rows = DB::table('products')
            ->join('product_translations', function ($j) {
                $j->on('products.id', '=', 'product_translations.product_id')
                  ->where('product_translations.language_code', '=', 'en');
            })
            ->whereNull('products.deleted_at')
            ->where('products.status', '!=', Product::STATUS_ARCHIVED)
            ->select('products.id', 'products.slug', 'products.status', 'product_translations.name')
            ->get();

        $groups = $rows->groupBy(fn ($r) => strtolower($this->normalizeWs($r->name)))
            ->filter(fn ($g) => $g->count() > 1);

        if ($groups->isEmpty()) {
            $this->newLine();
            $this->line('<info>No active duplicate name groups detected.</info>');
            return;
        }

        $this->newLine();
        $this->line('<comment>Active duplicate name groups</comment> (archive the loser — the row with fewer orders/stock):');
        foreach ($groups as $name => $g) {
            $this->line("  • <options=bold>{$g->first()->name}</>");
            $out = $g->map(function ($r) {
                [$orders, $prod, $stock] = $this->refCounts((int) $r->id);
                return [$r->id, $r->status, $r->slug, $orders, $prod, $stock];
            })->all();
            $this->table(['ID', 'Status', 'Slug', 'Orders', 'Prod', 'Stock'], $out);
        }
    }

    // ── Name fixes ─────────────────────────────────────────────────────────────

    private function doNameFixes(bool $apply, bool $titlecase): void
    {
        $translations = DB::table('product_translations')
            ->where('language_code', 'en')->get(['id', 'product_id', 'name']);

        $changes = [];
        $review  = [];

        foreach ($translations as $t) {
            $current = (string) $t->name;
            $ws      = $this->normalizeWs($current);
            $key     = strtolower($ws);

            if (array_key_exists($key, self::NAME_FIXES)) {
                $target = self::NAME_FIXES[$key];
            } elseif ($titlecase && $ws !== '' && $ws === strtolower($ws)) {
                $target = $this->titleCase($ws);           // all-lowercase → Title Case
            } else {
                $target = $ws;                              // whitespace-only normalisation
            }

            if ($target === $current) {
                // Flag names that still look messy but we won't touch automatically.
                if (preg_match('/[\[\]]|\s{2,}/', $current) || $current !== trim($current)) {
                    $review[] = [$t->product_id, $current];
                }
                continue;
            }

            $changes[] = [$t->product_id, $current, $target, $t->id];
        }

        if ($changes) {
            $this->newLine();
            $this->line('<comment>Name fixes</comment>' . ($apply ? ' — applying:' : ' — would apply:'));
            $this->table(['Product', 'Old name', 'New name'], array_map(fn ($c) => [$c[0], $c[1], $c[2]], $changes));

            if ($apply) {
                foreach ($changes as [$productId, $old, $new, $tid]) {
                    DB::table('product_translations')->where('id', $tid)->update(['name' => $new]);
                    $this->logChange('product_name_cleaned', $productId, $old, $new);
                }
                $this->info('Applied ' . count($changes) . ' name fix(es).');
            }
        } else {
            $this->line('<info>No name fixes needed.</info>');
        }

        if ($review) {
            $this->newLine();
            $this->line('<comment>Names needing a human decision</comment> (not changed):');
            $this->table(['Product', 'Name'], $review);
        }
    }

    // ── Slug fixes ─────────────────────────────────────────────────────────────

    private function doSlugFixes(bool $apply): void
    {
        $this->newLine();
        $this->line('<comment>Slug fixes</comment>' . ($apply ? ' — applying:' : ' — would apply:'));

        foreach (self::SLUG_FIXES as $id => $slug) {
            $p = Product::withTrashed()->find($id);
            if (!$p) { $this->warn("  id {$id}: not found — skipped."); continue; }
            if ($p->slug === $slug) { $this->line("  id {$id}: already '{$slug}'."); continue; }

            $clash = Product::withTrashed()->where('slug', $slug)->where('id', '!=', $id)->exists();
            if ($clash) { $this->warn("  id {$id}: target slug '{$slug}' already used — skipped (resolve by hand)."); continue; }

            $this->line("  id {$id}: '{$p->slug}' → '{$slug}'  (storefront overlay merges on this key)");
            if ($apply) {
                $old = $p->slug;
                $p->slug = $slug;
                $p->save();
                $this->logChange('product_slug_corrected', $id, $old, $slug);
            }
        }
    }

    // ── Archive duplicates ──────────────────────────────────────────────────────

    private function doArchive($ids, bool $apply): void
    {
        $this->newLine();
        $this->line('<comment>Archive duplicates</comment>' . ($apply ? ' — applying:' : ' — would apply:'));

        foreach ($ids as $id) {
            $p = Product::withTrashed()->find($id);
            if (!$p) { $this->warn("  id {$id}: not found — skipped."); continue; }
            if ($p->trashed()) { $this->line("  id {$id}: already deleted — skipped."); continue; }

            [$orders, $prod, $stock] = $this->refCounts($id);
            $this->line("  id {$id}: '{$this->nameOf($p)}' — orders={$orders}, prod={$prod}, stock={$stock} → archived + soft-deleted (reversible)");

            if ($apply) {
                if ($p->status !== Product::STATUS_ARCHIVED) {
                    $p->status = Product::STATUS_ARCHIVED;
                    $p->save();
                }
                $p->delete(); // soft delete — references preserved, restorable via restore()
                $this->logChange('product_archived_duplicate', $id, $this->nameOf($p), 'archived');
            }
        }
    }

    // ── Fix mismatches: rename to true identity + reassign category ─────────────

    private function doFixMismatches(bool $apply): void
    {
        $this->newLine();
        $this->line('<comment>Rename mis-named rows to their true identity</comment>' . ($apply ? ' — applying:' : ' — would apply:'));
        foreach (self::NAME_OVERRIDES as $id => $name) {
            $t = DB::table('product_translations')->where('product_id', $id)->where('language_code', 'en')->first();
            if (!$t) { $this->warn("  id {$id}: no en translation — skipped."); continue; }
            if ($t->name === $name) { $this->line("  id {$id}: already '{$name}'."); continue; }
            $this->line("  id {$id}: '{$t->name}' → '{$name}'");
            if ($apply) {
                DB::table('product_translations')->where('id', $t->id)->update(['name' => $name]);
                $this->logChange('product_name_corrected', $id, (string) $t->name, $name);
            }
        }

        $this->newLine();
        $this->line('<comment>Reassign categories</comment>' . ($apply ? ' — applying:' : ' — would apply:'));
        foreach (self::CATEGORY_ASSIGN as $id => $categoryName) {
            $p = Product::withTrashed()->find($id);
            if (!$p) { $this->warn("  id {$id}: not found — skipped."); continue; }
            $cat = Category::where('name_en', 'ILIKE', $categoryName)
                ->orWhere('slug', 'ILIKE', str_replace(' ', '-', strtolower($categoryName)))
                ->first();
            if (!$cat) { $this->warn("  id {$id}: category '{$categoryName}' not found — skipped."); continue; }
            if ((int) $p->category_id === (int) $cat->id) { $this->line("  id {$id}: already in '{$categoryName}'."); continue; }
            $this->line("  id {$id} ('{$this->nameOf($p)}') → category '{$cat->name_en}' (#{$cat->id})");
            if ($apply) {
                $from = (string) $p->category_id;
                $p->category_id = $cat->id;
                $p->save();
                $this->logChange('product_recategorised', $id, "category {$from}", "category {$cat->id} ({$categoryName})");
            }
        }
    }

    // ── Merge a duplicate into a keeper ─────────────────────────────────────────
    // Repoints history/allocations (orders, production, quotations, POs, transfers)
    // and product-level stock onto the keeper, then archives the loser. The loser's
    // OWN attribute rows (translations, prices, images, variants…) stay with the
    // archived row — the keeper has its own. Variant-level stock is left in place
    // and reported, since the loser's variants don't exist on the keeper.

    private function doMerge(string $pair, bool $apply): void
    {
        [$keeper, $loser] = array_pad(array_map('intval', explode(':', $pair)), 2, 0);
        $this->newLine();
        $this->line("<comment>Merge {$loser} → {$keeper}</comment>" . ($apply ? ' — applying:' : ' — would apply:'));

        if (!$keeper || !$loser || $keeper === $loser) { $this->warn("  invalid pair '{$pair}' — expected keeper:loser."); return; }
        $k = Product::withTrashed()->find($keeper);
        $l = Product::withTrashed()->find($loser);
        if (!$k || !$l) { $this->warn('  keeper or loser not found — skipped.'); return; }
        if ($l->trashed()) { $this->line("  loser {$loser} already archived — skipped."); return; }

        // Repoint plain product_id history/allocation tables.
        foreach (self::MERGE_REPOINT_TABLES as $tbl) {
            if (!Schema::hasTable($tbl) || !Schema::hasColumn($tbl, 'product_id')) continue;
            $n = DB::table($tbl)->where('product_id', $loser)->count();
            if ($n === 0) continue;
            $this->line("  {$tbl}: repoint {$n} row(s)");
            if ($apply) DB::table($tbl)->where('product_id', $loser)->update(['product_id' => $keeper]);
        }

        // Product-level stock (variant_id NULL): move to keeper, summing on the
        // (product, outlet) unique key. Variant-level stock stays on the loser.
        $prodLevel = DB::table('inventory_items')->where('product_id', $loser)->whereNull('product_variant_id')->get();
        $variantLevel = DB::table('inventory_items')->where('product_id', $loser)->whereNotNull('product_variant_id')->count();
        $this->line("  inventory_items: move " . count($prodLevel) . " product-level row(s)" .
            ($variantLevel ? ", LEAVE {$variantLevel} variant-level row(s) on the archived loser" : ''));
        if ($apply) {
            foreach ($prodLevel as $inv) {
                $existing = DB::table('inventory_items')->where('product_id', $keeper)
                    ->whereNull('product_variant_id')->where('outlet_id', $inv->outlet_id)->first();
                if ($existing) {
                    DB::table('inventory_items')->where('id', $existing->id)->update([
                        'quantity_on_hand'  => $existing->quantity_on_hand + $inv->quantity_on_hand,
                        'quantity_reserved' => $existing->quantity_reserved + $inv->quantity_reserved,
                    ]);
                    DB::table('inventory_items')->where('id', $inv->id)->delete();
                } else {
                    DB::table('inventory_items')->where('id', $inv->id)->update(['product_id' => $keeper]);
                }
            }
        }

        // Archive the loser (soft delete — reversible).
        $this->line("  archive loser {$loser} ('{$this->nameOf($l)}')");
        if ($apply) {
            if ($l->status !== Product::STATUS_ARCHIVED) { $l->status = Product::STATUS_ARCHIVED; $l->save(); }
            $l->delete();
            $this->logChange('product_merged', $loser, "merged into {$keeper}", 'archived');
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    private function refCounts(int $id): array
    {
        return [
            (int) DB::table('order_items')->where('product_id', $id)->count(),
            (int) DB::table('production_orders')->where('product_id', $id)->count(),
            (int) DB::table('inventory_items')->where('product_id', $id)->sum('quantity_on_hand'),
        ];
    }

    private function nameOf(Product $p): string
    {
        return (string) ($p->translations->firstWhere('language_code', 'en')?->name
            ?? DB::table('product_translations')->where('product_id', $p->id)->where('language_code', 'en')->value('name')
            ?? '');
    }

    private function normalizeWs(string $s): string
    {
        return preg_replace('/\s+/', ' ', trim($s)) ?? trim($s);
    }

    private function titleCase(string $s): string
    {
        return preg_replace_callback('/\b\p{L}[\p{L}\']*/u', fn ($m) => ucfirst(strtolower($m[0])), $s) ?? $s;
    }

    private function logChange(string $event, int $productId, string $from, string $to): void
    {
        try {
            $product = Product::withTrashed()->find($productId);
            if ($product) {
                ActivityLogService::log($event, $product, ['from' => $from, 'to' => $to]);
            }
        } catch (\Throwable) {
            // Auditing is best-effort — never let it block the cleanup.
        }
    }
}
