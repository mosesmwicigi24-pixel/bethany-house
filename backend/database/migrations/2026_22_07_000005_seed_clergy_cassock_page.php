<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A full worked example: seed the Clergy Cassock's product page as CMS blocks
 * (placement=product:clergy-cassock) so the live PDP renders an Apple-style
 * page — poster + highlights + "Take a closer look" features + story chapters —
 * all editable in admin → Home Front Customization → Product Pages. Idempotent
 * (updateOrInsert by placement+position+sort_order). Images are the product's
 * existing hosted photos. Pillars fall back to the built-in four.
 */
return new class extends Migration
{
    public function up(): void
    {
        $img = 'https://bethanyhouse.co.ke/products';
        $placement = 'product:clergy-cassock';
        $now = now();

        $blocks = [
            // ── Poster ────────────────────────────────────────────────────────
            ['position' => 'product_poster', 'sort_order' => 1,
             'title' => 'Daily Reverence, Tailored',
             'subtitle' => 'All liturgical colours  |  Full-length, breathable  |  Ready in 5–7 days',
             'image_url' => "{$img}/cassock212.jpg",
             'styles' => ['eyebrow' => 'Clergy Cassock · Bethany House']],

            // ── Highlights ────────────────────────────────────────────────────
            ['position' => 'product_highlight', 'sort_order' => 1,
             'title' => 'Sewn to your measurements',
             'subtitle' => 'Every cassock is cut to your numbers in our Nairobi workshop — a fit that is yours alone.',
             'image_url' => "{$img}/live-6U3A4750.jpg",
             'styles' => ['eyebrow' => 'Made to measure']],
            ['position' => 'product_highlight', 'sort_order' => 2,
             'title' => 'Every liturgical colour',
             'subtitle' => 'Black, white, purple, red and green — the full church year, with custom piping on request.',
             'image_url' => "{$img}/live-6U3A4787.jpg",
             'styles' => ['eyebrow' => 'Colour']],
            ['position' => 'product_highlight', 'sort_order' => 3,
             'title' => 'Ready in 5–7 days',
             'subtitle' => 'Measured, sewn and delivered within a week, anywhere in Kenya.',
             'image_url' => "{$img}/live-CASSOCK1.png",
             'styles' => ['eyebrow' => 'Fast turnaround']],

            // ── Take a closer look ────────────────────────────────────────────
            ['position' => 'product_feature', 'sort_order' => 1,
             'title' => 'Full-length cut',
             'subtitle' => 'A classic full-length silhouette, tailored to your measurements for daily wear.',
             'image_url' => "{$img}/live-6U3A4750.jpg", 'styles' => []],
            ['position' => 'product_feature', 'sort_order' => 2,
             'title' => 'Every colour',
             'subtitle' => 'Black, white, purple, red and green — the full liturgical year, plus custom piping.',
             'image_url' => "{$img}/live-6U3A4787.jpg", 'styles' => []],
            ['position' => 'product_feature', 'sort_order' => 3,
             'title' => 'Breathable weave',
             'subtitle' => 'Chosen for Nairobi heat: a breathable weave that keeps its drape through the day.',
             'image_url' => "{$img}/live-CASSOCK1.png", 'styles' => []],
            ['position' => 'product_feature', 'sort_order' => 4,
             'title' => 'Made in 5–7 days',
             'subtitle' => 'Measured, sewn and delivered within a week, anywhere in Kenya.',
             'image_url' => "{$img}/cassock212.jpg", 'styles' => []],

            // ── Story chapters ────────────────────────────────────────────────
            ['position' => 'product_chapter', 'sort_order' => 1,
             'title' => 'Made to measure. Sewn in Nairobi.',
             'subtitle' => 'No off-the-rack compromises. We take your measurements and cut each cassock by hand in our Moi Avenue workshop — so it hangs the way vestments should.',
             'image_url' => "{$img}/live-cassock2.jpg",
             'styles' => ['eyebrow' => 'The Craft']],
            ['position' => 'product_chapter', 'sort_order' => 2,
             'title' => 'Dressed for every season of the church.',
             'subtitle' => 'From Lenten purple to Easter white, Pentecost red to Ordinary green — woven in colours faithful to the liturgical year, and finished with the trim your parish prefers.',
             'image_url' => "{$img}/live-6U3A4787.jpg",
             'styles' => ['eyebrow' => 'Liturgical Colour']],
        ];

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
        }
    }

    public function down(): void
    {
        DB::table('banners')->where('placement', 'product:clergy-cassock')->delete();
    }
};
