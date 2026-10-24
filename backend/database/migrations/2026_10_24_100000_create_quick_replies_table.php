<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick replies (Messages, WhatsApp Business direction, owner 23 Oct 2026).
 *
 * The messages the hub sends every day — "Your order is ready for
 * collection", "Fitting confirmed for …" — saved once for the business and
 * inserted with `/` in the composer. `body` may carry {order}, {customer}
 * and {name}, filled from the thread at insert time on the client. One list
 * for the whole business; admins write it, every staff member reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_replies', function (Blueprint $table) {
            $table->id();
            $table->string('title', 80);
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_replies');
    }
};
