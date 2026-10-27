<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bethany People C3 (owner, 27 Oct 2026) — the Updates feed.
 *
 * posts: one row per item on the feed. kind = update (a notice, with an
 * optional photo and must_read acknowledgement), recognition (a shout-out
 * to recipient_id, with optional points), celebration (a birthday or work
 * anniversary the hub posts itself from the employee file; celebration_key
 * keeps it to one a year) or event (a training, a stock take: event_at,
 * place, RSVP). outlet_id null = everyone; set = that outlet's people (and
 * anyone with outlets.all_access). publish_at in the future = scheduled;
 * published_at is stamped when it goes out (updates:publish).
 *
 * post_reads: who opened it (read_at) and, for a must-read, who pressed
 * "I have read this" (acknowledged_at). post_comments and post_rsvps are
 * what they say. Nothing here is money except points, which payroll turns
 * into a taxable earning only when a point value is set (settings
 * people.recognition_point_kes; 0 = points are just points).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 16);                       // update | recognition | celebration | event
            $t->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete(); // recognition / celebration subject
            $t->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $t->string('title', 140)->nullable();
            $t->text('body')->nullable();
            $t->string('photo_path', 255)->nullable();
            $t->boolean('must_read')->default(false);
            $t->boolean('comments_enabled')->default(true);
            $t->unsignedSmallInteger('points')->nullable();
            $t->timestamp('event_at')->nullable();
            $t->string('event_place', 120)->nullable();
            $t->string('celebration_key', 60)->nullable()->unique(); // birthday:{user}:{year} | anniversary:{user}:{year}
            $t->timestamp('publish_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('notified_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['published_at', 'kind']);
            $t->index('recipient_id');
        });
        Schema::create('post_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamp('read_at');
            $t->timestamp('acknowledged_at')->nullable();
            $t->unique(['post_id', 'user_id']);
        });
        Schema::create('post_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->text('body');
            $t->timestamps();
            $t->index('post_id');
        });
        Schema::create('post_rsvps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('answer', 8); // yes | no | maybe
            $t->timestamps();
            $t->unique(['post_id', 'user_id']);
        });
        // Celebrations are an automation the owner can switch off (Setup › Automations).
        if (!DB::table('automations')->where('key', 'celebrations')->exists()) {
            DB::table('automations')->insert(['key' => 'celebrations', 'enabled' => true, 'threshold' => 0, 'recipients' => 'managers', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('automations')->where('key', 'celebrations')->delete();
        Schema::dropIfExists('post_rsvps');
        Schema::dropIfExists('post_comments');
        Schema::dropIfExists('post_reads');
        Schema::dropIfExists('posts');
    }
};
