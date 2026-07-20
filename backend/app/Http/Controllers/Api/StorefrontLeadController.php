<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Lead;
use App\Models\Order;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The two storefront endpoints the Neema assistant calls (docs/HUB_CONTRACT.md).
 * Both are public storefront-bridge routes, called SERVER-SIDE by the storefront
 * only. Mirrors StorefrontCheckoutController: same base path, JSON style, and
 * idempotency-on-client_request_id.
 *
 * Optional shared-secret gate: if HUB_STOREFRONT_KEY is set, both endpoints
 * require an X-Storefront-Key header matching it; unset ⇒ open (the storefront
 * sends no header today — §6). The storefront treats any non-2xx as "not
 * available" and falls back to WhatsApp, so nothing is ever lost.
 */
class StorefrontLeadController extends Controller
{
    /** POST /storefront/leads — persist a Neema-captured lead (§1). */
    public function store(Request $request)
    {
        if ($resp = $this->rejectBadKey($request)) {
            return $resp;
        }

        $validated = $request->validate([
            'client_request_id'      => 'required|string|max:100',
            'intent'                 => 'required|string|max:40',
            'readiness'              => 'nullable|in:low,medium,high',
            'customer'               => 'nullable|array',
            'customer.name'          => 'nullable|string|max:255',
            'customer.phone'         => 'required|string|max:40',
            'customer.email'         => 'nullable|string|max:255',
            'customer.church'        => 'nullable|string|max:255',
            'location'               => 'nullable|array',
            'location.country_code'  => 'nullable|string|max:2',
            'location.city'          => 'nullable|string|max:120',
            'products'               => 'nullable|array',
            'products.*'             => 'string|max:200',
            'quantity'               => 'nullable|string|max:120',
            'message'                => 'nullable|string|max:5000',
            'source_path'            => 'nullable|string|max:500',
        ]);

        // Idempotency: same client_request_id → the same lead, never a duplicate.
        $existing = Lead::where('client_request_id', $validated['client_request_id'])->first();
        if ($existing) {
            return response()->json(['lead' => ['id' => $existing->id]], 200);
        }

        // Unknown intents are stored as 'other', never rejected (§ field notes).
        $intent = in_array($validated['intent'], Lead::INTENTS, true) ? $validated['intent'] : 'other';

        $lead = Lead::create([
            'client_request_id' => $validated['client_request_id'],
            'intent'            => $intent,
            'readiness'         => $validated['readiness'] ?? 'medium',
            'name'              => $validated['customer']['name']   ?? null,
            'phone'             => $validated['customer']['phone'],
            'email'             => $validated['customer']['email']  ?? null,
            'church'            => $validated['customer']['church'] ?? null,
            'country_code'      => isset($validated['location']['country_code'])
                ? strtoupper($validated['location']['country_code']) : null,
            'city'              => $validated['location']['city'] ?? null,
            'products'          => $validated['products'] ?? null,
            'quantity'          => $validated['quantity'] ?? null,
            'message'           => $validated['message'] ?? null,
            'source_path'       => $validated['source_path'] ?? null,
            'status'            => 'new',
        ]);

        // Speed-to-lead: nudge staff for hot leads (quote intent or high readiness).
        if ($lead->intent === 'quote' || $lead->readiness === 'high') {
            try {
                NotificationService::leadCaptured($lead->id, $lead->name ?: $lead->phone, $lead->intent);
            } catch (\Throwable) {
                // Notification is best-effort; the lead is already persisted.
            }
        }

        return response()->json(['lead' => ['id' => $lead->id]], 201);
    }

    /** GET /storefront/shipping/estimate — options to a destination (§2). */
    public function shippingEstimate(Request $request)
    {
        if ($resp = $this->rejectBadKey($request)) {
            return $resp;
        }

        $validated = $request->validate([
            'country_code' => 'nullable|string|max:2',
            'country'      => 'nullable|string|max:120',
            'city'         => 'nullable|string|max:120',
            'items'        => 'nullable|string|max:500',
        ]);

        $country = $this->resolveCountry($validated['country_code'] ?? null, $validated['country'] ?? null);
        $city    = trim($validated['city'] ?? '');

        // Human-readable destination — "City, Country" / "Country" / the raw input.
        $countryName = $country?->name ?? ($validated['country'] ?? ($validated['country_code'] ?? 'your area'));
        $destination = $city !== '' ? "{$city}, {$countryName}" : $countryName;

        // Currency by the orders rule: KE → KES, else USD.
        $currency = Order::resolveCurrency($country?->code ?? ($validated['country_code'] ?? ''));

        // No shippable data (unknown country, or shipping disabled) → manual quote.
        if (!$country || !$country->is_shipping_enabled) {
            return response()->json([
                'destination' => $destination,
                'options'     => [],
                'note'        => 'We ship here by manual quote — a team member will confirm the rate.',
            ]);
        }

        $options = [];
        $days = (int) ($country->estimated_delivery_days ?? 0);
        $std  = $country->standard_shipping_cost;
        $exp  = $country->express_shipping_cost;
        $freeThreshold = $country->free_shipping_threshold;

        if ($std !== null) {
            $range = $days > 0 ? ($days <= 2 ? "{$days} day" . ($days === 1 ? '' : 's') : "{$days}–" . ($days + 3) . ' days') : '3–7 days';
            $cost  = ($freeThreshold !== null && (float) $freeThreshold > 0)
                ? 'Free over ' . $this->money($currency, (float) $freeThreshold)
                : $this->money($currency, (float) $std);
            $options[] = ['service' => 'Standard', 'range' => $range, 'cost' => $cost];
        }
        if ($exp !== null && (float) $exp > 0) {
            $expDays = $days > 0 ? max(1, (int) floor($days / 2)) : 3;
            $options[] = [
                'service' => 'Express',
                'range'   => $expDays <= 2 ? "{$expDays} day" . ($expDays === 1 ? '' : 's') : "{$expDays}–" . ($expDays + 2) . ' days',
                'cost'    => $this->money($currency, (float) $exp),
            ];
        }

        // Shipping enabled but no rates configured → still show the destination,
        // route to staff for the quote.
        if (empty($options)) {
            return response()->json([
                'destination' => $destination,
                'options'     => [],
                'note'        => 'Rates for this destination are confirmed by our team — we\'ll follow up with a quote.',
            ]);
        }

        $payload = ['destination' => $destination, 'options' => $options];
        if ($currency !== 'KES') {
            $payload['note'] = 'Import duties and taxes on arrival are the customer\'s responsibility.';
        }

        return response()->json($payload);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function resolveCountry(?string $code, ?string $name): ?Country
    {
        if ($code) {
            $c = Country::whereRaw('UPPER(code) = ?', [strtoupper(trim($code))])->first();
            if ($c) {
                return $c;
            }
        }
        if ($name) {
            return Country::whereRaw('LOWER(name) = ?', [strtolower(trim($name))])->first();
        }
        return null;
    }

    /** "KES 300" / "USD 48" — thousands-separated, no decimals. */
    private function money(string $currency, float $amount): string
    {
        return $currency . ' ' . number_format($amount);
    }

    /**
     * When HUB_STOREFRONT_KEY is configured, require a matching X-Storefront-Key.
     * Returns a 401 response to short-circuit, or null to proceed.
     */
    private function rejectBadKey(Request $request)
    {
        $secret = config('services.storefront.key');
        if (!$secret) {
            return null; // open until a key is configured (§6)
        }
        if (!hash_equals($secret, (string) $request->header('X-Storefront-Key'))) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        return null;
    }
}
