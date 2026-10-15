<?php

namespace App\Services\Tills;

use App\Models\CashRegister;
use App\Models\User;
use App\Support\Audit\EventContext;
use Illuminate\Http\JsonResponse;

/**
 * The morning check (owner, 2026-10-15): a till is one trading day.
 *
 * "The point of sale should restart every morning and should ask you for a
 * report for the previous evening. If she did not close it, she gets a
 * pop-up that requires her to write the report for yesterday so that today
 * she can sell."
 *
 * So, before money is taken at the POS — a sale, or a payment on a POS order,
 * whatever the tender (owner's answer: every sale, not only cash):
 *
 *  - a till of hers still open from an EARLIER day (Nairobi calendar) is
 *    refused with TILL_FROM_EARLIER_DAY — she must report on it and count it
 *    first, so yesterday's drawer never absorbs today's takings;
 *  - no till of hers open here at all is refused with TILL_NOT_OPEN, so every
 *    sale lands in a day's till (card and M-Pesa used to need none).
 *
 * Nothing is ever closed for her: a count cannot be invented. Returns and
 * voids are not gated — they reverse the drawer that took the original sale
 * (TillReversals), not today's.
 *
 * The sales agent's service account works no drawer and is never gated.
 * Off switch: config pos.same_day_till_required.
 */
final class TillGate
{
    public const EARLIER_DAY = 'TILL_FROM_EARLIER_DAY';
    public const NOT_OPEN    = 'TILL_NOT_OPEN';

    /** Her open till from an earlier day, at any outlet (one open till per person). */
    public static function staleTill(User $user): ?CashRegister
    {
        return CashRegister::with('outlet:id,name')
            ->where('opened_by', $user->id)
            ->where('status', 'open')
            ->where('opened_at', '<', today())
            ->oldest('opened_at')
            ->first();
    }

    /** NULL when she may take money at this outlet; otherwise the refusal. */
    public static function refusal(User $user, int $outletId): ?JsonResponse
    {
        if (!config('pos.same_day_till_required', true) || EventContext::isAgent($user)) {
            return null;
        }

        if ($stale = self::staleTill($user)) {
            return response()->json([
                'message' => 'Your till from ' . $stale->opened_at->format('l j M')
                    . ' is still open. Write that day\'s report and count the drawer before selling today.',
                'code'    => self::EARLIER_DAY,
                'till'    => self::describe($stale),
            ], 409);
        }

        $open = CashRegister::where('outlet_id', $outletId)
            ->where('opened_by', $user->id)
            ->where('status', 'open')
            ->exists();
        if (!$open) {
            return response()->json([
                'message' => 'Open your till for today before selling.',
                'code'    => self::NOT_OPEN,
            ], 422);
        }

        return null;
    }

    /**
     * Has she filed her end-of-day report for this till? Any submitted report
     * on it dated within its life counts: the trading day it opened (what the
     * morning pop-up writes) through today (what a same-day close writes).
     * Before 2026-10-15 only a report dated TODAY counted, so a till left open
     * overnight could only be closed by writing today's report on it.
     */
    public static function reportFiled(CashRegister $till, int $userId): bool
    {
        return \Illuminate\Support\Facades\DB::table('cash_register_eod_reports')
            ->where('register_id', $till->id)
            ->where('user_id', $userId)
            ->whereDate('report_date', '>=', $till->opened_at?->toDateString() ?? today()->toDateString())
            ->whereDate('report_date', '<=', today()->toDateString())
            ->whereNotNull('submitted_at')
            ->exists();
    }

    /** What the POS needs to walk her through closing it. */
    public static function describe(CashRegister $till): array
    {
        return [
            'id'          => $till->id,
            'outlet_id'   => $till->outlet_id,
            'outlet_name' => $till->outlet?->name,
            'opened_at'   => $till->opened_at?->toIso8601String(),
            // The trading day it belongs to — the date its report is written for.
            'trading_day' => $till->opened_at?->toDateString(),
        ];
    }
}
