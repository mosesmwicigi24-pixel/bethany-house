<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow ad-hoc (custom / non-catalogue) order lines.
 *
 * order_items.product_id was NOT NULL, so a quotation containing free-text lines
 * — normal for a tailoring business quoting custom work — could not be converted
 * into an invoice. Making it nullable lets those lines become order lines that
 * simply carry no stock item: reservation, commit, and serials all skip lines
 * with no product_id, which is the correct behaviour for a custom item. The
 * foreign key stays (a null FK means "no product").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('order_items', 'product_id')) {
            DB::statement('ALTER TABLE order_items ALTER COLUMN product_id DROP NOT NULL');
        }
    }

    public function down(): void
    {
        // Intentionally left as nullable on rollback — re-imposing NOT NULL would
        // fail if any ad-hoc order lines exist.
    }
};
