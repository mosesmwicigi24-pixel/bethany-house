<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1 - Centralised comment threads.
     *
     * A single polymorphic `comments` table stores threaded conversations
     * attached to any model: Order, ProductionOrder, PurchaseOrder, etc.
     *
     * Design decisions:
     *   - commentable_type / commentable_id  → polymorphic pivot
     *   - parent_id                          → top-level threading (one level deep for now)
     *   - mentions (jsonb)                   → array of user IDs parsed from @mentions
     *   - metadata (jsonb)                   → extensible: attachments, reactions, etc.
     *   - is_internal                        → hides from customer-facing views
     *   - type: comment | note | system      → system rows are auto-generated (status changes, etc.)
     *
     * The existing `production_order_messages` table stays intact for Phase 2
     * migration - the CommentController handles both models transparently.
     */
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->morphs('commentable');               // commentable_type + commentable_id
            $table->foreignId('user_id')
                  ->nullable()                           // nullable for system-generated rows
                  ->constrained('users')
                  ->nullOnDelete();
            $table->foreignId('parent_id')
                  ->nullable()
                  ->constrained('comments')
                  ->nullOnDelete();
            $table->enum('type', ['comment', 'note', 'system'])->default('comment');
            $table->text('body');
            $table->boolean('is_internal')->default(true);
            $table->jsonb('mentions')->default('[]');    // [user_id, ...]
            $table->jsonb('metadata')->default('{}');    // future: attachments, reactions
            $table->timestamp('edited_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Fast lookups per entity
            $table->index(['commentable_type', 'commentable_id', 'created_at']);
            // Fast subscription queries (who has commented on a thread)
            $table->index(['commentable_type', 'commentable_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};