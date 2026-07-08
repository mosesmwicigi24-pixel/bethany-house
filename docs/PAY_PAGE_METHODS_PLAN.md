# Customer payment page — payment methods plan

**Branch:** `feat/pay-page-methods` (off `main`). `main` auto-deploys to prod, so this
customer-facing **payment** work stays isolated until reviewed, credential-ready, and
tested. Fuse to `main` only when a coherent, safe unit is done.

The page in question is the link the WhatsApp agent (Neema) sends, e.g.
`https://hub.bethanyhouse.co.ke/pay/<token>`.

---

## Root cause: why the link shows no payment method

The pay page lists methods from the `payment_methods` table via
`PublicPaymentController::show()`, which excludes `type=other`, `type=cash`, and any
`is_active=0` row, then filters by the order's currency. **On the live DB every method
is excluded:**

| Method | code | type | is_active | Shown to customer? |
|---|---|---|---|---|
| Paystack | paystack | card | **0** | ✗ (inactive) |
| M-PESA | mpesa | mobile_money | **0** | ✗ (inactive) |
| Cash | cash | cash | 1 | ✗ (type=cash filtered) |
| I&M Paybill | inmpaybill | cash | 1 | ✗ (type=cash filtered) |

→ `available_methods` is empty → the customer sees *"No payment methods available.
Please contact the business."* (`PaymentLinkPage.tsx:270-273`).

## What already exists (reuse, don't rebuild)

- **Backend** `app/Http/Controllers/Api/PublicPaymentController.php`:
  `show` (30), `initiate` (91, routes to `initiateMpesa` 220 / `initiatePaystack` 307 /
  `initiateBankTransfer` 362), `status` (129), `mpesaCallback` (156), `uploadProof`
  (393), `confirmMpesa`, `verifyPaystack`.
- **Routes** `routes/api.php:114-122` — the `pay/{token}` group.
- **Frontend** `react-admin/src/pages/PaymentLinkPage.tsx` — full flows for M-Pesa STK
  (+ manual code confirm), Paystack (email→redirect→verify), and **bank transfer =
  "instructions → upload proof → pending admin approval"** (`BankTransferPanel` 755).
- Gateway credentials are already populated in `settings` (mpesa_*, paystack_*).
- `payment_methods` columns: `code, name, description, provider, is_active,
  supported_currencies (json), configuration (json), sort_order` + a `type` field used
  for filtering. **`configuration`/`description` can hold per-method instructions.**

The bank-transfer "instructions + proof" flow is **exactly** the pattern the manual
methods (Mukuru, Western Union/MoneyGram) need.

---

## Plan (3 parts, escalating risk)

### Part 1 — Manual / instructional methods  ← safest, biggest immediate unblock
Give customers a way to pay *now* with no gateway risk, reusing the proof-upload flow.

- **DB:** add customer-visible rows for **Mukuru**, **Western Union / MoneyGram**, and
  **M-Pesa-to-number/paybill**, each with pay-to details in `configuration`
  (or `description`), `is_active=1`, and a customer-facing manual type.
- **Backend `show()`:** stop excluding manual methods — introduce a customer-facing
  "manual/instruction" method (e.g. type `manual_transfer`, or a per-method
  `customer_visible` flag) and return each method's `instructions` payload.
- **Backend `initiate()`:** route the manual methods through the existing
  record-intent → proof-upload path (generalize `initiateBankTransfer`).
- **Frontend:** make `BankTransferPanel` instructions **data-driven** (render from the
  method's `configuration.instructions` instead of the hardcoded "Transfer to
  {business}") so each method shows its own pay-to details. Add icons/labels in the
  `COPY` map (`PaymentLinkPage.tsx:223-260`) + method matchers (100-102).
- **Known content (provided):**
  - Mukuru App → pay to **+254727891989**
  - Western Union / MoneyGram → **Moses Mwicigi**, **+254727891989**
  - M-Pesa manual → *need the paybill/till/number + steps* (see "Need from you").

### Part 2 — Turn on the automated gateways (M-Pesa STK, Paystack)
- Flip `is_active=1` for `mpesa` and `paystack` (customer-facing, currency-matched).
- ⚠️ **Real-money checks first:**
  - `mpesa_environment` is currently **`sandbox`** (test mode — will not take real
    money). Going live needs the **production** Daraja shortcode + passkey +
    consumer key/secret.
  - Confirm Paystack keys are **live** (`pk_live_…`/`sk_live_…`), not test.
- No secrets in the repo — they live in `settings` (admin UI) / env.

### Part 3 — PayPal (new integration)
- Not wired today (no code, no credentials). Add a `paypal` branch to `initiate()`
  (create-order via PayPal REST), a capture/webhook verify, config, and a frontend
  redirect+return flow mirroring Paystack. For USD / diaspora buyers.
- Needs PayPal REST **client id + secret** (live) + the receiving account currency.

## Recommended sequence
Part 1 → ship first (unblocks customers, safe). Part 2 → once production M-Pesa +
live Paystack confirmed. Part 3 → PayPal as a follow-up once credentials are in hand.

## Need from you (to build/enable)
- [ ] **M-Pesa manual** pay-to for the instructional method: paybill/till/number + any steps.
- [ ] **M-Pesa STK go-live:** production Daraja shortcode + passkey + consumer key/secret
      (or confirm "keep sandbox for testing for now").
- [ ] **Paystack:** confirm live keys are the ones in settings (or provide live keys).
- [ ] **PayPal:** REST client id + secret (live) + receiving currency — only if doing Part 3.
- [ ] **Western Union / MoneyGram:** confirm receiver details — name **Moses Mwicigi**,
      phone **+254727891989**; WU usually also needs receiver **city + country** (Nairobi,
      Kenya?) — confirm exact wording to display.
- [ ] **Mukuru:** confirm "pay to +254727891989" wording + any account name.
- [ ] Which methods to show and in what **order** (sort_order), and per currency (KES/USD).

Credentials should be entered via the admin settings UI / env, never committed here.
