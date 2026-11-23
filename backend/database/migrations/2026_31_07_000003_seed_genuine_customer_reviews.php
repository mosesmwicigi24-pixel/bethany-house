<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replace the storefront's testimonials with genuine customer reviews.
 *
 * The three quotes seeded by 2026_26_07_000001_seed_home_shop_blocks ("Rev.
 * Canon Mwangi", "Pastor Achieng O.", "Fr. Kamau") were written for the
 * website, not by customers. Owner, 2026-11-15: "Replace reviews with genuine
 * ones." They are soft-deleted here — recoverable, but no longer published.
 *
 * In their place: thirteen reviews from the shop's Google Business Profile,
 * transcribed 2026-11-16 from the owner's own screenshots of that listing —
 * spelling, punctuation and emoji exactly as each customer posted them, under
 * the customer's public display name.
 *
 * A review is a content block in slot `home_testimonial`:
 *   title    = the reviewer's name, as published
 *   subtitle = the review, word for word
 *   styles   = { rating: 1–5, eyebrow: where it was posted }
 * and is edited like any other block in admin → Home Front Customization →
 * Home Page → Customer Reviews.
 *
 * Idempotent: a reviewer already present in the slot (live or deleted — the
 * owner may have removed one on purpose) is left exactly as found.
 *
 * Written 2026-11-16; the filename prefix is a sort key (see
 * 2026_31_07_000002) so this runs after the seed it corrects.
 */
return new class extends Migration
{
    private const SLOT = 'home_testimonial';
    private const SEED = 'google-2026-11-16';

    /** Sub-texts of the three written-for-the-website quotes. */
    private const INVENTED = [
        'Rev. Canon Mwangi · Cathedral parish, Nakuru',
        'Pastor Achieng O. · Nairobi West',
        'Fr. Kamau · Diocese procurement',
    ];

    public function up(): void
    {
        $now = now();

        DB::table('banners')
            ->where('position', self::SLOT)
            ->whereIn('subtitle', self::INVENTED)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'is_active' => false, 'updated_at' => $now]);

        foreach ($this->reviews() as $i => [$author, $rating, $text]) {
            $exists = DB::table('banners')
                ->where('position', self::SLOT)->where('title', $author)->exists();
            if ($exists) {
                continue;
            }

            DB::table('banners')->insert([
                'placement'  => 'homepage',
                'position'   => self::SLOT,
                'sort_order' => $i + 1,
                'title'      => $author,
                'subtitle'   => $text,
                'styles'     => json_encode(
                    ['rating' => $rating, 'eyebrow' => 'Google review', 'seed' => self::SEED],
                    JSON_UNESCAPED_UNICODE,
                ),
                'is_active'  => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Remove only what this migration added. The written-for-the-website
        // quotes are deliberately NOT restored.
        DB::table('banners')
            ->where('position', self::SLOT)
            ->whereRaw("styles::jsonb ->> 'seed' = ?", [self::SEED])
            ->delete();
    }

    /** @return list<array{0:string,1:int,2:string}> [name, stars, review] in display order */
    private function reviews(): array
    {
        return [
            ['Doreen Dee', 5, 'The best shop for communion elements. Customer satisfaction 👌 is top notch thank you, Bethany, for being a one stop shop. God bless you 🙏'],
            ['Njagi Munene', 5, "Excellent work by Bethany House. The touch of quality and timeliness is just but adorable. I recommend Bethany House to anyone seeking priestly garments and services.\nMay God bless you."],
            ['Njeri Muturi', 4, 'Ordered my home communion pack and im very greatful that delivery was done in quick time and the customer service too was splendid!! The packing of the items is well done. I would not hesitate to recommend Bethany House for your communion needs supply! I will be back!'],
            ['Ruchiaka Joseph Kinyua', 5, 'Very efficient, very good quality service, good customer service and friendliness . I kindly and highly recommend it for you. They will not disappoint you. Always prompt and quick'],
            ['Bengat Naftali', 5, "I've visited Bethany Gift shop countless time and their Church Products are amazing. also their customer service is exceptional.I recommend anyone to them you wont be disappointed. Good Job Bethany Keep up and God bless."],
            ['Susan Njenga', 5, 'Customer service is great! the quality of products is excellent. I recommend Bethany gift shop for all your priestly accessories and more.'],
            ['Grace Kibe', 5, 'Thank you Bethany Gift Shop. Your customer care are excellent and timely delivery. Keep up the good work. I would recommend you over and over again.'],
            ['Eric Mwangi', 5, "Lovely place, incredible professional team, high quality product's"],
            ['Njoroh Bazyon', 5, 'Efficient, welcoming and their work is neat, clean and presentable.'],
            ['Peter muasya', 4, 'Well served by courteous, friendly staff. Keep up'],
            ['gennevieve awino', 5, 'They are very polite and helpful'],
            ['peter mukhwana', 5, "It's just exemplary"],
            ['Judah Thura', 4, 'Good service 💯'],
        ];
    }
};
