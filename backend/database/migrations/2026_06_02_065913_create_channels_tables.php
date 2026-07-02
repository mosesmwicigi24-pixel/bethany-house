<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 - DMs and Spaces messaging.
     *
     * channels: dm | space | announcement
     *   - dm:           exactly 2 members, no name
     *   - space:        named group, open membership, optional description
     *   - announcement: read-only for non-admins (e.g. "Company Updates")
     *
     * channel_members: pivot with last_read_message_id for unread counts.
     *
     * channel_messages: the actual messages.
     *   - reply_to_id: one level of threading within a channel
     *   - edited_at:   present when body was changed
     *   - type:        text | system (system = join/leave events)
     */
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['dm', 'space', 'announcement'])->default('space');
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('slug')->nullable()->unique();
            $table->boolean('is_private')->default(false);
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->foreignId('last_message_id')->nullable(); // updated after each message
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
            $table->index('last_activity_at');
        });

        Schema::create('channel_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['member', 'admin'])->default('member');
            $table->foreignId('last_read_message_id')->nullable(); // for unread badge
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('muted_until')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'user_id']);
            $table->index(['user_id', 'channel_id']);
        });

        Schema::create('channel_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->foreignId('reply_to_id')
                  ->nullable()
                  ->constrained('channel_messages')
                  ->nullOnDelete();
            $table->enum('type', ['text', 'system'])->default('text');
            $table->text('body');
            $table->jsonb('mentions')->default('[]');
            $table->jsonb('attachments')->default('[]');
            $table->jsonb('reactions')->default('{}');  // {"👍": [user_id, ...]}
            $table->timestamp('edited_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['channel_id', 'created_at']);
            $table->index(['channel_id', 'user_id']);
        });

        // Update channels.last_message_id FK now that channel_messages exists
        Schema::table('channels', function (Blueprint $table) {
            $table->foreign('last_message_id')
                  ->references('id')
                  ->on('channel_messages')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropForeign(['last_message_id']);
        });
        Schema::dropIfExists('channel_messages');
        Schema::dropIfExists('channel_members');
        Schema::dropIfExists('channels');
    }
};