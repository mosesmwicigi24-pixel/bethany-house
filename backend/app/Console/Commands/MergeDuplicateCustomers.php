<?php

namespace App\Console\Commands;

use App\Services\CustomerMergeService;
use Illuminate\Console\Command;

/**
 * Merge duplicate customer records from the owner's ticked list — the text the
 * "Duplicate customers" document produces ("Group 1 (+254…): keep #219, merge
 * #73, #74"). A dry run by default: it prints exactly what each merge would
 * move and change, and writes nothing. --execute merges, each group in its own
 * transaction, and records every merge in the audit trail against --causer.
 *
 *   php artisan customers:merge choices.txt
 *   php artisan customers:merge choices.txt --execute --causer=8
 *   php artisan customers:unmerge 12345        (reverse one merge by its audit entry)
 */
class MergeDuplicateCustomers extends Command
{
    protected $signature = 'customers:merge {file : the ticked choices, one "Group N (...): keep #A, merge #B, #C" per line}
                            {--execute : actually merge (default is a dry run)}
                            {--causer= : the user id the audit trail records as having merged}';

    protected $description = 'Merge duplicate customer records from a ticked list (dry run unless --execute).';

    public function handle(CustomerMergeService $merges): int
    {
        $path = $this->argument('file');
        if (! is_readable($path)) {
            $this->error("Cannot read {$path}.");
            return self::FAILURE;
        }
        $groups = self::parse((string) file_get_contents($path));
        if ($groups === []) {
            $this->error('No "keep #… merge #…" lines found.');
            return self::FAILURE;
        }
        $execute = (bool) $this->option('execute');
        $causer = $this->option('causer') ? (int) $this->option('causer') : null;
        if ($execute && ! $causer) {
            $this->error('--execute needs --causer=<user id>, so the audit trail names who merged.');
            return self::FAILURE;
        }

        $this->line(($execute ? 'MERGING' : 'DRY RUN — nothing will be changed') . ': ' . count($groups) . ' groups');
        $totals = []; $ok = 0; $refused = [];
        foreach ($groups as $g) {
            $plan = $merges->plan($g['keep'], $g['merge']);
            $label = "Group {$g['group']}: keep #{$g['keep']} ← #" . implode(', #', $g['merge']);
            if (! $plan['ok']) {
                $refused[] = $g['group'];
                $this->warn("{$label}  REFUSED — " . implode('; ', $plan['problems']));
                continue;
            }
            $moves = array_filter($plan['moves']);
            foreach ($moves as $t => $n) {
                $totals[$t] = ($totals[$t] ?? 0) + $n;
            }
            $fields = collect($plan['field_changes'])->map(fn ($c, $f) => $f === 'notes' ? 'notes (combined)' : "{$f}: " . json_encode($c['old']) . ' → ' . json_encode($c['new']))->values()->implode('; ');
            $this->line("{$label}  [{$plan['keep']['number']} {$plan['keep']['name']}]  moves: "
                . ($moves ? collect($moves)->map(fn ($n, $t) => "{$n} {$t}")->implode(', ') : 'nothing')
                . ($fields ? "  | kept record: {$fields}" : ''));

            if ($execute) {
                try {
                    $merges->merge($g['keep'], $g['merge'], $causer);
                    $ok++;
                } catch (\Throwable $e) {
                    $refused[] = $g['group'];
                    $this->error("  not merged: {$e->getMessage()}");
                }
            } else {
                $ok++;
            }
        }

        $this->newLine();
        $this->line(($execute ? 'Merged' : 'Would merge') . ": {$ok} groups; refused: " . (count($refused) ? 'groups ' . implode(', ', $refused) : 'none'));
        $this->line('Rows ' . ($execute ? 'moved' : 'to move') . ': ' . ($totals ? collect($totals)->map(fn ($n, $t) => "{$n} {$t}")->implode(', ') : 'none'));

        return $refused === [] ? self::SUCCESS : self::FAILURE;
    }

    /** "Group 3 (+254…): keep #177, merge #12, #97" → ['group' => 3, 'keep' => 177, 'merge' => [12, 97]] */
    public static function parse(string $text): array
    {
        $groups = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/Group\s+(\d+).*?keep\s+#(\d+)\s*,\s*merge\s+(#\d+(?:\s*,\s*#\d+)*)/i', $line, $m)) {
                preg_match_all('/#(\d+)/', $m[3], $ids);
                $groups[] = ['group' => (int) $m[1], 'keep' => (int) $m[2], 'merge' => array_map('intval', $ids[1])];
            }
        }

        return $groups;
    }
}
