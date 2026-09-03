<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a product say its clip is still being converted.
 *
 * Conversion used to run inside the upload request, so `video_url` was either
 * absent or final and nothing in between needed saying. Now that ffmpeg runs on
 * the queue, there is a real third state — uploaded, not yet playable — and
 * without somewhere to put it the admin screen cannot tell "no video" from
 * "converting", so the owner re-uploads a clip that was already on its way.
 *
 * NULL means settled: either there is a video_url or there is no video.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('video_status', 20)->nullable()->after('video_url');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('video_status');
        });
    }
};
