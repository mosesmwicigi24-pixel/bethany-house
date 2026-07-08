# Neema — multichannel identity (epic)

**Branch:** `claude/neema-multichannel-identity-7xqj2u` (off `main`). `main`
auto-deploys, so this stays isolated until each slice is reviewed and safe.

Neema is the conversational agent that takes orders for Sonalux Store. Today it
reaches shoppers over **WhatsApp only**, and it identifies a shopper by their
**phone number** — the single `customers.phone` column. This epic gives Neema a
**channel-agnostic identity layer** so the same person is recognised whether they
message on WhatsApp, Instagram DM, or Facebook Messenger — the three channels
**Meta** operates through one Graph API.

---

## Why the phone column isn't enough

Each Meta channel stamps a message with a **different, channel-scoped identifier**
for the same human, and only one of them is a phone number:

| Channel | Contact id Meta gives us | Phone available? |
|---|---|---|
| WhatsApp | `wa_id` (the phone digits) | ✅ yes |
| Instagram DM | `IGSID` (Instagram-scoped id) + `@username` | ❌ no |
| Messenger | `PSID` (page-scoped id) | ❌ no |

A `phone` column cannot store an IGSID or a PSID, so an Instagram DM and a later
WhatsApp chat from one customer look like **two strangers**, orders scatter across
duplicate customer records, and Neema can't carry context between channels.

---

## The model: one customer, many identities

A new `customer_identities` table lets a customer own many channel identities.
`(provider, provider_uid)` is the natural key for "this contact on this channel"
and is **unique**, so resolving the same contact twice returns the same row.

```
customers ──< customer_identities
                 provider       whatsapp | instagram | messenger | facebook | sms | web
                 provider_uid   wa_id / IGSID / PSID           (unique with provider)
                 username       @handle                        (Instagram, ...)
                 display_name   platform profile name
                 phone_e164     +2547…      (when the channel exposes one)
                 profile        json snapshot
                 verified_at    set when minted from a platform-authenticated source
                 last_interaction_at
```

`App\Enums\IdentityProvider` enumerates the channels and flags the **Meta** group
(`isMeta()`), whose ids arrive platform-authenticated and are therefore recorded
`verified`.

---

## Resolution rules (`CustomerIdentityResolver`)

Given an inbound `{provider, provider_uid, phone?, username?, name?, profile?}`:

1. **Seen before?** Exact `(provider, provider_uid)` match → return that customer,
   refreshing the handle/phone/name we now know and stamping `last_interaction_at`.
2. **Same person, new channel?** No identity yet, but a **shared phone** (E.164,
   Kenyan-local-tolerant) matches an existing customer → link the new identity to
   that customer. WhatsApp's `wa_id` doubles as the phone, so a WhatsApp contact
   auto-merges onto a customer already known by that number.
3. **New to us?** Otherwise create a customer (name split from the profile, phone
   backfilled when known) and hang the identity off it.

It **never** auto-merges two customers on a weak signal (e.g. a matching display
name). Cross-provider merges without a shared phone/email stay a deliberate,
manual action — see "Next" below.

Everything runs inside a transaction with `lockForUpdate()` on the identity row so
two concurrent messages from the same contact can't create duplicates.

---

## What shipped in this slice (foundation)

- `customer_identities` migration (additive; no change to live money/stock paths).
- `IdentityProvider` enum, `CustomerIdentity` model, `Customer::identities()`.
- `CustomerIdentityResolver` service (the rules above) + phone normalisation.
- API for the agent:
  - `POST /api/v1/admin/neema/identities/resolve` — resolve a contact to a
    customer (gated by `customers.create`). Neema calls this per conversation, then
    places the pending order against the returned `customer_id`.
  - `GET  /api/v1/admin/customers/{id}/identities` — list a customer's channels
    (gated by `customers.view`).
- Unit + feature tests (idempotency, phone merge, no-cross-collision, endpoints).

This is intentionally **decoupled from the POS/order write path**: the agent
resolves identity first, then reuses the existing `pending-order` endpoint with a
real `customer_id`. No live cashier flow is touched.

---

## Next (not in this slice)

1. **Meta webhook ingestion** — a verified webhook endpoint that receives WhatsApp
   / Instagram / Messenger events and feeds the resolver directly (signature
   verification, per-channel payload mapping).
2. **Manual identity merge/unmerge** — admin action to fold duplicate customers
   together (and split a mistaken link), with an audit trail.
3. **Conversation threading** — tie the existing `channels`/`channel_messages`
   chat tables to `customer_identities` so staff see one thread per person across
   channels.
4. **Outbound routing** — send Neema replies/payment links back out on the channel
   the customer used, keyed off the resolved identity.
5. **Email/social as additional merge signals** beyond phone.
