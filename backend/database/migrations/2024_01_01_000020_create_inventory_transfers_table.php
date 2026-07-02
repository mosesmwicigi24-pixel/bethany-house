<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number', 50)->unique();
            $table->foreignId('from_outlet_id')->constrained('outlets');
            $table->foreignId('to_outlet_id')->constrained('outlets');
            $table->string('status', 50)->default('pending');
            $table->date('transfer_date');
            $table->date('received_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('received_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['transfer_number']);
            $table->index(['from_outlet_id']);
            $table->index(['to_outlet_id']);
            $table->index(['status']);
        });
        
        DB::statement('ALTER TABLE inventory_transfers ADD CONSTRAINT check_different_outlets CHECK (from_outlet_id != to_outlet_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfers');
    }
};
