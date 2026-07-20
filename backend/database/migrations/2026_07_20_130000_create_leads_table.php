<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront leads captured by the Neema assistant — quote requests, bulk/parish
 * enquiries, shipping questions, "have someone follow up". Persisted via
 * POST /storefront/leads (docs/HUB_CONTRACT.md §1). Idempotent on
 * client_request_id, mirroring the guest-checkout orders bridge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('client_request_id', 100)->unique(); // idempotency key (string, like the orders bridge)
            $table->string('intent', 40);                // quote|shipping|product_inquiry|measurement|order_support|other
            $table->string('readiness', 10)->default('medium'); // low|medium|high
            $table->string('name')->nullable();
            $table->string('phone');                     // the one guaranteed contact
            $table->string('email')->nullable();
            $table->string('church')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('city')->nullable();
            $table->json('products')->nullable();        // storefront slugs, raw
            $table->string('quantity')->nullable();      // free text
            $table->text('message')->nullable();
            $table->string('source_path')->nullable();   // attribution
            $table->string('status', 20)->default('new'); // new|assigned|quoted|won|lost
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('intent');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
