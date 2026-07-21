<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ActivityLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
                            {--apply       : Write changes. Without this flag the command only previews.}
                            {--fix-names   : Normalise messy names (trim/collapse spaces + known typo fixes).}
                            {--titlecase   : Also Title-Case names that are entirely lower-case (implies --fix-names).}
                            {--fix-slugs   : Apply slug corrections (see class docblock — coordinate with the storefront).}
                            {--archive=    : Comma-separated product IDs to archive (duplicate rows).}';

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

    /** IDs the reconciliation flagged — always shown in the audit. */
    private const FLAGGED = [6, 7, 11, 33, 37, 39, 48, 50, 75, 95];

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
