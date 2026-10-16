<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expense evidence (owner, 2026-10-16): "a place to upload an image,
 * especially quotations done on paper … when water comes … someone can take
 * a photo and upload it as evidence."
 *
 * Several files per expense, each labelled (receipt, quotation, bill/invoice,
 * meter reading, other). Append-only evidence: a file is never overwritten or
 * deleted. Before anyone has decided on the expense, the person who uploaded
 * a file may withdraw it (wrong photo) — it is marked removed, with who and
 * when, and the file stays on disk. The old single receipt
 * (expenses.receipt_path, whose "Replace" deleted the previous file) is
 * copied in as the first attachment; receipt_path itself is left as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->string('kind', 16);                 // receipt | quotation | invoice | meter | other
            $table->string('disk', 16)->default('private');
            $table->string('path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->string('original_name')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['expense_id', 'removed_at']);
        });

        // Existing receipts become the first attachment. Idempotent: a path
        // already copied is not copied twice. No file moves.
        DB::table('expenses')->whereNotNull('receipt_path')->where('receipt_path', '!=', '')
            ->orderBy('id')
            ->select(['id', 'receipt_path', 'created_by', 'updated_at'])
            ->chunk(500, function ($rows) {
                foreach ($rows as $r) {
                    $exists = DB::table('expense_attachments')
                        ->where('expense_id', $r->id)->where('path', $r->receipt_path)->exists();
                    if ($exists) {
                        continue;
                    }
                    DB::table('expense_attachments')->insert([
                        'expense_id'    => $r->id,
                        'kind'          => 'receipt',
                        'disk'          => 'private',
                        'path'          => $r->receipt_path,
                        'original_name' => basename($r->receipt_path),
                        'uploaded_by'   => $r->created_by,
                        'created_at'    => $r->updated_at ?? now(),
                        'updated_at'    => $r->updated_at ?? now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // receipt_path was never touched, so dropping the table loses nothing
        // the old screens used — except files added only as attachments.
        Schema::dropIfExists('expense_attachments');
    }
};
