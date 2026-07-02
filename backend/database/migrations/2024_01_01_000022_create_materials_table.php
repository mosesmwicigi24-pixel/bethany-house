<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('unit_of_measure', 20);
            $table->string('category', 100)->nullable();
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->integer('reorder_point')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['code']);
            $table->index(['category']);
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
