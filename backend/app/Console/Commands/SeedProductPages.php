<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seed Apple-style Product Page CMS blocks (the banners table, placement
 * product:<slug>) for every product, so the storefront PDP renders a poster +
 * highlights + "Take a closer look" + story chapters instead of falling back to
 * built-in copy. Blocks are surfaced/edited in admin → Home Front → Product Pages.
 *
 * GROUNDED, NOT FABRICATED. Every block is built from the product's OWN data —
 * its name, category, short/long description, price, features, measurements and
 * its real hosted images. No invented specs, no borrowed photos. A product with
 * thin data gets fewer (but still true) blocks rather than made-up claims.
 *
 * PREVIEW BY DEFAULT — nothing is written unless --apply is passed.
 *
 *   php artisan products:seed-pages --dump      # emit raw product data as JSON (inspect the catalog)
 *   php artisan products:seed-pages             # preview the blocks that WOULD be written
 *   php artisan products:seed-pages --apply     # write the blocks
 *   php artisan products:seed-pages --slug=alb  # limit to one product (preview or apply)
 *   php artisan products:seed-pages --force     # also (re)seed products that already have curated blocks
 *
 * Idempotent: updateOrInsert keyed by placement+position+sort_order. By default
 * a product that ALREADY has curated blocks (e.g. the hand-written Clergy
 * Cassock) is skipped so this never clobbers bespoke work; --force overrides.
 */
class SeedProductPages extends Command
{
    protected $signature = 'products:seed-pages
                            {--apply       : Write the blocks. Without this flag the command only previews.}
                            {--dump        : Emit each product\'s raw source data as JSON and exit (no writes).}
                            {--slug=       : Limit to a single product slug.}
                            {--force       : Re-seed products that already have curated blocks (default: skip them).}';

    protected $description = 'Seed grounded, per-product Apple-style page blocks (poster, highlights, features, chapters) into the banners CMS.';

    /** Products the storefront/migrations already curate by hand — never touch. */
    private const HAND_CURATED = ['clergy-cassock'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');
        $onlySlug = $this->option('slug');

        $products = Product::query()
            ->with([
                'translations' => fn ($q) => $q->where('language_code', 'en'),
                'category',
                'images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('sort_order'),
                'prices' => fn ($q) => $q->whereNull('product_variant_id')->where('currency_code', 'KES'),
            ])
            ->when($onlySlug, fn ($q) => $q->where('slug', $onlySlug))
            ->whereIn('status', [Product::STATUS_ACTIVE, Product::STATUS_DRAFT])
            ->orderBy('id')
            ->get();

        if ($products->isEmpty()) {
            $this->warn('No products matched.');
            return self::SUCCESS;
        }

        // ── Dump mode: emit real data so a human can review before generating. ──
        if ($this->option('dump')) {
            $out = $products->map(fn ($p) => $this->source($p))->values();
            $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $existing = DB::table('banners')
            ->where('placement', 'like', 'product:%')
            ->select('placement')
            ->distinct()
            ->pluck('placement')
            ->map(fn ($p) => Str::after($p, 'product:'))
            ->flip();

        $now = now();
        $seeded = 0;
        $skipped = 0;
        $blocksWritten = 0;

        foreach ($products as $product) {
            $slug = $product->slug;
            if (in_array($slug, self::HAND_CURATED, true)) {
                $skipped++;
                continue;
            }
            if (! $force && $existing->has($slug)) {
                $skipped++;
                continue;
            }

            $src = $this->source($product);
            $blocks = $this->buildBlocks($src);

            if (empty($blocks)) {
                $this->line("  <fg=yellow>skip</> {$slug} — too little data to ground a page");
                $skipped++;
                continue;
            }

            $this->line('');
            $this->line("<fg=cyan>■</> <options=bold>{$src['name']}</> <fg=gray>· {$src['sku']} · product:{$slug}</>");
            foreach ($blocks as $b) {
                $eyebrow = $b['styles']['eyebrow'] ?? $b['styles']['icon'] ?? '';
                $tag = str_pad($this->slotLabel($b['position']), 18);
                $this->line("   <fg=gray>{$tag}</> " . ($eyebrow ? "<fg=magenta>[{$eyebrow}]</> " : '') . $b['title']);
                if (! empty($b['subtitle'])) {
                    $this->line("   <fg=gray>" . str_repeat(' ', 18) . "</> <fg=gray>{$b['subtitle']}</>");
                }
            }

            if ($apply) {
                $placement = "product:{$slug}";
                foreach ($blocks as $b) {
                    DB::table('banners')->updateOrInsert(
                        ['placement' => $placement, 'position' => $b['position'], 'sort_order' => $b['sort_order']],
                        [
                            'title'      => $b['title'],
                            'subtitle'   => $b['subtitle'],
                            'image_url'  => $b['image_url'],
                            'styles'     => json_encode($b['styles']),
                            'is_active'  => true,
                            'updated_at' => $now,
                            'created_at' => $now,
                        ],
                    );
                    $blocksWritten++;
                }
            } else {
                $blocksWritten += count($blocks);
            }
            $seeded++;
        }

        $this->line('');
        $mode = $apply ? '<fg=green>APPLIED</>' : '<fg=yellow>PREVIEW</> (re-run with --apply to write)';
        $this->line("{$mode} — {$seeded} product page(s), {$blocksWritten} block(s); {$skipped} skipped.");

        return self::SUCCESS;
    }

    /**
     * Collapse a product into the plain, real-data shape the generator reads.
     * This is the single source of truth — the --dump output and buildBlocks()
     * both consume it, so what you review is exactly what gets generated.
     */
    private function source(Product $p): array
    {
        $tr = $p->translations->first();
        $price = $p->prices->first();

        $images = $p->images
            ->pluck('image_url')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'id'           => $p->id,
            'slug'         => $p->slug,
            'sku'          => $p->sku,
            'name'         => $tr?->name ?: Str::headline($p->slug),
            'category'     => $p->category?->name_en ?: null,
            'short'        => $this->clean($tr?->short_description),
            'description'  => $this->clean($tr?->description),
            'price'        => $price ? (float) ($price->sale_price ?? $price->regular_price) : null,
            'is_producible'=> (bool) $p->is_producible,
            'features'     => collect($p->features ?? [])
                ->map(fn ($f) => is_array($f) ? trim((string) ($f['text'] ?? '')) : trim((string) $f))
                ->filter()
                ->values()
                ->all(),
            'measurements' => collect($p->measurements ?? [])
                ->map(fn ($m) => is_array($m) ? trim((string) ($m['name'] ?? '')) : trim((string) $m))
                ->filter()
                ->values()
                ->all(),
            'images'       => $images,
        ];
    }

    /** Strip HTML/entities/whitespace from a CMS text field. */
    private function clean(?string $s): string
    {
        if (! $s) {
            return '';
        }
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** Split prose into sentences (for chapters/highlights) — naive but robust. */
    private function sentences(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z“"\'])/u', $text) ?: [];
        return collect($parts)
            ->map(fn ($s) => trim($s))
            ->filter(fn ($s) => mb_strlen($s) >= 12)
            ->values()
            ->all();
    }

    private function img(array $images, int $i): ?string
    {
        if (empty($images)) {
            return null;
        }
        return $images[$i % count($images)];
    }

    private function slotLabel(string $pos): string
    {
        return [
            'product_poster'    => 'Poster',
            'product_highlight' => 'Highlight',
            'product_feature'   => 'Closer look',
            'product_chapter'   => 'Chapter',
            'product_pillar'    => 'Pillar',
        ][$pos] ?? $pos;
    }

    /**
     * The generator. Every string here is composed from real product fields; the
     * only fixed phrasing is Bethany House's own service promises (Nairobi
     * workshop, delivery), which are true for the whole catalog.
     */
    private function buildBlocks(array $s): array
    {
        $name = $s['name'];
        $cat = $s['category'];
        $short = $s['short'];
        $desc = $s['description'];
        $imgs = $s['images'];
        $feats = $s['features'];
        $producible = $s['is_producible'];

        // Need at least a primary image to render an editorial page honestly.
        if (empty($imgs)) {
            return [];
        }

        $descSentences = $this->sentences($desc);
        // Pool of true, product-specific claim lines, richest first.
        $claims = array_values(array_unique(array_filter(array_merge(
            $feats,
            $short !== '' && $short !== $desc ? [$short] : [],
            $descSentences,
        ))));

        $blocks = [];

        // ── Poster ────────────────────────────────────────────────────────────
        $specStrip = $this->specStrip($s);
        $blocks[] = [
            'position' => 'product_poster', 'sort_order' => 1,
            'title' => $this->posterHeadline($s),
            'subtitle' => $specStrip,
            'image_url' => $this->img($imgs, 0),
            'styles' => ['eyebrow' => "{$name} · Bethany House"],
        ];

        // ── Highlights (up to 3 skimmable claim cards) ─────────────────────────
        $order = 1;
        foreach (array_slice($claims, 0, 3) as $i => $claim) {
            $blocks[] = [
                'position' => 'product_highlight', 'sort_order' => $order,
                'title' => $this->headlineFrom($claim),
                'subtitle' => $this->expand($claim),
                'image_url' => $this->img($imgs, $i + 1),
                'styles' => ['eyebrow' => $this->eyebrowFor($claim, $producible, $order)],
            ];
            $order++;
        }

        // ── Take a closer look (up to 4 feature cards) ─────────────────────────
        $order = 1;
        foreach (array_slice($claims, 0, 4) as $i => $claim) {
            $blocks[] = [
                'position' => 'product_feature', 'sort_order' => $order,
                'title' => $this->headlineFrom($claim),
                'subtitle' => $this->expand($claim),
                'image_url' => $this->img($imgs, $i),
                'styles' => [],
            ];
            $order++;
        }

        // ── Story chapters (1–2 cinematic sections) ────────────────────────────
        $chapters = $this->chapters($s, $descSentences);
        $order = 1;
        foreach ($chapters as $ch) {
            $blocks[] = [
                'position' => 'product_chapter', 'sort_order' => $order,
                'title' => $ch['title'],
                'subtitle' => $ch['copy'],
                'image_url' => $this->img($imgs, $order),
                'styles' => ['eyebrow' => $ch['eyebrow']],
            ];
            $order++;
        }

        return $blocks;
    }

    /** A poster headline that reads as marketing, not a data dump. */
    private function posterHeadline(array $s): string
    {
        $name = $s['name'];
        if ($s['is_producible']) {
            return "{$name}, Made to Your Measure";
        }
        if ($s['short'] !== '' && mb_strlen($s['short']) <= 60) {
            return Str::ucfirst($s['short']);
        }
        return "The {$name}, Done Properly";
    }

    /** Spec strip: real features/category/price joined with the ` | ` convention. */
    private function specStrip(array $s): string
    {
        $bits = [];
        foreach (array_slice($s['features'], 0, 3) as $f) {
            $bits[] = $this->headlineFrom($f);
        }
        if (count($bits) < 2 && $s['category']) {
            $bits[] = $s['category'];
        }
        if (count($bits) < 3) {
            $bits[] = $s['is_producible'] ? 'Made in Nairobi' : 'In stock, ships nationwide';
        }
        if ($s['price']) {
            $bits[] = 'KES ' . number_format($s['price']);
        }
        return implode('  |  ', array_slice(array_values(array_unique($bits)), 0, 4));
    }

    /** Trim a claim into a tight card headline (a few words, no trailing stop). */
    private function headlineFrom(string $claim): string
    {
        $claim = rtrim(trim($claim), '.');
        $words = preg_split('/\s+/u', $claim);
        if (count($words) > 7) {
            $claim = implode(' ', array_slice($words, 0, 7));
        }
        return Str::ucfirst($claim);
    }

    /** Expand a claim into a supporting line; if it is already a full sentence, keep it. */
    private function expand(string $claim): string
    {
        $claim = trim($claim);
        if (mb_strlen($claim) >= 40 && preg_match('/[.!?]$/u', $claim)) {
            return Str::ucfirst($claim);
        }
        $claim = rtrim($claim, '.');
        return Str::ucfirst($claim) . '.';
    }

    private function eyebrowFor(string $claim, bool $producible, int $order): string
    {
        $c = mb_strtolower($claim);
        return match (true) {
            str_contains($c, 'gold') || str_contains($c, 'silver') || str_contains($c, 'brass') || str_contains($c, 'plated') => 'Finish',
            str_contains($c, 'engrav') || str_contains($c, 'custom') || str_contains($c, 'personal') => 'Personalisation',
            str_contains($c, 'measure') || str_contains($c, 'fit') || str_contains($c, 'tailor') => 'Made to measure',
            str_contains($c, 'colour') || str_contains($c, 'color') => 'Colour',
            str_contains($c, 'day') || str_contains($c, 'week') || str_contains($c, 'fast') || str_contains($c, 'ready') => 'Turnaround',
            str_contains($c, 'hand') || str_contains($c, 'craft') => 'Craft',
            default => $producible ? 'Made to order' : ['Quality', 'Detail', 'Built to last'][$order % 3],
        };
    }

    /**
     * 1–2 story chapters. Prefer real description prose split into halves; fall
     * back to a craft/quality chapter grounded in category + service promise.
     */
    private function chapters(array $s, array $sentences): array
    {
        $name = $s['name'];
        $cat = $s['category'];
        $out = [];

        if (count($sentences) >= 2) {
            $mid = (int) ceil(count($sentences) / 2);
            $first = implode(' ', array_slice($sentences, 0, $mid));
            $second = implode(' ', array_slice($sentences, $mid));
            $out[] = [
                'eyebrow' => $s['is_producible'] ? 'The Craft' : 'The Detail',
                'title' => $this->chapterTitle($sentences[0], $name),
                'copy' => $first,
            ];
            if (mb_strlen($second) >= 24) {
                $out[] = [
                    'eyebrow' => $cat ?: 'Why Bethany House',
                    'title' => $this->chapterTitle($sentences[$mid] ?? $second, $name),
                    'copy' => $second,
                ];
            }
            return $out;
        }

        // Thin prose: one grounded chapter from what we do know.
        $lead = $s['short'] !== '' ? $s['short'] : ($sentences[0] ?? '');
        if ($s['is_producible']) {
            $copy = trim(($lead ? $this->expand($lead) . ' ' : '')
                . "Cut and finished by hand in our Nairobi workshop, made to your measurements and delivered anywhere in Kenya.");
            $out[] = ['eyebrow' => 'Made in Nairobi', 'title' => 'Made to measure, by hand.', 'copy' => $copy];
        } else {
            $copy = trim(($lead ? $this->expand($lead) . ' ' : '')
                . "Sourced and finished to Bethany House standards" . ($cat ? " for {$cat}" : '') . ", ready to ship nationwide.");
            $out[] = ['eyebrow' => $cat ?: 'Bethany House', 'title' => "The {$name}, done properly.", 'copy' => $copy];
        }
        return $out;
    }

    private function chapterTitle(string $sentence, string $name): string
    {
        $t = rtrim(trim($sentence), '.');
        $words = preg_split('/\s+/u', $t);
        if (count($words) > 9) {
            $t = implode(' ', array_slice($words, 0, 9));
        }
        return Str::ucfirst($t) . '.';
    }
}
