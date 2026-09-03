<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ChecksStorefrontKey;
use App\Http\Controllers\Controller;
use App\Models\InterestCart;
use App\Support\Phone;
use Illuminate\Http\Request;

/**
 * The interest ledger — Hub half of the storefront's HUB_CONTRACT §7.
 *
 * The storefront mirrors every Neema cart here as it changes, keyed on the
 * cross-channel token ("BH-XXXX") that the WhatsApp handoff message carries;
 * neema-ai reads the same rows to resume a cart on WhatsApp and transitions
 * the outcome when it closes there. All three endpoints are public
 * storefront-bridge routes called SERVER-SIDE only, behind the same optional
 * X-Storefront-Key gate as the leads bridge.
 *
 * Contract rules implemented here:
 *  - POST upserts on token (last write wins for cart contents);
 *  - status never moves backwards — an active_cart write cannot overwrite a
 *    converted online_order, and abandoned never overwrites a conversion;
 *  - rows are never deleted: "abandoned" is a status, not a delete.
 */
class StorefrontInterestCartController extends Controller
{
    use ChecksStorefrontKey;

    /** POST /storefront/interest-carts — upsert the live cart (§7a). */
    public function store(Request $request)
    {
        if ($resp = $this->rejectBadKey($request)) {
            return $resp;
        }

        $validated = $request->validate([
            'client_request_id'     => 'nullable|string|max:100',
            'token'                 => 'required|string|max:40',
            'channel'               => 'required|string|max:20',
            'visitor_id'            => 'nullable|string|max:80',
            'session_id'            => 'nullable|string|max:120',
            'status'                => 'nullable|in:active_cart,checkout_started',
            'customer'              => 'nullable|array',
            'customer.name'         => 'nullable|string|max:255',
            'customer.phone'        => 'nullable|string|max:40',
            'customer.church'       => 'nullable|string|max:160',
            'items'                 => 'required|array|min:1|max:40',
            'items.*.slug'          => 'required|string|max:200',
            'items.*.quantity'      => 'nullable|integer|min:1|max:9999',
            'items.*.measurements'  => 'nullable|array',
            'items.*.size'          => 'nullable|string|max:40',
            'subtotal'              => 'nullable|numeric|min:0',
            'currency'              => 'nullable|string|size:3',
            'source_path'           => 'nullable|string|max:500',
        ]);

        // Unknown channels are stored as-is nowhere: clamp to the known set so
        // the ledger's channel facets stay clean (mirrors leads' intent rule).
        $channel = in_array($validated['channel'], InterestCart::CHANNELS, true)
            ? $validated['channel'] : 'web';

        $incomingStatus = $validated['status'] ?? InterestCart::STATUS_ACTIVE;

        $cart    = InterestCart::where('token', $validated['token'])->first();
        $created = false;

        if (!$cart) {
            $cart    = new InterestCart(['token' => $validated['token'], 'channel' => $channel]);
            $created = true;
        }

        $cart->last_channel      = $channel;
        $cart->items             = array_values($validated['items']);
        $cart->client_request_id = $validated['client_request_id'] ?? $cart->client_request_id;
        if (array_key_exists('visitor_id', $validated) && $validated['visitor_id']) {
            $cart->visitor_id = $validated['visitor_id'];
        }
        if (array_key_exists('session_id', $validated) && $validated['session_id']) {
            $cart->session_id = $validated['session_id'];
        }
        if (array_key_exists('subtotal', $validated) && $validated['subtotal'] !== null) {
            $cart->subtotal = $validated['subtotal'];
        }
        if (!empty($validated['currency'])) {
            $cart->currency = strtoupper($validated['currency']);
        }
        if (!empty($validated['source_path'])) {
            $cart->source_path = $validated['source_path'];
        }
        $this->attachCustomer($cart, $validated['customer'] ?? null);

        // Forward-only status: a stale active_cart write must not reopen a
        // cart that has already converted or been closed as abandoned.
        if ($created || !$cart->statusWouldRegress($incomingStatus)) {
            $cart->status = $incomingStatus;
        }

        $cart->save();

        return response()->json([
            'interest_cart' => ['token' => $cart->token, 'status' => $cart->status],
        ], $created ? 201 : 200);
    }

    /** GET /storefront/interest-carts?token=… | ?phone=… — load & resume (§7b). */
    public function lookup(Request $request)
    {
        if ($resp = $this->rejectBadKey($request)) {
            return $resp;
        }

        $validated = $request->validate([
            'token' => 'nullable|string|max:40',
            'phone' => 'nullable|string|max:40',
        ]);

        if (!empty($validated['token'])) {
            $cart = InterestCart::where('token', $validated['token'])->first();
            if (!$cart) {
                return response()->json(['message' => 'No such cart.'], 404);
            }
            return response()->json(['interest_cart' => $this->format($cart)]);
        }

        if (!empty($validated['phone'])) {
            // Full-number canonical match only (Phone doc: never a last-N
            // fragment — international collisions).
            $canonical = Phone::canonical($validated['phone']);
            $carts = $canonical
                ? InterestCart::where('phone_canonical', $canonical)
                    ->orderByDesc('updated_at')->limit(50)->get()
                : collect();

            return response()->json([
                'interest_carts' => $carts->map(fn ($c) => $this->format($c))->values(),
            ]);
        }

        return response()->json(['message' => 'Provide token or phone.'], 422);
    }

    /** PATCH /storefront/interest-carts/{token} — transition the outcome (§7c). */
    public function transition(Request $request, string $token)
    {
        if ($resp = $this->rejectBadKey($request)) {
            return $resp;
        }

        $validated = $request->validate([
            'status'          => 'required|in:online_order,whatsapp_order,abandoned',
            'order_ref'       => 'nullable|string|max:60',
            'customer'        => 'nullable|array',
            'customer.name'   => 'nullable|string|max:255',
            'customer.phone'  => 'nullable|string|max:40',
            'customer.church' => 'nullable|string|max:160',
        ]);

        $cart = InterestCart::where('token', $token)->first();
        if (!$cart) {
            return response()->json(['message' => 'No such cart.'], 404);
        }

        $this->attachCustomer($cart, $validated['customer'] ?? null);
        if (!empty($validated['order_ref'])) {
            $cart->order_ref = $validated['order_ref'];
        }

        // "Won't regress a converted cart": abandoned never undoes a sale.
        if (!$cart->statusWouldRegress($validated['status'])) {
            $cart->status = $validated['status'];
            if (in_array($validated['status'], InterestCart::CONVERTED, true) && !$cart->converted_at) {
                $cart->converted_at = now();
            }
        }

        $cart->save();

        return response()->json([
            'interest_cart' => ['token' => $cart->token, 'status' => $cart->status],
        ]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** Fill identity fields as they become known; never blank an existing one. */
    private function attachCustomer(InterestCart $cart, ?array $customer): void
    {
        if (!$customer) {
            return;
        }
        if (!empty($customer['name'])) {
            $cart->name = $customer['name'];
        }
        if (!empty($customer['phone'])) {
            $cart->phone = $customer['phone']; // mutator stamps phone_canonical
        }
        if (!empty($customer['church'])) {
            $cart->church = $customer['church'];
        }
    }

    private function format(InterestCart $c): array
    {
        return [
            'token'      => $c->token,
            'status'     => $c->status,
            'channel'    => $c->channel,
            'items'      => $c->items,
            'subtotal'   => $c->subtotal !== null ? (float) $c->subtotal : null,
            'currency'   => $c->currency,
            'phone'      => $c->phone,
            'name'       => $c->name,
            'order_ref'  => $c->order_ref,
            'updated_at' => $c->updated_at?->toISOString(),
        ];
    }
}
