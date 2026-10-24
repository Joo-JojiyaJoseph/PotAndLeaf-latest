# Pot & Leaf ERP — all fixes (24 Oct 2026, update 2)

This package holds **every file changed** from the uploaded project (117 files + this note).
It replaces all earlier packages; install only this one.

## Install

1. Copy each file over the same path in your project.
2. Backend: `php artisan migrate` (5 new migrations).
3. Frontend: `npm run build`.

## Verified

| Check | Result |
|---|---|
| Backend test suite (Pest, PHP 8.4) | 420 passed, 0 failed, 16 skipped (before: 379 passed) |
| Frontend build (Vite) + unit tests (Vitest) | builds · 21 passed |
| Browser check of every changed screen | no script errors, no failed API calls |
| API run-through: purchase → batch/barcode → damage → purchase return; sale (cash/UPI/credit/walk-in) → confirm; transfer between companies → receive; bulk split | all OK; batch totals = stock |

## 00. Update 2 — one location per company, simpler commission

**Company delete is safe (update 5)**
- A company that has any products, customers, suppliers, bills, payments, stock records, categories or users cannot be deleted; the message lists what it has and says to make it Inactive instead. Only an empty company can be deleted.

**One location per company**
- A company gets its single location automatically when it is created (named after the company). The "Locations" box on the company form, the Locations menu/screen, the "By location" inventory tab/report and the branch filter are gone.
- A second location cannot be added, and the only one cannot be deleted. Transfers are company → company only.
- Migration `2026_10_24_000001`: every company ends up with one active location; stock recorded under extra locations is added to it; old documents keep their history.

**Commission, in plain rupees**
- Tabs renamed: Pay staff · Staff rates · Product offers · Manager share · Production bonus · Earnings log · WhatsApp messages · Care reminders — each with a one-line explanation and an example.
- Staff rate form: "Commission % of their sales", "Monthly sales target", "Bonus ₹ for the monthly target", "Max commission a month", with a live example ("sells ₹1,00,000 → ₹2,000 commission"). "₹ per piece" is shown only for production supervisors (it only ever applied to them); saved fields are the same as before.
- "Tiers" → **Higher % slabs** (with example), "Daily" → **Daily bonus**; product offers show "Extra ₹ per piece / % of price" with an example; manager share no longer asks for a branch.
- Pay commission shows every part: commission, monthly target bonus, daily bonuses, offers, manager share, production bonus, and amounts taken back for returns — so the lines add up to "To pay". Removing a payment asks first.
- **3 simple steps (update 4):** the page is now 1 Set rates → 2 See earnings → 3 Pay staff; product offers, manager share, production bonus and messages sit under "Extras". Setting a rate is a 3-question guide (Who? → How much? → Good-day bonus) with a summary before saving. Calculation unchanged.
- **Simple words (update 3):** a "How commission works" box (4 steps + a worked rupee example, can be hidden), rates shown as sentences ("Gets 2% of what they sell — ₹2 for every ₹100"), and the fixed monthly bonus is offered only for staff with no commission % (the only time the existing calculation pays it).
- **No commission calculation changed in this update** — only wording, examples and layout. The screen now says plainly how the existing rules work, e.g. the monthly target bonus is added only in a month with no per-bill commission (otherwise target rewards come from Daily bonus).

## 0. Tester issue list (13–22 Oct) — status of every item

| # | Issue | Status | What was done / found |
|---|---|---|---|
| 1, 2, 5 | Inactive company / category; supplier advance | Done (tester) | — |
| 3 | Same item ordered again; ordered items still on reorder | **Fixed** | One line per product on a PO. If it is already on an open PO, the screen says which PO and asks "Order again anyway?". Reorder list subtracts stock already on order and shows "+5 on order (PO-…)". |
| 4 | Supplier advance not visible | Already fixed | Shown on Supplier page (Advance balance + payments) and Payment history. |
| 6 | "Couldn't load users" | Not reproducible | Loads for every status and with All companies. |
| 7 | Batches/barcodes after purchase | Works | Created when the purchase is confirmed (not on payment). |
| 8 | Purchase return amount | Works | List, detail and debit note show the correct total. |
| 9, 12 | Batch-wise damage / return / bulk split | Already fixed | All three pick a batch. |
| 10, 24, 57 | Damage + return quantities | Works | Damaged units are offered in the return (capped at the damaged count) and are not taken off stock twice. |
| 11 | Confirmed return can be cancelled | Already fixed | Cancel only on drafts. |
| 13 | Bulk split amounts mismatch | Explained + **improved** | Cost is split exactly (4 × ₹40 = ₹160 = 2 × 26.665 + 2 × 53.335); the screen rounds each to paise, so it can look like ₹160.02. The new check step shows the true total. |
| 16 | Delete product with transactions | Already fixed | Blocked with the reason; deactivate instead. |
| 17 | Changes only after refresh | Already fixed | Every screen reloads data when opened and on window focus. |
| 18, 27, 28 | Transfers | Works | Receiving uses the existing product (matched by SKU/name), quantities match. |
| 19 | Supplier outstanding on edit | Already fixed | Changing opening balance moves outstanding by the difference. |
| 20 | Remove pricing from PO | **Needs your decision** | Rates feed the PO total, CBM value and the "convert to purchase" step. Option: keep rates but hide them on the PO PDF sent to the supplier. |
| 21 | Barcodes not in barcode section | **Fixed** | Barcodes live on batches; the labels page listed only the product barcode (mostly empty). Now one row per batch barcode in stock. |
| 22 | Batch qty not updated | **Fixed** | Rental return, sale cancel, sales return (unscanned) and pooled items now put stock back on the batches. |
| 23 | Wrong time zone | **Fixed** | All times sent with zone and shown in Indian time (also loyalty, activity, split pages). |
| 25 | Confirm bulk split pricing | **Fixed** | "Check prices before confirming" table (qty, cost, selling price; below-cost rows red). |
| 26 | Company user changing "from company" | **Fixed** | Screen already hid it; the server now also refuses another company's stock list / barcode scan. |
| 30 | Sale price not automatic | **Fixed** | Price filled on pick (already); now also switches to wholesale/dealer price when the customer is chosen after the items. |
| 31 | Unable to confirm sale | Not reproducible | Cash, UPI, credit and walk-in sales all confirm. |
| 33, 42 | Inactive rental products / suppliers listed | **Fixed** | Hidden in PO, rental, advance order, backorder, production pickers. |
| 38 | GST as text box | **Fixed** | Type any rate; usual slabs suggested. |
| 39 | Cannot edit stock on hand | By design | Stock changes only by documents; the form now links to Stock count. |
| 40 | Batch/barcode after adding product | **Fixed** | Opening stock gets its own batch + barcode (OPEN-SKU) at once and follows opening-stock edits. |
| 41 | Unwanted "blocked" option | **Fixed** | Removed from suppliers (it behaved like Inactive). |
| 44, 46 | Payment section confusing | **Improved** | Plain words ("Purchase bill" not "GRN"), what Record payment vs Apply advance means, confirm before voiding. New purchase bills are numbered **GRN-…** — they used PO-… like purchase orders, so two documents could share a number. |
| 45 | No need for location | Already done | Location pickers were removed from daily forms. |
| 47, 55 | Stock count not reflected in batches | **Fixed** | Shortage comes off the oldest batches, excess goes on the newest; the count sheet shows "Batches: B-001 −4, B-002 −1" and each stock movement names its batch. |
| 52 | Removed users have no view page | **Fixed** | Users → **Removed** tab (HO only), view page, and **Restore** to a company. |
| 53 | Different price per batch | **Needs your decision** | Today one selling price per product. Option: optional selling price per batch, used when that barcode is scanned. |
| 54 | Stock value uses product cost | **Fixed** | Stock value = each batch × what it actually cost (landed); stock outside batches × product cost. |
| 56 | Product cost becomes latest purchase cost | **Needs your decision** | Keep "latest cost" or switch to weighted average. Stock value and profit no longer depend on it. |

Old data: products whose stock and batches already differ can be corrected with Inventory → Batches → **Generate opening barcodes** (adds a batch for the missing quantity).

## Loyalty "Add rule" form (rebuilt)

The form only sent name, type, ₹/points and tier, so **Product bonus** and **Category bonus** rules could not choose a product or category and never gave points. Now each type shows its own fields (product/category picker, bonus per piece, multiplier, minimum bill, max per bill, dates, priority, customer group), the list explains each rule in words, rules can be removed, and bonus points come **on top of** the normal points.

## 1. Calculation fixes

| Problem | Fix |
|---|---|
| Supplier credit days could not be saved/edited | Accepted, returned, editable; due date = bill date + credit days |
| Cancelled/returned sale took commission off twice (salesman + supervisor) | Totals count original + reversal rows |
| Part-returned then cancelled sale over-reversed commission | Only the open remainder is reversed |
| Daily target bonus could not be re-earned after a same-day return | Fixed |
| Promotion % / product-tier commission subtracted the discount twice | Base = taxable value |
| MySQL "Data too long" on promotion bonus / partial reversal (sale confirm or return failed) | `commission_transactions.source_id` → 191 chars (migration 000001) |
| Booking advance counted as paid but still usable again; extra cash receipt | Advance used up on sale confirm; returned on cancel |
| Rental settlement re-charged months already invoiced | Only un-invoiced rent charged |
| Freight always added to supplier balance | Setting "Freight/loading is paid to the supplier" (default ON); owed amount fixed per purchase (migration 000002) |
| Pooled case cancelled/returned outside the pool | Goes back through the unit pool |
| Customer credit limit never checked | Enforced on credit sale confirm (0 = no limit) |
| Return took back default-rate points, could exceed earned | Pro-rata of points actually earned, capped |
| Return confirmed straight after create missed credit reduction | Full sale reloaded on confirm |
| Profit/margin included GST; profit used today's cost | Before-GST revenue; cost at time of sale |
| GST reconciliation ignored purchase-return GST | Deducted from input tax |
| Batch quantities drifted from stock | Updated on returns, production, counts, rentals, pooled sales |

## 2. Crashes found in testing

| Screen / API | Cause | Fix |
|---|---|---|
| Reports → Production by product | ambiguous `company_id` / `status` columns | Qualified columns |
| Reports → Production batches | missing `ProductBatch::location()` | Relation added |
| 19 reports with "All companies" (sales comparisons, YTD, 12-month, GST, leaderboard, commission, margin, profit, price tier, rental ×3 + exports) | services rejected `null` company | Consolidated across companies |
| 7 Commission lists with "All companies" | unguarded company scope | Fixed |
| Commission statement (monthly / range / daily) with "All companies" | needs one company | Uses the open company |
| Any API link opened while logged out (e.g. PDF in new tab) | redirect to non-existent `login` route → HTTP 500 | Returns 401 JSON |

## 3. Missing requirements added (from the proposal)

| Proposal item | Added |
|---|---|
| Module 08 — Tally-compatible export | Reports → Cash/Bank book, GST, Debtors, Creditors → Export → "Export for Tally (XML)": ledgers + Sales, Purchase, Credit/Debit Note, Receipt, Payment vouchers |
| Module 09 — CBM for import orders | CBM / container-fill panel on New Purchase Order |
| Module 10 — customer notification | WhatsApp on booking (automatic) and "Tell customer stock is in / it's ready" buttons on Backorder and Advance Order screens; all logged |
| Milestone 1 — Finance role | Finance role (payments, receipts, books, reports; view-only on bills/stock) — migration 000003 + seeder |
| Milestone 2 — reorder alerts per location | "Low here" flag and count on Inventory → By location |
| (earlier request) Owner-only internal estimate report | Reports → Internal |

## 4. Ease of use

- Side menu regrouped (Main, Selling, Buying, Stock, Accounts, Setup) and now lists **Reports, Sales Returns, Advance Orders, Locations, Backups, Activity Log**, which were only reachable by typing the address.
- POS: the Disc. box is a **percentage** (it was labelled % but sent as rupees — 10 meant ₹10). Columns now say "Disc. %" / "Disc. ₹".
- Product picker: type, ↑/↓, **Enter** to pick — no mouse needed at the counter.
- Dashboard: figures no longer break mid-number; staff without report access no longer see "0 products · 0 suppliers".
- "No access" page explains what to do and links back to the dashboard.
- Transfer form explains "Save request" vs "Confirm transfer"; purchase form explains when freight is owed to the supplier.
- Advance order screen now shows a message when Cancel/Fulfil fails (it failed silently).

## Not changed (needs your decision or setup)

- Seed data creates 3 companies; the proposal says 4 — add the 4th in Companies.
- WhatsApp/SMS need WATI or Twilio keys in `.env`; without them messages are logged, not sent (the screen says so).
- The proposal names Inertia.js; the build is a React SPA + JSON API (works the same for users).
- Backups use MySQL commands; they were not tested here (test database was SQLite).
