<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for automated WhatsApp reorder reminders (replenishment pings).
 *
 * One row per DECISION the daily job makes about a due (customer, product)
 * pair — sent, failed, or skipped and why. The trail is what makes the
 * cooldown enforceable ("did we already ping this pair this cycle?") and
 * what the Replenishment tab reads to show "Pinged Nd ago" on due rows.
 *
 * phone is stored CANONICAL (E.164 digits, no '+', via App\Support\Phone) so
 * pings match radar rows regardless of how the order captured the number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replenishment_pings', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 32);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            // Snapshot of what the radar knew when the decision was made —
            // survives product renames/deletes.
            $table->string('product_name');
            $table->integer('cycle_days');
            $table->integer('days_over');
            $table->decimal('avg_value', 12, 2);
            // sent | failed | skipped_cooldown | skipped_optout | skipped_no_phone
            $table->string('status', 32);
            // Meta's message id (their status webhooks reference it).
            $table->string('wamid')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            // Cooldown lookups and "latest ping per pair" both hit this.
            $table->index(['phone', 'product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replenishment_pings');
    }
};
