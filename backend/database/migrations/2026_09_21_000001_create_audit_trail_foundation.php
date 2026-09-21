<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail becomes a record that cannot be quietly rewritten.
 *
 * Before this, three things could erase the history of what staff did:
 *   - logs:purge-old deleted every activity_log row older than 90 days, weekly,
 *     and the 90 came from a settings row a super admin could lower to 30;
 *   - Database → "clear by date" listed activity_log in its ledger group;
 *   - Database → "full wipe" truncated activity_log with everything else.
 * Those code paths are fixed in PHP in the same change. This migration makes
 * the database itself refuse, so the next code path that tries — or a person
 * with a SQL console — fails loudly instead of succeeding silently:
 *
 *   activity_log, request_logs, audit_seals: UPDATE / DELETE / TRUNCATE raise.
 *   The one exception is the retention prune of request_logs, which must set
 *   `SET LOCAL audit.allow_prune = 'on'` inside its transaction to delete.
 *
 * A determined database superuser can still drop the trigger — nothing inside
 * a database stops its owner. That is what audit_seals is for: a daily SHA-256
 * chain over both tables, mailed off the server (Phase 3), so tampering that
 * the trigger cannot prevent is still detected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_logs', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->string('route', 255)->nullable();
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->uuid('request_id')->nullable();
            // For list endpoints: how many records the response carried — so a
            // bulk read of customers is visible even without a download.
            $table->unsignedInteger('rows_returned')->nullable();
            $table->json('query')->nullable();

            $table->index('occurred_at');
            $table->index(['user_id', 'occurred_at']);
            $table->index('request_id');
        });

        Schema::create('audit_seals', function (Blueprint $table) {
            $table->id();
            $table->string('table_name', 64);
            $table->unsignedBigInteger('first_id');
            $table->unsignedBigInteger('last_id');
            $table->unsignedInteger('row_count');
            // The columns hashed, in order. A later migration that adds a
            // column must not change the hash of rows sealed before it.
            $table->json('columns');
            $table->char('prev_hash', 64);
            $table->char('hash', 64);
            $table->timestampTz('sealed_at')->useCurrent();

            $table->unique(['table_name', 'last_id']);
        });

        Schema::table('activity_log', function (Blueprint $table) {
            if (!Schema::hasColumn('activity_log', 'request_id')) {
                $table->uuid('request_id')->nullable();
                $table->index('request_id');
            }
        });

        // Range queries on the viewer ("this person, this week") and the seal job.
        DB::statement('CREATE INDEX IF NOT EXISTS activity_log_created_at_index ON activity_log (created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS activity_log_causer_created_index ON activity_log (causer_id, created_at)');

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE'
                   AND TG_TABLE_NAME = 'request_logs'
                   AND current_setting('audit.allow_prune', true) = 'on' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'audit trail is append-only: % on % is not allowed', TG_OP, TG_TABLE_NAME
                    USING ERRCODE = 'insufficient_privilege';
            END
            $$ LANGUAGE plpgsql;
        SQL);

        foreach (['activity_log', 'request_logs', 'audit_seals'] as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$t}_append_only ON {$t}");
            DB::unprepared("DROP TRIGGER IF EXISTS {$t}_no_truncate ON {$t}");
            DB::unprepared("CREATE TRIGGER {$t}_append_only BEFORE UPDATE OR DELETE ON {$t}
                            FOR EACH ROW EXECUTE FUNCTION audit_append_only()");
            DB::unprepared("CREATE TRIGGER {$t}_no_truncate BEFORE TRUNCATE ON {$t}
                            FOR EACH STATEMENT EXECUTE FUNCTION audit_append_only()");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['activity_log', 'request_logs', 'audit_seals'] as $t) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$t}_append_only ON {$t}");
                DB::unprepared("DROP TRIGGER IF EXISTS {$t}_no_truncate ON {$t}");
            }
            DB::unprepared('DROP FUNCTION IF EXISTS audit_append_only()');
        }

        DB::statement('DROP INDEX IF EXISTS activity_log_created_at_index');
        DB::statement('DROP INDEX IF EXISTS activity_log_causer_created_index');

        Schema::table('activity_log', function (Blueprint $table) {
            if (Schema::hasColumn('activity_log', 'request_id')) {
                $table->dropIndex(['request_id']);
                $table->dropColumn('request_id');
            }
        });

        Schema::dropIfExists('audit_seals');
        Schema::dropIfExists('request_logs');
    }
};
