<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the plain composite index on (context_type, context_id) with a
 * PostgreSQL partial unique index so that only one non-deleted channel can
 * ever exist per context entity, even under concurrent requests.
 *
 * The plain index (channels_context_index) was created by the previous
 * migration (2026_06_15_000001_add_context_to_channels). This migration
 * drops it and replaces it with the unique variant.
 *
 * BEFORE RUNNING:
 *   If duplicate context channels already exist in the DB, clean them up
 *   first — otherwise the CREATE UNIQUE INDEX will fail. Run this SQL to
 *   soft-delete all but the oldest channel per context entity:
 *
 *   UPDATE channels SET deleted_at = NOW()
 *   WHERE id IN (
 *       SELECT id FROM (
 *           SELECT id,
 *                  ROW_NUMBER() OVER (
 *                      PARTITION BY context_type, context_id
 *                      ORDER BY id ASC
 *                  ) AS rn
 *           FROM channels
 *           WHERE context_type IS NOT NULL AND deleted_at IS NULL
 *       ) ranked
 *       WHERE rn > 1
 *   );
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the original plain index added by the previous migration.
        Schema::table('channels', function (Blueprint $table) {
            $table->dropIndex('channels_context_index');
        });

        // PostgreSQL partial unique index: enforces one active channel per
        // context entity while ignoring:
        //   - rows where context is NULL  (DMs and plain spaces)
        //   - soft-deleted rows           (deleted_at IS NOT NULL)
        //
        // This makes Channel::findOrCreateContext race-safe at the DB level.
        // A second concurrent INSERT for the same entity will receive a
        // UniqueConstraintViolation which the model catches and handles by
        // re-fetching the row the first request already committed.
        \Illuminate\Support\Facades\DB::statement(
            'CREATE UNIQUE INDEX channels_context_unique
             ON channels (context_type, context_id)
             WHERE context_type IS NOT NULL
               AND context_id   IS NOT NULL
               AND deleted_at   IS NULL'
        );
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS channels_context_unique');

        // Restore the original plain index so the previous migration's
        // down() can still run cleanly if needed.
        Schema::table('channels', function (Blueprint $table) {
            $table->index(['context_type', 'context_id'], 'channels_context_index');
        });
    }
};