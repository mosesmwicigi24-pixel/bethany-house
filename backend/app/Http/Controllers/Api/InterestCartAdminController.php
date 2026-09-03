<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InterestCart;
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
}
