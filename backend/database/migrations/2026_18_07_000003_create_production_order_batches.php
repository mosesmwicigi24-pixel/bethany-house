<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colourway batches inside a production order.
 *
 * A hundred white cassocks are rarely one thing: ten with blue plates, buttons
 * and piping; ten green; the rest cream. The body is the same product — the
 * trim combination is decided AT PRODUCTION TIME, which is why this lives on
 * the production order and not on the product's variant matrix.
 *
 * A batch is a label ("Blue trim"), an attributes map ({plates: blue, piping:
 * blue}), and a quantity. Batch quantities must sum EXACTLY to the order's
 * quantity — enforced at the endpoint, atomically over the whole set.
 *
 * Piece progress then counts per batch per stage (production_task_batch_progress),
 * and the task's own quantity_done becomes the SUM of its batch rows — kept
 * denormalised so every existing consumer (flow gating, completion %, the
 * distribution chips, task lifecycle) works untouched. "Ten green done" is a
 * batch whose last stage passed its full quantity — visible as complete within
 * the batch of a hundred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_order_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->json('attributes')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['production_order_id']);
        });

        Schema::create('production_task_batch_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_order_batch_id')->constrained('production_order_batches')->cascadeOnDelete();
            $table->unsignedInteger('quantity_done')->default(0);
            $table->timestamps();

            $table->unique(['production_task_id', 'production_order_batch_id'], 'uq_task_batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_task_batch_progress');
        Schema::dropIfExists('production_order_batches');
    }
};
