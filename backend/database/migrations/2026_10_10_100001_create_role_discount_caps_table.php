<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-role discount limits (owner, 2026-10-10): "clerks at 10% and Admins up
 * to 15%. Make it not automatically but when needed a clerk can give up to.
 * Create a place where super admin can set this too."
 *
 * One row per role name: the most a holder of that role may type as a
 * discount at the till, on an order or on a quotation, as a percentage. A
 * role without a row is held to the global maximum (config
 * pos.discount_cap_percent, 5). Nothing here applies a discount — it only
 * bounds what a person chooses to give. Promotions, coupons and sale prices
 * stay on the global maximum. Read by App\Support\RoleDiscountCaps; set by the
 * super admin at Setup → Discount limits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_discount_caps', function (Blueprint $table) {
            $table->id();
            $table->string('role', 125)->unique();
            $table->decimal('cap_percent', 5, 2);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('role_discount_caps')->insert([
            ['role' => 'pos_clerk', 'cap_percent' => 10.00, 'updated_by' => null, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'admin',     'cap_percent' => 15.00, 'updated_by' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('role_discount_caps');
    }
};
