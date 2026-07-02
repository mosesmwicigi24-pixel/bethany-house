<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 - Production Order Workflow Enhancements
 *
 * Changes to production_orders:
 *   - status enum gains 'draft' (default)
 *   - is_customer_order flag
 *   - customer_id FK (nullable)
 *   - measurements jsonb
 *   - customer_preferences jsonb
 *   - estimated_completion_date
 *   - confirmed_at / confirmed_by
 *   - target_outlet_id
 *
 * New tables:
 *   - production_order_assignees   (who is on each order + their role)
 *   - production_order_approvals   (gate sign-offs)
 *   - production_auto_assignee_rules  (per-outlet default assignees)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. production_orders - new columns ───────────────────────────
        Schema::table('production_orders', function (Blueprint $table) {
            // is_customer_order: false = in-house stock production
            $table->boolean('is_customer_order')->default(true)->after('order_number');

            // Direct customer link (used when raising from POS/sales, may differ from customer_order)
            $table->unsignedBigInteger('customer_id')->nullable()->after('is_customer_order');
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();

            // Tailoring-specific capture
            $table->jsonb('measurements')->nullable()->after('specifications');
            $table->jsonb('customer_preferences')->nullable()->after('measurements');

            // Scheduling visibility
            $table->date('estimated_completion_date')->nullable()->after('due_date');

            // Confirmation gate (draft → pending)
            $table->timestamp('confirmed_at')->nullable()->after('started_at');
            $table->unsignedBigInteger('confirmed_by')->nullable()->after('confirmed_at');
            $table->foreign('confirmed_by')->references('id')->on('users')->nullOnDelete();

            // Where to deliver finished goods
            $table->unsignedBigInteger('target_outlet_id')->nullable()->after('outlet_id');
            $table->foreign('target_outlet_id')->references('id')->on('outlets')->nullOnDelete();
        });

        // Add 'draft' to the status enum (MySQL only).
        // On PostgreSQL, production_orders.status is a VARCHAR - no ALTER needed.
        // On MySQL/MariaDB, it is an ENUM column that must be explicitly extended.
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE production_orders MODIFY COLUMN status ENUM(
                'draft','pending','in_progress','on_hold','qc_pending','qc_passed','qc_failed','completed','cancelled'
            ) NOT NULL DEFAULT 'draft'");
        }
        // pgsql / sqlite: varchar - no ALTER required

        // ── 2. production_order_assignees ─────────────────────────────────
        Schema::create('production_order_assignees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('production_order_id');
            $table->foreign('production_order_id')->references('id')->on('production_orders')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('role_in_order', 100)->default('assignee');
            $table->boolean('auto_assigned')->default(false);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['production_order_id', 'user_id']);
        });

        // ── 3. production_order_approvals (gate sign-offs) ────────────────
        Schema::create('production_order_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('production_order_id');
            $table->foreign('production_order_id')->references('id')->on('production_orders')->cascadeOnDelete();
            // gate values: payment_received | production_started | qc_passed | qc_failed | dispatched | delivered
            $table->string('gate', 100);
            $table->unsignedBigInteger('approved_by');
            $table->foreign('approved_by')->references('id')->on('users');
            $table->text('notes')->nullable();
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->index(['production_order_id', 'gate']);
        });

        // ── 4. production_auto_assignee_rules ─────────────────────────────
        Schema::create('production_auto_assignee_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('role_in_order', 100)->default('observer');
            // null = applies to all outlets; set = outlet-specific
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->foreign('outlet_id')->references('id')->on('outlets')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_auto_assignee_rules');
        Schema::dropIfExists('production_order_approvals');
        Schema::dropIfExists('production_order_assignees');

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['confirmed_by']);
            $table->dropForeign(['target_outlet_id']);
            $table->dropColumn([
                'is_customer_order',
                'customer_id',
                'measurements',
                'customer_preferences',
                'estimated_completion_date',
                'confirmed_at',
                'confirmed_by',
                'target_outlet_id',
            ]);
        });
    }
};