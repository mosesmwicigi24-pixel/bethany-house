<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seed Apple-style Product Page CMS blocks (the banners table, placement
 * product:<slug>) for every product, so the storefront PDP renders a poster +
 * highlights + "Take a closer look" + story chapters + value pillars instead of
 * falling back to built-in copy. Blocks are surfaced/edited in admin → Home
 * Front → Product Pages.
 *
 * GROUNDED, NOT FABRICATED. Every block is composed from what is TRUE about the
 * product: its real name, category, price, producible flag and its own hosted
 * images — plus, when the product's own description is rich enough, its real
 * prose. Where a product's description is thin or unreliable (many are just
 * "Golden" / "stole" / "Bishops"), the copy falls back to category-level truths
 * about that kind of church good (vestments are tailored to liturgical colour,
 * communion ware serves the Lord's table, etc.) rather than inventing specifics.
 * No borrowed photos, no made-up measurements.
 *
 * PREVIEW BY DEFAULT — nothing is written unless --apply is passed.
 *
 *   php artisan products:seed-pages --dump      # emit each product's raw source data as JSON
 *   php artisan products:seed-pages             # preview the blocks that WOULD be written
 *   php artisan products:seed-pages --apply     # write the blocks
 *   php artisan products:seed-pages --slug=alb  # limit to one product
 *   php artisan products:seed-pages --force     # re-seed products that already have curated blocks
 *
 * Idempotent: updateOrInsert keyed by placement+position+sort_order. A product
 * that already has curated blocks (e.g. the hand-written Clergy Cassock) is
 * skipped by default so bespoke work is never clobbered; --force overrides.
 */
class SeedProductPages extends Command
{
    protected $signature = 'products:seed-pages
                            {--apply       : Write the blocks. Without this flag the command only previews.}
                            {--dump        : Emit each product\'s raw source data as JSON and exit (no writes).}
                            {--slug=       : Limit to a single product slug.}
                            {--force       : Re-seed products that already have curated blocks (default: skip them).}';

    protected $description = 'Seed grounded, per-product Apple-style page blocks (poster, highlights, features, chapters, pillars) into the banners CMS.';

    /** Products the storefront/migrations already curate by hand — never touch. */
    private const HAND_CURATED = ['clergy-cassock'];

    /**
     * A description is only mined for prose when it is genuinely a written
     * blurb, not a one-word label. Below this it is ignored and the product
     * falls back to category truths (avoids "Horn" → headline "stole").
     */
    private const RICH_MIN_CHARS = 60;
    private const RICH_MIN_WORDS = 12;

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
                $this->line("  <fg=yellow>skip</> {$slug} — no image to ground a page");
                $skipped++;
                continue;
            }

            $this->line('');
            $this->line("<fg=cyan>■</> <options=bold>{$src['name']}</> <fg=gray>· {$src['sku']} · {$src['category']} · product:{$slug}</>");
            foreach ($blocks as $b) {
                $eyebrow = $b['styles']['eyebrow'] ?? $b['styles']['icon'] ?? '';
                $tag = str_pad($this->slotLabel($b['position']), 12);
                $this->line("   <fg=gray>{$tag}</> " . ($eyebrow ? "<fg=magenta>[{$eyebrow}]</> " : '') . $b['title']);
                if (! empty($b['subtitle'])) {
                    $this->line("   <fg=gray>" . str_repeat(' ', 12) . "</> <fg=gray>{$b['subtitle']}</>");
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
     * Single source of truth: --dump output and buildBlocks() both consume it.
     */
    private function source(Product $p): array
    {
        $tr = $p->translations->first();
        $price = $p->prices->first();

        $images = $p->images->pluck('image_url')->filter()->unique()->values()->all();

        return [
            'id'            => $p->id,
            'slug'          => $p->slug,
            'sku'           => $p->sku,
            'name'          => $tr?->name ?: Str::headline($p->slug),
            'category'      => $p->category?->name_en ?: null,
            'short'         => $this->normalize($tr?->short_description),
            'description'   => $this->normalize($tr?->description),
            'price'         => $price ? (float) ($price->sale_price ?? $price->regular_price) : null,
            'is_producible' => (bool) $p->is_producible,
            'features'      => collect($p->features ?? [])
                ->map(fn ($f) => is_array($f) ? trim((string) ($f['text'] ?? '')) : trim((string) $f))
                ->filter()->values()->all(),
            'measurements'  => collect($p->measurements ?? [])
                ->map(fn ($m) => is_array($m) ? trim((string) ($m['name'] ?? '')) : trim((string) $m))
                ->filter()->values()->all(),
            'images'        => $images,
        ];
    }

    /**
     * Clean AND repair a CMS text field. The catalog's descriptions are pasted
     * from source sites and routinely lose the spaces after punctuation
     * ("embellishment.The Priest") and join list items in camelCase
     * ("serversLectorsDeacons"). Strip markup, then restore those breaks so the
     * prose can be split into real sentences.
     */
    private function normalize(?string $s): string
    {
        if (! $s) {
            return '';
        }
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s);
        // Space after . , : ; ! ? when glued to a following letter (not a digit,
        // so "1.5" and "1,000" survive).
        $s = preg_replace('/([.,:;!?])(?=\p{L})/u', '$1 ', $s);
        // ALLCAPS label glued to a following Title word ("FEATURESUses" → "FEATURES Uses").
        $s = preg_replace('/(\p{Lu}{2,})(\p{Lu}\p{Ll})/u', '$1 $2', $s);
        // camelCase / list-join boundary: a lowercase letter glued to an
        // uppercase one becomes a comma-separated list ("servers, Lectors").
        $s = preg_replace('/(\p{Ll})(\p{Lu})/u', '$1, $2', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** Split prose into clean sentences. */
    private function sentences(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];
        return collect($parts)
            ->map(fn ($x) => trim($x))
            ->filter(fn ($x) => mb_strlen($x) >= 14 && str_word_count($x) >= 3)
            ->values()
            ->all();
    }

    private function isRich(string $desc): bool
    {
        return mb_strlen($desc) >= self::RICH_MIN_CHARS
            && str_word_count($desc) >= self::RICH_MIN_WORDS;
    }

    private function img(array $images, int $i): ?string
    {
        return empty($images) ? null : $images[$i % count($images)];
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

    // ── The generator ──────────────────────────────────────────────────────────

    private function buildBlocks(array $s): array
    {
        $imgs = $s['images'];
        if (empty($imgs)) {
            return []; // no honest way to render an editorial page without a photo
        }

        $name = $s['name'];
        $profile = $this->profileFor($s['category'], $s['is_producible']);
        $rich = $this->isRich($s['description']);
        $sentences = $rich ? $this->sentences($s['description']) : [];

        // Claim pool for the skimmable cards. Prefer the product's own prose when
        // it is rich; otherwise use the category's true talking points.
        $claims = $rich && count($sentences) >= 2
            ? array_map(fn ($x) => ['title' => $this->headline($x), 'text' => $this->expand($x), 'eyebrow' => $this->eyebrowFor($x, $s['is_producible'])], $sentences)
            : $this->profileCards($profile, $s);

        $blocks = [];

        // ── Poster ─────────────────────────────────────────────────────────────
        $blocks[] = [
            'position' => 'product_poster', 'sort_order' => 1,
            'title'    => $this->posterHeadline($s, $profile),
            'subtitle' => $this->specStrip($s, $profile),
            'image_url'=> $this->img($imgs, 0),
            'styles'   => ['eyebrow' => "{$name} · Bethany House"],
        ];

        // ── Highlights (skimmable strip) ────────────────────────────────────────
        foreach (array_slice($claims, 0, 3) as $i => $c) {
            $blocks[] = [
                'position' => 'product_highlight', 'sort_order' => $i + 1,
                'title' => $c['title'], 'subtitle' => $c['text'],
                'image_url' => $this->img($imgs, $i + 1),
                'styles' => ['eyebrow' => $c['eyebrow']],
            ];
        }

        // ── Take a closer look (feature cards) ──────────────────────────────────
        foreach (array_slice($claims, 0, 4) as $i => $c) {
            $blocks[] = [
                'position' => 'product_feature', 'sort_order' => $i + 1,
                'title' => $c['title'], 'subtitle' => $c['text'],
                'image_url' => $this->img($imgs, $i),
                'styles' => [],
            ];
        }

        // ── Story chapters ──────────────────────────────────────────────────────
        foreach ($this->chapters($s, $profile, $sentences) as $i => $ch) {
            $blocks[] = [
                'position' => 'product_chapter', 'sort_order' => $i + 1,
                'title' => $ch['title'], 'subtitle' => $ch['copy'],
                'image_url' => $this->img($imgs, $i + 1),
                'styles' => ['eyebrow' => $ch['eyebrow']],
            ];
        }

        // ── Best place to buy (value pillars) ───────────────────────────────────
        foreach ($this->pillars($s) as $i => $pl) {
            $blocks[] = [
                'position' => 'product_pillar', 'sort_order' => $i + 1,
                'title' => $pl['title'], 'subtitle' => $pl['text'],
                'image_url' => '', // pillars are icon + text, no photo
                'styles' => ['icon' => $pl['icon']],
            ];
        }

        // Keep every block inside the banners column limits (title 150,
        // subtitle 255) — real descriptions run long, so clip on a word boundary.
        return array_map(function ($b) {
            $b['title'] = $this->clip($b['title'], 150);
            $b['subtitle'] = $this->clip($b['subtitle'], 255);
            return $b;
        }, $blocks);
    }

    /** Trim to a column limit at a word boundary, adding an ellipsis if cut. */
    private function clip(?string $s, int $max): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = trim($s);
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        $cut = mb_substr($s, 0, $max - 1);
        $sp = mb_strrpos($cut, ' ');
        if ($sp !== false && $sp > $max * 0.6) {
            $cut = mb_substr($cut, 0, $sp);
        }
        return rtrim($cut, " ,.;:—-") . '…';
    }

    // ── Copy helpers ────────────────────────────────────────────────────────────

    private function posterHeadline(array $s, array $p): string
    {
        $name = $s['name'];
        if ($s['is_producible'] && $p['tailorable']) {
            return "{$name}, Made to Your Measure";
        }
        return str_replace('{name}', $name, $p['poster']);
    }

    private function specStrip(array $s, array $p): string
    {
        $bits = [];
        foreach (array_slice($s['features'], 0, 2) as $f) {
            $bits[] = $this->headline($f);
        }
        if ($s['category']) {
            $bits[] = $s['category'];
        }
        $bits[] = $s['is_producible']
            ? ($p['tailorable'] ? 'Made to order in Nairobi' : 'Made in Kenya')
            : 'In stock · ships nationwide';
        if ($s['price']) {
            $bits[] = 'KES ' . number_format($s['price']);
        }
        return implode('  |  ', array_slice(array_values(array_unique($bits)), 0, 4));
    }

    private function headline(string $claim): string
    {
        $claim = rtrim(trim($claim), '.');
        $words = preg_split('/\s+/u', $claim);
        if (count($words) > 7) {
            $claim = implode(' ', array_slice($words, 0, 7));
        }
        return Str::ucfirst($claim);
    }

    private function expand(string $claim): string
    {
        $claim = trim($claim);
        if (mb_strlen($claim) >= 40 && preg_match('/[.!?]$/u', $claim)) {
            return Str::ucfirst($claim);
        }
        return Str::ucfirst(rtrim($claim, '.')) . '.';
    }

    private function eyebrowFor(string $claim, bool $producible): string
    {
        $c = mb_strtolower($claim);
        return match (true) {
            str_contains($c, 'gold') || str_contains($c, 'silver') || str_contains($c, 'brass') || str_contains($c, 'plated') => 'Finish',
            str_contains($c, 'engrav') || str_contains($c, 'custom') || str_contains($c, 'personal') => 'Personalisation',
            str_contains($c, 'measure') || str_contains($c, 'fit') || str_contains($c, 'tailor') => 'Made to measure',
            str_contains($c, 'colour') || str_contains($c, 'color') || str_contains($c, 'liturg') => 'Liturgical colour',
            str_contains($c, 'hand') || str_contains($c, 'craft') || str_contains($c, 'artisan') => 'Craft',
            str_contains($c, 'day') || str_contains($c, 'week') || str_contains($c, 'fast') || str_contains($c, 'ready') || str_contains($c, 'deliver') => 'Turnaround',
            str_contains($c, 'wear') || str_contains($c, 'worn') || str_contains($c, 'worship') || str_contains($c, 'prayer') => 'In worship',
            default => $producible ? 'Made to order' : 'The detail',
        };
    }

    /** 1–2 chapters. Real prose when rich; category truth when thin. */
    private function chapters(array $s, array $p, array $sentences): array
    {
        $name = $s['name'];
        if (count($sentences) >= 3) {
            $mid = (int) ceil(count($sentences) / 2);
            $out = [[
                'eyebrow' => $s['is_producible'] ? 'The Craft' : 'The Detail',
                'title'   => $this->chapterTitle($sentences[0]),
                'copy'    => implode(' ', array_slice($sentences, 0, $mid)),
            ]];
            $second = implode(' ', array_slice($sentences, $mid));
            if (mb_strlen($second) >= 30) {
                $out[] = [
                    'eyebrow' => $s['category'] ?: 'Bethany House',
                    'title'   => $this->chapterTitle($sentences[$mid] ?? $second),
                    'copy'    => $second,
                ];
            }
            return $out;
        }

        // Thin data → one grounded, category-true chapter (+ the house promise).
        $lead = str_replace('{name}', $name, $p['chapter']['copy']);
        return [[
            'eyebrow' => $p['chapter']['eyebrow'],
            'title'   => str_replace('{name}', $name, $p['chapter']['title']),
            'copy'    => $s['is_producible'] && $p['tailorable']
                ? $lead . ' Cut and finished by hand in our Nairobi workshop, made to your measurements and delivered anywhere in Kenya.'
                : $lead . ' Sourced and checked to Bethany House standards, ready to ship anywhere in Kenya.',
        ]];
    }

    private function chapterTitle(string $sentence): string
    {
        $t = rtrim(trim($sentence), '.');
        $words = preg_split('/\s+/u', $t);
        if (count($words) > 9) {
            $t = implode(' ', array_slice($words, 0, 9));
        }
        return Str::ucfirst($t) . '.';
    }

    /** The four "why buy from Bethany House" cards — true for the whole catalog. */
    private function pillars(array $s): array
    {
        $made = $s['is_producible']
            ? ['icon' => '✂️', 'title' => 'Crafted to order', 'text' => 'Tailored and finished by hand in our Nairobi workshop.']
            : ['icon' => '✅', 'title' => 'Ready to ship', 'text' => 'In stock and dispatched quickly from Nairobi.'];
        return [
            ['icon' => '⛪', 'title' => 'Made for the Church', 'text' => 'Vestments, communion ware and church supplies from one trusted house.'],
            $made,
            ['icon' => '🚚', 'title' => 'Delivered nationwide', 'text' => 'Fast, tracked delivery anywhere in Kenya.'],
            ['icon' => '💬', 'title' => 'Talk to a person', 'text' => 'Message us on WhatsApp for sizing, colours and bulk orders.'],
        ];
    }

    // ── Category profiles: real, true talking points per kind of church good ─────

    private function profileCards(array $p, array $s): array
    {
        return array_map(function ($c) use ($s) {
            return [
                'title'   => str_replace('{name}', $s['name'], $c[1]),
                'text'    => str_replace('{name}', $s['name'], $c[2]),
                'eyebrow' => $c[0],
            ];
        }, $p['cards']);
    }

    /**
     * Keyed by category keyword. `tailorable` marks categories we would tailor
     * to measure (vestments/gowns). `cards` are true, category-level highlights;
     * `poster`/`chapter` are the thin-data fallbacks. {name} is substituted.
     *
     * The "vestments" bucket holds both tailored fabric (cassocks, gowns,
     * chasubles) and non-fabric regalia (crosses, mitres, cinctures, collars).
     * Only genuinely made-to-order items ($producible) get "made to measure"
     * copy; everything else gets regalia language that is true for metalwork
     * and ready-made pieces alike.
     */
    private function profileFor(?string $category, bool $producible = false): array
    {
        $c = mb_strtolower((string) $category);

        $vestments = $producible ? [
            'tailorable' => true,
            'poster'     => '{name}, Vested for Worship',
            'cards'      => [
                ['Made to measure', 'Cut to your measurements', 'Tailored to your own measurements in our Nairobi workshop for a fit that is yours alone.'],
                ['Liturgical colour', 'Every liturgical colour', 'Available across the colours of the church year — black, white, purple, red and green — with the trim your parish prefers.'],
                ['Craft', 'Finished by hand', 'Sewn and finished by hand from quality, breathable fabric chosen to keep its drape through a long service.'],
            ],
            'chapter'    => ['eyebrow' => 'The Craft', 'title' => 'The {name}, made in Nairobi.', 'copy' => 'A {name} for the altar, made to serve through every season of the church.'],
        ] : [
            'tailorable' => false,
            'poster'     => 'The {name}, for the Altar',
            'cards'      => [
                ['For the altar', 'Made for worship', 'A {name} made for the altar and the vesting of clergy and worship leaders.'],
                ['Craft', 'Finished with care', 'Chosen and finished to Bethany House standards to look right in the sanctuary and last through regular use.'],
                ['Set apart', 'Part of the vestments', 'The piece that completes the vestment and honours the liturgical life of the church.'],
            ],
            'chapter'    => ['eyebrow' => 'For the Altar', 'title' => 'The {name}.', 'copy' => 'A {name} made for worship and the vesting of clergy.'],
        ];
        $communion = [
            'tailorable' => false,
            'poster'     => '{name} for the Lord\'s Table',
            'cards'      => [
                ['For the Table', 'Made for communion', 'Designed for the Lord\'s table — dignified, practical and easy to serve from during the communion service.'],
                ['Finish', 'A reverent finish', 'A clean, reverent finish that looks right in the sanctuary and stands up to regular use.'],
                ['Care', 'Easy to keep', 'Straightforward to clean and store between services, so it is always ready for the next.'],
            ],
            'chapter'    => ['eyebrow' => 'The Lord\'s Table', 'title' => 'Set for communion.', 'copy' => 'A {name} chosen to serve the Lord\'s table with reverence and care.'],
        ];
        $bibles = [
            'tailorable' => false,
            'poster'     => '{name}',
            'cards'      => [
                ['Readable', 'Clear, readable print', 'Laid out for easy reading in the pew, at the desk or on the go.'],
                ['Everyday', 'For every day', 'A Bible to carry into worship, study and quiet time alike.'],
                ['A gift', 'Ready to gift', 'A meaningful gift for baptisms, confirmations and dedications.'],
            ],
            'chapter'    => ['eyebrow' => 'The Word', 'title' => '{name}.', 'copy' => 'A {name} to read, carry and treasure.'],
        ];
        $anointing = [
            'tailorable' => false,
            'poster'     => '{name}, Set Apart',
            'cards'      => [
                ['For prayer', 'For prayer and anointing', 'Set apart for prayer, anointing and worship in the life of the church.'],
                ['Quality', 'Chosen with care', 'Selected to Bethany House standards for use in ministry.'],
            ],
            'chapter'    => ['eyebrow' => 'Set Apart', 'title' => 'For prayer and anointing.', 'copy' => 'A {name} set apart for the ministry of prayer and anointing.'],
        ];
        $accessories = [
            'tailorable' => false,
            'poster'     => 'The {name}, Done Properly',
            'cards'      => [
                ['The detail', 'The finishing detail', 'The small piece that completes the vestment and gets the details right.'],
                ['Quality', 'Made to last', 'Chosen to hold up to regular wear through the church year.'],
            ],
            'chapter'    => ['eyebrow' => 'The Detail', 'title' => 'The {name}.', 'copy' => 'A {name} to finish the look properly.'],
        ];
        $default = [
            'tailorable' => false,
            'poster'     => 'The {name}, Done Properly',
            'cards'      => [
                ['Quality', 'Chosen with care', 'Selected and checked to Bethany House standards.'],
                ['For the Church', 'For your ministry', 'Part of a full range of church supplies from one trusted house.'],
            ],
            'chapter'    => ['eyebrow' => 'Bethany House', 'title' => 'The {name}.', 'copy' => 'A {name} chosen with care for your ministry.'],
        ];

        return match (true) {
            str_contains($c, 'vestment') || str_contains($c, 'gown') || str_contains($c, 'clergy vest') => $vestments,
            str_contains($c, 'communion') || str_contains($c, 'chalice') || str_contains($c, 'tray') || str_contains($c, 'wine') => $communion,
            str_contains($c, 'bible') => $bibles,
            str_contains($c, 'anoint') => $anointing,
            str_contains($c, 'accessor') => $accessories,
            default => $default,
        };
    }
}
