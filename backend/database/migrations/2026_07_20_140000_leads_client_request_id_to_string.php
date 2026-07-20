<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * leads.client_request_id was created as a strict `uuid` — a non-UUID
 * idempotency key then 500s at the DB instead of being stored/deduped. The
 * orders bridge (which this endpoint mirrors) uses a varchar; match it so the
 * key is resilient to whatever the storefront sends. The unique index is kept
 * through the type change.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: only alter if the table + column exist and are still uuid.
        if (!Schema::hasColumn('leads', 'client_request_id')) {
            return;
        }
        DB::statement('ALTER TABLE leads ALTER COLUMN client_request_id TYPE varchar(100) USING client_request_id::text');
    }

    public function down(): void
    {
        if (!Schema::hasColumn('leads', 'client_request_id')) {
            return;
        }
        DB::statement('ALTER TABLE leads ALTER COLUMN client_request_id TYPE uuid USING client_request_id::uuid');
    }
};
