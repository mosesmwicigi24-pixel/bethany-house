<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the storefront's current hero slides into the CMS (banners, slot
 * home_hero) so the live homepage content is now editable in
 * admin → Home Front Customization → Home Page. Idempotent (updateOrInsert by
 * position + sort_order). Images point at the storefront's hosted files so they
 * load in both the CMS preview and the storefront. `title` uses *word* markers
 * for the gold emphasis; `styles` carries the eyebrow/theme/second CTA/marks/
 * price plate the storefront renders.
 */
return new class extends Migration
{
    public function up(): void
    {
        $img = 'https://bethanyhouse.co.ke/products';
        $now = now();

        $slides = [
            [
                'sort_order' => 1,
                'title'      => 'Everything the *altar* calls for.',
                'subtitle'   => 'Holy Communion elements, tailored clergy apparel and Christian gifts — chosen with reverence, delivered with care.',
                'image_url'  => "{$img}/Chalice_Cup.jpg",
                'link_url'   => '/shop',
                'link_text'  => 'Shop Communion',
                'styles'     => [
                    'eyebrow' => 'Nairobi · Serving churches across East Africa',
                    'theme' => '', 'cta2_text' => 'Explore Clergy Apparel', 'cta2_url' => '/shop',
                    'cta2_class' => 'pill-ghost-dark',
                    'marks' => [
                        ['b' => 'Same-day', 't' => 'Nairobi delivery'],
                        ['b' => 'Made-to-measure', 't' => 'vestments & gowns'],
                        ['b' => 'M-Pesa & Card', 't' => 'secure checkout'],
                    ],
                    'plate_name' => 'Chalice Royale', 'plate_kes' => 18500,
                ],
            ],
            [
                'sort_order' => 2,
                'title'      => "The Lord's Table, *complete*.",
                'subtitle'   => 'Chalice, altar wine and 1,000 hosts — one bundle, one delivery, ready before Holy Week. From KES 21,800.',
                'image_url'  => "{$img}/gold-wares.jpg",
                'link_url'   => '/product/chalice-royale',
                'link_text'  => 'Shop the bundle',
                'styles'     => [
                    'eyebrow' => 'Holy Week Offer', 'theme' => 'light',
                    'cta2_text' => 'See communion sets', 'cta2_url' => '/shop', 'cta2_class' => 'pill-ghost',
                    'marks' => [
                        ['b' => 'Save 15%', 't' => 'vs buying separately'],
                        ['b' => 'Free engraving', 't' => 'on the chalice'],
                        ['b' => 'Guaranteed', 't' => 'pre-Easter delivery'],
                    ],
                    'plate_name' => 'Communion Bundle', 'plate_prefix' => 'from', 'plate_kes' => 21800,
                ],
            ],
            [
                'sort_order' => 3,
                'title'      => 'Tailored for the *pulpit*.',
                'subtitle'   => 'Preaching gowns, cassocks and chasubles — measured in Nairobi, sewn to your order, ready in 5–7 days.',
                'image_url'  => "{$img}/preaching_gown1.jpg",
                'link_url'   => '/shop',
                'link_text'  => 'Book a fitting',
                'styles'     => [
                    'eyebrow' => 'Made to Measure', 'theme' => 'slate',
                    'cta2_text' => 'Browse apparel', 'cta2_url' => '/shop', 'cta2_class' => 'pill-ghost-dark',
                    'marks' => [
                        ['b' => '5–7 days', 't' => 'measure to delivery'],
                        ['b' => 'Every colour', 't' => 'of the liturgical year'],
                        ['b' => 'Repairs', 't' => '& alterations after'],
                    ],
                    'plate_name' => 'Preaching Gown', 'plate_kes' => 13000,
                ],
            ],
        ];

        foreach ($slides as $s) {
            DB::table('banners')->updateOrInsert(
                ['position' => 'home_hero', 'sort_order' => $s['sort_order']],
                [
                    'placement'  => 'homepage',
                    'title'      => $s['title'],
                    'subtitle'   => $s['subtitle'],
                    'image_url'  => $s['image_url'],
                    'link_url'   => $s['link_url'],
                    'link_text'  => $s['link_text'],
                    'styles'     => json_encode($s['styles']),
                    'is_active'  => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('banners')->where('position', 'home_hero')->whereIn('sort_order', [1, 2, 3])->delete();
    }
};
