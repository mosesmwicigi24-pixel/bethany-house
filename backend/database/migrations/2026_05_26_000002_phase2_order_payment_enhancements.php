<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 - Order & Payment Enhancements
 *
 * Adds the following to support:
 *   - Currency enforcement (KES for Kenya, USD for international)
 *   - Manual shipping-fee override just before payment
 *   - Deposit / partial payment workflow
 *   - Tax toggle per payment transaction
 *
 * PostgreSQL notes:
 *   - after() is silently ignored by Laravel on PG (columns are appended)
 *   - payment_status is a VARCHAR on this schema (not a named PG enum type),
 *     so no ALTER TYPE is needed - VARCHAR accepts any value.
 *   - MySQL only: MODIFY COLUMN to add 'deposit' to the ENUM definition.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. orders table ───────────────────────────────────────────────
        Schema::table('orders', function (Blueprint $table) {
            // Country snapshot - drives currency rule (KE→KES, else→USD)
            $table->string('customer_country_code', 2)->nullable()->after('shipping_country_code');

            // Shipping fee override - set manually just before payment
            $table->boolean('shipping_fee_overridden')->default(false)->after('shipping_amount');
            $table->string('shipping_fee_note', 255)->nullable()->after('shipping_fee_overridden');

            // Deposit workflow
            $table->decimal('deposit_amount', 12, 2)->nullable()->after('payment_status');
            $table->date('balance_due_date')->nullable()->after('deposit_amount');

            // Tax inclusion flag
            $table->boolean('prices_include_tax')->default(true)->after('tax_amount');
        });

        // ── 2. payments table ─────────────────────────────────────────────
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('tax_inclusive')->default(true)->after('currency_code');
            $table->decimal('tax_amount_collected', 12, 2)->nullable()->after('tax_inclusive');
        });

        // ── 3. Extend payment_status to support 'deposit' ─────────────────
        //
        // On PostgreSQL: payment_status is a VARCHAR - no DDL change needed.
        //   VARCHAR accepts 'deposit' (or any other value) without modification.
        //
        // On MySQL / MariaDB: payment_status is an ENUM column, so we must
        //   add 'deposit' to the allowed values list.
        //
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN payment_status ENUM(
                'pending','partial','deposit','paid','failed','refunded','cancelled'
            ) NOT NULL DEFAULT 'pending'");
        }
        // pgsql / sqlite: varchar - no ALTER required
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'customer_country_code',
                'shipping_fee_overridden',
                'shipping_fee_note',
                'deposit_amount',
                'balance_due_date',
                'prices_include_tax',
            ]);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['tax_inclusive', 'tax_amount_collected']);
        });

        // Revert MySQL ENUM (remove 'deposit')
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN payment_status ENUM(
                'pending','partial','paid','failed','refunded','cancelled'
            ) NOT NULL DEFAULT 'pending'");
        }
    }
};