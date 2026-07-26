<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the Home Front CMS blocks that were still empty in admin — Promo Strips,
 * Shop Page Hero, Category Tiles and Testimonials — so they show as editable
 * entries instead of "No entries — the storefront shows its built-in content".
 *
 * The copy, images and links here MIRROR what the live storefront already
 * renders (read from bethanyhouse.co.ke / /shop), so nothing on the site
 * changes visually — the content simply becomes editable in Home Front
 * Customization. Same shape as the hero seed (000004) and product pages (000005):
 * banners keyed by placement + position + sort_order, styles carries
 * eyebrow/theme, image URLs are absolute and verified.
 *
 *   home_promo        placement=homepage   the two mid-page offer bands
 *   shop_hero         placement=shop       the banner atop the /shop page
 *   home_category     placement=homepage   the "Shop by category" cards
 *   home_testimonial  placement=homepage   the "Loved at the altar" quotes
 *
 * Idempotent (updateOrInsert). down() removes exactly these positions.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $hub = 'https://hub.bethanyhouse.co.ke/storage/banners/66f1d0d8-4f34-44d6-9e1a-3b8a4e1de6d5.webp';
        $img = 'https://bethanyhouse.co.ke/products';

        $blocks = [
            // ── Promo Strips (home_promo · homepage) ──────────────────────────
            ['placement' => 'homepage', 'position' => 'home_promo', 'sort_order' => 1,
             'title' => 'The Lord\'s Table, *complete*.',
             'subtitle' => 'Golden Communion Tray — one set, one delivery, ready before Holy Week. From KES 35,000. We deliver across the world.',
             'image_url' => $hub, 'link_text' => 'Shop the set', 'link_url' => '/product/silver-communion-cups',
             'styles' => ['eyebrow' => 'Holy Week Offer', 'theme' => 'light']],
            ['placement' => 'homepage', 'position' => 'home_promo', 'sort_order' => 2,
             'title' => 'Tailored for the *pulpit*.',
             'subtitle' => 'Preaching gowns, cassocks and chasubles, copes, surplices and stoles — measured in Nairobi, sewn to your order, ready in 5–7 days.',
             'image_url' => "{$img}/preaching_gown1.jpg", 'link_text' => 'Made to measure', 'link_url' => '/product/clergy-cassock',
             'styles' => ['eyebrow' => 'Made to Measure', 'theme' => 'slate']],

            // ── Shop Page Hero (shop_hero · shop) ─────────────────────────────
            ['placement' => 'shop', 'position' => 'shop_hero', 'sort_order' => 1,
             'title' => 'Is the sanctuary ready?',
             'subtitle' => 'Communion ware, fresh hosts and seasonal vestments — order early for guaranteed delivery before Holy Week.',
             'image_url' => "{$img}/gold-wares.jpg", 'link_text' => 'Shop the set', 'link_url' => '/product/chalice-royale',
             'styles' => ['eyebrow' => 'Easter is coming.', 'theme' => 'light']],

            // ── Category Tiles (home_category · homepage) ─────────────────────
            ['placement' => 'homepage', 'position' => 'home_category', 'sort_order' => 1,
             'title' => 'Communion', 'subtitle' => 'Chalices · wine · hosts',
             'image_url' => "{$img}/Chalice_Cup.jpg", 'link_text' => 'Shop', 'link_url' => '/shop?category=Communion%20Elements',
             'styles' => []],
            ['placement' => 'homepage', 'position' => 'home_category', 'sort_order' => 2,
             'title' => 'Clergy Apparel', 'subtitle' => 'Cassocks · gowns · new arrivals',
             'image_url' => "{$img}/preaching_gown1.jpg", 'link_text' => 'Shop', 'link_url' => '/shop?category=Clergy%20Apparel',
             'styles' => []],
            ['placement' => 'homepage', 'position' => 'home_category', 'sort_order' => 3,
             'title' => 'Bibles', 'subtitle' => 'Study · children\'s · gift',
             'image_url' => "{$img}/niv_bible.jpg", 'link_text' => 'Shop', 'link_url' => '/shop?category=Bibles%20%26%20Devotionals',
             'styles' => []],
            ['placement' => 'homepage', 'position' => 'home_category', 'sort_order' => 4,
             'title' => 'Gifts', 'subtitle' => 'Crosses · keepsakes',
             'image_url' => "{$img}/cross1.jpg", 'link_text' => 'Shop', 'link_url' => '/shop?category=Gifts%20%26%20Accessories',
             'styles' => []],
            ['placement' => 'homepage', 'position' => 'home_category', 'sort_order' => 5,
             'title' => 'Essentials', 'subtitle' => 'Bells · linens · ware',
             'image_url' => "{$img}/bell.jpg", 'link_text' => 'Shop', 'link_url' => '/shop?category=Church%20Essentials',
             'styles' => []],
            ['placement' => 'homepage', 'position' => 'home_category', 'sort_order' => 6,
             'title' => 'Prayer Wear', 'subtitle' => 'Tallits · shawls',
             'image_url' => "{$img}/tallit.jpg", 'link_text' => 'Shop', 'link_url' => '/shop?category=Clergy%20Apparel',
             'styles' => []],

            // ── Testimonials (home_testimonial · homepage) ────────────────────
            // The parish quotes already published on the live storefront.
            ['placement' => 'homepage', 'position' => 'home_testimonial', 'sort_order' => 1,
             'title' => 'The finish is far richer in person than in the photos, and it was delivered to Nakuru efficiently.',
             'subtitle' => 'Rev. Canon Mwangi · Cathedral parish, Nakuru',
             'image_url' => null, 'link_text' => null, 'link_url' => null,
             'styles' => ['eyebrow' => 'Loved at the altar']],
            ['placement' => 'homepage', 'position' => 'home_testimonial', 'sort_order' => 2,
             'title' => 'Our preaching gowns were measured on Tuesday and worn that Sunday.',
             'subtitle' => 'Pastor Achieng O. · Nairobi West',
             'image_url' => null, 'link_text' => null, 'link_url' => null,
             'styles' => ['eyebrow' => 'Loved at the altar']],
            ['placement' => 'homepage', 'position' => 'home_testimonial', 'sort_order' => 3,
             'title' => 'One supplier for hosts, wine and ware — with a parish account.',
             'subtitle' => 'Fr. Kamau · Diocese procurement',
             'image_url' => null, 'link_text' => null, 'link_url' => null,
             'styles' => ['eyebrow' => 'Loved at the altar']],
        ];

        foreach ($blocks as $b) {
            DB::table('banners')->updateOrInsert(
                ['placement' => $b['placement'], 'position' => $b['position'], 'sort_order' => $b['sort_order']],
                [
                    'title'      => $b['title'],
                    'subtitle'   => $b['subtitle'],
                    'image_url'  => $b['image_url'],
                    'link_text'  => $b['link_text'],
                    'link_url'   => $b['link_url'],
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
        DB::table('banners')
            ->whereIn('position', ['home_promo', 'shop_hero', 'home_category', 'home_testimonial'])
            ->whereIn('placement', ['homepage', 'shop'])
            ->delete();
    }
};
