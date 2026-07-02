<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Temporary secret used during setup (before 2FA is fully enabled)
            $table->string('two_factor_secret_temp')->nullable()->after('two_factor_secret');
            
            // When the setup was started
            $table->timestamp('two_factor_setup_started_at')->nullable()->after('two_factor_enabled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret_temp', 'two_factor_setup_started_at']);
        });
    }
};