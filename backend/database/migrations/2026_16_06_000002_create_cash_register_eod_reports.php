<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_eod_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('register_id')->constrained('cash_registers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->date('report_date');
            // JSON map of { "order_id": "note text" }
            $table->json('order_notes')->nullable();
            // Freetext daily observations
            $table->text('sentiments')->nullable();
            // Set when user clicks Submit — checked by closeRegister
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['register_id', 'user_id', 'report_date'], 'unique_eod_per_register_user_day');
            $table->index(['outlet_id', 'report_date']);
            $table->index(['user_id', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_eod_reports');
    }
};