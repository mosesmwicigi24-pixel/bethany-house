<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds:
 *  - mime_type: stored at upload time so the frontend can decide whether to
 *    render a thumbnail (image) or a generic file icon (PDF, etc) without
 *    guessing from the file extension.
 *  - Backfills original_name on every EXISTING row with a random display
 *    name, since this table previously stored the real uploaded filename
 *    in original_name and showed it directly to staff/customers (the bug
 *    being fixed here). New uploads will never store the real filename -
 *    see the ShipmentController changes in this change set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_attachments', function (Blueprint $table) {
            $table->string('mime_type', 100)->nullable()->after('original_name');
        });

        // Backfill mime_type for existing rows by reading the actual file
        // from disk where possible (cheap one-time cost, paginated to avoid
        // loading the whole table into memory on large installs).
        DB::table('shipment_attachments')->orderBy('id')->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                $mime = null;
                try {
                    if (\Illuminate\Support\Facades\Storage::disk('local')->exists($row->path)) {
                        $mime = \Illuminate\Support\Facades\Storage::disk('local')->mimeType($row->path);
                    }
                } catch (\Throwable) {
                    // Leave mime_type null if it can't be read - the frontend
                    // falls back to extension-based detection in that case.
                }

                // FIX: replace the real original filename with a random one.
                // Preserve the extension so downloads still behave sensibly.
                $ext = pathinfo($row->original_name ?? '', PATHINFO_EXTENSION);
                $randomName = Str::random(12) . ($ext ? '.' . strtolower($ext) : '');

                DB::table('shipment_attachments')->where('id', $row->id)->update([
                    'mime_type'     => $mime,
                    'original_name' => $randomName,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipment_attachments', function (Blueprint $table) {
            $table->dropColumn('mime_type');
        });
        // Note: the original_name backfill above is NOT reversible - the real
        // filenames were never recoverable once overwritten, which is the
        // intended outcome of this fix.
    }
};