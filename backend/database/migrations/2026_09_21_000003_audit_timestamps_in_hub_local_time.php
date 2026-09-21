<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * request_logs.occurred_at and audit_seals.sealed_at were created as
 * timestamptz. Every other timestamp in this database (222 columns) is
 * `timestamp without time zone` holding hub-local time (config app.timezone,
 * Africa/Nairobi), because that is what Laravel writes: a local wall-clock
 * string with no offset. Postgres read those local strings as UTC, so the
 * Staff-activity view showed every call three hours late.
 *
 * Convert both to the database's convention. `AT TIME ZONE 'UTC'` recovers
 * exactly the wall-clock value Laravel wrote. The defaults become hub-local
 * too, so a row inserted without an explicit time agrees with the rest.
 * (ALTER ... TYPE rewrites the table; row triggers such as the append-only
 * guard do not fire on a rewrite.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $tz = str_replace("'", '', (string) config('app.timezone', 'Africa/Nairobi'));

        DB::statement("ALTER TABLE request_logs ALTER COLUMN occurred_at DROP DEFAULT");
        DB::statement("ALTER TABLE request_logs ALTER COLUMN occurred_at TYPE timestamp(0) without time zone USING (occurred_at AT TIME ZONE 'UTC')");
        DB::statement("ALTER TABLE request_logs ALTER COLUMN occurred_at SET DEFAULT (now() AT TIME ZONE '{$tz}')");

        DB::statement("ALTER TABLE audit_seals ALTER COLUMN sealed_at DROP DEFAULT");
        DB::statement("ALTER TABLE audit_seals ALTER COLUMN sealed_at TYPE timestamp(0) without time zone USING (sealed_at AT TIME ZONE 'UTC')");
        DB::statement("ALTER TABLE audit_seals ALTER COLUMN sealed_at SET DEFAULT (now() AT TIME ZONE '{$tz}')");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ([['request_logs', 'occurred_at'], ['audit_seals', 'sealed_at']] as [$t, $c]) {
            DB::statement("ALTER TABLE {$t} ALTER COLUMN {$c} DROP DEFAULT");
            DB::statement("ALTER TABLE {$t} ALTER COLUMN {$c} TYPE timestamptz USING ({$c} AT TIME ZONE 'UTC')");
            DB::statement("ALTER TABLE {$t} ALTER COLUMN {$c} SET DEFAULT CURRENT_TIMESTAMP");
        }
    }
};
