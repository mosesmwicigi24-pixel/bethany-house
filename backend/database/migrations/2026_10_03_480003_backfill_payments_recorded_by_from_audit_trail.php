<?php

use App\Models\Payment;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * OWNER APPROVAL REQUIRED BEFORE THIS SHIPS — a write to production data.
 *
 * Role hardening 4D: fill payments.recorded_by on rows created before the
 * column existed, from the audit trail.
 *
 * What it writes, and nothing else:
 *   - ONLY rows where payments.recorded_by IS NULL. A stamped row is never
 *     touched.
 *   - The value is the causer of the EARLIEST activity_log row with
 *     subject_type = App\Models\Payment, subject_id = the payment,
 *     event = 'created', causer_type = App\Models\User — the same row Phase 1B's
 *     maker≠checker check reads today, so approval behaviour does not change.
 *   - Skipped (left NULL) when there is no such row (payments from before the
 *     AuditObserver, 2026-09-21; public pay page and webhook payments, which
 *     have no causer) or when that user no longer exists (the FK would refuse).
 *
 * It records its counts — candidates, filled, no_audit_row, causer_missing —
 * in the application log and as one activity_log entry
 * (event payments_recorded_by_backfilled), so the run is itself on the trail.
 *
 * Deploys run `migrate --force`: merging this file IS running it. Kept
 * separate from the schema migration (2026_10_03_480002) so it can be held
 * back until approved. down() is a no-op: once filled, a value cannot be told
 * apart from one stamped at creation, and the audit trail still holds the
 * source of every value written here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('payments', 'recorded_by')) {
            return;
        }

        $candidates = DB::table('payments')->whereNull('recorded_by')->pluck('id');
        $counts = ['candidates' => $candidates->count(), 'filled' => 0, 'no_audit_row' => 0, 'causer_missing' => 0];

        foreach ($candidates->chunk(500) as $chunk) {
            // Earliest 'created' row per payment in this chunk.
            $first = DB::table('activity_log')
                ->where('subject_type', Payment::class)
                ->where('event', 'created')
                ->where('causer_type', User::class)
                ->whereNotNull('causer_id')
                ->whereIn('subject_id', $chunk->all())
                ->orderBy('id')
                ->get(['subject_id', 'causer_id'])
                ->unique('subject_id')
                ->pluck('causer_id', 'subject_id');

            $existing = DB::table('users')->whereIn('id', $first->values()->unique()->all())->pluck('id')->flip();

            foreach ($chunk as $paymentId) {
                $causer = $first[$paymentId] ?? null;
                if ($causer === null) {
                    $counts['no_audit_row']++;
                    continue;
                }
                if (!isset($existing[$causer])) {
                    $counts['causer_missing']++;
                    continue;
                }
                $counts['filled'] += DB::table('payments')
                    ->where('id', $paymentId)
                    ->whereNull('recorded_by')        // only ever a NULL
                    ->update(['recorded_by' => $causer]);
            }
        }

        Log::info('payments.recorded_by backfilled from the audit trail', $counts);
        ActivityLogService::log('payments_recorded_by_backfilled', null, $counts,
            "Backfilled payments.recorded_by: {$counts['filled']} of {$counts['candidates']} filled from audit 'created' rows");
    }

    public function down(): void
    {
        // Intentionally empty — see the docblock.
    }
};
