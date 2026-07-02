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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('customer_number', 50)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 255)->unique();
            $table->string('phone', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('company', 255)->nullable();
            $table->string('tax_id', 50)->nullable();
            $table->string('customer_type', 50)->default('individual'); // individual, business
            $table->string('preferred_language', 5)->default('en');
            $table->string('preferred_currency', 3)->default('KES');
            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->decimal('outstanding_balance', 12, 2)->default(0);
            $table->integer('loyalty_points')->default(0);
            $table->string('status', 50)->default('active'); // active, inactive, blocked
            $table->text('notes')->nullable();
            $table->timestamp('last_purchase_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_number']);
            $table->index(['email']);
            $table->index(['phone']);
            $table->index(['status']);
            $table->index(['customer_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
