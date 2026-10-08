<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InterestCart;
use App\Services\DataScopeResolver;
use App\Support\Phone;
use Illuminate\Http\Request;

/**
 * Staff lookup over the interest ledger: paste the "cart BH-XXXX" token from
 * a customer's WhatsApp handoff message and see their cart in five seconds.
 *
 * Gated by orders.view — the person who serves a walk-in at the till is
 * exactly the person who answers the WhatsApp handoff. Pipeline truth only:
 * these are expressions of interest, shown apart from orders and never in
 * any sales, cash, or receivables figure.
 */
class InterestCartAdminController extends Controller
{
    /** GET /admin/interest-carts — search / list, newest first. */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q'        => 'nullable|string|max:60',
            'status'   => 'nullable|string|max:30',
            'channel'  => 'nullable|string|max:20',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = InterestCart::query()->orderByDesc('updated_at');

        // Phase 4A scope. A cart is a web/WhatsApp expression of interest and
        // carries NO outlet, so there is no "this shop's carts" to list. A
        // caller bounded to outlets (outlet manager) or to their own work
        // (cashier) gets:
        //   - the cart whose token the customer hands them — record-specific,
        //     the exact token, which is what the till actually does; and
        //   - carts that became an order at one of their outlets.
        // Never a browsable list of every lead's name and phone.
        $bounded = DataScopeResolver::outletIdsForUnowned($request->user(), 'orders.view');
        if ($bounded !== null) {
            $token = $this->exactToken($validated['q'] ?? '');
            $query->where(function ($w) use ($token, $bounded) {
                $w->whereIn('order_ref', \App\Models\Order::withoutViewerScope()
                        ->whereIn('outlet_id', $bounded)->select('order_number'));
                if ($token !== null) {
                    // The storefront matches tokens case-insensitively and
                    // whole (StorefrontInterestCartController::findByToken);
                    // so does this, with or without the BH- prefix typed.
                    $w->orWhereIn(\Illuminate\Support\Facades\DB::raw('UPPER(token)'),
                        array_unique([$token, str_starts_with($token, 'BH-') ? substr($token, 3) : 'BH-' . $token]));
                }
            });
            $validated['q'] = null;   // the token was the whole search
        }

        if (!empty($validated['q'])) {
            $q = trim($validated['q']);
            // A token, a phone, or a name — one box answers all three. Tokens
            // match loosely (with or without the BH- prefix, any case); phones
            // match on the canonical join key, full number only.
            $canonical = Phone::canonical($q);
            $query->where(function ($w) use ($q, $canonical) {
                $w->where('token', 'ILIKE', "%{$q}%")
                  ->orWhere('name', 'ILIKE', "%{$q}%")
                  ->orWhere('order_ref', 'ILIKE', "%{$q}%");
                if ($canonical) {
                    $w->orWhere('phone_canonical', $canonical);
                }
            });
        }
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (!empty($validated['channel'])) {
            $query->where('channel', $validated['channel']);
        }

        $page = $query->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json($page);
    }

    /** A whole token as typed (no wildcards, no spaces), upper-cased; else null. */
    private function exactToken(string $q): ?string
    {
        $q = strtoupper(trim($q));

        return preg_match('/^[A-Z0-9][A-Z0-9_\-]{3,39}$/', $q) ? $q : null;
    }
}
