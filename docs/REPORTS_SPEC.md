# Reports — canonical specification

**Owner:** Moses Mwicigi · **Status:** authoritative · **Applies to:** every report,
KPI tile, export, and scheduled digest in the Bethany House ERP.

This document is the constitution for reporting. A report that violates a rule in
Part 1 is a bug even if its page renders beautifully. When a new report is built
or an old one is touched, it is checked against this document first.

---

## Part 1 — The rules every report must obey

### 1. Name your truth source

The system has **three different kinds of money truth**, and every figure on every
report must know which one it is measuring:

| Truth | Source of truth | Answers |
|---|---|---|
| **Sales truth** | `orders` (+ `order_items`) | What did we sell, when, to whom, at what price? |
| **Money truth** | `payments`, net of `refund_amount`, `status = 'paid'` only | What cash/paybill/card actually came in? |
| **Drawer truth** | `cash_registers` + cash ledger movements | What should physically be in each drawer right now? |

These three deliberately disagree: a part-paid order creates sales truth without
full money truth; a pending-approval cheque is neither until approved; a voided
sale removes sales truth but the refund lives in money truth. **A report may never
mix them in one column.** "Revenue" on a sales report is order totals; "Collected"
on a collections report is settled payments; the difference between them *is* the
Outstanding Balances page. The July 2026 duplicate-payment incident happened
precisely because collected money was read from raw payment rows without the
guard — the lesson is permanent: money figures are computed from
`payments WHERE status = 'paid'` net of refunds, **never** from denormalised
copies, and never trusted just because a page displays them.

### 2. Derived, never stored

Reports compute from source rows at read time. No report writes its own totals
back to the database, and no report reads a stored aggregate when the source rows
are available (register aggregates are drawer truth and are the one exception —
they are an operational device count, reconciled at close, not a reporting cache).
If a report is slow, the fix is an index or a materialised view refreshed from
source — never a hand-maintained total.

### 3. Every number opens

A figure with no drill-down is a rumour. Every aggregate on every report must
click through to the rows that produced it: revenue → the orders; collected → the
payments; shrinkage → the adjustments; a production on-time % → the late orders.
The drill-down uses the **same query with the aggregation removed** — not a
second query that can drift from the first.

### 4. One clock

All day boundaries are **Africa/Nairobi** (EAT, UTC+3). "Today" on every report,
KPI, EOD cut-off, and scheduled digest means the Nairobi calendar day. Dates are
stored UTC and converted at the query edge, in one shared helper — never
per-report arithmetic. A sale at 23:40 in the shop belongs to that shop day even
though it is 20:40 UTC.

### 5. Scope is enforced in the query

RBAC decides which *reports* a role can open; **outlet scoping decides which
*rows* feed them**, and it is applied in the query layer, not the menu:

- `reports.view` — the general catalog (sales, customers, inventory, production,
  procurement).
- `reports.financial` — P&L, revenue, tax, cash-flow. Deliberately withheld from
  outlet managers and procurement roles; finance_manager and admins only.
- `reports.export` — PDF/Excel export and scheduled deliveries.
- An outlet manager's every report is silently filtered to their outlet(s); a
  cross-outlet comparison is an admin/finance view. Hiding a menu item is
  presentation; the query filter is the security.

### 6. Time always has a mirror

Every period figure carries its comparison: same length, immediately preceding
period by default (this week vs last week, this month vs last month), with
year-over-year available where seasonality matters (December vestment season).
A number without a delta is a fact; a number with a delta is information.

### 7. Voids, refunds, deposits — one treatment everywhere

- **Voided orders** leave sales truth entirely (they are not "negative sales");
  the void itself appears only on the audit/exceptions report.
- **Refunds** reduce money truth on the day the refund happened, not the day of
  the original sale.
- **Deposits** are collected money but *unearned*: a deposit report line is money
  truth, but revenue recognition for financial statements counts an order when it
  is fully paid. The gap between "collected" and "earned" is shown, not hidden.
- **Pending-approval tenders** (cheque, bank transfer, Western Union, MoneyGram)
  appear nowhere in money truth until approved — they have their own "awaiting
  approval" line so the money is visible without being counted.

### 8. Closed periods are snapshots

An EOD report, once submitted, is an immutable record of that day — discussion
happens through acknowledgement and comments (the `#eod` thread), never through
editing the report. The same principle extends to any future month-end close:
correcting a closed period is a *new, dated adjusting entry with a reason*, never
a rewrite. (The duplicate-payment cleanup of 18 Jul 2026 followed this rule: rows
backed up, deletions audit-logged on each order, historical closed registers left
untouched.)

### 9. Exceptions get their own page

A healthy report hides problems inside averages. The exceptions surface — voids,
refunds, price overrides, negative-margin sales, stock adjustments, payment
approval rejections, duplicate-payment removals — is a first-class report, not a
byproduct, because it is the report an owner actually reads to sleep well.

### 10. Export is the same query

PDF (via `PdfService`, branded, navy/gold) and Excel exports run the **same
query** as the on-screen report — same filters, same scoping, same period — and
say so in the footer: period, outlet scope, generated-at timestamp, and who
generated it. A scheduled digest is an export on a timer, nothing more.

---

## Part 2 — The catalog and what each report answers

Each report exists to answer one owner-question. If a report can't state its
question, it shouldn't exist.

### Daily operations
| Report | The question it answers | Truth |
|---|---|---|
| **Daily summary** | How did today go, per outlet? Sales, collected, transactions, top items. | Sales + money, side by side, labelled |
| **End of Day (per clerk)** | Does each clerk's drawer tie out — expected vs counted, variance named? | Drawer |
| **EOD admin review** | Which EODs are submitted / acknowledged / discussed? | Workflow over drawer |
| **Register sessions** | Every open/close, float, expected vs actual, who and when. | Drawer |

### Sales
| Report | Question | Notes |
|---|---|---|
| **Sales summary** | Gross → discounts → net → tax, by period/outlet. | Order truth; voids excluded |
| **By product / category** | What is selling, what is dead? | Feeds slow-mover clearance |
| **By customer** | Who buys, how often, how much? | |
| **By outlet** | Which shop earns its rent? | Admin/finance only |
| **By payment method** | How does money arrive — cash vs paybill vs card mix? | *Money* truth, labelled as such |
| **Returns/voids** | What came back and why? | Exceptions surface |

### Money
| Report | Question | Notes |
|---|---|---|
| **Collections** | What settled, per day/method/outlet? | Payments net of refunds |
| **Outstanding balances** | Who owes what, oldest first? | total − collected, per order |
| **Aging (30/60/90)** | Which debts are going bad? | Buckets by order date |
| **Awaiting approval** | What money is claimed but not confirmed? | Pending tenders, with age |
| **Deposits held** | What have we been paid for but not delivered? | Unearned liability view |

### Production
| Report | Question | Notes |
|---|---|---|
| **WIP / throughput** | What is on the floor, moving at what rate? | Piece-weighted, per stage |
| **On-time delivery** | Of orders due this period, what % completed by due date? | The tailoring promise |
| **Stage bottlenecks** | Where do pieces pile up, how long over estimate? | Same arithmetic as the order page |
| **Order costing** | Materials + labour vs price, per production order. | Margin per garment |

### Inventory
| Report | Question | Notes |
|---|---|---|
| **Stock on hand / valuation** | What do we own and what is it worth? | |
| **Movement** | In, out, transferred, adjusted — per item, per period. | Every adjustment carries its reason |
| **Low stock** | What will strand a sale or a production order? | Includes raw materials vs BOM demand |
| **Aging** | What has not moved in 60/90/180 days? | Clearance candidates |

### Financial (reports.financial only)
| Report | Question | Notes |
|---|---|---|
| **P&L** | Revenue − COGS − expenses, monthly. | Revenue = fully-paid orders (rule 7) |
| **Cash flow** | Money in vs money out, by week. | Pure money truth |
| **Tax** | VAT collected vs owed. | |
| **Expenses** | Spend by category/outlet vs prior period. | |

### Exceptions & audit (rule 9)
Voids · refunds · price overrides · stock adjustments · payment approval
rejections · duplicate/replay removals · EOD variances above threshold — one
page, filterable by period and outlet, every line linking to its audit-trail
entry and the person who acted.

---

## Part 3 — Definitions (the glossary the numbers swear by)

- **A sale** = a non-voided order, dated by its Nairobi order date.
- **Revenue (sales reports)** = sum of non-voided order totals for the period.
- **Collected** = sum of `payments.amount − refund_amount` where
  `status = 'paid'`, dated by payment date.
- **Outstanding** = order total − collected for that order, floor zero, only for
  payment statuses pending/partial/deposit.
- **Earned revenue (financial)** = revenue of orders fully paid in the period.
- **Drawer variance** = counted cash − expected cash at register close.
- **On-time** = production order completed on or before its due date.
- **Shrinkage** = negative stock adjustments not tied to a sale or transfer.

Any report using a word from this glossary uses this definition or renames the
column. Any new metric earns a line here before it ships.
