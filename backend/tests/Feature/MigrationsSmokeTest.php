<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the full migration set applies cleanly on Postgres (the real engine),
 * which is what stands up the DB-backed test harness.
 *
 * Doubles as the canary for the malformed migration timestamps
 * (2026_15_06_*, 2026_16_06_*) that sort out of order on a fresh install — if
 * that ordering ever becomes load-bearing and breaks, this test fails loudly
 * instead of only surfacing on a brand-new deploy.
 */
class MigrationsSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_tables_exist_after_migration(): void
    {
        $tables = [
            'users', 'orders', 'order_items', 'payments', 'products',
            'inventory_items', 'cash_registers', 'cash_register_transactions',
            'cash_register_eod_reports', 'production_orders', 'purchase_orders',
            'roles', 'permissions',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table '{$table}' to exist after migration.");
        }
    }

    public function test_out_of_order_migration_artifacts_are_present(): void
    {
        // These columns/tables are added by the malformed-timestamp migrations
        // that sort last on a fresh install; assert they still land.
        $this->assertTrue(Schema::hasColumn('cash_registers', 'user_id'));
        $this->assertTrue(Schema::hasColumn('cash_registers', 'cash_difference')); // generated column
        $this->assertTrue(Schema::hasTable('cash_register_eod_reports'));
    }
}
