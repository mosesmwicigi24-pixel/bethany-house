<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A content block's sub-text was capped at 255 characters — enough for a
 * slide's strapline, not for a customer's review quoted word for word (the
 * longest genuine review on file is 268). Reviews live in the same table
 * (slot `home_testimonial`), so the column becomes `text`; the admin form's
 * own limit keeps entries reasonable. (Postgres — matches prod + the CI test
 * service. varchar → text is a metadata-only change: no table rewrite.)
 *
 * Written 2026-11-16. The filename prefix is a SORT KEY, not a date: this
 * must run after 2026_22_07_000003 (nullable title/image) and
 * 2026_26_07_000001 (which seeds the testimonials the next migration
 * replaces), and filenames in that family sort after every YYYY_MM_DD one —
 * the same reason 2026_31_07_000001 is named as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE banners ALTER COLUMN subtitle TYPE text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE banners ALTER COLUMN subtitle TYPE varchar(255) USING left(subtitle, 255)');
    }
};
