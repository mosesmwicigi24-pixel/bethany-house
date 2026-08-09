<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports daily sales aggregates exported from the legacy POS MySQL into
 * legacy_sales_daily — the historical backbone of seasonal demand planning.
 *
 * Input: a JSON array of rows
 *   [{"date": "YYYY-MM-DD", "product_name": "...", "units": N, "revenue": N}, ...]
 *
 * Product matching, in precedence order:
 *   1. --map=names.json — {"lowercased trimmed legacy name": product_id}
 *   2. exact case-insensitive match on product_translations (en) name
 *   3. unmatched → row still imported with product_id NULL (name-keyed
 *      history is preserved; the row simply cannot fuel per-product
 *      projections until re-imported with a map entry)
 *
 * Idempotent: rows upsert on (sale_date, product_name), so re-running the
 * same export — or an extended one — converges instead of duplicating.
 */
class ImportLegacySales extends Command
{
    protected $signature = 'seasonal:import-legacy
        {file : Path to the JSON export of daily aggregates}
        {--map= : Path to a JSON object mapping lowercased trimmed legacy names to product ids}';

    protected $description = 'Import legacy POS daily sales aggregates into legacy_sales_daily (analytics only)';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($file), true);
        if (! is_array($rows)) {
            $this->error('The file is not a JSON array of rows.');

            return self::FAILURE;
        }

        $map = [];
        if ($mapPath = $this->option('map')) {
            if (! is_file($mapPath)) {
                $this->error("Map file not found: {$mapPath}");

                return self::FAILURE;
            }
            $raw = json_decode((string) file_get_contents($mapPath), true);
            if (! is_array($raw)) {
                $this->error('The map file is not a JSON object of {name: product_id}.');

                return self::FAILURE;
            }
            foreach ($raw as $name => $id) {
                $map[mb_strtolower(trim((string) $name))] = (int) $id;
            }
        }

        // Exact case-insensitive catalogue names (en) — the fallback matcher.
        $catalogue = [];
        foreach (DB::table('product_translations')->where('language_code', 'en')->get(['product_id', 'name']) as $t) {
            $catalogue[mb_strtolower(trim((string) $t->name))] ??= (int) $t->product_id;
        }

        $validIds = array_flip(
            DB::table('products')->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        $now       = now();
        $upserts   = [];
        $skipped   = 0;
        $matched   = [];   // legacy name → product_id
        $unmatched = [];   // legacy name → true

        foreach ($rows as $row) {
            $date  = is_array($row) ? trim((string) ($row['date'] ?? '')) : '';
            $name  = is_array($row) ? trim((string) ($row['product_name'] ?? '')) : '';
            $units = is_array($row) ? ($row['units'] ?? null) : null;

            if ($name === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! is_numeric($units)) {
                $skipped++;
                continue;
            }

            $nameKey   = mb_strtolower($name);
            $productId = $map[$nameKey] ?? $catalogue[$nameKey] ?? null;
            if ($productId !== null && ! isset($validIds[$productId])) {
                $productId = null; // a mapped id that doesn't exist must not fail the FK
            }
            if ($productId !== null) {
                $matched[$name] = $productId;
            } else {
                $unmatched[$name] = true;
            }

            // Last row wins per (date, name) within one file.
            $upserts[$date . '|' . $nameKey] = [
                'sale_date'    => $date,
                'product_id'   => $productId,
                'product_name' => mb_substr($name, 0, 200),
                'units'        => round((float) $units, 2),
                'revenue'      => round((float) ($row['revenue'] ?? 0), 2),
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        foreach (array_chunk(array_values($upserts), 500) as $chunk) {
            DB::table('legacy_sales_daily')->upsert(
                $chunk,
                ['sale_date', 'product_name'],
                ['product_id', 'units', 'revenue', 'updated_at'],
            );
        }

        $this->info(sprintf(
            'Imported %d day-rows (%d skipped as malformed).',
            count($upserts),
            $skipped,
        ));
        $this->info(sprintf(
            'Product names matched: %d, unmatched: %d.',
            count($matched),
            count($unmatched),
        ));

        if ($unmatched !== []) {
            $this->warn('Unmatched legacy names (rows kept with product_id NULL — add them to --map and re-run):');
            foreach (array_slice(array_keys($unmatched), 0, 40) as $name) {
                $this->line("  - {$name}");
            }
            if (count($unmatched) > 40) {
                $this->line('  … and ' . (count($unmatched) - 40) . ' more.');
            }
        }

        return self::SUCCESS;
    }
}
