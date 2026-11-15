# Storefront Neema bridge — leads & shipping (IMPLEMENTED)

Implements the storefront team's **HUB_CONTRACT v1** — the two endpoints the
Neema assistant already calls server-side. Before this, both fell back to a
WhatsApp handoff (nothing lost); now they persist.

| Endpoint | Method | Handler | Auth |
|---|---|---|---|
| `/api/v1/storefront/leads` | POST | `StorefrontLeadController::store` | optional `X-Storefront-Key` |
| `/api/v1/storefront/shipping/estimate` | GET | `StorefrontLeadController::shippingEstimate` | optional `X-Storefront-Key` |

Both live in the **public** storefront-bridge route group (same place as
`POST /storefront/orders`), throttled, and called **server-side by the
storefront only**.

## Leads (`POST /storefront/leads`)
- Persists to the `leads` table; returns `{ "lead": { "id": … } }` (201, or 200 on replay).
- **Idempotent** on `client_request_id` (unique index) — a retry returns the same lead.
- Only `customer.phone` is required; unknown `intent` is stored as `other`.
- `quote`/`high-readiness` leads notify owners (speed-to-lead) via `NotificationService::leadCaptured` — best-effort, the lead persists regardless.
- Lifecycle column `status`: `new → assigned → quoted → won | lost`.

## Shipping (`GET /storefront/shipping/estimate`)
- Data-driven from the `countries` table (`standard_shipping_cost`,
  `express_shipping_cost`, `estimated_delivery_days`, `free_shipping_threshold`,
  `is_shipping_enabled`). Resolves by `country_code` first, then free-text `country`.
- Currency by the orders rule (`Order::resolveCurrency`): `KE` → KES, else USD.
- Always returns `{ destination, options: [...] }` with `options` an **array**;
  unknown / shipping-disabled / unrated destinations return `options: []` + a
  `note` so the storefront still shows the destination and routes to staff.
- International destinations carry a customs/duties `note`.

## Auth (§6)
Set `HUB_STOREFRONT_KEY` in the Hub env to require `X-Storefront-Key: <secret>`
on both endpoints; unset ⇒ open (matches the storefront, which sends no header
today). When you set it, tell the storefront side the header name and it wires
the one line in `lib/hub.ts`.

Full request/response spec: the storefront repo's `docs/HUB_CONTRACT.md`.
Pinned by `tests/Feature/StorefrontLeadShippingTest.php` (the §4 acceptance checklist).

## Buying patterns (`GET /storefront/insights`) — HUB_CONTRACT §9

| Endpoint | Method | Handler | Auth |
|---|---|---|---|
| `/api/v1/storefront/insights` | GET | `StorefrontInsightsController::index` | public, `throttle:60,1` |

What the storefront may say about real purchases — "frequently bought
together" and best sellers — without a second definition of either:

```json
{
  "data": {
    "bought_together": { "altar-wine": ["communion-wafers", "communion-cups"] },
    "popular": ["study-bible", "altar-wine"]
  },
  "meta": { "window_days": 180, "generated_at": "2026-11-15T21:17:29+03:00" }
}
```

- Mined by `MetricEngine::storefrontAffinity()` from the **same pass** as the
  till's suggestions and the attach-rate report (`mineBasketPairs()`): recognised
  sales on every channel (till, web, chat, quotes that became orders), trailing
  180 days, anchor in ≥ 5 baskets, pair in ≥ 3, lift ≥ 1.2, attach ≥ 10%, top 5
  companions per product, strongest first.
- `popular` — products by number of baskets, most first, minimum 5 baskets, at most 48.
- **Slugs of published products only.** No ids, names, counts, rates or amounts;
  an unpublished product is never named, on either side of a pair.
- Unconverted quotations, cancelled, voided and refunded orders never count.
- Advisory: an empty feed is a normal answer (`bought_together` then arrives as
  `[]`), and a mining failure is reported and degrades to the empty feed —
  never a 5xx on a product page. Cached 10 minutes.

Pinned by `tests/Feature/StorefrontInsightsTest.php`.
