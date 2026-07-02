<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Payment link token (SHA-256 HMAC, 64 hex chars)
            $table->string('payment_token', 64)->nullable()->unique()->after('customer_country_code');
            $table->timestamp('payment_token_expires_at')->nullable()->after('payment_token');

            // Convenience flag: true when customer_country_code differs from app_country setting
            $table->boolean('is_international')->default(false)->after('payment_token_expires_at');

            // Track which user / system created this order (for notification dispatch)
            $table->unsignedBigInteger('created_by')->nullable()->after('is_international');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn(['payment_token', 'payment_token_expires_at', 'is_international', 'created_by']);
        });
    }
};