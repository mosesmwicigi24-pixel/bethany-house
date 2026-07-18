<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference images on colourway batches.
 *
 * A batch called "Purple Self Print" means one thing to the coordinator who
 * named it and several things to the floor. A photo of the fabric or the
 * finished design removes the guesswork: the first image doubles as the
 * batch's thumbnail, the rest are visual reference for production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_order_batches', function (Blueprint $table) {
            $table->json('images')->nullable()->after('attributes');
        });
    }

    public function down(): void
    {
        Schema::table('production_order_batches', function (Blueprint $table) {
            $table->dropColumn('images');
        });
    }
};
