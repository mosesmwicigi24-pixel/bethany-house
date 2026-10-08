<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductionOrder;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Merge duplicate customer records: one person registered more than once
 * (owner's list, 2026-10-02: 40 numbers on 89 records; the owner chose 38
 * groups and which record to keep in each).
 *
 * WHAT MOVES — every table that points at a customer (all seven foreign keys
 * on production, checked 2026-10-02): orders and production orders through
 * their models, so each move lands in the audit trail with its old and new
 * customer (the order observer reacts only to status and payment changes, so
 * Neema hears nothing); addresses, carts, channel touchpoints, replenishment
 * pings and win-back outreach directly, their row ids recorded below. None of
 * the seven has a unique index on customer_id, so nothing can collide.
 *
 * THE KEPT RECORD is completed, never overwritten: blanks are filled from the
 * merged record; loyalty points and outstanding balance add up; the later last
 * purchase and the higher credit limit win; a WhatsApp marketing opt-out on
 * either record survives (consent is never widened by a merge); a block on
 * either survives. Notes are kept, labelled with where they came from.
 *
 * THE MERGED RECORD is archived (soft-deleted), not deleted, with a note naming
 * the record it went into.
 *
 * UNDO — one 'customer_merged' audit entry per merged record holds every moved
 * row id and the kept record's prior values; unmerge() reverses it exactly.
 * The key is merge_ref, not merge_token: the log redacts any key containing
 * "_token", which would have made every read-back miss.
 * ActivityLogService swallows its own failures, so the entry is read back
 * inside the transaction and the merge rolls back if it is not there: a merge
 * without its undo record never commits.
 *
 * REFUSED, never guessed: records missing or already archived, records that do
 * not share the phone number, a record listed twice, and two records that each
 * have a login (one person cannot hold two accounts in one record).
 */
class CustomerMergeService
{
    /** Tables moved directly (no model events); orders and production orders move through their models. */
    public const DIRECT_TABLES = ['addresses', 'carts', 'channel_touchpoints', 'replenishment_pings', 'win_back_outreach'];

    /**
     * What merging $mergeIds into $keepId would do, without changing anything.
     *
     * @return array{ok: bool, problems: string[], keep: array, merge: array[], moves: array<string,int>, field_changes: array}
     */
    public function plan(int $keepId, array $mergeIds): array
    {
        $problems = [];
        $mergeIds = array_values(array_map('intval', $mergeIds));
        $keep = Customer::find($keepId);
        $merge = Customer::whereIn('id', $mergeIds)->get()->keyBy('id');

        if (! $keep) {
            $problems[] = "record #{$keepId} (to keep) does not exist or is archived";
        }
        foreach ($mergeIds as $id) {
            if (! $merge->has($id)) {
                $problems[] = "record #{$id} does not exist or is already archived";
            }
        }
        if (in_array($keepId, $mergeIds, true) || count($mergeIds) !== count(array_unique($mergeIds))) {
            $problems[] = 'a record is listed more than once';
        }
        if ($mergeIds === []) {
            $problems[] = 'nothing to merge';
        }
        if ($problems) {
            return ['ok' => false, 'problems' => $problems, 'keep' => [], 'merge' => [], 'moves' => [], 'field_changes' => []];
        }

        $number = Phone::e164($keep->phone);
        foreach ($merge as $m) {
            if ($number === null || Phone::e164($m->phone) !== $number) {
                $problems[] = "record #{$m->id} does not share #{$keep->id}'s phone number";
            }
        }
        $logins = collect([$keep])->merge($merge->values())->filter(fn ($c) => $c->user_id !== null);
        if ($logins->count() > 1) {
            $problems[] = 'more than one of these records has a login (' . $logins->pluck('id')->map(fn ($i) => "#{$i}")->implode(', ') . ')';
        }

        $moves = ['orders' => Order::whereIn('customer_id', $mergeIds)->count(),
                  'production_orders' => ProductionOrder::whereIn('customer_id', $mergeIds)->count()];
        foreach (self::DIRECT_TABLES as $t) {
            $moves[$t] = DB::table($t)->whereIn('customer_id', $mergeIds)->count();
        }

        return [
            'ok'            => $problems === [],
            'problems'      => $problems,
            'keep'          => $this->describe($keep),
            'merge'         => $merge->values()->map(fn ($m) => $this->describe($m))->all(),
            'moves'         => $moves,
            'field_changes' => $this->combinedFields($keep, $merge->values()->all()),
        ];
    }

    /**
     * Merge, in one transaction per call. Throws (and changes nothing) if the
     * plan has problems or the undo record cannot be written.
     *
     * @return array the audit entry's properties (the undo record)
     */
    public function merge(int $keepId, array $mergeIds, ?int $causerId = null): array
    {
        return DB::transaction(function () use ($keepId, $mergeIds, $causerId) {
            // Lock every record involved, so a sale cannot attach to a record
            // while it is being archived.
            $ids = array_merge([$keepId], array_map('intval', $mergeIds));
            Customer::whereIn('id', $ids)->lockForUpdate()->get();

            $plan = $this->plan($keepId, $mergeIds);
            if (! $plan['ok']) {
                throw new RuntimeException('Merge refused: ' . implode('; ', $plan['problems']));
            }

            $keep = Customer::findOrFail($keepId);
            $before = $keep->only(array_keys($plan['field_changes']));
            $token = bin2hex(random_bytes(8));
            $moved = ['orders' => [], 'production_orders' => []];

            foreach (Order::whereIn('customer_id', $mergeIds)->get() as $o) {
                $moved['orders'][] = ['id' => $o->id, 'from' => $o->customer_id];
                $o->customer_id = $keepId;
                $o->save();
            }
            foreach (ProductionOrder::whereIn('customer_id', $mergeIds)->get() as $p) {
                $moved['production_orders'][] = ['id' => $p->id, 'from' => $p->customer_id];
                $p->customer_id = $keepId;
                $p->save();
            }
            foreach (self::DIRECT_TABLES as $t) {
                $rows = DB::table($t)->whereIn('customer_id', $mergeIds)->get(['id', 'customer_id']);
                $moved[$t] = $rows->map(fn ($r) => ['id' => $r->id, 'from' => $r->customer_id])->all();
                if ($rows->isNotEmpty()) {
                    DB::table($t)->whereIn('id', $rows->pluck('id'))->update(['customer_id' => $keepId]);
                }
            }

            // A login moves with its person: the one record that has it hands it over.
            $merged = Customer::whereIn('id', $mergeIds)->get();
            $loginFrom = $merged->firstWhere('user_id', '!==', null);
            $loginUser = $loginFrom?->user_id;
            if ($loginFrom && $keep->user_id === null) {
                $loginFrom->user_id = null;
                $loginFrom->save();
            }

            foreach ($plan['field_changes'] as $field => $change) {
                $keep->{$field} = $change['new'];
            }
            if ($loginFrom && $keep->user_id === null) {
                $keep->user_id = $loginUser;
            }
            $keep->save();

            $archived = [];
            foreach ($merged as $m) {
                $archived[] = ['id' => $m->id, 'number' => $m->customer_number, 'notes' => $m->notes, 'user_id' => $m->id === $loginFrom?->id ? $loginUser : $m->user_id];
                $m->notes = trim("Merged into {$keep->customer_number} (#{$keep->id}) on " . now()->toDateString() . ".\n" . ($m->notes ?? ''));
                $m->save();
                $m->delete();
            }

            $undo = [
                'merge_ref' => $token,
                'kept'        => $keepId,
                'merged'      => array_map('intval', $mergeIds),
                'moved'       => $moved,
                'kept_before' => $before,
                'login'       => $loginFrom ? ['user_id' => $loginUser, 'from' => $loginFrom->id] : null,
                'archived'    => $archived,
            ];
            ActivityLogService::log('customer_merged', $keep, $undo,
                'Merged ' . implode(', ', array_map(fn ($a) => $a['number'], $archived)) . " into {$keep->customer_number}",
                $causerId ? \App\Models\User::find($causerId) : null);

            $written = DB::table('activity_log')->where('event', 'customer_merged')
                ->where('subject_id', $keepId)->where('properties->merge_ref', $token)->exists();
            if (! $written) {
                throw new RuntimeException("Merge into #{$keepId} rolled back: its undo record could not be written.");
            }

            return $undo;
        });
    }

    /** Reverse one merge from its audit entry: rows go back, records come back, the kept record's fields are restored. */
    public function unmerge(int $activityId, ?int $causerId = null): void
    {
        DB::transaction(function () use ($activityId, $causerId) {
            $entry = DB::table('activity_log')->where('id', $activityId)->where('event', 'customer_merged')->first();
            if (! $entry) {
                throw new RuntimeException("No customer merge with audit entry #{$activityId}.");
            }
            $u = json_decode($entry->properties, true);

            foreach ($u['archived'] as $a) {
                $c = Customer::withTrashed()->findOrFail($a['id']);
                $c->restore();
                $c->notes = $a['notes'];
                $c->save();
            }
            foreach ($u['moved']['orders'] as $r) {
                $o = Order::find($r['id']);
                if ($o) { $o->customer_id = $r['from']; $o->save(); }
            }
            foreach ($u['moved']['production_orders'] as $r) {
                $p = ProductionOrder::find($r['id']);
                if ($p) { $p->customer_id = $r['from']; $p->save(); }
            }
            foreach (self::DIRECT_TABLES as $t) {
                foreach ($u['moved'][$t] ?? [] as $r) {
                    DB::table($t)->where('id', $r['id'])->update(['customer_id' => $r['from']]);
                }
            }
            $keep = Customer::findOrFail($u['kept']);
            foreach ($u['kept_before'] as $field => $value) {
                $keep->{$field} = $value;
            }
            if ($u['login']) {
                $keep->user_id = null;
                $keep->save();
                $from = Customer::findOrFail($u['login']['from']);
                $from->user_id = $u['login']['user_id'];
                $from->save();
            }
            $keep->save();

            ActivityLogService::log('customer_unmerged', $keep, ['merge_entry' => $activityId],
                "Reversed customer merge (audit entry #{$activityId})", $causerId ? \App\Models\User::find($causerId) : null);
        });
    }

    private function describe(Customer $c): array
    {
        return [
            'id' => $c->id, 'number' => $c->customer_number, 'name' => trim("{$c->first_name} {$c->last_name}"),
            'phone' => $c->phone, 'has_login' => $c->user_id !== null, 'status' => $c->status,
        ];
    }

    /** The kept record's fields after the merge: only those that change, with old and new. */
    private function combinedFields(Customer $keep, array $merged): array
    {
        $new = [];
        foreach (['company', 'tax_id', 'date_of_birth', 'gender'] as $f) {
            if (blank($keep->{$f})) {
                foreach ($merged as $m) {
                    if (! blank($m->{$f})) { $new[$f] = $m->{$f}; break; }
                }
            }
        }
        $new['loyalty_points']      = (int) $keep->loyalty_points + array_sum(array_map(fn ($m) => (int) $m->loyalty_points, $merged));
        $new['outstanding_balance'] = round((float) $keep->outstanding_balance + array_sum(array_map(fn ($m) => (float) $m->outstanding_balance, $merged)), 2);
        $new['credit_limit']        = max(array_merge([(float) $keep->credit_limit], array_map(fn ($m) => (float) $m->credit_limit, $merged)));
        $last = collect($merged)->pluck('last_purchase_at')->push($keep->last_purchase_at)->filter()->max();
        $new['last_purchase_at']    = $last;
        $new['wa_marketing_opt_out'] = (bool) $keep->wa_marketing_opt_out || collect($merged)->contains(fn ($m) => (bool) $m->wa_marketing_opt_out);
        if (collect($merged)->contains(fn ($m) => $m->status === 'blocked')) {
            $new['status'] = 'blocked';
        }
        $extraNotes = collect($merged)->filter(fn ($m) => ! blank($m->notes))
            ->map(fn ($m) => "[From {$m->customer_number}] " . trim($m->notes))->implode("\n");
        if ($extraNotes !== '') {
            $new['notes'] = trim(($keep->notes ? trim($keep->notes) . "\n" : '') . $extraNotes);
        }

        $changes = [];
        foreach ($new as $f => $v) {
            $old = $keep->{$f};
            $same = $old instanceof \DateTimeInterface || $v instanceof \DateTimeInterface
                ? (string) $old === (string) $v
                : (is_numeric($old) && is_numeric($v) ? (float) $old === (float) $v : $old === $v);
            if (! $same) {
                $changes[$f] = ['old' => $old instanceof \DateTimeInterface ? $old->toDateTimeString() : $old,
                                'new' => $v instanceof \DateTimeInterface ? $v->toDateTimeString() : $v];
            }
        }

        return $changes;
    }
}
