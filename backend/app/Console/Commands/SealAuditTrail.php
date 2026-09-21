<?php

namespace App\Console\Commands;

use App\Services\ActivityLogService;
use App\Services\Audit\AuditSealer;
use Illuminate\Console\Command;

/** Daily: extend the SHA-256 chain over the audit tables (see AuditSealer). */
class SealAuditTrail extends Command
{
    protected $signature   = 'audit:seal';
    protected $description = 'Seal audit-trail rows written since the last seal into the SHA-256 chain';

    public function handle(AuditSealer $sealer): int
    {
        $summary = [];
        foreach (array_keys(AuditSealer::TABLES) as $table) {
            $seal = $sealer->seal($table);
            $summary[$table] = $seal
                ? ['rows' => $seal->row_count, 'last_id' => $seal->last_id, 'hash' => $seal->hash]
                : ['rows' => 0];
            $this->line($seal
                ? "{$table}: sealed {$seal->row_count} rows up to #{$seal->last_id} — {$seal->hash}"
                : "{$table}: nothing new to seal");
        }

        ActivityLogService::log('audit_sealed', null, $summary, 'Audit trail sealed');

        return self::SUCCESS;
    }
}
