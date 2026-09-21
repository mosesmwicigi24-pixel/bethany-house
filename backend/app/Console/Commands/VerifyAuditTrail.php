<?php

namespace App\Console\Commands;

use App\Services\ActivityLogService;
use App\Services\Audit\AuditSealer;
use Illuminate\Console\Command;

/**
 * Recompute the seal chain and report any range that no longer matches.
 * Daily with --days=7 (cheap), weekly in full. A failure is written to the
 * trail as audit_verification_failed — which the Activity Log shows as a red
 * integrity badge — and exits non-zero so the scheduler log shows it too.
 */
class VerifyAuditTrail extends Command
{
    protected $signature   = 'audit:verify {--days= : Only re-hash seals from the last N days (default: all)}';
    protected $description = 'Verify the audit-trail seal chain';

    public function handle(AuditSealer $sealer): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : null;

        $results = [];
        $ok = true;
        foreach (array_keys(AuditSealer::TABLES) as $table) {
            $r = $sealer->verify($table, $days);
            $results[$table] = $r;
            $ok = $ok && $r['ok'];
            $this->line(($r['ok'] ? 'OK  ' : 'FAIL') . " {$table}: {$r['checked']} seals re-hashed"
                . ($r['ok'] ? '' : ' — ' . json_encode($r['failures'])));
        }

        ActivityLogService::log($ok ? 'audit_verified' : 'audit_verification_failed', null, [
            'scope'   => $days ? "last {$days} days" : 'full history',
            'results' => $results,
        ], $ok ? 'Audit trail verified intact' : 'AUDIT TRAIL INTEGRITY CHECK FAILED');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
