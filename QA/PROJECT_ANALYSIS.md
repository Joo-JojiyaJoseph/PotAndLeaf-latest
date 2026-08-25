# PotAndLeaf ERP — Project Analysis (QA)

**Date:** 2026-08-25  
**Scope:** Read-only analysis — no production code changes, no package installs, no automated tests created.  
**Repository layout:** Monorepo with `PotAndLeaf-backend/` (Laravel API) and `PotAndLeaf-frontend/` (React SPA).

---

## Executive Summary

PotAndLeaf is a multi-company ERP for nursery/plant retail operations. The frontend is a decoupled React SPA that consumes a JSON REST API backed by Laravel 13. Authentication uses Laravel Sanctum bearer tokens with company scoping via the `X-Company-Id` header. Role-based access control (RBAC) gates both API endpoints and frontend routes.

Backend automated testing is mature (116 Pest tests, all passing). The frontend has **no** unit, integration, or E2E test framework configured.

---

## 1. Technology Stack

| Layer | Technology | Version / Notes |
|-------|------------|-----------------|
| **Frontend framework** | React | 19.x |
| **Build tool** | Vite | 6.x |
| **Routing** | React Router | 7.x |
| **Data fetching** | TanStack React Query | 5.x |
| **HTTP client** | Axios | 1.7.x |
| **Styling** | Tailwind CSS | 4.x |
| **Icons** | Heroicons | 2.x |
| **Backend framework** | Laravel | 13.x (PHP ^8.3) |
| **API auth** | Laravel Sanctum | 4.x (personal access tokens) |
| **PDF generation** | DomPDF (barryvdh/laravel-dompdf) | 3.x |
| **Frontend package manager** | **npm** | `package-lock.json` present |
| **Backend package manager** | **Composer** | `composer.lock` present |
| **Backend test framework** | **Pest** (PHPUnit) | Pest 5 + pest-plugin-laravel |
| **Frontend test framework** | **None** | No Vitest/Jest/Cypress/Playwright in `package.json` |
| **Database** | MySQL/SQLite (env-dependent) | Migrations under `PotAndLeaf-backend/database/migrations/` |

### Environment & Dev Proxy

- Frontend dev server: `http://localhost:5173` (`npm run dev`)
- API proxy: `/api` → `VITE_API_PROXY` (default `http://potandleaf-backend.test`)
- Production API base: `VITE_API_URL` at build time (see `.env.production`)

---

## 2. Application Entry Points

| App | Entry | Config |
|-----|-------|--------|
| **Frontend SPA** | `PotAndLeaf-frontend/src/main.jsx` | Mounts `App` inside `BrowserRouter`, `QueryClientProvider`, `AuthProvider` |
| **Frontend routing root** | `PotAndLeaf-frontend/src/App.jsx` | All client-side routes |
| **Backend HTTP** | `PotAndLeaf-backend/public/index.php` | Standard Laravel front controller |
| **Backend routing** | `PotAndLeaf-backend/routes/api.php` | Registered in `bootstrap/app.php` under `/api` prefix |
| **Backend bootstrap** | `PotAndLeaf-backend/bootstrap/app.php` | API-only JSON exception handling |

---

## 3. Application Structure

```
PotAndLeaf-backend/          # Workspace root
├── PotAndLeaf-backend/      # Laravel API
│   ├── app/
│   │   ├── Actions/         # Domain actions (CreateProduct, etc.)
│   │   ├── Http/Controllers/Api/
│   │   ├── Http/Middleware/ # ResolveApiCompany, EnsureUserIsActive
│   │   ├── Http/Requests/   # Form request validation
│   │   ├── Models/
│   │   ├── Services/        # InventoryService, ReportService, etc.
│   │   └── Support/Rbac/    # PermissionRegistry
│   ├── routes/api.php       # ~200 API endpoints
│   ├── database/migrations/
│   ├── database/seeders/
│   └── tests/               # Pest feature + unit tests
├── PotAndLeaf-frontend/     # React SPA
│   ├── src/
│   │   ├── App.jsx          # Route definitions
│   │   ├── main.jsx         # Bootstrap
│   │   ├── components/      # AppShell, Sidebar, UI primitives
│   │   ├── context/         # AuthContext
│   │   ├── lib/             # api.js, queryClient, toast, confirm
│   │   ├── pages/           # Feature pages (~70 route targets)
│   │   └── routes/          # ProtectedRoute, PermissionRoute
│   └── vite.config.js
└── QA/
    └── PROJECT_ANALYSIS.md  # This document
```

---

## 4. Modules / Features

Modules align with sidebar groups in `Sidebar.jsx` and backend controllers in `routes/api.php`.

### Main (Operations)

| Module | Frontend path(s) | Backend controller | Key capabilities |
|--------|------------------|-------------------|------------------|
| Dashboard | `/` | `DashboardController` | KPIs, alerts, quick stats |
| Purchases (GRN) | `/purchases`, `/purchases/new`, `/purchases/:id` | `PurchaseController` | Create/edit/confirm GRN, PDF invoice |
| Purchase Orders | `/purchase-orders/*` | `PurchaseOrderController` | PO lifecycle, reorder suggestions, convert to GRN |
| Inventory | `/inventory` | `InventoryController` | Stock, ledger, valuation, movement, cross-branch |
| Batches & Barcodes | `/inventory/batches`, `/products/labels` | `ProductController` | Batch overview, scan, opening batch generation, label print |
| Damage Entry | `/damage-entries` | `DamageEntryController` | Stock write-off |
| Purchase Returns | `/purchase-returns/*` | `PurchaseReturnController` | Debit note + stock reversal |
| Stock Count | `/stock-verifications/*` | `StockVerificationController` | Physical count, submit, HO approve/reject |
| Bulk Split | `/bulk-splits/*` | `BulkSplitController` | Cost redistribution, stock conversion |
| Transfers | `/transfers/*` | `TransferController` | Inter-location/branch transfers, dispatch/receive workflow |
| Production | `/production/*` | `ProductionController` | BOMs, production orders, stage workflow |

### Commerce

| Module | Frontend path(s) | Backend controller | Key capabilities |
|--------|------------------|-------------------|------------------|
| Sales / POS | `/sales/*` | `SaleController` | Draft/confirm sales, proforma, cancellation workflow, WhatsApp |
| Sales Returns | `/sales-returns/*` | `SalesReturnController` | Return against confirmed sale |
| Backorders | `/backorders/*` | `BackorderController` | Shortage handling, fulfill from sale |
| Advance Orders | `/advance-orders/*` | `AdvanceOrderController` | Customer pre-bookings |
| Plant Rental | `/rentals/*` | `RentalController` | Issue, return, settle, invoice |
| Customers | `/customers/*` | `CustomerController` | CRUD, purchase history |
| Loyalty | `/loyalty` | `LoyaltyController` | Points, rules, manual adjust |
| Commission | `/commission` | `CommissionController` | Rules, tiers, payouts, promotions, WhatsApp templates |

### Setup & Admin

| Module | Frontend path(s) | Backend controller | Key capabilities |
|--------|------------------|-------------------|------------------|
| Suppliers | `/suppliers/*` | `SupplierController` | CRUD, purchase history |
| Supplier Payments | `/payments` | `SupplierPaymentController` | Payables, record payment |
| Customer Receipts | `/receipts` | `CustomerReceiptController` | Receivables, record receipt |
| Companies | `/companies/*` | `CompanyController` | **Super-admin only** — multi-tenant management |
| Roles | `/roles` | `RoleController` | Permission matrix per company |
| Users | `/users/*` | `UserController` | User CRUD, company assignment |
| Master Data | `/masters` | `MasterDataController` | Categories, brands, units |
| Products | `/products/*` | `ProductController` | Product master, opening stock, batches |
| Locations | `/locations` | `LocationController` | Warehouse/branch locations |
| Reports | `/reports` | `ReportController` | Sales, margin, profit, rental, production, accounting, GST |
| Activity Monitoring | `/activity-monitoring` | `ActivityMonitoringController` | HO audit trail |
| Backups | `/backups` | `BackupController` | DB backup run/download/restore |
| Settings | `/settings` | `SettingsController` | Company-level configuration |
| Profile | `/profile` | `AuthController` | User profile + password |

---

## 5. Frontend Routes / Pages

**Total:** 69 explicit routes in `App.jsx` (excluding catch-all redirect).

### Public

| Path | Component | Guard |
|------|-----------|-------|
| `/login` | `Login` | None |

### Protected (require token via `ProtectedRoute`)

| Path | Component | Permission / Guard |
|------|-----------|-------------------|
| `/` | `Dashboard` | `reports.view` |
| `/suppliers` | `SuppliersList` | `suppliers.view` |
| `/suppliers/:id` | `SupplierDetail` | `suppliers.view` |
| `/products` | `ProductsList` | `products.view` |
| `/products/labels` | `BarcodeLabelsPage` | `products.view` |
| `/products/new` | `ProductForm` | `products.create` |
| `/products/:id/edit` | `ProductForm` | `products.update` |
| `/products/:id` | `ProductDetail` | `products.view` |
| `/purchases` | `PurchasesList` | `purchases.view` |
| `/purchases/new` | `PurchaseForm` | `purchases.create` |
| `/purchases/:id/edit` | `PurchaseForm` | `purchases.update` |
| `/purchases/:id` | `PurchaseDetail` | `purchases.view` |
| `/inventory` | `InventoryList` | `inventory.view` |
| `/damage-entries` | `DamageEntriesPage` | `damage.view` |
| `/inventory/batches` | `BatchesPage` | `inventory.view` |
| `/purchase-returns` | `PurchaseReturnsList` | `purchase_returns.view` |
| `/purchase-returns/new` | `PurchaseReturnForm` | `purchase_returns.create` |
| `/purchase-returns/:id` | `PurchaseReturnDetail` | `purchase_returns.view` |
| `/stock-verifications` | `StockVerificationsList` | `stock_verifications.view` |
| `/stock-verifications/new` | `StockVerificationForm` | `stock_verifications.create` |
| `/stock-verifications/:id` | `StockVerificationDetail` | `stock_verifications.view` |
| `/companies` | `CompaniesList` | Super admin |
| `/companies/:id` | `CompanyDetail` | Super admin |
| `/users` | `UsersList` | `users.view` |
| `/users/:id` | `UserDetail` | `users.view` |
| `/roles` | `RolesList` | `roles.view` |
| `/masters` | `MastersPage` | Any of `categories.view`, `subcategories.view`, `units.view` |
| `/profile` | `ProfilePage` | Authenticated (no specific permission) |
| `/bulk-splits` | `BulkSplitsList` | `bulk_splits.view` |
| `/bulk-splits/new` | `BulkSplitForm` | `bulk_splits.create` |
| `/bulk-splits/:id` | `BulkSplitDetail` | `bulk_splits.view` |
| `/customers` | `CustomersList` | `customers.view` |
| `/customers/:id` | `CustomerDetail` | `customers.view` |
| `/loyalty` | `LoyaltyPage` | `loyalty.view` |
| `/sales` | `SalesList` | `sales.view` |
| `/sales/new` | `SaleForm` | `sales.create` |
| `/sales/:id` | `SaleDetail` | `sales.view` |
| `/sales-returns` | `SalesReturnsList` | `sales_returns.view` |
| `/sales-returns/new` | `SalesReturnForm` | `sales_returns.create` |
| `/sales-returns/:id` | `SalesReturnDetail` | `sales_returns.view` |
| `/payments` | `PaymentsList` | `payments.view` |
| `/receipts` | `ReceiptsList` | `receipts.view` |
| `/commission` | `CommissionList` | `commission.view` |
| `/transfers` | `TransfersList` | `transfers.view` |
| `/transfers/new` | `TransferForm` | `transfers.create` |
| `/transfers/:id` | `TransferDetail` | `transfers.view` |
| `/locations` | `LocationsList` | `locations.view` |
| `/production` | `ProductionList` | `production.view` |
| `/production/orders/:id` | `ProductionOrderDetail` | `production.view` |
| `/rentals` | `RentalsList` | `rental.view` |
| `/rentals/new` | `RentalForm` | `rental.create` |
| `/rentals/:id` | `RentalDetail` | `rental.view` |
| `/reports` | `ReportsPage` | `reports.view` |
| `/activity-monitoring` | `ActivityMonitoringPage` | `activity.view` |
| `/backups` | `BackupDashboardPage` | `backup.view` |
| `/purchase-orders` | `PurchaseOrdersList` | `po.view` |
| `/purchase-orders/reorder` | `PurchaseOrderReorderPage` | `po.create` |
| `/purchase-orders/new` | `PurchaseOrderForm` | `po.create` |
| `/purchase-orders/:id` | `PurchaseOrderDetail` | `po.view` |
| `/advance-orders` | `AdvanceOrdersList` | `advance.view` |
| `/advance-orders/new` | `AdvanceOrderForm` | `advance.create` |
| `/advance-orders/:id` | `AdvanceOrderDetail` | `advance.view` |
| `/backorders` | `BackordersList` | `backorder.view` |
| `/backorders/new` | `BackorderForm` | `backorder.create` |
| `/backorders/:id` | `BackorderDetail` | `backorder.view` |
| `/settings` | `SettingsPage` | Authenticated |
| `/soon/:module` | `ComingSoon` | Placeholder |
| `*` | Redirect to `/` | — |

**Route guards:**

- `ProtectedRoute` — redirects to `/login` if no bearer token
- `PermissionRoute` — blocks page content if user lacks permission (super-admin bypass)

---

## 6. Forms

### Dedicated full-page forms (`*Form.jsx`)

| Form | Path | Primary API |
|------|------|-------------|
| `PurchaseForm` | `/purchases/new`, `/purchases/:id/edit` | `GET/POST/PUT /purchases` |
| `PurchaseOrderForm` | `/purchase-orders/new` | `GET/POST /purchase-orders` |
| `PurchaseReturnForm` | `/purchase-returns/new` | `GET/POST /purchase-returns` |
| `ProductForm` | `/products/new`, `/products/:id/edit` | `GET/POST/PUT /products` |
| `SaleForm` | `/sales/new` | `GET/POST /sales` |
| `SalesReturnForm` | `/sales-returns/new` | `GET/POST /sales-returns` |
| `BulkSplitForm` | `/bulk-splits/new` | `GET/POST /bulk-splits` |
| `TransferForm` | `/transfers/new` | `GET/POST /transfers` |
| `StockVerificationForm` | `/stock-verifications/new` | `GET/POST /stock-verifications` |
| `RentalForm` | `/rentals/new` | `GET/POST /rentals` |
| `AdvanceOrderForm` | `/advance-orders/new` | `GET/POST /advance-orders` |
| `BackorderForm` | `/backorders/new` | `GET/POST /backorders` |

### Modal / inline forms (list & detail pages)

| Location | Form purpose | Key fields / actions |
|----------|--------------|---------------------|
| `Login.jsx` | Sign in | email, password |
| `ProfilePage.jsx` | Profile update | name, email, phone, password change |
| `SettingsPage.jsx` | Company settings | GST, invoice prefs, cash opening, etc. |
| `CompaniesList.jsx` | Create/edit company | company details, status toggle |
| `SuppliersList.jsx` | Create/edit supplier | name, GST, contact, company scope |
| `CustomersList.jsx` | Create/edit customer | name, phone, pricing tier, GST |
| `ProductsList.jsx` | Quick actions | status toggle, filters |
| `MastersPage.jsx` | Categories/brands/units CRUD | name, company (super-admin) |
| `UsersList.jsx` | Create/edit user | name, email, role, companies |
| `RolesList.jsx` | Create/edit role | permission matrix checkboxes |
| `LocationsList.jsx` | Create/edit location | name, type, branch |
| `DamageEntriesPage.jsx` | Record damage | product, qty, reason, location |
| `PaymentsList.jsx` | Record supplier payment | supplier, amount, mode, purchase link |
| `ReceiptsList.jsx` | Record customer receipt | customer, amount, sale link |
| `StockVerificationsList.jsx` | Quick create modal | location, date |
| `StockVerificationDetail.jsx` | Approve/reject/adjust lines | counted qty per product |
| `CommissionList.jsx` | Multiple modals | rules, tiers, daily targets, promotions, seasonal rules, WhatsApp templates, payouts |
| `LoyaltyPage.jsx` | Loyalty rules + point adjust | earn/redeem rules, manual adjust |
| `ProductionList.jsx` | BOM + production order modals | BOM lines, order stages |
| `SaleDetail.jsx` | Confirm/cancel/convert actions | workflow buttons + modals |
| `TransferDetail.jsx` | Dispatch/approve/receive/reject | workflow actions |
| `RentalDetail.jsx` | Activate/return/settle/invoice | rental lifecycle |
| `PurchaseOrderReorderPage.jsx` | Batch PO from reorder report | supplier grouping |

### Shared form patterns

- TanStack Query for `form-data` prefetch (`GET */form-data`)
- Axios via `src/lib/api.js` with auth + company headers
- Validation errors from Laravel 422 `errors` object
- `useSubmitLock` hook prevents double-submit on critical forms

---

## 7. API Endpoints

**Base URL:** `/api`  
**Route count:** ~200 registered routes in `routes/api.php` (~100 `Route::` declarations, many with multiple verbs).

### Public

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/login` | Issue Sanctum token |

### Authenticated (no company scope)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/me` | Current user + companies |
| PUT | `/me` | Update profile / password |
| POST | `/logout` | Revoke token |
| POST | `/uploads` | File upload |
| GET/POST/PUT/PATCH/DELETE | `/companies`, `/companies/{id}`, `/companies/{id}/status` | Super-admin company management |

### Company-scoped (require `X-Company-Id`)

All routes below are wrapped in `ResolveApiCompany` middleware.

#### Auth & Dashboard

| Method | Endpoint |
|--------|----------|
| GET | `/permissions` |
| GET | `/dashboard` |

#### Masters & Products

| Method | Endpoint |
|--------|----------|
| GET/POST/PUT/DELETE | `/masters/{type}`, `/masters/{type}/{id}` — types: `categories`, `brands`, `units` |
| GET | `/products/form-data`, `/products`, `/products/{id}`, `/products/{id}/batches` |
| POST/PUT/DELETE | `/products`, `/products/{id}` |
| PATCH | `/products/{id}/status` |
| GET | `/batches/scan`, `/inventory/batches` |
| POST | `/batches/generate-opening` |

#### Procurement

| Method | Endpoint |
|--------|----------|
| GET/POST/PUT/DELETE | `/purchases`, `/purchases/{id}` |
| POST | `/purchases/{id}/confirm` |
| GET | `/purchases/{id}/batches`, `/purchases/{id}/invoice.pdf` |
| GET/POST/DELETE | `/purchase-returns`, `/purchase-returns/{id}` |
| POST | `/purchase-returns/{id}/confirm` |
| GET | `/purchase-returns/source` |
| GET/POST/DELETE | `/purchase-orders`, `/purchase-orders/{id}` |
| POST | `/purchase-orders/{id}/send`, `/purchase-orders/{id}/convert` |
| GET | `/purchase-orders/form-data`, `/suggestions`, `/reorder-report` |
| POST | `/purchase-orders/batch-from-reorder` |

#### Inventory & Stock

| Method | Endpoint |
|--------|----------|
| GET | `/inventory/stock`, `/inventory/stock/cross-branch`, `/inventory/alerts` |
| GET | `/inventory/ledger`, `/inventory/ledger/form-data`, `/inventory/ledger/export` |
| GET | `/inventory/valuation`, `/inventory/movement`, `/inventory/by-location` |
| GET/POST | `/damage-entries`, `/damage-entries/form-data` |
| GET/POST/DELETE | `/bulk-splits`, `/bulk-splits/{id}` |
| POST | `/bulk-splits/{id}/confirm` |
| GET/POST | `/stock-verifications`, `/stock-verifications/{id}` |
| POST | `/stock-verifications/{id}/submit`, `/approve`, `/reject` |

#### Transfers & Locations

| Method | Endpoint |
|--------|----------|
| GET/POST/PUT/DELETE | `/locations`, `/locations/{id}` |
| GET/POST/DELETE | `/transfers`, `/transfers/{id}` |
| POST | `/transfers/{id}/dispatch`, `/approve`, `/reject`, `/redirect`, `/receive` |

#### Sales & Returns

| Method | Endpoint |
|--------|----------|
| GET/POST/DELETE | `/sales`, `/sales/{id}` |
| POST | `/sales/{id}/confirm`, `/cancel-request`, `/cancel-approve`, `/cancel-reject`, `/convert-proforma`, `/whatsapp` |
| GET | `/sales/form-data`, `/sales/{id}/invoice.pdf` |
| GET/POST/DELETE | `/sales-returns`, `/sales-returns/{id}` |
| POST | `/sales-returns/{id}/confirm` |
| GET | `/sales-returns/source` |

#### Customers, Loyalty, Commerce

| Method | Endpoint |
|--------|----------|
| GET/POST/PUT/DELETE | `/customers`, `/customers/{id}` |
| PATCH | `/customers/{id}/status` |
| GET | `/customers/{id}/purchase-history` |
| GET/POST/DELETE | `/loyalty`, `/loyalty/rules`, `/loyalty/rules/{id}` |
| POST | `/loyalty/adjust` |
| GET/POST/DELETE | `/advance-orders`, `/advance-orders/{id}` |
| POST | `/advance-orders/{id}/fulfill` |
| GET/POST/DELETE | `/backorders`, `/backorders/{id}` |
| POST | `/backorders/{id}/fulfill`, `/sales/{id}/backorder` |

#### Rentals & Production

| Method | Endpoint |
|--------|----------|
| GET/POST/DELETE | `/rentals`, `/rentals/{id}` |
| POST | `/rentals/{id}/activate`, `/return`, `/settle`, `/invoices` |
| POST/DELETE | `/rental-invoices/{id}/paid`, `/whatsapp` |
| GET | `/rental-invoices/{id}/invoice.pdf` |
| GET/POST/PUT/DELETE | `/production/boms`, `/production/boms/{id}` |
| GET/POST/PUT/DELETE | `/production/orders`, `/production/orders/{id}` |
| POST | `/production/orders/{id}/complete`, `/stages/{id}/start`, `/stages/{id}/complete` |
| GET | `/production/form-data`, `/production/estimate` |

#### Payments & Commission

| Method | Endpoint |
|--------|----------|
| GET/POST/DELETE | `/supplier-payments`, `/customer-receipts` |
| GET | `/supplier-payments/form-data`, `/payables`, `/customer-receipts/form-data`, `/receivables` |
| GET/POST/DELETE | `/commission/rules`, `/commission/payouts`, `/commission/promotions`, etc. |
| GET | `/commission/form-data`, `/compute`, `/daily-summary`, `/transactions` |
| POST | `/commission/send-eod`, `/commission/rules/{id}/tiers`, `/daily-targets` |

#### Suppliers, Users, Roles, Settings

| Method | Endpoint |
|--------|----------|
| GET/POST/PUT/DELETE | `/suppliers`, `/suppliers/{id}` |
| GET | `/suppliers/{id}/purchase-history` |
| PATCH | `/suppliers/{id}/status`, `/users/{id}/status` |
| GET/POST/PUT/DELETE | `/users`, `/users/{id}`, `/roles`, `/roles/{id}` |
| GET | `/users/form-data`, `/roles/form-data` |
| GET/PUT | `/settings` |

#### Reports, Activity, Backups

| Method | Endpoint |
|--------|----------|
| GET | `/reports/*` — dashboard, margin, profit, price-levels, rental, production, transfers, accounting, sales comparison, GST reconciliation, commission, leaderboard, EOD |
| GET | `/reports/*/export` — CSV/PDF exports for many report types |
| POST | `/reports/eod-management/send` |
| GET | `/activity-monitoring`, `/activity-monitoring/form-data` |
| GET/POST | `/backups`, `/backups/run`, `/backups/{filename}/download`, `/restore` |

### Frontend → API integration

- Central client: `PotAndLeaf-frontend/src/lib/api.js`
- ~200+ `api.get/post/put/patch/delete` calls across 60+ frontend files
- PDF downloads via `lib/pdfDownload.js` (authenticated blob fetch)
- Super-admin cross-company reads: `withCompany(id, config)` helper + `?company_id=` query param on GET lists

---

## 8. Authentication Mechanism

### Flow

1. User submits email/password to `POST /api/login`
2. Backend validates credentials, checks `is_active`, company membership
3. Returns Sanctum personal access token + user payload + accessible companies
4. Frontend stores token in `localStorage` (`pl_token`) and default company (`pl_company`)
5. All subsequent requests include:
   - `Authorization: Bearer {token}`
   - `X-Company-Id: {companyId}` (for company-scoped routes)
6. On 401, axios interceptor clears token and redirects to `/login`
7. On app load, `AuthContext` calls `GET /me` to rehydrate session

### Key files

| File | Role |
|------|------|
| `PotAndLeaf-frontend/src/lib/api.js` | Token storage, interceptors, `withCompany()` |
| `PotAndLeaf-frontend/src/context/AuthContext.jsx` | login/logout, company switch, permissions load |
| `PotAndLeaf-backend/app/Http/Controllers/Api/AuthController.php` | login, me, logout, permissions |
| `PotAndLeaf-backend/app/Http/Middleware/ResolveApiCompany.php` | Company membership validation |
| `PotAndLeaf-backend/app/Http/Middleware/EnsureUserIsActive.php` | Block deactivated users |

### Multi-tenancy

- Users belong to one or more companies via pivot
- Super admins (`is_super_admin`) bypass permission checks and can access all companies
- Writes always use active `X-Company-Id`; super-admin can filter GET lists with `?company_id=`

---

## 9. Authorization / Roles

### Permission model

- Permissions defined in `app/Support/Rbac/PermissionRegistry.php` (single source of truth)
- Seeded via `PermissionSeeder`
- Roles are company-scoped; users get permissions through assigned roles
- Wildcard: `*` = full access; `{module}.*` = all actions in module

### Permission groups (summary)

| Group | Example permissions |
|-------|---------------------|
| System | `*` |
| Suppliers | `suppliers.view/create/update/delete/force-delete` |
| Products | `products.*`, `products.view_cost` |
| Purchases | `purchases.*`, `purchases.confirm` |
| Purchase Returns | `purchase_returns.view/create/confirm/delete` |
| Inventory / Damage | `inventory.view`, `damage.view/create` |
| Sales | `sales.view/create/confirm/delete/cancel_*`, `sales.whatsapp` |
| Sales Returns | `sales_returns.*` |
| PO / Advance / Backorder | `po.*`, `advance.*`, `backorder.*` |
| Rental / Production / Transfers | `rental.*`, `production.*`, `transfers.*` |
| Commission / Loyalty / WhatsApp | `commission.*`, `loyalty.*`, `whatsapp.templates` |
| Receipts / Payments | `receipts.*`, `payments.*` |
| Bulk Split / Stock Count | `bulk_splits.*`, `stock_verifications.*` |
| Master data | `categories.*`, `subcategories.*`, `units.*` |
| Users / Roles / Settings | `users.*`, `roles.*`, `settings.*` |
| Reports / Activity / Backups | `reports.*`, `activity.view`, `backup.*` |
| Locations | `locations.view/manage` |

### Enforcement layers

| Layer | Mechanism |
|-------|-----------|
| **Frontend navigation** | `Sidebar.jsx` filters items by `can(permission)` |
| **Frontend routes** | `PermissionRoute` blocks unauthorized direct URL access |
| **Frontend actions** | Buttons gated with `can('module.action')` |
| **Backend** | Controller/policy checks via `InteractsWithPermissions` trait on User model |

### Special roles

- **Super Admin (HO):** `user.is_super_admin === true` — full UI access, company management, cross-branch reporting
- **Company Admin / Staff:** Custom roles with subset of permissions per branch

---

## 10. Existing Tests

### Backend (Pest / PHPUnit)

**Location:** `PotAndLeaf-backend/tests/`  
**Count:** 116 tests, 405 assertions — **all passing** (`php artisan test`)

| Test file | Focus area | ~Tests |
|-----------|------------|--------|
| `ErpQaMatrixTest.php` | Cross-module QA flows (group: `qa`) | 9 |
| `SalesPhaseATest.php` | Sales/POS | 9 |
| `CommissionIncentiveLoyaltyTest.php` | Commission + loyalty | 10 |
| `RentalPhase4Test.php` | Rentals | 8 |
| `RentalReportsTest.php` | Rental reports | 12 |
| `CompanyManagementTest.php` | Companies (super-admin) | 8 |
| `TransferPhase3Test.php` | Transfers phase 3 | 4 |
| `TransferPhase5Test.php` | Transfers phase 5 | 7 |
| `TransferCancelBatchTest.php` | Transfer cancel | 1 |
| `ProductionPhase1Test.php` | Production | 4 |
| `ProductionPhase2Test.php` | Production | 5 |
| `ProductionFormDataTest.php` | Production form data | 3 |
| `BackordersPhaseBTest.php` | Backorders | 7 |
| `PurchaseOrdersPhaseDTest.php` | Purchase orders | 4 |
| `AccountingPhaseCTest.php` | Accounting reports | 7 |
| `ReportsAnalyticsTest.php` | Analytics reports | 7 |
| `PosSaleReceiptTest.php` | POS + receipts | 3 |
| `MasterDataTest.php` | Master data CRUD | 2 |
| `PurchaseUpdateTest.php` | Purchase update | 1 |
| `ProductStockTest.php` | Product stock / opening | 3 |
| `ExampleTest.php` (Feature + Unit) | Smoke | 2 |

**Test utilities:** `tests/Support/CreatesErpFixtures.php` — factory helpers for company, user, product, customer, API headers.

**Run commands:**

```bash
cd PotAndLeaf-backend
php artisan test                    # full suite
php artisan test --group=qa         # QA matrix only
php artisan test tests/Feature/MasterDataTest.php  # single file
```

### Frontend

| Type | Status |
|------|--------|
| Unit tests | **None** |
| Integration tests | **None** |
| E2E tests | **None** |
| Test runner in package.json | **None** (only `dev`, `build`, `preview`) |

### Manual / external QA artifacts

- Excel test case matrix referenced in prior sessions: `PotAndLeaf_ERP_FullTestCases` (~158 cases)
- Prior mapping: ~99 Pass, ~16 Partial, ~43 Manual (requires deployed environment + UI verification)

---

## 11. Recommended Testing Approach

### Phase 1 — Manual QA (immediate, no code changes)

1. **Environment matrix**
   - Local: Vite `:5173` + Laravel API (Herd/Valet or `php artisan serve`)
   - Staging: production-like API URL
   - Test with at least 2 company contexts + super-admin account

2. **Role-based smoke**
   - For each permission group, verify sidebar visibility, route access, and action buttons
   - Confirm 403/permission-denied UI for direct URL navigation without permission

3. **Critical business flows** (priority order)
   - Purchase → confirm → inventory ledger update
   - Sale → confirm → stock deduction → receipt
   - Opening stock on product create (no double-count)
   - Bulk split (qty cap vs available stock)
   - Transfer (dispatch → receive, qty limits)
   - Purchase return / sales return confirm
   - Stock verification submit → HO approve
   - PO → convert to GRN
   - Rental activate → return → settle

4. **Cross-cutting checks**
   - Company switch refreshes permissions and data scope
   - Super-admin company selector on multi-company forms
   - PDF invoice downloads (purchase, sale, rental)
   - Report exports (CSV/PDF)
   - 401 logout redirect
   - Validation error display (422)

5. **Use existing Excel matrix**
   - Map each of 158 cases to: route + API + role + expected result
   - Mark Partial/Manual cases for GST splits, barcode printing, WhatsApp sends

### Phase 2 — Extend backend automated tests (when approved)

- Add regression tests for recently fixed bugs (company_id validation, opening stock, transfer/bulk-split qty)
- Expand `ErpQaMatrixTest` for purchase returns, GST reconciliation, customer pricing tiers
- Add permission-denied tests per controller for RBAC coverage gaps
- Run in CI on every PR: `composer test`

### Phase 3 — Frontend test infrastructure (when approved)

| Layer | Tool suggestion | Scope |
|-------|-----------------|-------|
| Unit | Vitest + React Testing Library | `AuthContext`, `api.js`, formatters, permission helpers |
| Integration | MSW (Mock Service Worker) | Form submit flows with mocked API |
| E2E | Playwright | Login, CRUD smoke, critical workflows across modules |

**Suggested first E2E scenarios:**

1. Login → select company → dashboard loads
2. Create product with opening stock → verify inventory
3. Create purchase → confirm → stock increases
4. Create sale → confirm → stock decreases
5. Permission-restricted user cannot access `/companies`

### Phase 4 — CI/CD quality gates

- Backend: `php artisan test` (required pass)
- Frontend: `npm run build` (required pass)
- Optional: Playwright against staging on merge to main
- Optional: Laravel Pint for code style

### Test data strategy

- Use `CreatesErpFixtures` and seeders for consistent backend tests
- Maintain dedicated QA company + users per role in staging DB
- Never test destructive backup/restore against production

### Risk areas (higher defect likelihood)

| Area | Reason |
|------|--------|
| Multi-company scoping | `X-Company-Id`, super-admin overrides, `company_id` on writes |
| Stock math | Ledger posts, opening stock, splits, transfers, returns |
| Workflow state machines | Sales cancellation, transfer approve/receive, stock verification |
| GST / interstate | Tax splits on purchase/sale |
| Reports & exports | Large data sets, date filters, export formats |
| Commission / loyalty | Complex rule engines, tier sync |
| PDF / WhatsApp | External integrations, manual verification |

---

## 12. Quick Reference Checklist

| # | Item | Finding |
|---|------|---------|
| 1 | Frontend framework | React 19 + Vite 6 |
| 2 | Backend/API | Laravel 13 REST JSON API |
| 3 | Package manager | npm (frontend), Composer (backend) |
| 4 | Testing framework | Pest 5 (backend only) |
| 5 | Entry point | `main.jsx` (FE), `public/index.php` + `routes/api.php` (BE) |
| 6 | Routes/pages | 69 frontend routes; ~200 API endpoints |
| 7 | Modules/features | 25+ ERP modules (see Section 4) |
| 8 | Forms | 12 full-page forms + 18+ modal/inline forms |
| 9 | API calls | Centralized Axios client; 200+ call sites |
| 10 | Authentication | Laravel Sanctum bearer token + localStorage |
| 11 | Authorization | RBAC via PermissionRegistry; super-admin bypass |
| 12 | Existing tests | 116 backend Pest tests (pass); 0 frontend tests |

---

*Generated by QA analysis — read-only inspection. No application code was modified.*
