<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One short, silent product clip per product (absolute URL on the public
 * disk, built the same way product_images.image_url is). The storefront
 * plays it over the still when a card is hovered (or scrolled into view on
 * phones) and leads the product-page gallery with it. Uploaded from the
 * Images tab of the admin product form; converted server-side to a small
 * web-ready MP4 by ProductVideoService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('video_url', 500)->nullable()->after('features');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('video_url');
        });
    }
};
