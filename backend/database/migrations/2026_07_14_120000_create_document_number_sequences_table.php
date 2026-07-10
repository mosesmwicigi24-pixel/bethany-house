<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gapless per-type document numbering (quotation / invoice / receipt).
 *
 * One counter row per (doc_type, period). Numbers are allocated by taking a
 * row lock (SELECT … FOR UPDATE) and incrementing, INSIDE the same transaction
 * that issues the document — so a number is consumed only if the issue commits
 * (no gaps), and two concurrent issues can never get the same number.
 *
 * Sequential, gapless invoice numbering is a KRA requirement for tax invoices;
 * this table is the single source of truth for it. See App\Services\DocumentNumberService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('doc_type', 20);          // quotation | invoice | receipt
            $table->string('period', 10);            // e.g. '2026' (yearly reset)
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['doc_type', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_number_sequences');
    }
};
