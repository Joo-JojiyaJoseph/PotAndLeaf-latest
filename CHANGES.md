# Pot & Leaf ERP — all fixes (23 Oct 2026)

This package holds **every file changed** from the uploaded project (67 files).
It replaces the earlier two packages; install only this one.

## Install

1. Copy each file over the same path in your project.
2. Backend: `php artisan migrate` (3 new migrations).
3. Frontend: `npm run build`.

## Verified

| Check | Result |
|---|---|
| Backend test suite (Pest, PHP 8.4) | 408 passed, 0 failed, 16 skipped (before: 379 passed, 16 skipped) |
| Frontend build (Vite) | builds |
| Frontend unit tests (Vitest) | 21 passed |
| Browser crawl of 52 screens as Admin, Manager, Cashier | no script errors, no failed API calls |
| Every list/report API endpoint, single company and "All companies" | no server errors |
| API run-through: rental (create → activate → invoice → settle → PDF), bulk split, damage, settings, location, user, loyalty rule, invoice PDFs | all OK |
| Browser sale: 2 × ₹120, 10% off, 5% GST | screen and saved bill both ₹227 |

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
