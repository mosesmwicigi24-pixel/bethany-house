<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('email', 255)->unique();
            $table->string('phone', 20)->unique()->nullable();
            $table->string('password', 255);
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('user_type', 50)->default('customer');
            $table->string('status', 20)->default('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_enabled_at')->nullable();
            $table->string('preferred_language', 5)->default('en');
            $table->string('preferred_currency', 3)->default('KES');
            $table->timestamp('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['email']);
            $table->index(['phone']);
            $table->index(['user_type']);
            $table->index(['status']);
            $table->index(['deleted_at']);
        });

        // Add check constraint for PostgreSQL to simulate ENUM
        DB::statement("
            ALTER TABLE users 
            DROP CONSTRAINT IF EXISTS users_user_type_check
        ");
        
        DB::statement("
            ALTER TABLE users 
            ADD CONSTRAINT users_user_type_check 
            CHECK (user_type IN ('system', 'staff', 'customer'))
        ");

        // Schema::create('password_reset_tokens', function (Blueprint $table) {
        //     $table->string('email')->primary();
        //     $table->string('token');
        //     $table->timestamp('created_at')->nullable();
        // });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        // Schema::dropIfExists('password_reset_tokens');
        DB::statement("
            ALTER TABLE users 
            DROP CONSTRAINT IF EXISTS users_user_type_check
        ");
        Schema::dropIfExists('users');
    }
};
