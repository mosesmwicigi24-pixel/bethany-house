<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('shopping_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('session_id', 255)->nullable();
            $table->string('currency_code', 3)->default('KES');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            
            $table->unique(['user_id']);
            $table->unique(['session_id']);
            $table->index(['expires_at']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('shopping_carts');
    }
};
