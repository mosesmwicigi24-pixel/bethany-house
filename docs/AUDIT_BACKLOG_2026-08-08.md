# Security & Financial Audit — backlog for Monday

Raised 2026-08-08 (Sat) by a five-vector adversarial audit of the hub. Deferred to
Monday by the owner. Nothing here is fixed yet.

Method: five parallel auditors over the real code, then every finding attacked by a
separate agent instructed to refute it. 19 survived refutation, 2 were refuted.
The three marked VERIFIED below were additionally confirmed by hand.

## Do these first, in this order

| # | Finding | Why first |
|---|---------|-----------|
| 1 | Unauthenticated M-Pesa callback (`api.php:122`) — **VERIFIED** | Live payment-authorisation bypass: a holder of a payment token can POST a success body and mark their own order paid. Money never arrives, ledger reports it collected. |
| 2 | Ledger balance clamped per bucket (`ReportController.php:419`) | Breaks `sales = paid + balance`. An over-paid order cross-subsidises an unpaid one and the debt disappears. Our own tests miss it because they never build an over-payment. |
| 3 | Channel scopes overlap (`Order.php:207`) | An `online` order at a WhatsApp outlet matches two branches, so weekly/monthly totals exceed the channel sum. |
| 4 | `normalize_phone` wrong merges (migration `2026_08_08_000001`) | `'0044 7401 182700'` -> `+2540447401182700`; `'MPESA REF 123456789'` -> `+254123456789`. This is the silent-merge failure the function exists to prevent. |
| 5 | Contrast + dead token classes | `surface-400` 3.13:1 across 1,242 sites; `surface-300` 1.47:1 as timestamps; `bg-brand` / `bg-surface` / `text-muted` compile to nothing. |

## Already exposed — rotate regardless of the code fixes

The VAPID **private** key is committed as an inline default in `docker-compose.yml:112`
and is in git history, so it is readable by anyone with repo access and by every CI
job. It was also printed into the audit transcript. Rotating the keypair is
independent of every fix below and takes minutes.

## Verified by hand (not just agent-reported)

- `api.php:122` — the M-Pesa callback sits in the public `pay` group with only
  `throttle:60,1`. No auth, no signature, no source-IP check.
- `docker-compose.yml:112` — VAPID private key present as an inline default.
- `EodReportsPage.tsx:564` — `dangerouslySetInnerHTML` renders clerk-authored HTML
  from the EoD "sentiments" field straight into the admin.

## Confirmed NOT broken (checked empirically, do not re-litigate)

- **Timezone.** Laravel writes Nairobi wall-clock into `timestamp without time zone`,
  so `DATE(created_at)` cuts the correct day. The UTC Postgres session timezone is
  irrelevant for a naive timestamp. Proven from the generated INSERT.
- **Scenario B** (two walk-ins with "refer to IANDM"): both normalise to NULL and
  stay uncounted rather than merging. Correct.
- **Scenario C** (stepper at 150% zoom): both stepper containers and the stage row
  carry `flex-wrap`, so controls wrap instead of clipping.

## Known cohort-vs-treasury gap (decide, do not "fix" blindly)

The payment-method breakdown buckets by ORDER date, inheriting the ledger's
receivables rule. But the widget's job is reconciling against a paybill statement,
which is treasury. Measured: I&M reads **1,348,140** in the report vs **1,382,990**
on a statement for the same window — a **34,850** gap, because 64 of 390 payments
settle on a different day from their order. Either bucket that widget by payment
date, or label it as cohort. Do not silently force the two to agree.

## Full finding list

### Critical — Stored XSS: POS clerk's EoD "sentiments" HTML is rendered unescaped in the admin console
`EodReportsPage.tsx:564`

**Breaks:** `sentiments` is free HTML from a WYSIWYG (`UserEodModal.tsx:628` RichEditor), accepted with no sanitisation at `PosController.php:1855` (`'sentiments' => 'nullable|string|max:20000'`), persisted verbatim at `PosController.php:1888/1900`, and rendered as raw HTML here. Writing it needs only `pos.access` (`routes/api.php:731`); reading the detail is `settings.view` (`routes/api.php:734`). A cashier 

**Fix:** Sanitise on write (server is the only place both readers share), then keep the render defensive.

backend — composer require mews/purifier, then in PosController::saveUserEodReport replace both `'sentiments' => $validated['sentiments'] ?? null,` (lines 1888 and 1900) with:

    'sentiments' => isset($validated['sentiments'])
        ? \Mews\Purifier\Facades\Purifier::clean($validated['sentiments']

### Critical — Public M-Pesa callback is unauthenticated — the payer can settle their own order for free
`PublicPaymentController.php:201`

**Breaks:** POST /api/v1/pay/{token}/mpesa-callback (routes/api.php:122) has no auth, no signature, no source-IP check — its only middleware is throttle:60,1. Settlement is decided entirely by attacker-controlled request body. The attacker is the customer: (1) GET /pay/{token} — they already hold the token, it is in the link they were sent; (2) POST /pay/{token}/initiate with method=mpesa — initiateMpesa retu

**Fix:** Stop routing the callback on a value the customer knows, and stop handing the customer the correlation id.

1) Migration — add a server-only secret to payments:
```php
// database/migrations/2026_08_09_000001_add_callback_secret_to_payments.php
public function up(): void {
    Schema::table('payments', function (Blueprint $t) {
        $t->string('callback_secret', 64)->nullable()->unique()->after

### Critical — Topbar breadcrumb cannot yield, so the user-menu button is pushed out of an overflow-hidden shell and clipped away on phones
`Topbar.tsx:592`

**Breaks:** The header (Topbar.tsx:554) is `flex items-center px-4 gap-4` with NO flex-wrap and NO overflow control. Of its three children, the hamburger is floored at 44px by `.btn-icon`'s `min-w-[44px]`, the actions group (line 625, `ml-auto flex items-center gap-2`) has no `shrink-0` but is floored at its own min-content, and the breadcrumb `<nav>` has neither `min-w-0`, `overflow-hidden`, nor `truncate` —

**Fix:** // Topbar.tsx:592 — the breadcrumb must be the thing that yields.
// `min-w-0 overflow-hidden` drops its automatic minimum size to 0 so it can
// shrink to nothing instead of shoving the actions past the clip edge;
// the trailing crumb truncates, the ancestor crumbs give way first.
                <nav
                    aria-label="Breadcrumb"
                    className="flex min-w-0 flex-1 

### High — normalize_phone reads the `00` international access prefix as the Kenyan trunk `0`, splitting a customer and emitting impossible E.164
`2026_08_08_000001_add_normalize_phone_function.php:64`

**Breaks:** Verified against PostgreSQL 16 with the function loaded verbatim from this migration:

  '0044 7401 182700'  -> '+2540447401182700'   (16 digits — no such number exists)
  '+44 7401 182700'   -> '+447401182700'
  '00254722123456'    -> '+2540254722123456'
  '+254722123456'     -> '+254722123456'

The migration's doc-block says the dataset already holds +44 numbers. `00` is how an international num

**Fix:** Recognise both international notations before the national-trunk rule, and reject anything outside E.164 (verified: this returns the identical value for all six variants in `test_the_same_kenyan_number_normalises_to_one_value`, all three in `test_foreign_numbers_keep_their_own_country_code`, keeps `+254712345678` distinct from `+255712345678`, and returns NULL for every string in `test_clerk_notes

### High — normalize_phone has a digit floor but no E.164 ceiling — any string with 9+ digits becomes a "customer", violating R4
`2026_08_08_000001_add_normalize_phone_function.php:75`

**Breaks:** The function guards the short end (line 54, `IF length(digits) < 9 THEN RETURN NULL`) but never the long end, so it emits keys that cannot be phone numbers. Verified against PostgreSQL 16:

  '0722123456 / 0733987654'  -> '+2547221234560733987654'  (22 digits)
  '0722123456 ext 201'       -> '+254722123456201'         (15 digits, wrong number)
  'receipt no 20240815001'   -> '+20240815001'

Both o

**Fix:** Bound the international branch to E.164 and stop falling through to a catch-all. Within the corrected function (see the `00`-prefix finding), the two guards that close this are:

        IF intl OR left(digits, 3) = '254' THEN
            IF length(digits) BETWEEN 8 AND 15 AND left(digits, 1) <> '0' THEN
                RETURN '+' || digits;
            END IF;
            RETURN NULL;
        END

### High — customer_phone validation permits 30 chars into a varchar(20) column — a common two-number entry 500s the sale
`OrderController.php:1513`

**Breaks:** `orders.customer_phone` is `$table->string('customer_phone', 20)` (`database/migrations/2024_01_01_000027_create_orders_table.php:27`) and no migration ever widens it. Validation allows 30 here and at `PosController.php:512`, `:2910`, `:3263`, so a 21-30 char value passes validation and then dies at the INSERT. Verified on PostgreSQL 16: `INSERT INTO t(customer_phone varchar(20)) VALUES ('07221234

**Fix:** Widen the columns to match the intent rather than shrinking the rule — a 30-char phone field is genuinely useful, and truncating a customer's second number silently is worse than either.

New migration `database/migrations/2026_08_09_000001_widen_customer_contact_columns.php`:

    public function up(): void
    {
        DB::statement('ALTER TABLE orders     ALTER COLUMN customer_phone      TYPE 

### High — `bg-brand` is not a real class — the filter-count badge renders white-on-nothing
`PaymentTransactionsPage.tsx:337`

**Breaks:** `brand` in tailwind.config.js:14 defines only numeric steps 50–950 and no `DEFAULT` key, so Tailwind emits no `.bg-brand` rule at all. Verified by compiling the real content glob (`npx tailwindcss -i src/index.css`): `^\.bg-brand\s*{` → 0 matches, same for `.text-brand`. The badge therefore has no background; `text-white` paints the digit on the button's transparent background, which sits on the w

**Fix:** Replace every bare `-brand` utility with an explicit step:
- L192: `color="text-brand-700"`   // #b23514, 6.15:1 on white
- L330: `(showFilters || activeFilters > 0) && "text-brand-700",`
- L337: `<span className="w-4 h-4 bg-brand-500 text-white text-2xs rounded-full flex items-center justify-center font-bold">{activeFilters}</span>`
- L436: `className="text-xs font-semibold text-brand-700 hover:u

### High — `bg-surface` is not a real class — the product-search dropdown renders as a transparent panel
`QuotationsPage.tsx:483`

**Breaks:** `surface` (tailwind.config.js:38) has `canvas`, `0`, `50`…`950` but no `DEFAULT`, so `.bg-surface` is never generated (0 matches in the compiled stylesheet). Both quotation product-search dropdowns — this one and the per-row one at :528 (`absolute z-20 … max-h-56 overflow-auto rounded-md border bg-surface shadow-lg`) — are absolutely positioned over the line-items table with **no background**. The

**Fix:** Use the explicit white step on both dropdowns:
- L483: `<div className="absolute z-10 mt-1 w-full rounded-md border border-line bg-surface-0 shadow-pop">`
- L528: `<div className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border border-line bg-surface-0 shadow-pop">`

### High — Filled status chips and toasts: white on `warning` is 2.94:1, on `success` 3.30:1
`Toast.tsx:8`

**Breaks:** `warning.DEFAULT` = #ca8a04 and white text measures **2.94:1**; `success.DEFAULT` = #16a34a measures **3.30:1** (Toast.tsx:6). The toast body is `text-sm` (14px, index.css fontSize `sm` = 0.875rem) — normal text, so AA requires 4.5:1. Both fail. The same two pairings are the active state of every status filter row (StatusTabs.tsx:47 `success: "bg-success text-white border-success"`, :48 `warning: 

**Fix:** Move the white-text fills one to three steps darker — the ramps already exist:
```ts
// components/ui/Toast.tsx
const variantStyles: Record<ToastVariant, string> = {
  success: 'bg-success-700 text-white',   // #15803d — 5.02:1
  error:   'bg-danger text-white',        // #dc2626 — 4.83:1 (already passing)
  warning: 'bg-warning-800 text-white',   // #854d0e — 6.85:1
  info:    'bg-info text-white

### High — Offline banner is white on amber-500 — 2.15:1 on the app's only connectivity warning
`PWAInstallBanner.tsx:28`

**Breaks:** `amber-500` now resolves to the token #f59e0b (tailwind.config.js:116 gave the amber object numeric keys, so this stopped falling through to stock Tailwind). White on #f59e0b measures **2.15:1** at `text-xs` (12px) — less than half the 4.5:1 AA floor and below even the 3:1 large-text floor. This is a `role="status" aria-live="polite"` banner telling the user their writes are queued and unsynced; o

**Fix:** ```tsx
"bg-amber-800 text-white text-xs font-semibold py-1.5 px-4",   // #92400e — 8.11:1
```
The pulse dot below it (`bg-white`) stays legible against amber-800.

### High — `text-surface-400` is used as body/data text at 3.13:1 — 1,242 sites, 856 of them at 11–14px
`SalesReportPage.tsx:554`

**Breaks:** surface-400 = #8c9489: **3.13:1 on white**, **2.81:1 on the page canvas** (`bg-surface-canvas` #f2f3f1, set at AdminLayout.tsx:201), **3.02:1 on `bg-field` #fafbfa**. tailwind.config.js:63 concedes the token is "used ~1178x, much of it on text" and was tuned only to "clear the 3:1 bar for UI and large text" — but `grep` finds 1,242 `text-surface-400` sites, 856 of which carry `text-2xs`/`text-xs`/

**Fix:** Two moves. (1) Reserve surface-400 for borders and decorative glyphs; make surface-500 the metadata text token and darken it so it clears 4.5:1 against the canvas as well as white — in tailwind.config.js:38 replace `500: '#70786d',` with:
```js
500: '#687066',   // 5.12:1 on white, 4.60:1 on surface-canvas (#f2f3f1)
```
(2) Codemod the text sites: `rg -l 'text-surface-400' src | xargs sed -i '' -E

### High — `text-surface-300` is used as content text at 1.47:1 — effectively invisible
`ProductionOrderDetailPage.tsx:1587`

**Breaks:** surface-300 = #d2d6d1 measures **1.47:1 on white** and **1.41:1 on surface-50**. 186 `text-surface-300` sites exist and they are not decorative: this one is the timestamp on every production-order message, :1108 is the `Unassigned` state label (`text-surface-300 italic text-2xs`), :1564 is the empty-state help text, :1653/:1658/:1663 are the composer's status hints, and PaymentTransactionsPage.tsx

**Fix:** Retire surface-300 as a text colour. `rg -l 'text-surface-300' src | xargs sed -i '' -E 's/text-surface-300/text-surface-500/g'`, then re-apply surface-300 only where the element is a non-text glyph that still needs 3:1 (e.g. ProductionOrderDetailPage.tsx:344, the remove-icon button — that one should go to `text-surface-500` too, since 1.47:1 fails 1.4.11 for an interactive icon). For the specific

### High — Sales heatmap: order counts printed white on brand-300 at 1.97:1 and brand-600 on brand-100 at 3.66:1
`SalesReportPage.tsx:760`

**Breaks:** The hour-of-day heatmap prints the actual order count inside each cell (`{d?.orders ?? 0}`, `text-xs font-mono`). For intensity 4–6 the cell is brand-300 #ffa17d with white text: **1.97:1**. For intensity 1–3 (line 758, `bg-brand-100 text-brand-600`) it is #d8431a on #ffe4d8: **3.66:1**. Both are normal text needing 4.5:1, and the mid band is the one most cells land in, so the middle of the distri

**Fix:** Keep the fill ramp for the heat signal and fix only the ink, which is what carries the value:
```tsx
intensity === 0
    ? "bg-surface-50 text-surface-500"
    : intensity <= 3
      ? "bg-brand-100 text-brand-800"    // #8f2c13 on #ffe4d8 — 6.51:1
      : intensity <= 6
        ? "bg-brand-300 text-brand-950"  // #3f1106 on #ffa17d — 6.87:1
        : "bg-brand-600 text-white",     // 5.09:1 — unc

### High — Prior-period comparison always spans one day MORE than the current period (Carbon 3 float diff)
`ReportController.php:100`

**Breaks:** composer.lock pins nesbot/carbon 3.11.1, where diffInDays() returns a signed FLOAT (vendor/nesbot/carbon/src/Carbon/Traits/Difference.php: `public function diffInDays(...): float`), not the absolute int Carbon 2 returned. dateRange() always appends ' 23:59:59' to $end (line 89), so the diff is never integral. Range 2026-07-10 .. 2026-08-08 23:59:59: diffInDays = 29.99998842592593, $days = 30.99998

**Fix:**     private function priorPeriod(string $start, string $end): array
    {
        // Carbon 3 returns diffIn*() as a signed FLOAT. $end always carries
        // ' 23:59:59' (see dateRange()), so diffInDays() gave 29.99998... and
        // +1 gave 30.99998...; subDays() then cascaded the fraction into
        // 23h59m59s and pushed priorStart a whole day early, making EVERY
        // comparison

### High — GREATEST() clamps the balance per GROUP, not per order — it breaks `sales = paid + balance` and silently nets over-payment against other orders' debt
`ReportController.php:419`

**Breaks:** Two distinct failures, both from clamping the aggregate instead of each order. (a) Cross-subsidy: Order A total 100 / paid 150, Order B total 100 / paid 0, same day and channel. The row reports sales 200, paid 150, balance 50. True receivables are 100 — B's debt is erased by A's excess, understating what is owed with no trace. (b) R1 breaks outright: a bucket with a single over-paid order (total 1

**Fix:**         // Clamp PER ORDER, not per group. GREATEST(SUM(total) - SUM(paid), 0)
        // let one over-paid order cancel another order's outstanding balance,
        // and a bucket that nets over-paid clamped to 0 so sales != paid +
        // balance — the one thing this ledger promises. Over-payment is
        // reachable today: the public pay link charges the FULL total on an
        // order

### High — Sales ledger double-counts an online order taken at a WhatsApp outlet: it matches two channel scopes at once
`Order.php:207`

**Breaks:** The three branches are not mutually exclusive. An order with order_type='online' AND outlet_id in a sales_channel='whatsapp' outlet satisfies the 'online' branch (line 206) and the 'whatsapp' branch simultaneously. Only 'pos' was made disjoint (its whereNotIn guard, lines 215-217). salesLedger calls $scoped($c) once per channel (ReportController.php:425 and 472), so that order's value is added to 

**Fix:**         return match ($channel) {
            // The three channels must PARTITION the order set — salesLedger sums
            // them into weekly/monthly totals, so any overlap is double-counted
            // money. Online is order_type-driven and wins outright (see the
            // 2026_07_07_120000_add_sales_channel_to_outlets migration note);
            // before this, an order created wi

### High — Eight revenue endpoints still filter payment_status='paid' — the filter that was removed from the summary for erasing every part-paid order
`ReportController.php:519`

**Breaks:** salesSummary and salesLedger adopted the sales truth 'any non-voided, non-cancelled order' (line 169: whereNotIn('orders.status', ['voided','cancelled'])) precisely because, in this file's own words at lines 163-165, "The old payment_status='paid' filter silently erased every part-paid and deposit order from 'revenue'." That filter is still live in salesByProduct (519), salesByCategory (569), sale

**Fix:** // Replace the payment_status filter with the same sales truth salesSummary and
// salesLedger use, at every site listed above. For salesByProduct (line 519):

            ->whereNotIn('orders.status', ['voided', 'cancelled'])

// unqualified in the DB::table('orders') / profitLoss / revenue / dashboardKPIs
// queries that have no join alias:

            ->whereNotIn('status', ['voided', 'cancell

### High — Every /api/v1/admin/* comms endpoint — dashboard sales, global search, internal comments — is open to any authenticated user, including self-registered storefront customers
`api.php:271`

**Breaks:** This group carries only auth:sanctum (inherited from line 224) and a throttle. There is no role, permission, or staff check anywhere in it, and none of its controllers add one. POST /api/v1/auth/register (AuthController.php:26) is public, creates the user with status 'active', and immediately returns a Sanctum token with ['*'] abilities (User::createAuthToken, User.php:221). So any stranger on the

**Fix:** Gate the group on staff membership; User::canAccessAdmin() (User.php:193) already exists and is deliberately NOT enforced by the customer login.

1) New middleware app/Http/Middleware/EnsureStaff.php:
```php
<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaff
{
    public function handle(Request $request,

### High — Trusted-proxy list covers every RFC1918 range, so any sibling container or host process can spoof X-Forwarded-For and defeat the rate limiters
`app.php:44`

**Breaks:** The comment above this line claims the broad list 'stays correct if the app is ever exposed more widely'. It does not. Laravel/Symfony honours X-Forwarded-For whenever REMOTE_ADDR matches a trusted entry. The Laravel container joins bethany_network, a plain bridge network with no pinned subnet (docker-compose.yml, networks: bethany_network: driver: bridge), and shares it with nextjs, react-admin, 

**Fix:** Trust one address: the bridge gateway nginx actually arrives from. Pin the subnet so that address is stable.

docker-compose.yml:
```yaml
networks:
    bethany_network:
        driver: bridge
        ipam:
            config:
                - subnet: 172.28.0.0/16
                  gateway: 172.28.0.1
```

bootstrap/app.php:
```php
// Trust ONLY the hop nginx connects from. The container publishe

### High — VAPID application-server private key is committed as an inline default in docker-compose.yml
`docker-compose.yml:112`

**Breaks:** This is a live P-256 VAPID private key (43-char base64url) tracked in git, paired with the public key on line 111. Anyone with repo read access — every contributor, every CI job, anyone who ever forks or clones — can sign Web Push requests as this application server and deliver push notifications to every browser subscription minted against the matching public key (staff PWA installs). Because it 

**Fix:** Remove the defaults, require the vars, rotate the key.
```yaml
            # Web Push Notifications — no inline defaults; a missing key must
            # fail the deploy, not silently fall back to a committed keypair.
            VAPID_PUBLIC_KEY: ${VAPID_PUBLIC_KEY:?VAPID_PUBLIC_KEY is required}
            VAPID_PRIVATE_KEY: ${VAPID_PRIVATE_KEY:?VAPID_PRIVATE_KEY is required}
            VAPID_

### High — IssueMaterialsModal puts min-w-[440px] on the header row only, collapsing the "Issue Now" quantity input to a 0px content box
`ProductionPage.tsx:986`

**Breaks:** The scroll container is `<div className="p-5 space-y-4 overflow-x-auto">` (line 985). Only the header row carries `min-w-[440px]`; the per-allocation row cards (line 998, `rounded-xl p-3 space-y-2` → inner `grid grid-cols-12 gap-2`) carry no min-width. A block child of an `overflow-x-auto` box sizes to the box's content width, not its scrollWidth, so the rows stay at the modal's real width while t

**Fix:** // ProductionPage.tsx:985-1017 — one shared min-width track for the header AND
// the rows, and keep the action buttons out of the horizontal scroller so
// Cancel/Issue never scroll off. The 440px track is what makes the numeric
// cell wide enough to type in.
            <div className="p-5 space-y-4">
                <div className="overflow-x-auto -mx-1 px-1">
                    <div classNam

### High — AssignModal has the same header-only min-w-[480px]; the "Est. hours" field collapses to a 5px content box and the column captions sit over the wrong controls
`ProductionOrderDetailPage.tsx:568`

**Breaks:** Same defect class as ProductionPage.tsx:986. The scroller is `<div className="space-y-2 overflow-x-auto">` (line 535); only the caption row is given `min-w-[480px]`, while the task rows (line 576, `grid grid-cols-12 gap-3 items-center p-3`) size to the modal's real width. Measured at 390px with the real Modal chrome (p-4 + px-6 + p-5 = 120px): header 480px, rows 266px. With gap-3 that is 11 × 12px

**Fix:** // ProductionOrderDetailPage.tsx:535-607 — put the min-width on ONE track that
// both the captions and the rows live inside, and let the two data controls
// yield with min-w-0 instead of being crushed by the 12-column gutter budget.
                    <div className="space-y-2">
                        {/* Bulk assign stays out of the horizontal scroller. */}
                        <div classN

### Medium — normalize_phone stamps +254 onto any 9-digit string, so a reference number becomes a plausible Kenyan customer
`2026_08_08_000001_add_normalize_phone_function.php:69`

**Breaks:** This branch tests only the length, never the leading digits, so anything nine digits long is declared a Kenyan subscriber number. Verified: `normalize_phone('MPESA REF 123456789')` -> `'+254123456789'`. That is not NULL, it is a well-formed Kenyan key, and it will merge with any order whose phone field also reduces to those nine digits — a direct breach of R4's "never a shared bucket". The same br

**Fix:** Require an allocated Kenyan prefix before assuming Kenya — 7x mobile, 10/11 mobile, 2x geographic:

        IF length(digits) = 9 AND digits ~ '^(7[0-9]|1[01]|2[0-9])' THEN
            RETURN '+254' || digits;
        END IF;

Verified: '724351780' -> '+254724351780' (unchanged, still passes `test_the_same_kenyan_number_normalises_to_one_value`), '202712345' -> '+254202712345' (Nairobi landline st

### Medium — Storefront order lookup matches raw stored phone strings, so a customer whose number was typed with spaces cannot find their own order
`StorefrontLookupController.php:108`

**Breaks:** `phoneVariants()` (`StorefrontOtpService.php:89-96`) generates exactly three literals — `+254724351780`, `254724351780`, `0724351780` — and this does an exact `IN` match against the stored column. The entire premise of migration 2026_08_08_000001 and of commit b40e319 ("count a customer once, however their number was typed") is that `orders.customer_phone` holds the same number in many formats; `P

**Fix:** Match on the canonical form on both sides so there is one definition, not two:

    // StorefrontLookupController.php:108
    $query->whereRaw('normalize_phone(customer_phone) = normalize_phone(?)', [$value]);

    // StorefrontOtpService.php:144
    $email = Order::whereRaw('normalize_phone(customer_phone) = normalize_phone(?)', [$value])
        ->whereNotNull('customer_email')
        ->orderBy

### Medium — A non-scalar measurement value white-screens the tailor's My Tasks page
`TailorWorkspacePage.tsx:906`

**Breaks:** `production_orders.measurements` is `jsonb` (`2026_05_26_000004_phase4_production_workflow.php:39`) cast to `'array'`, and the only server-side constraint is `'measurements' => 'nullable|array'` (`ProductionController.php:191`, persisted verbatim at line 228; same rule again at line 317). Arbitrary nesting is therefore accepted and stored. The client types it as `Record<string, string>` (`TailorWo

**Fix:** Coerce at the render boundary — the client cannot trust a jsonb column's shape:

    // top of TailorWorkspacePage.tsx
    const cell = (v: unknown): string =>
        v == null ? "" : typeof v === "object" ? JSON.stringify(v) : String(v);

then replace each `{v}` at lines 534, 559, 584 and 906 with `{cell(v)}` (and `{v}` at `ProductionPage.tsx:1687` likewise), and loosen the type at line 55 to `m

### Medium — `text-muted` / `bg-muted/40` reference a token that does not exist — 17 dead classes
`QuotationsPage.tsx:484`

**Breaks:** There is no `muted` key anywhere in tailwind.config.js. A fresh compile of the real content glob contains zero occurrences of the string `muted`, so all 17 usages emit nothing. Text sites silently inherit the ancestor colour (surface-900 inside a card, surface-700 inside `.table td`), so "muted" copy renders at full weight and the visual hierarchy the author intended is gone; the interactive ones 

**Fix:** `rg -l -- '-muted' src/pages/sales | xargs sed -i '' -E 's/text-muted/text-surface-500/g; s/bg-muted\/40/bg-surface-100/g'`. For the quoted line: `{searching && <div className="px-3 py-2 text-sm text-surface-500">Searching…</div>}`; for :489/:534 `… text-left text-sm hover:bg-surface-100`.

### Medium — `.page-subtitle` and `.table th` put surface-500 on non-white backgrounds — 4.10:1 and 4.11:1
`index.css:224`

**Breaks:** tailwind.config.js:69 justifies surface-500 = #70786d on the strength of "4.57:1 on white" — but neither of its two biggest consumers sits on white. `.page-subtitle` (47 pages) renders directly on the shell canvas `bg-surface-canvas` #f2f3f1 (AdminLayout.tsx:201 sets it; `<main>` and the content wrapper at AdminLayout.tsx:279 add no background), giving **4.10:1** at 15/16px. `.table th` (index.css

**Fix:** Darken the token once rather than patching 2 component classes and ~450 call sites — in tailwind.config.js:38 replace `500: '#70786d',` with:
```js
// 5.12:1 on white, 4.60:1 on surface-canvas (#f2f3f1), 4.61:1 on surface-100.
// The old #70786d cleared 4.5:1 only against pure white, which is not where
// .page-subtitle or .table th actually render.
500: '#687066',
```
No markup changes needed; th

### Medium — `.input` has no visible boundary — 1.24:1 border on a 1.02:1 fill (WCAG 1.4.11)
`index.css:52`

**Breaks:** `.input` is used 263 times and forms live inside `.card` (index.css:123, `bg-white`). Its only two affordances are `border-line` #e5e7e3 — **1.24:1 against the white card** — and `bg-field` #fafbfa — **1.02:1 against the white card**. WCAG 1.4.11 requires 3:1 for the visual information that identifies a control, and nothing here reaches it, so an empty text field is indistinguishable from blank ca

**Fix:** Give the control a boundary that clears 3:1 while leaving the decorative hairline alone — surface-400 (#8c9489) is 3.13:1 on white and is already in the ramp:
```css
.input {
    @apply block w-full rounded-field border border-surface-400 bg-field px-4 py-3
       text-[15px] leading-tight text-surface-900 placeholder:text-surface-500
       min-h-[48px] transition-colors duration-150
       focus

### Medium — Sidebar "Sign out" is danger red on the navy slab — 3.08:1
`Sidebar.tsx:995`

**Breaks:** `danger.DEFAULT` #dc2626 on the account-menu panel, which is `bg-nav-hover` #1b2740 (Sidebar.tsx:969), measures **3.08:1** at `text-xs` (12px) — normal text, 4.5:1 required. The rest of the sidebar is fine (white/60 email = 7.00:1, white/55 section headers = 6.11:1, brand-300 on the active pill = 7.31:1); this is the only item on the slab that fails, and it is the destructive one, so it is also th

**Fix:** Use a light step of the danger ramp — dark chrome wants light ink, not the mid ramp:
```tsx
className="w-full flex items-center gap-3 px-4 py-2.5 text-xs text-danger-300 hover:bg-danger/10 hover:text-danger-200 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
```
`danger-300` #fca5a5 on #1b2740 is 8.03:1.

### Medium — Money fields ship as JSON strings from four endpoints that skip numify() — ::float8 alone is not sufficient (R5)
`ReportController.php:708`

**Breaks:** The file's own docblock (lines 36-39) states the rule: "The ::float8 casts on the aggregates are correct SQL but NOT sufficient: PDO_PGSQL hands numeric and float8 back to PHP as STRINGS regardless... The guarantee has to be made in PHP." Four money-bearing endpoints return without wrapping in $this->numify(): salesByPaymentMethod (708), profitLoss (1345, whose expenses_by_category[].total is a ra

**Fix:** // salesByPaymentMethod (line 708) — the same guarantee every other money
// endpoint in this file already makes:
        return response()->json($this->numify([
            'period'          => ['start' => $start, 'end' => $end],
            'payment_methods' => $rows,
        ]));

// profitLoss (line 1345):
        return response()->json($this->numify([
            'period'                    

### Medium — public-api limiter's API-key branch is unreachable — throttle runs before the middleware that sets the attribute it reads
`RouteServiceProvider.php:69`

**Breaks:** api_key_name is set by ValidateApiKey (ValidateApiKey.php:83), which is registered AFTER the throttle on the group: `Route::middleware(['throttle:public-api', 'api.key:optional'])` (routes/api.php:137). Laravel's middleware priority map contains ThrottleRequests but not ValidateApiKey, and SortedMiddleware only reorders middlewares that are both in the priority map — a non-priority middleware is n

**Fix:** Reorder the group so the key is resolved before it is read. Since ValidateApiKey is not in the priority map, declaration order is honoured — routes/api.php:137:
```php
    // api.key MUST precede the throttle: the public-api limiter reads the
    // api_key_name attribute that ValidateApiKey sets, and Laravel's priority
    // map would otherwise leave ThrottleRequests first and the key branch dea

### Medium — Public Paystack flow writes payment_method 'card' but the verify path only matches 'card_paystack', so every card payment leaves a permanently-pending duplicate row
`PublicPaymentController.php:404`

**Breaks:** initiatePaystack creates the pending Payment with payment_method 'card'. verifyPaystack then looks the payment up twice — line 631 `->where('payment_method', 'card_paystack')` with the reference, and the 'in case reference differs slightly' fallback at line 639, which ALSO filters on 'card_paystack'. Neither can ever match a row this controller wrote, so $payment is always null and the 'Webhook ma

**Fix:** Write the same method string the verifier expects. PublicPaymentController.php:404:
```php
                'payment_method'     => 'card_paystack',
```
and tighten the fallback lookup so it cannot grab an unrelated row (line 637-643):
```php
        // Fallback: same order, same method, still pending — but never ignore
        // the reference entirely; a mismatched reference must not settle here.

### Medium — Public storefront checkout shares the 5-per-minute login bucket, so guest orders lock staff out of the admin console
`api.php:182`

**Breaks:** Named limiters key on md5($limiterName . $limit->key) — the route is not part of the key (Illuminate\Routing\Middleware\ThrottleRequests::handleRequestUsingNamedLimiter). So every route tagged throttle:auth shares ONE 5-request/minute bucket per client IP: /v1/auth/register, /v1/auth/login, /v1/auth/forgot-password, /v1/auth/reset-password, /v1/admin/auth/login, /v1/admin/auth/2fa/verify — and thi

**Fix:** Give checkout its own limiter. routes/api.php:181-182:
```php
        Route::post('/storefront/orders', [\App\Http\Controllers\Api\StorefrontCheckoutController::class, 'store'])
            ->middleware('throttle:checkout');
```
and in RouteServiceProvider::configureRateLimiting():
```php
        // Guest checkout — abuse-resistant but deliberately NOT the 'auth'
        // bucket: named limiters 

### Medium — PageShell hard-codes height: calc(100vh - 112px), which under-reserves the phone chrome by 62px and re-introduces the iOS large-viewport bug the shell already fixed
`ProductionPage.tsx:3074`

**Breaks:** PageShell wraps four production routes (Orders 3097, WIP 3220, BOM 3238, QC 3246) and pins the page column to a fixed viewport calculation. Two errors compound. (1) The 112px constant does not match the shell's actual chrome. On a phone AdminLayout reserves topbar `h-[60px]` + main padding `py-3` (24px) + BottomTabBar `h-14` (56px) + `pb-[env(safe-area-inset-bottom)]` (34px on a notched iPhone) = 

**Fix:** function PageShell({ title, subtitle, children, headerRight }: {
    title: string;
    subtitle: string;
    children: React.ReactNode;
    headerRight?: React.ReactNode;
}) {
    return (
        // Height comes from --vh (the shell's REAL visible height, kept current by
        // AdminLayout's visualViewport listener) minus the chrome the shell
        // actually reserves — NOT from 100vh, wh

### Low — A whitespace-only brief renders an empty amber warning banner on the My Tasks focus card
`TailorWorkspacePage.tsx:868`

**Breaks:** `"   "` is truthy in JavaScript, so a note containing only spaces or a stray newline passes this guard and renders the full alert block at lines 869-877 — amber background, amber border, warning-triangle icon — wrapping an empty `<p>`. On the focus card that furniture *is* the signal: it tells the tailor this job carries a special instruction. They stop, look for it, and there is nothing there. `n

**Fix:** Guard on content, not on truthiness, in both components.

    // TailorWorkspacePage.tsx:868
    {order?.notes?.trim() && (
        ...
        <p className="text-xs text-amber-800 leading-relaxed">
            {order.notes.trim()}
        </p>
    )}

    // ProductionPage.tsx:2340
    {o.notes?.trim() && (
        <p className="text-2xs text-surface-600 mt-1.5 line-clamp-2 leading-snug" title={o

### Low — `.btn-danger` still reaches past the tokens to stock Tailwind red
`index.css:103`

**Breaks:** This is the single surviving raw-palette utility in the whole of `src` (a full regex sweep over every Tailwind colour family × every step across .ts/.tsx/.css returns exactly this line), so R7 is otherwise clean. `red` is not extended in tailwind.config.js, so these fall through to stock Tailwind: the compiled rule is `.btn-danger:hover { background-color: rgb(185 28 28) }` = #b91c1c. Today that i

**Fix:** ```css
.btn-danger {
    @apply btn bg-danger text-white hover:bg-danger-700 active:bg-danger-800
       focus-visible:ring-danger;
}
```

### Low — `.btn-sm`'s min-height silently overrides `h-8`, so pagination page buttons render 32×40
`index.css:108`

**Breaks:** `min-height` always beats `height`, and `.btn-sm` is emitted after every `.btn-*` variant, so its `min-h-[40px]` wins over an author's explicit `h-8`. DataTable.tsx:491 asks for square 32px page buttons (`"btn btn-sm w-8 h-8 p-0 text-xs"`) and gets a 32×40 `rounded-full` oval sitting next to the 44×44 circular prev/next `btn-icon` buttons (DataTable.tsx:359/367/393/401 — those resolve correctly to

**Fix:** Let the size modifier be overridable by an explicit height. In index.css:
```css
.btn-sm {
    @apply px-5 text-[13px] min-h-[40px] py-0;
}
/* Square icon-sized paging buttons opt out of the 40px floor. */
.btn-square {
    @apply min-h-0 aspect-square p-0;
}
```
and in components/ui/DataTable.tsx:491 / pages/sales/orders/OrdersListPage.tsx:509 use `"btn btn-sm btn-square w-8 h-8 text-xs"`. Delete

### Low — White on brand-500 is 3.50:1 — the primary CTA label misses AA (documented owner deviation)
`index.css:87`

**Breaks:** White on #f05423 measures **3.50:1**. `.btn` is `text-[16px] font-medium` (index.css:75) and `.btn-sm` is 13px, both normal text under 1.4.3, so 4.5:1 applies and the app's primary action fails it everywhere. Recording it for completeness with the measured number rather than as a new bug: tailwind.config.js:20 already states this is deliberate ("an exact match to the reference app, which accepts ~

**Fix:** No change if the brand fill is fixed by the owner — but the deviation should be scoped so it cannot spread past the CTA. Correct the stale ratio in tailwind.config.js and record the exemption where it is enforced:
```js
// 500 is the brand hue used on primary CTA FILLS with white text — 3.50:1,
// a deliberate deviation from 1.4.3 to match the reference app. Do not use
// 500 as an ink colour and 

### Low — discount_rate_percent is diluted by shipping and tax — its denominator is not the merchandise the discount applied to
`ReportController.php:199`

**Breaks:** orders.total_amount is built as `$afterDiscount + ($taxInclusive ? 0 : $taxAmount) + $shippingAmt` where `$afterDiscount = $itemSubtotal - $cartDiscount` (PosController.php:3528-3530), so `total_amount + discount_amount` re-adds the cart discount and yields subtotal + shipping (+ tax when prices are tax-exclusive). Shipping fees and output VAT are not merchandise a discount was ever applied to, so

**Fix:**             -- Rate the CART discount against the merchandise it was applied to.
            -- total_amount + discount_amount reconstitutes subtotal + shipping
            -- (+ tax when prices are tax-exclusive), and neither delivery nor
            -- output VAT was ever discounted, so the rate came out diluted.
            -- orders.subtotal is already the pre-cart-discount line total
        

### Low — Paystack webhook signature compared with !== instead of hash_equals
`PaymentController.php:600`

**Breaks:** PHP's !== on strings short-circuits at the first differing byte, so comparison time leaks a prefix-match oracle on the expected HMAC (CWE-208). The endpoint is public, unauthenticated and rate-limited at 100/min per IP, which is enough budget to sample. Exploitation over the internet is slow and noisy, but the mitigation is one function call and this is the only signature check standing between th

**Fix:** ```php
        $signature         = (string) $request->header('x-paystack-signature');
        $secretKey         = $this->paystackSecretKey();
        $computedSignature = hash_hmac('sha512', $request->getContent(), $secretKey);

        if (! hash_equals($computedSignature, $signature)) {
```
Apply the same change to any other signature check added later (the M-Pesa handlers currently have none 

### Low — web.php and api.php are loaded three times on every boot, which also makes php artisan route:cache impossible
`providers.php:5`

**Breaks:** bootstrap/app.php already declares routing via withRouting(web: ..., api: ...), which calls RouteServiceProvider::loadRoutesUsing() — that writes the framework class's STATIC $alwaysLoadRoutesUsing (vendor/laravel/framework/src/Illuminate/Foundation/Support/Providers/RouteServiceProvider.php:97, `self::$alwaysLoadRoutesUsing = $routesCallback`) — and then force-registers the framework RouteService

**Fix:** Keep bootstrap/app.php as the single routing declaration and demote the provider to what it uniquely owns, the rate limiters. NOTE: do not simply delete the provider — configureRateLimiting() defines auth/public-api/search/api/admin-api/webhooks, and without it every throttle:<name> middleware throws 'Rate limiter [auth] is not defined'.

1) Move the limiters into AppServiceProvider::boot():
```ph

