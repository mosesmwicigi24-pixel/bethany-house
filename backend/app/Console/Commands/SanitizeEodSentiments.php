<?php

namespace App\Console\Commands;

use App\Support\HtmlSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sanitise EoD sentiments already stored before the write path started doing it.
 *
 * The backfill lives in a command rather than only inside its migration so it
 * can be run again. A migration gets exactly one attempt: it is marked as run
 * whether it did the work or skipped it, and this one has a reason to skip —
 * it will not rewrite months-old notes with the degraded plain-text fallback
 * when mews/purifier is missing from the container, because down() restores
 * nothing and that formatting cannot be typed back.
 *
 * So the migration calls this, this stands down when the engine is absent, and
 * whoever fixes the container runs it afterwards:
 *
 *   php artisan eod:sanitize-sentiments
 *
 * Idempotent — re-sanitising already-clean HTML returns the same value, so it
 * is safe to run whenever there is doubt.
 */
class SanitizeEodSentiments extends Command
{
    protected $signature = 'eod:sanitize-sentiments {--dry-run : Report what would change without writing.}';

    protected $description = 'Sanitise stored EoD sentiments (backfill for pre-sanitisation rows)';

    public function handle(): int
    {
        if (!Schema::hasTable('cash_register_eod_reports')) {
            $this->info('No cash_register_eod_reports table — nothing to backfill.');

            return self::SUCCESS;
        }

        if (!HtmlSanitizer::engineAvailable()) {
            // Deliberately SUCCESS: a container missing the package must not be
            // what stops a deploy, and this is re-runnable by design.
            $this->warn(
                'mews/purifier is unavailable in this container, so stored notes were left as they are '
                . 'rather than flattened to plain text. Re-run this command once the package is present.'
            );

            return self::SUCCESS;
        }

        $dry     = (bool) $this->option('dry-run');
        $changed = 0;
        $seen    = 0;

        DB::table('cash_register_eod_reports')
            ->whereNotNull('sentiments')
            ->where('sentiments', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$changed, &$seen, $dry) {
                foreach ($rows as $row) {
                    $seen++;
                    $clean = HtmlSanitizer::sentiments($row->sentiments);

                    if ($clean === $row->sentiments) {
                        continue;
                    }

                    $changed++;

                    if (!$dry) {
                        DB::table('cash_register_eod_reports')
                            ->where('id', $row->id)
                            ->update(['sentiments' => $clean]);
                    }
                }
            });

        $this->info($dry
            ? "{$changed} of {$seen} stored notes would change. Nothing was written."
            : "{$changed} of {$seen} stored notes sanitised.");

        return self::SUCCESS;
    }
}
