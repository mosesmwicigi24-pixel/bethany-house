<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tamper-evidence for the audit trail.
 *
 * The append-only trigger stops the application — and anyone using it — from
 * editing or deleting log rows. It cannot stop a database superuser, who can
 * drop the trigger. The seal is for that case: each day, the rows written
 * since the last seal are hashed into a chain,
 *
 *     seal_n = SHA-256( seal_(n-1) ‖ row ‖ row ‖ … )
 *
 * and the newest seal is mailed off the server (Phase 3 daily digest). An
 * edited, deleted or back-dated row changes every seal from its own onward;
 * verify() recomputes the chain and names the first range that no longer
 * matches. A copy of the seal outside the server is what makes the chain
 * worth trusting: an attacker who rewrites the rows can rewrite the seals in
 * the database too, but not the ones already in the owner's mailbox.
 */
class AuditSealer
{
    /** table => timestamp column used for the settle window */
    public const TABLES = [
        'activity_log' => 'created_at',
        'request_logs' => 'occurred_at',
    ];

    /**
     * Rows younger than this are left for the next seal: an id is taken at
     * INSERT, not at COMMIT, so a slow transaction can commit a lower id after
     * a seal has been written past it.
     */
    private const SETTLE_MINUTES = 10;

    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Seal every row written since the last seal. Returns the new seal, or null if nothing to seal. */
    public function seal(string $table): ?object
    {
        $this->assertTable($table);

        $last   = $this->lastSeal($table);
        $fromId = (int) ($last->last_id ?? 0);
        $prev   = $last->hash ?? self::GENESIS;

        $toId = DB::table($table)
            ->where('id', '>', $fromId)
            ->where(self::TABLES[$table], '<', now()->subMinutes(self::SETTLE_MINUTES))
            ->max('id');

        if (!$toId) {
            return null;
        }

        $columns = Schema::getColumnListing($table);
        sort($columns);
        [$hash, $count, $firstId] = $this->hashRange($table, $columns, $fromId, (int) $toId, $prev);

        $id = DB::table('audit_seals')->insertGetId([
            'table_name' => $table,
            'first_id'   => $firstId ?? $fromId + 1,
            'last_id'    => (int) $toId,
            'row_count'  => $count,
            'columns'    => json_encode($columns),
            'prev_hash'  => $prev,
            'hash'       => $hash,
            'sealed_at'  => now(),
        ]);

        return DB::table('audit_seals')->find($id);
    }

    /**
     * Recompute the chain. $sinceDays limits the work to recent seals (the
     * daily check); null walks the whole history (the weekly check).
     *
     * @return array{ok: bool, checked: int, failures: array<int, array>}
     */
    public function verify(string $table, ?int $sinceDays = null): array
    {
        $this->assertTable($table);

        $seals = DB::table('audit_seals')->where('table_name', $table)->orderBy('last_id')->get();

        $failures = [];
        $checked  = 0;
        $prevHash = self::GENESIS;
        $prevLast = 0;
        $cutoff   = $sinceDays !== null ? now()->subDays($sinceDays) : null;

        foreach ($seals as $seal) {
            if ($seal->prev_hash !== $prevHash) {
                $failures[] = ['seal_id' => $seal->id, 'range' => [$prevLast + 1, $seal->last_id],
                               'problem' => 'chain broken: prev_hash does not match the previous seal'];
            }

            $recent = $cutoff === null || \Carbon\Carbon::parse($seal->sealed_at)->greaterThanOrEqualTo($cutoff);
            if ($recent) {
                $columns = json_decode($seal->columns, true) ?: [];
                [$hash, $count] = $this->hashRange($table, $columns, $prevLast, (int) $seal->last_id, $seal->prev_hash);
                $checked++;
                if ($count !== (int) $seal->row_count) {
                    $failures[] = ['seal_id' => $seal->id, 'range' => [$prevLast + 1, $seal->last_id],
                                   'problem' => "row count is {$count}, sealed as {$seal->row_count} (rows deleted or inserted)"];
                } elseif ($hash !== $seal->hash) {
                    $failures[] = ['seal_id' => $seal->id, 'range' => [$prevLast + 1, $seal->last_id],
                                   'problem' => 'content changed since it was sealed'];
                }
            }

            $prevHash = $seal->hash;
            $prevLast = (int) $seal->last_id;
        }

        return ['ok' => $failures === [], 'checked' => $checked, 'failures' => $failures];
    }

    public function lastSeal(string $table): ?object
    {
        return DB::table('audit_seals')->where('table_name', $table)->orderByDesc('last_id')->first();
    }

    /** @return array{0: string, 1: int, 2: ?int} hash, row count, first id */
    private function hashRange(string $table, array $columns, int $fromId, int $toId, string $prev): array
    {
        $ctx   = hash_init('sha256');
        hash_update($ctx, $prev);
        $count = 0;
        $first = null;

        DB::table($table)
            ->select($columns === [] ? ['*'] : $columns)
            ->where('id', '>', $fromId)
            ->where('id', '<=', $toId)
            ->chunkById(2000, function ($rows) use ($ctx, &$count, &$first) {   // orders by id itself
                foreach ($rows as $row) {
                    $first ??= (int) $row->id;
                    hash_update($ctx, self::canonical((array) $row) . "\n");
                    $count++;
                }
            });

        return [hash_final($ctx), $count, $first];
    }

    /** Stable text for a row: keys sorted, every value as text, nulls explicit. */
    private static function canonical(array $row): string
    {
        ksort($row);
        $row = array_map(fn ($v) => $v === null ? null : (is_bool($v) ? ($v ? '1' : '0') : (string) $v), $row);
        return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function assertTable(string $table): void
    {
        if (!array_key_exists($table, self::TABLES)) {
            throw new \InvalidArgumentException("Not a sealed audit table: {$table}");
        }
    }
}
