# Pot & Leaf ERP — Backend setup (API-only, no teams, no Inertia)

This backend is now a **pure Laravel JSON API**. The starter-kit `teams` concept
and all Inertia code have been removed. Tenancy is a real **`companies`** table
(the Cheerakuzhy group's nursery companies), and every row is scoped by
`company_id`.

## Why your `migrate:fresh` failed — and why it's fixed

The error was:

```
Can't create table `potandleaf`.`suppliers` (errno: 150 "Foreign key constraint
is incorrectly formed") … foreign key (`team_id`) references `teams` (`id`)
```

`suppliers.team_id` was a bigint pointing at the kit's `teams.id`, whose id type
didn't match (the kit uses a non-bigint key), so MySQL rejected the FK. Every
`team_id` is now `company_id` → `companies.id` (both bigint), and there is a
`companies` migration that runs first. The mismatch is gone.

## One-time setup

1. **Sanctum + API routing** (if not already done):
   ```bash
   composer require laravel/sanctum
   php artisan install:api          # wires routes/api.php + publishes the tokens migration
   ```
   The included `app/Models/User.php` already has `Laravel\Sanctum\HasApiTokens`.

2. **Register the repository bindings** — add to `bootstrap/providers.php`:
   ```php
   App\Providers\RepositoryServiceProvider::class,
   ```

3. **Remove the kit baggage that you no longer use** (this is what caused the
   FK error and pulls in `teams`). Delete these if present:
   - every migration matching `*_create_teams_table`, `*_create_team_user_table`,
     `*_create_team_invitations_table`, `*_create_memberships_table`, and
     `*_add_current_team_id_to_users_table`
   - Inertia / Fortify / Jetstream service providers in `bootstrap/providers.php`
     (e.g. `FortifyServiceProvider`, `JetstreamServiceProvider`) — API-only doesn't need them
   - the `require __DIR__.'/nursery.php';` line in `routes/web.php`
     (a minimal API-only `routes/web.php` is included — use it)

   Keep: the framework's `users`, `cache`, `jobs` migrations and Sanctum's
   `personal_access_tokens` migration.

4. **Database** — set `.env` (`DB_DATABASE=potandleaf`, credentials), then:
   ```bash
   php artisan migrate:fresh --seed
   php artisan serve            # http://localhost:8000
   ```

## What the seeders create

`php artisan migrate:fresh --seed` runs, in order: permissions → companies →
admin user → admin roles → lookups → suppliers → products.

- **4 companies**: Cheerakuzhy HO, Calicut, Thrissur, Palakkad.
- **Admin user**: `admin@potandleaf.test` / `password`, with access to all four
  companies and an "Administrator" role (full `*` access) in each.
- **Per company**: product categories (Plants, Pots, Seeds, Fertilizers), brands,
  units (Nos/Kg/Bag), 3 suppliers, and 5 products. Products start at 0 stock so
  you can see purchases raise stock and low-stock alerts fire.

## Hitting the API

```bash
# 1) log in
curl -s http://localhost:8000/api/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@potandleaf.test","password":"password"}'
# → { data: { token, user, companies:[{id,name,code}] } }

# 2) use the token + pick a company (X-Company-Id) for scoped endpoints
curl -s http://localhost:8000/api/dashboard \
  -H 'Authorization: Bearer <TOKEN>' \
  -H 'X-Company-Id: 1'
```

The SPA does both automatically: it stores the token, shows a company switcher,
and sends `X-Company-Id` on every request.

## Run the SPA

```bash
cd potandleaf-spa
npm install
npm run dev            # http://localhost:5173  (proxies /api → :8000)
```

Sign in with the seeded admin, pick a company, and Suppliers / Products /
Purchases / Inventory / Purchase Returns are live.

---

## What's new in this build

**Modern theme.** The React SPA now follows the uploaded "Modern Theme" reference:
a cool green-grey canvas, soft sage-green accent, white cards floating on soft
shadows, Inter throughout, and rounded 18px corners. The change is at the design-token
and shared-primitive level, so every screen (dashboard, suppliers, purchases,
inventory, returns, counts) picks it up consistently.

**New module — Physical Stock Verification (Milestone 2).** A stock-count document
with an HO approval workflow:

- Create a count: system stock is snapshotted per product and you key in the
  physically counted quantity; variance is shown live.
- **draft → submitted → approved / rejected.** On **approve**, the variance posts to
  the same stock ledger (an `in`/`out` adjustment) so system stock lands exactly on
  the counted figure; the adjustment is recomputed against live stock at approval
  time. **Reject** records a reason and leaves stock untouched.
- New permissions: `stock_verifications.view`, `stock_verifications.create`
  (create + submit), `stock_verifications.approve` (approve/reject, i.e. HO).
- Endpoints: `GET /stock-verifications`, `GET /stock-verifications/form-data`,
  `POST /stock-verifications`, `GET /stock-verifications/{id}`,
  `POST /stock-verifications/{id}/{submit|approve|reject}`.

Because permissions are registry-driven, `php artisan migrate:fresh --seed` (or
re-running `PermissionSeeder` + re-syncing the admin role) grants the new ones
automatically.

### Milestone 2 status
Done: GST purchase entry + landed cost, stock ledger, reorder alerts, purchase
returns (debit note + reversal), **physical stock verification with HO approval**.
Still open: bulk unit splitting, CBM calculation. Then Milestone 3 (Production/BOM,
Stock Transfer with per-location stock, Plant Rental).

---

## Troubleshooting: `Route [login] not defined` (HTTP 500 on /api/*)

If any API call returns **500** with `"message": "Route [login] not defined."`,
the request reached the server **unauthenticated**, and the framework tried to
redirect to a `login` web page that doesn't exist in an API-only app. Fix — all
three are included in this package:

1. **Use the included `bootstrap/app.php`.** It returns a clean JSON **401**
   (`{"message":"Unauthenticated."}`) for `api/*` instead of the redirect, and
   wires `routes/api.php`. Also included: `bootstrap/providers.php` (registers
   `RepositoryServiceProvider`) and `routes/console.php`.

2. **Your browser token is stale after `migrate:fresh`.** Re-seeding wipes
   `personal_access_tokens`, so the token saved in the SPA's localStorage no
   longer exists in the DB → every request is unauthenticated. **Sign out and log
   in again** (or clear site data for localhost:5173). With the 401 fix above, the
   SPA now auto-redirects to /login when the token is stale, so this becomes
   self-healing.

3. **Point the SPA at your backend host.** You're running the API at
   `http://potandleaf-backend.test` (Herd), but the dev proxy defaulted to
   `http://localhost:8000`. `vite.config.js` now targets
   `http://potandleaf-backend.test` by default and is overridable — create a
   `.env` in `potandleaf-spa/` with `VITE_API_PROXY=http://localhost:8000` if you
   use `php artisan serve` instead. Restart `npm run dev` after changing it.

Quick check the API is up and auth works (should be **401 JSON**, never 500):
```bash
curl -i http://potandleaf-backend.test/api/suppliers          # → 401 {"message":"Unauthenticated."}
curl -s http://potandleaf-backend.test/api/login -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@potandleaf.test","password":"password"}'   # → { data: { token, ... } }
```

---

## What's new: Products/Barcode + Multi-Company & Access Control (Module 14)

**Product Master (SPA).** Full create/edit/delete for products with pricing, tax,
reorder level, and an **auto-generated Code128 barcode** (printable label; barcode
search works from the products list).

**Companies (HO super admin).** `admin@potandleaf.test` is now a **super admin** who
can add / edit / delete companies and manage users in any of them (menu: Companies).
Every company keeps its own products, suppliers, purchases, inventory, users and
roles — isolation is enforced by `company_id` on every query.

**Users & roles per company.** Each user is a real login (email + password) attached
to a company with one role. Roles carry a permission matrix (grouped by module) you
can edit. Deactivated users can't sign in. Seeded roles per company: Administrator
(full), Manager, Cashier, Godown Staff, Supervisor, Salesman.

**Inline validation.** Forms now show validation errors **under each field** (company,
user, role, and product forms), not just a banner.

After pulling this build, re-run `php artisan migrate:fresh --seed` (new columns:
`users.is_super_admin/phone/is_active`; new permissions for users/roles). Then sign in
fresh — the stale-token behaviour from the troubleshooting section applies.

### Endpoints added
`GET/POST/PUT/DELETE /companies` (super admin), `/users` (+ `/users/form-data`),
`/roles` (+ `/roles/form-data`), and full `/products` CRUD (+ `/products/form-data`).

---

## Modules 1 & 2 completed

**Purchase Management (Module 01)** now also has:
- **Bulk Splitting** — convert a bulk product (e.g. a 25 kg bag) into sellable units,
  with the bulk's cost **redistributed by qty × weight** across the outputs (exact to
  the paisa). Confirming posts stock: source out, outputs in, output cost prices
  refreshed. Endpoints: `/bulk-splits` (+ `form-data`, `{id}/confirm`, `DELETE`).
- **CBM / container planning** — product dimensions (L×W×H cm) feed a live **total CBM**
  and a **container fill %** indicator on the purchase entry form.
- **Sales-rate suggestion** — on the product form, enter a margin % to auto-fill
  retail/MRP from cost.

**Inventory Management (Module 04)** now has **Stock Reports** as tabs on the Inventory
screen: **Valuation** (stock × cost, with totals) and **Fast / Slow / Dead** movement
classification over a 30/60/90-day window — plus the existing live stock levels,
reorder alerts, and per-product ledger.

New permissions: `bulk_splits.view/create/confirm/delete`. Re-run
`php artisan migrate:fresh --seed` (new tables: `bulk_splits`, `bulk_split_items`;
new product columns `length_cm/width_cm/height_cm`).

### Still deferred to Milestone 3 (correctly, not skipped)
Per-location **in-transit** and **rental** stock buckets depend on Stock Transfer and
Plant Rental — those are Module 05/06 and will land with Milestone 3. Purchase-list PDF
export is a small follow-up.

---

## Detail pages + Customer master

**Detail pages.** Records are now viewable, not just editable. Click the name/number
in any list to open a full detail page: **Purchase** (GRN with line items, GST split,
landed cost, totals + confirm/cancel/edit), **Purchase Return** (debit-note breakdown),
**Stock Count** (counted items + variance, with submit/approve/reject), **Bulk Split**
(source + outputs with cost allocation), **Supplier**, **User**, and **Customer**.
Document actions live on the detail page; master records show an Edit shortcut.

**Customer master (Module 12).** New `customers` table and full CRUD, mirroring
suppliers: types (retail / wholesale / dealer), GST, contact + WhatsApp, address,
credit terms, opening balance, outstanding, and loyalty points (for the future
loyalty module). Inline-validated create/edit + detail page. Permissions
`customers.view/create/update/delete`; seeded 3 customers per company. This is the
foundation the Sales/POS and Loyalty modules will build on.

Re-run `php artisan migrate:fresh --seed` (new table `customers`).

---

## Sales / POS (Module 03)

Point-of-sale billing that reuses the Customer master, Products, Inventory and GST.
- **POS entry** — pick a customer (or Walk-in), add product lines. The rate auto-fills
  from the customer's **pricing tier** (retail / wholesale / dealer) and is editable.
  Live GST split (CGST+SGST or IGST), per-line discount, and a **round-off** to the
  nearest rupee. Super admins get a "billing for company" picker.
- **Confirm** posts stock **out** (COGS at product cost), and for a chosen customer
  updates **outstanding** (credit sales) and **loyalty points** (1 per ₹100). Cancel
  reverses all of it. Draft → confirmed → cancelled, guarded against overselling.
- **List + invoice detail** with full line items and totals.

Permissions `sales.view/create/confirm/delete` are seeded to Manager, Cashier and
Salesman roles (which also now get Customers access). Endpoints: `/sales`
(+ `form-data`, `{id}/confirm`, `DELETE`). Re-run `php artisan migrate:fresh --seed`
(new tables `sales`, `sale_items`).

---

## Supplier Payment Tracking (Module 08)

Payables now flow end to end. Confirming a purchase **adds its total to the
supplier's outstanding**; cancelling reverses it. A new **Payments** screen records
payments against a supplier (and optionally allocates them to a specific GRN):

- **Record payment** — pick a supplier (shows current outstanding), optionally choose
  an unpaid/partly-paid GRN (auto-fills the balance), enter amount, mode
  (cash / bank / UPI / cheque) and a UTR/cheque reference. Recording it decreases the
  supplier's outstanding and increases the GRN's paid amount; deleting reverses both.
- **Purchase payment status** — purchases now show **paid / partial / unpaid** badges
  and a Paid/Balance breakdown on the detail page.

Permissions `payments.view/create/delete` (seeded to Manager). Endpoints:
`/supplier-payments` (+ `form-data`, `DELETE`). Re-run `php artisan migrate:fresh --seed`
(new table `supplier_payments`, new column `purchases.amount_paid`).

---

## Supplier Payment Tracking (Module 08)

Money owed to suppliers, tracked per GRN. Confirming a purchase already raises the
supplier's outstanding; this module records payments that draw it down.
- **Payables tab** — every confirmed purchase with invoice total, **paid**, **balance**,
  a **due date** (purchase date + supplier credit days), and a **paid / partial / unpaid**
  status. A "Pay" button opens the record form pre-filled for that GRN.
- **Record payment** — pick a supplier (shows current outstanding), optionally allocate
  to a specific GRN, enter amount / mode (cash, bank, UPI, cheque) / date / reference.
  Recording reduces the supplier's outstanding; voiding a payment restores it.
- **Payment history tab** — all recorded payments, with void.

Permissions `payments.view/create/delete` (seeded to Manager). Endpoints:
`/supplier-payments` (+ `form-data`, `payables`, `DELETE`). Re-run
`php artisan migrate:fresh --seed` (new table `supplier_payments`).

---

## Customer Receipts (receivables)

The receivables mirror of supplier payments. A credit sale already raises the
customer's outstanding at confirm; receipts draw it down.
- **Receivables tab** — confirmed credit sales with credit amount, **received**,
  **balance**, due date (sale date + customer credit days) and paid/partial/unpaid
  status. A "Collect" button pre-fills the receipt form for that invoice.
- **Record receipt** — pick a customer (shows outstanding), optionally allocate to an
  invoice, enter amount / mode (cash, bank, UPI, cheque, card) / date / reference.
  Recording reduces outstanding; voiding restores it.
- **Receipt history tab** with void.

Permissions `receipts.view/create/delete` (seeded to Manager + Cashier). Endpoints:
`/customer-receipts` (+ `form-data`, `receivables`, `DELETE`). Re-run
`php artisan migrate:fresh --seed` (new table `customer_receipts`).

---

## Commission (Module 07)

Staff commission computed from the confirmed sales each user billed (attributed via
the sale's creator).
- **Rules tab** — per-staff rule: base % of their sales, a monthly sales target, and a
  flat bonus paid when the target is met.
- **Payouts tab** — pick a staff member + month; the system **computes live** from that
  month's billed sales (sales × base %, plus bonus if the target was hit) and shows the
  breakdown. Record the payout (amount editable) as paid or draft; one payout per
  staff+month. Delete to redo.

Permissions `commission.view / commission.manage / commission.pay` (seeded to Manager).
Endpoints: `/commission/rules`, `/commission/compute`, `/commission/payouts`,
`/commission/form-data`. Re-run `php artisan migrate:fresh --seed` (new tables
`commission_rules`, `commission_payouts`).

---

## Per-location stock + Stock Transfer (Module 05)

Stock now has a **location dimension**, added *additively* so nothing already working
breaks: company `current_stock` stays the source of truth for totals, and a new
per-location layer tracks where that stock physically sits.

- **Locations master** — godowns and shops per company (seeded: Main Godown [default]
  + Front Shop). Managed under Setup → Locations.
- **Confirmed purchases** now also credit the chosen location (default if none), so
  locations have real balances to move.
- **Stock Transfer** — draft → **dispatch** (removes from source, stock goes *in transit*)
  → **receive** (accepted qty lands at destination; any shortfall returns to source).
  Cancel returns in-transit stock to source. Guarded against overselling the source.
- **Inventory → By location** tab shows on-hand quantities grouped by location.

Permissions `transfers.view/create/dispatch/receive/delete` and `locations.view/manage`
(seeded to Manager; Godown Staff gets transfers + locations view). Endpoints:
`/locations`, `/transfers` (+ `form-data`, `{id}/dispatch`, `{id}/receive`, `DELETE`),
`/inventory/by-location`. Re-run `php artisan migrate:fresh --seed` (new tables
`locations`, `location_stock`, `stock_transfers`, `stock_transfer_items`; purchases gain
a nullable `location_id`).

### Honest scope note
Sales still post stock at company level only (not yet decremented per location) — wiring
POS to a location is a small follow-up. In-transit is modelled inside the transfer
workflow; a dedicated in-transit inventory bucket view can build on `location_stock`.

---

## Fix — supplier_payments foreign key (errno 150)

If an earlier copy failed on `migrate` with *"Can't create table supplier_payments
(errno: 150 Foreign key constraint is incorrectly formed)"*: `suppliers.id` is a UUID,
so `supplier_payments.supplier_id` must be `foreignUuid`, not `foreignId` (bigint). This
build corrects it. Re-run `php artisan migrate:fresh --seed`. (All other new tables were
already correctly typed — `foreignUuid` for UUID tables, `foreignId` only for the bigint
`companies` and `users`.)

---

## Sales now post per-location + demo data

**POS per-location** — a sale can name the location it bills from (defaults to the
default location). Confirming a sale decrements that location's balance (and restores it
on cancel), so `Inventory → By location` stays accurate for both purchases and sales.
New nullable `sales.location_id`; the POS form has a Location picker.

**Demo dataset** — `DemoSeeder` seeds a complete slice of live activity for the first
company on `migrate:fresh --seed`: two confirmed purchases, three sales (incl. one credit
sale → receivable + customer outstanding), a partial supplier payment, a partial customer
receipt, and a commission rule for the admin. So Reports/Payables/Receivables/Commission/
By-location all show real numbers immediately. It's **idempotent** (skips if the company
already has purchases) and runs last in the seeder chain. To skip it, remove
`DemoSeeder::class` from `database/seeders/DatabaseSeeder.php`.

## Codebase audits (defensive)

Two whole-repo checks were run to catch the classes of bug that only surface at runtime:
- **FK type audit** — every migration foreign key matches the referenced table's PK type
  (`foreignUuid` for UUID tables, `foreignId` only for the bigint `companies`/`users`).
- **SPA reference audit** — every component/icon used in JSX is imported or locally
  defined. Both pass clean.

---

## Production / BOM (Module 02)

Raise finished plants from input materials, with cost flowing from inputs to output.
- **Bills of materials** — a recipe: an output product, the units it yields, and the
  component products (with quantities) consumed to make it. Managed under Production → BOMs.
- **Production orders** — pick a BOM + output quantity + location. Completing the order:
  consumes each component from stock (company + location) at its cost price, guards against
  shortfalls, then produces the output at a **unit cost derived from total input cost ÷
  output quantity** (and refreshes the output product's cost). Draft → completed →
  cancelled (cancel reverses inputs and output). The order detail shows the materials
  consumed and the resulting unit cost.
- The demo seed now includes a BOM and one completed run so the module shows live data.

Permissions `production.view / manage_bom / create / complete / delete` (seeded to
Manager). Endpoints under `/production` (`form-data`, `boms`, `orders`,
`orders/{id}/complete`). Re-run `php artisan migrate:fresh --seed` (new tables `boms`,
`bom_items`, `production_orders`, `production_order_items`).

---

## Plant Rental (Module 06)

Rent plants out on an agreement, with stock movement and periodic billing.
- **Rental agreement** — customer, location, start/expected-end dates, billing cycle
  (daily/weekly/monthly), deposit, and rented plant lines (qty + rate per cycle).
- **Lifecycle** — draft → **activate** (issues the rented stock out of inventory) →
  **return** (partial or full; brings stock back, closes when all returned). Cancel
  returns any still-out stock. Guarded against renting more than is in stock.
- **Billing** — generate an invoice for a period; the system computes billing cycles from
  the dates and bills (plants still out × rate × cycles), raising the customer's
  outstanding. Mark paid draws the outstanding back down; deleting an unpaid invoice
  reverses it.
- The demo seed adds one active rental with an invoice.

Permissions `rental.view / create / activate / return / bill / delete` (seeded to
Manager). Endpoints under `/rentals` (+ `form-data`, `{id}/activate`, `{id}/return`,
`{id}/invoices`) and `/rental-invoices/{id}/paid`. Re-run `php artisan migrate:fresh
--seed` (new tables `rentals`, `rental_items`, `rental_invoices`).

This completes the domain modules from the work-effort plan: Purchase, Production,
Inventory, Stock Transfer, Purchase Returns, Stock Count, Bulk Split, Sales/POS,
Customers, Supplier Payments, Customer Receipts, Commission, and Plant Rental — on
multi-company + RBAC.

---

## Reports dashboard (Module 11)

A business summary that pulls together every module over a chosen date range
(7/30/90-day presets or custom).
- **KPI cards** — sales, purchases, receivables, payables, and stock value.
- **Sales trend** — a dependency-free inline SVG bar chart of daily confirmed sales.
- **Top products** and **top customers** by revenue in the range.
- **Sales by payment mode**, **production** (completed runs + input value), **rentals**
  (active + invoiced), and a low-stock warning.

Permission `reports.view` (seeded to Manager). Endpoint `/reports/dashboard?from=&to=`
(defaults to the last 30 days). No new tables — it aggregates existing data, so the demo
seed already makes it look alive.

---

## Printable invoices & GRNs (PDF export)

Print / Save-as-PDF for the documents a shop actually hands over — done client-side, so
there's **no server PDF library to install**. Each opens in an isolated print window with
a clean A4 layout and auto-triggers the browser print dialog (choose "Save as PDF").
- **Tax invoice** — Print / PDF button on a sale's detail page. Company header with GSTIN,
  bill-to, line items with HSN + GST split, totals, and **amount in words** (Indian
  lakh/crore format).
- **Goods Receipt Note** — Print GRN button on a purchase's detail page: supplier,
  received items with landed unit cost, and totals.

To support the headers, the sale/purchase detail responses now include a small company
block (name, GSTIN, address, state). No new tables or dependencies. A rental-bill print
can reuse the same `src/lib/invoicePrint.js` helpers if wanted.

---

## Bulk barcode label sheets

Print a grid of barcode labels for shelf/stock labelling — client-side, using the
existing Code128 generator. From **Products → Labels**: set how many labels to print per
product (quick actions: 1 each, match stock, clear), choose columns (2–5), and print. Each
label shows the product name, barcode, SKU and price, laid out to a printable sheet that
saves to PDF. No new backend — reads products' existing barcodes.

---

## Fix — rental_invoices audit column

The `rental_invoices` table was missing the `updated_by` column that the shared audit
trait stamps on every save, which broke `migrate:fresh --seed` at the DemoSeeder. Added
`updated_by` to the migration. **Re-run `php artisan migrate:fresh --seed`.** An
audit-column check (every model using the audit trait must have created_by/updated_by, plus
deleted_by if soft-deletable) now runs alongside the FK audit each build.

## Purchase orders / reorder (Module 09)

Order stock from suppliers before it arrives, driven by reorder levels.
- **Reorder suggestions** — the PO form's "Load reorder suggestions" button pulls every
  product at or below its reorder level, pre-filling lines with a suggested top-up quantity
  (to ~2× reorder level), the product's cost as rate, and its GST — attaching the product's
  preferred supplier where set.
- **Purchase order** — supplier + item lines (qty, rate, GST); the form shows a running
  estimated total. Statuses: draft → sent → received / cancelled.
- **Convert to GRN** — turns an open PO into a **draft purchase** (reusing the existing
  purchase pipeline) and jumps to it, so receiving is just confirming the GRN. The PO then
  links to its GRN.

Permissions `po.view / create / send / convert / delete` (seeded to Manager). Endpoints
under `/purchase-orders` (+ `form-data`, `suggestions`, `{id}/send`, `{id}/convert`). New
tables `purchase_orders`, `purchase_order_items`.

---

## Advance orders (Module 10)

Customer pre-bookings against future stock — the customer-side mirror of purchase orders.
- **Booking** — customer + item lines (qty, rate defaulting to retail, GST) with a running
  estimated total, plus an optional **advance paid** amount. Statuses: booked → fulfilled /
  cancelled. The detail shows advance vs balance.
- **Fulfil → sale** — turns a booking into a **draft credit sale** (through the existing
  sale pipeline), counting the advance as amount already paid so the balance lands on the
  customer's receivables when the sale is confirmed. Jumps to the created sale; the booking
  then links to it.

Permissions `advance.view / create / fulfill / delete` (seeded to Manager). Endpoints under
`/advance-orders` (+ `form-data`, `{id}/fulfill`). New tables `advance_orders`,
`advance_order_items`. This is the last planning module from the work-effort plan.

---

## Rental-bill print + demo rows for PO / advance orders

- **Rental invoice print** — each invoice row on a rental's detail page now has a print
  button that opens a rental bill (company header with GSTIN, customer, billing period and
  cycles, plants with per-cycle rate, total due, amount in words) to Save-as-PDF. Reuses
  `src/lib/invoicePrint.js`; the rental detail response now carries the company header block.
- **Demo seed** — now also creates a draft purchase order and a booked advance order, so
  those two modules show live data on a fresh seed (guarded on having enough suppliers /
  products / customers; record-only, no stock movement).

Re-run `php artisan migrate:fresh --seed` (no new tables this time — just seed content).

---

## Fix — missing users table migration (makes the backend self-contained)

A defensive whole-repo audit turned up that the backend had **no `create_users_table`
migration** — 48 migrations, many with foreign keys to `users` and one altering it, but
nothing creating it. An existing project (like the dev machine) still migrated because
Laravel's default users migration was already there from `laravel new`; but a fresh unzip
would fail. Added the canonical `0001_01_01_000000_create_users_table.php` (users +
password_reset_tokens + sessions), dated to run first. It uses Laravel's standard filename,
so if your project already has it, this simply overwrites it with identical content — no
duplicate-table error. **Re-run `php artisan migrate:fresh --seed`.**

### Defensive audit suite (now run each build)
Static cross-checks for the class of bugs that `php -l` / esbuild can't see:
1. Model `$fillable`/`$casts` columns all exist in the table migration — clean.
2. Every route's `[Controller::class, 'method']` resolves to a real method — clean.
3. Permission strings used in code all exist in the registry (incl. crud-generated) — clean.
4. Every FK target table exists — clean.
5. Every FK target table is created in an earlier migration than its use — clean.
Plus the existing FK-type audit and audit-column audit. All green.

---

## Product masters (categories / brands / units)

These lookup tables existed and were seeded, and products referenced them, but there was no
screen to manage them. Added a **Masters** area (sidebar) with three tabs — Categories,
Brands, Units — each a company-scoped list with add / edit / delete. Categories support an
optional parent; units carry a short name (kg, pc). One type-parameterized
`MasterDataController` backs all three at `/masters/{type}` (categories|brands|units), using
the `categories.* / brands.* / units.*` permissions that were already in the registry (now
also granted to Manager). No new tables — existing seeded lookups appear right away.

---

## Fix — SQL error adding a product with a supplier (Step 1 of the full brief)

**Root cause.** The `product_supplier` pivot was defined with `$table->uuid('id')->primary()`
— a non-nullable UUID primary key with no default. Eloquent's `belongsToMany(...)->sync()`
(used when a product's suppliers are saved) inserts pivot rows **without** a value for a
custom `id` column, so MySQL in strict mode rejects the insert with
`Field 'id' doesn't have a default value`. It only surfaces when a product is saved **with a
supplier** — `ProductSeeder` doesn't attach suppliers, which is why `migrate:fresh --seed`
never hit it.

**Fix.** Made the pivot consistent with the other two pivots in the schema (`company_user`,
`role_user`), which use a **composite primary key** and no surrogate id — and which work
correctly in the seed. `product_supplier` now uses `primary(['product_id','supplier_id'])`
with no `id` column, so `sync()` inserts cleanly.

**Verification.** Schema lints clean; the FK-type audit (including raw `foreign()` refs)
passes. A live MySQL insert test couldn't be run in the build sandbox (no DB driver), but the
shape is now identical to the two pivots already proven working by your successful seed.
**Re-run `php artisan migrate:fresh --seed`**, then add a product with a supplier to confirm.

Note on **multiple product photos**: the data layer already supports a gallery — `products.images`
is a JSON array and the store/update requests accept `images[]`. What's not yet built is the
binary upload endpoint + gallery UI (see the gap list shared in chat).

---

## Admin UX layer — foundation (first slice of the four-epic backlog)

Reusable primitives + profile, wired end-to-end so the rest of the app can adopt them:
- **Toasts** — `src/lib/toast.jsx` (ToastProvider + `useToast()`); success/error/info, auto-dismiss,
  accent-tinted, mounted app-wide. Replaces silent success/failure.
- **Confirm dialogs** — `src/lib/confirm.jsx` (`useConfirm()` → Promise<bool>); a soft glass
  modal for destructive/consequential actions. Replaces raw deletes.
- **Pagination** — `src/components/Pagination.jsx`, driven by the API's existing `meta`
  ({current_page,last_page,per_page,total,from,to}). Lists can now page instead of capping.
- **Instant status toggle** — `src/components/StatusToggle.jsx`; optimistic AJAX on/off with
  revert-on-error. Backed by a light `PATCH /products/{id}/status` endpoint (pattern to reuse
  for suppliers/customers/users/companies).
- **Profile page** — `/profile` (linked from the top-bar avatar menu): edit name/email/phone
  and change password. Backend `PUT /me` (validates `current_password` before a change).
- **Company-switch confirmation** — super admins get a confirm dialog + toast before the
  workspace switches companies.

Wired fully into the **Products list** as the reference implementation (pagination +
confirm-delete + toast + live status toggle). The primitives are ready to roll out to the
other lists/entities next.

Still queued from the four requested epics: media + detail (photo uploads, supplier bank
details, card layouts, per-supplier/customer purchase-history pages); the rest of the admin
toggle/pagination rollout and super-admin "pick company first" record creation; accounting
depth (cash/bank books, ageing, running expenses, profit & comparison reports, EOD summaries);
loyalty redemption and plant-care WhatsApp campaigns.

---

## Admin UX rollout across lists (Step 3, items 1–6)

The primitives from the previous slice are now wired across the main entity lists:
- **Status toggles everywhere** — instant AJAX active/deactivate on products, suppliers,
  customers, and users, backed by dedicated endpoints:
  `PATCH products|suppliers|customers/{id}/status` (status) and
  `PATCH users/{id}/status`, `PATCH companies/{company}/status` (is_active). Optimistic with
  revert-on-error; each fires a toast. (Blocked customers stay a badge; super admins can't be
  toggled.)
- **Delete confirmations + toasts** on every delete in those lists (replaced the old modals
  and a stray `window.confirm`).
- **Pagination** rendered on products, customers, and users lists (suppliers already had a
  pager); filters/search reset to page 1 and are preserved across pages.
- **Super admin** now excluded from the user list (backend filter), and the user list is
  paginated.
- **Create/update/delete/status/switch** actions now surface success/error toasts app-wide.

Still to come in the media/detail pass (Step 3 items 7–10): company list as cards +
username/password/photo/description + reset-password; supplier list as cards + photo +
address + bank details + purchase-history page; customer photo + purchase-history view;
product multiple-photo gallery upload; and super-admin "pick company first" on create forms.
These need a shared photo-upload/storage primitive, which is the first thing I'll build there.

---

## Media epic — upload primitive + product gallery (first slice of Step 3 items 7–10)

**Photo upload endpoint** — `POST /uploads` (auth required) accepts an image
(jpg/png/webp/gif, ≤5MB), stores it on the `public` disk under `uploads/`, and returns an
absolute URL. **Two setup steps on your machine:** run `php artisan storage:link` once, and
make sure `APP_URL` in `.env` is your backend host (e.g. `http://potandleaf-backend.test`)
so returned image URLs resolve from the SPA's dev origin.

**Reusable uploader components** (`src/components/media.jsx`):
- `ImageUpload` — single avatar/logo picker (upload / replace / remove, live preview).
- `ImageGallery` — multi-photo grid (first image = primary, up to N, per-photo remove).

**Product form** now has a **photo gallery** and a **description** field, both round-tripping
through the product's existing `images` (JSON) and `description` columns — completing Step 3
item 10 (multiple photos + description + the active toggle already on the list). Re-confirms
the Step 1 area: creating/editing a product with several photos and a supplier works.

**Schema groundwork** (migration `add_media_and_bank_fields`): added `photo` to suppliers and
customers; `bank_account_name` + `address` to suppliers (they already had bank_name /
bank_account_no / bank_ifsc); `logo` + `description` to companies. Columns + model `$fillable`
are in place, ready for the supplier/customer/company form wiring next.

Re-run `php artisan migrate:fresh --seed` (or `php artisan migrate`) to add the new columns.

Still queued in this epic: wire photo/bank/address into the supplier, customer, and company
forms; supplier & company **card list** layouts; per-supplier and per-customer **purchase-history**
pages; company **username/password + reset-password**; and super-admin **"pick company first"**
on create forms.
