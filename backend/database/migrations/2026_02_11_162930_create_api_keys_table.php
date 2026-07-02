<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Mobile App", "Website Frontend"
            $table->string('key', 64)->unique();
            $table->enum('type', ['public', 'private'])->default('public');
            $table->boolean('is_active')->default(true);
            $table->integer('rate_limit')->default(60); // requests per minute
            $table->text('allowed_ips')->nullable(); // JSON array of IPs
            $table->text('allowed_endpoints')->nullable(); // JSON array of endpoint patterns
            $table->timestamp('last_used_at')->nullable();
            $table->integer('total_requests')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // Create default public API key for frontend
        DB::table('api_keys')->insert([
            'name' => 'Public Website Frontend',
            'key' => Str::random(64),
            'type' => 'public',
            'is_active' => true,
            'rate_limit' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};