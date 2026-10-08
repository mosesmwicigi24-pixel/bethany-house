<?php

namespace App\Http\Middleware;

use App\Support\ReportInput;
use Closure;
use Illuminate\Http\Request;

/**
 * One date contract for Reports, whichever spelling the caller knows (D4).
 *
 * Reports grew three conventions that do not agree:
 *
 *   executive endpoints     `from` / `to`
 *   legacy endpoints        `start_date` / `end_date`, else the last 30 days
 *   intelligence endpoints  `period`, ignoring from/to unless period=custom
 *
 * A caller who used the wrong pair got a DIFFERENT window back — silently,
 * with a 200 and a plausible number. That is the failure the brief's
 * invariant exists to prevent, and it was live: a link copied from the
 * Executive page into a Sales URL, a saved bookmark, or our own frontend
 * sending `from`/`to` to a legacy route all quietly answered for the last
 * thirty days instead of the window on screen.
 *
 * Normalising here rather than in nineteen validate() blocks means the next
 * report endpoint inherits the fix instead of repeating the bug. Nothing is
 * overwritten: a caller who sends a pair explicitly keeps it, and the
 * controllers' own validation still runs afterwards.
 *
 * Unifying the three properly — one parameter, unknown ranges rejected —
 * belongs to the consolidation. This stops the lying in the meantime.
 */
class NormalisesReportWindow
{
    public function handle(Request $request, Closure $next)
    {
        // Refuse malformed input at the door, before anything is mirrored or
        // queried (cycle 8): a bad date or outlet used to reach Postgres and
        // come back as a 500 on twelve legacy endpoints. See ReportInput.
        $day = [];
        foreach (['start_date', 'end_date', 'from', 'to'] as $field) {
            $day[$field] = ReportInput::date($field, $request->query($field));
        }
        ReportInput::outletId($request->query('outlet_id'));

        // Both spellings, different days: the legacy pages read start_date, the
        // executive ones read from, so one URL answered for two windows on two
        // pages (cycle 10). Mirroring below fills a MISSING spelling; a
        // contradiction is the caller's to resolve, not ours to pick.
        foreach ([['start_date', 'from'], ['end_date', 'to']] as [$a, $b]) {
            if ($day[$a] !== null && $day[$b] !== null && $day[$a] !== $day[$b]) {
                abort(422, "{$a} and {$b} name different days. Send one of them.");
            }
        }

        $mirror = function (string $a, string $b) use ($request) {
            if ($request->filled($a) && ! $request->filled($b)) {
                $request->merge([$b => $request->get($a)]);
            }
        };

        $mirror('start_date', 'from');
        $mirror('from', 'start_date');
        $mirror('end_date', 'to');
        $mirror('to', 'end_date');

        // An explicit window means a custom period. Without this, the
        // intelligence endpoints take `from`/`to`, ignore them, and answer
        // for this month — the worst of the three failures, because the
        // caller supplied exactly the dates they wanted.
        if ($request->filled('from') && $request->filled('to') && ! $request->filled('period')) {
            $request->merge(['period' => 'custom']);
        }

        return $next($request);
    }
}
