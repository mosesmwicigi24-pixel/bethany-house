<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('sku', 100)->unique();
            $table->string('variant_name', 255)->nullable();
            $table->jsonb('attributes'); // ← Changed from json() to jsonb()
            $table->decimal('weight', 10, 2)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['product_id']);
            $table->index(['sku']);
        });
        
        // Add GIN index for JSONB search - this will now work
        DB::statement('CREATE INDEX idx_product_variants_attributes ON product_variants USING GIN(attributes)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};