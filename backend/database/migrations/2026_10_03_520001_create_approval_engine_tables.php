<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role hardening, Phase 3B — the approval engine's three tables
 * (owner's Role Hardening Plan §5, §16; bh-tools/phase3_spec.md "3B").
 *
 *   approval_thresholds  the bands, as effective-dated configuration — never
 *                        code constants. One event's bands are a SET sharing
 *                        one effective_from; changing a threshold inserts a
 *                        new set, so history is never edited and a request
 *                        always knows which set it was judged against.
 *   approval_requests    one per submission of a record for approval. A
 *                        rejected or expired record comes back only as a NEW
 *                        version linked to the old (supersedes_id).
 *   approval_signatures  one row per band signed (or the one rejection).
 *
 * Band semantics: bands are cumulative and signed in order. Band k+1 is
 * required when the amount exceeds band k's up_to_kes; up_to_kes NULL is the
 * open-ended top. An amount that cannot be stated in KES (no reporting rate,
 * no product cost) needs every band — never a guess.
 *
 * Seeded with the plan's proposed defaults (decision sheet T1–T15, all
 * "accept"); the owner confirms the figures when approving the merge, and a
 * super_admin changes them through PUT /admin/approvals/thresholds/{event}.
 */
return new class extends Migration
{
    /** Spec defaults: event => [[up_to_kes|null, approver_permission], ...] in band order. */
    private const DEFAULTS = [
        'purchase_order' => [
            [100000, 'procurement.approve'],
            [500000, 'approvals.finance_sign'],
            [null,   'approvals.super_sign'],
        ],
        'stock_adjustment' => [
            [10000,  'inventory.approve'],
            [100000, 'approvals.finance_sign'],
            [null,   'approvals.super_sign'],
        ],
        // "Always PM + FM": the procurement manager alone never suffices for
        // any positive value (up_to 0), so the finance band always follows.
        'serialized_write_off' => [
            [0,      'inventory.approve'],
            [100000, 'approvals.finance_sign'],
            [null,   'approvals.super_sign'],
        ],
        // Below the category's requires_approval_above an expense needs no
        // approval at all (ExpenseController decides that); above it, these.
        'expense' => [
            [50000, 'expenses.approve'],
            [null,  'approvals.super_sign'],
        ],
        'imprest_topup' => [
            [100000, 'expenses.approve'],
            [null,   'approvals.super_sign'],
        ],
        'payment_void' => [
            [50000, 'payments.void'],
            [null,  'approvals.super_sign'],
        ],
        'payment_reassign' => [
            [50000, 'payments.reassign'],
            [null,  'approvals.super_sign'],
        ],
        // Inter-outlet transfer: the procurement manager, no value band.
        'stock_transfer' => [
            [null, 'inventory.approve'],
        ],
    ];

    public function up(): void
    {
        Schema::create('approval_thresholds', function (Blueprint $table) {
            $table->id();
            $table->string('event', 64);
            $table->unsignedSmallInteger('band_order');
            $table->decimal('up_to_kes', 16, 2)->nullable();        // NULL = no ceiling
            $table->string('approver_permission', 125);
            $table->timestamp('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event', 'effective_from', 'band_order'], 'approval_thresholds_set_band_unique');
            $table->index(['event', 'effective_from']);
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('event', 64);
            $table->morphs('approvable');
            $table->unsignedInteger('version');
            $table->foreignId('maker_id')->nullable()->constrained('users')->nullOnDelete();
            // "supplier:12", "customer:4", "expense_category:3" — the anti-splitting key.
            $table->string('counterparty', 100)->nullable();
            // The record's own value as submitted, and in KES at the reporting rate.
            $table->decimal('amount', 16, 2)->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->decimal('amount_kes', 16, 2)->nullable();
            // What the band was judged on: amount_kes plus the same maker's
            // other submissions to the same counterparty in the last 24 hours.
            $table->decimal('basis_kes', 16, 2)->nullable();
            // true when the amount could not be stated in KES (no rate, no cost)
            // and every band was required.
            $table->boolean('value_unknown')->default(false);
            // The required bands, snapshotted at submission:
            // [{order, permission, up_to_kes, covers:[orders]}]
            $table->json('bands');
            // The whole set the request was judged against, for escalation
            // past the required bands when nobody but the maker holds a band.
            $table->json('ladder');
            $table->unsignedSmallInteger('current_band')->nullable();
            $table->timestamp('thresholds_effective_from')->nullable();
            $table->string('status', 20)->default('pending');   // pending|approved|rejected|expired|cancelled
            // sha256 of the fields the approval is bound to; a change after
            // submission makes the request stale (422), never silently approved.
            $table->string('fingerprint', 64);
            $table->json('payload')->nullable();                 // action parameters (e.g. reassign target)
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejected_reason')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $table->timestamps();

            $table->unique(['approvable_type', 'approvable_id', 'event', 'version'], 'approval_requests_version_unique');
            $table->index(['status', 'expires_at']);
            $table->index(['maker_id', 'counterparty', 'created_at'], 'approval_requests_splitting_idx');
        });

        // At most one open request per record and event.
        DB::statement("CREATE UNIQUE INDEX approval_requests_one_pending ON approval_requests (approvable_type, approvable_id, event) WHERE status = 'pending'");

        Schema::create('approval_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('band_order');
            $table->foreignId('signer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 20);                      // approved | rejected
            // Band orders this one signature satisfied: more than one when a
            // band was escalated because only the maker could have signed it.
            $table->json('covers')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();

            $table->unique(['approval_request_id', 'band_order']);
        });

        $now = now();
        foreach (self::DEFAULTS as $event => $bands) {
            foreach ($bands as $i => [$upTo, $permission]) {
                DB::table('approval_thresholds')->insert([
                    'event'               => $event,
                    'band_order'          => $i + 1,
                    'up_to_kes'           => $upTo,
                    'approver_permission' => $permission,
                    'effective_from'      => $now,
                    'created_by'          => null,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_signatures');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_thresholds');
    }
};
