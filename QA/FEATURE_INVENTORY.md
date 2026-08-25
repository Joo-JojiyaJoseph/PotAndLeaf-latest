# PotAndLeaf ERP — Feature Inventory

**Date:** 2026-08-25  
**Purpose:** Complete QA feature inventory for manual and automated test planning.  
**Scope:** Read-only analysis — no production code modified.

---

## Legend

| Symbol | Meaning |
|--------|---------|
| ✅ | Supported |
| ❌ | Not supported |
| ⚡ | Workflow action (not classic CRUD update) |
| 🔒 | Super-admin only |

### Priority

| Level | Meaning |
|-------|---------|
| **P0** | Critical path — auth, stock, sales, purchases |
| **P1** | Core ERP modules — returns, transfers, orders |
| **P2** | Setup, admin, master data |
| **P3** | Reports, analytics, monitoring |

### Standard list capabilities (when applicable)

Most paginated list endpoints support: `page`, `per_page` (max 100), and super-admin `company_id=all|{id}` on GET. Unless noted, **Sort** is fixed server-side (typically date DESC or name ASC).

---

## Module CRUD Matrix (Summary)

| Module | Page | Route | Create | List | View | Update | Delete | Search | Filter | Sort | Pagination | Primary Permission |
|--------|------|-------|--------|------|------|--------|--------|--------|--------|------|------------|-------------------|
| Auth | Login | `/login` | — | — | — | Profile ✅ | Logout | — | — | — | — | Public / authenticated |
| Dashboard | Dashboard | `/` | — | ✅ | — | — | — | — | Company 🔒 | — | — | `reports.view` |
| Companies | CompaniesList | `/companies` | ✅ Modal | ✅ | ✅ Detail | ✅ Modal | ✅ | ✅ | — | name | ❌ | 🔒 Super admin |
| Suppliers | SuppliersList | `/suppliers` | ✅ Modal | ✅ | ✅ Detail | ✅ Modal | ✅ | ✅ | status | ✅ | ✅ | `suppliers.view` |
| Customers | CustomersList | `/customers` | ✅ Modal | ✅ | ✅ Detail | ✅ Modal | ✅ | ✅ | type | — | ✅ | `customers.view` |
| Products | ProductsList | `/products` | ✅ Form | ✅ | ✅ Detail | ✅ Form | ✅ | ✅ | status, category, low | name | ✅ | `products.view` |
| Master Data | MastersPage | `/masters` | ✅ Modal | ✅ | — | ✅ Modal | ✅ | ❌ | tab (type) | name | ❌ | `categories/subcategories/units.view` |
| Purchases | PurchasesList | `/purchases` | ✅ Form | ✅ | ✅ Detail | ✅ Form | ✅ Draft | ✅ | status, supplier | date ↓ | ✅ | `purchases.view` |
| Purchase Orders | PurchaseOrdersList | `/purchase-orders` | ✅ Form | ✅ | ✅ Detail | ❌ | ✅ | ✅ | status | date ↓ | ✅ | `po.view` |
| Purchase Returns | PurchaseReturnsList | `/purchase-returns` | ✅ Form | ✅ | ✅ Detail | ❌ | ✅ Draft | ✅ | status | date ↓ | ✅ | `purchase_returns.view` |
| Inventory | InventoryList | `/inventory` | — | ✅ Tabs | — | — | — | ✅ stock/ledger | low, dates, product | — | ✅ | `inventory.view` |
| Batches | BatchesPage | `/inventory/batches` | ⚡ Generate | ✅ | — | — | — | ✅ scan | product | — | ✅ | `inventory.view` |
| Barcode Labels | BarcodeLabelsPage | `/products/labels` | — | ✅ | — | — | — | ✅ | product | — | — | `products.view` |
| Damage | DamageEntriesPage | `/damage-entries` | ✅ Modal | ✅ | — | ❌ | ❌ | — | product, dates | date ↓ | ✅ | `damage.view` |
| Bulk Splits | BulkSplitsList | `/bulk-splits` | ✅ Form | ✅ | ✅ Detail | ❌ | ✅ Draft | ✅ | status | date ↓ | ✅ | `bulk_splits.view` |
| Transfers | TransfersList | `/transfers` | ✅ Form | ✅ | ✅ Detail | ❌ | ✅ Draft | ✅ | status | date ↓ | ✅ | `transfers.view` |
| Stock Count | StockVerificationsList | `/stock-verifications` | ✅ Form | ✅ | ✅ Detail | ❌ | ❌ | ✅ | status | date ↓ | ✅ | `stock_verifications.view` |
| Production | ProductionList | `/production` | ✅ Modal | ✅ | ✅ Order | ✅ Order/BOM | ✅ | — | status | date ↓ | ✅ orders | `production.view` |
| Sales | SalesList | `/sales` | ✅ Form | ✅ | ✅ Detail | ❌ | ✅ Draft | ✅ | status | date ↓ | ✅ | `sales.view` |
| Sales Returns | SalesReturnsList | `/sales-returns` | ✅ Form | ✅ | ✅ Detail | ❌ | ✅ Draft | ✅ | status | date ↓ | ✅ | `sales_returns.view` |
| Advance Orders | AdvanceOrdersList | `/advance-orders` | ✅ Form | ✅ | ✅ Detail | ❌ | ✅ | ✅ | status | date ↓ | ✅ | `advance.view` |
| Backorders | BackordersList | `/backorders` | ✅ Form | ✅ | ✅ Detail | ❌ | ✅ | ✅ | status | date ↓ | ✅ | `backorder.view` |
| Rentals | RentalsList | `/rentals` | ✅ Form | ✅ | ✅ Detail | ❌ | ✅ Draft | ✅ | status | date ↓ | ✅ | `rental.view` |
| Loyalty | LoyaltyPage | `/loyalty` | ✅ Rules | ✅ | — | ✅ Adjust | ✅ Rule | — | — | points ↓ | ✅ partial | `loyalty.view` |
| Commission | CommissionList | `/commission` | ✅ Modals | ✅ Tabs | — | ✅ Upsert | ✅ Payout | — | tab, dates | — | partial | `commission.view` |
| Payments | PaymentsList | `/payments` | ✅ Modal | ✅ | — | ❌ | ✅ Void | — | supplier | date ↓ | ✅ | `payments.view` |
| Receipts | ReceiptsList | `/receipts` | ✅ Modal | ✅ | — | ❌ | ✅ Void | — | customer | date ↓ | ✅ | `receipts.view` |
| Locations | LocationsList | `/locations` | ✅ Modal | ✅ | — | ✅ Modal | ✅ | ❌ | — | default, name | ❌ | `locations.view` |
| Users | UsersList | `/users` | ✅ Modal | ✅ | ✅ Detail | ✅ Modal | ✅ | ❌ | status | name | ✅ | `users.view` |
| Roles | RolesList | `/roles` | ✅ Modal | ✅ | — | ✅ Modal | ✅ | ✅ | — | ✅ | ✅ | `roles.view` |
| Reports | ReportsPage | `/reports` | — | ✅ Tabs | — | — | — | — | date, location | — | partial | `reports.view` |
| Activity | ActivityMonitoringPage | `/activity-monitoring` | — | ✅ Snapshot | — | — | — | — | company 🔒 | — | ❌ | `activity.view` |
| Backups | BackupDashboardPage | `/backups` | ⚡ Run | ✅ | — | ⚡ Restore | — | ❌ | — | date | ❌ | `backup.view` |
| Settings | SettingsPage | `/settings` | — | ✅ | — | ✅ | — | — | — | — | ❌ | `settings.view` |
| Profile | ProfilePage | `/profile` | — | ✅ | — | ✅ | — | — | — | — | ❌ | Authenticated |

---

# Detailed Feature Entries

---

## Module: Authentication

### Feature: Login

**Module:** Authentication  
**Feature:** Login  
**Route:** `/login`  
**User Action:** Enter email and password, click Sign in  
**API:** `POST /api/login`  
**Required Fields:** `email`, `password`  
**Optional Fields:** —  
**Validation:** email required, valid email; password required string; inactive users rejected; non-super-admin without company rejected  
**Expected Result:** HTTP 200 with `token`, `user`, `companies`; token stored in `localStorage`; redirect to `/`; permissions loaded for default company  
**Priority:** P0

---

### Feature: Session rehydration

**Module:** Authentication  
**Feature:** Session rehydration  
**Route:** Any protected route (on page load)  
**User Action:** Refresh browser while logged in  
**API:** `GET /api/me`  
**Required Fields:** Bearer token (header)  
**Optional Fields:** —  
**Validation:** Valid token required  
**Expected Result:** User and companies restored; `X-Company-Id` applied; permissions fetched via `GET /api/permissions`  
**Priority:** P0

---

### Feature: Logout

**Module:** Authentication  
**Feature:** Logout  
**Route:** App shell (header)  
**User Action:** Click Log out  
**API:** `POST /api/logout`  
**Required Fields:** Bearer token  
**Optional Fields:** —  
**Validation:** Authenticated user  
**Expected Result:** Token revoked; localStorage cleared; redirect to `/login`  
**Priority:** P0

---

### Feature: Update profile

**Module:** Authentication  
**Feature:** Update profile  
**Route:** `/profile`  
**User Action:** Edit name/email/phone/password and save  
**API:** `PUT /api/me`  
**Required Fields:** `name`, `email`  
**Optional Fields:** `phone`, `current_password`, `password`, `password_confirmation`  
**Validation:** email unique; phone regex; password min 8 confirmed; current_password required when changing password  
**Expected Result:** Profile updated; user object refreshed in context  
**Priority:** P2

---

## Module: Dashboard

### Feature: View dashboard KPIs

**Module:** Dashboard  
**Feature:** View dashboard KPIs  
**Route:** `/`  
**User Action:** Land on home after login  
**API:** `GET /api/dashboard`  
**Required Fields:** `X-Company-Id` header  
**Optional Fields:** `company_id` (super-admin GET filter)  
**Validation:** `reports.view` permission  
**Expected Result:** Summary counts (suppliers, products, low stock, members) displayed  
**Priority:** P1

---

## Module: Companies (Super Admin)

### Feature: List companies

**Module:** Companies  
**Feature:** List companies  
**Route:** `/companies`  
**User Action:** Navigate to Companies (HO sidebar)  
**API:** `GET /api/companies`  
**Required Fields:** Bearer token, super-admin  
**Optional Fields:** `search` (name, code, email, phone)  
**Validation:** `is_super_admin` only  
**Expected Result:** All companies listed; search filters results  
**Permissions:** 🔒 Super admin  
**Priority:** P2

---

### Feature: Create company

**Module:** Companies  
**Feature:** Create company  
**Route:** `/companies` (modal)  
**User Action:** Click Add company, fill form, submit  
**API:** `POST /api/companies`  
**Required Fields:** `name`  
**Optional Fields:** `code`, `legal_name`, `gst_number`, `state`, `state_code`, `address`, `phone`, `email`, `logo`, `photo`, `description`, `locations`, `is_active`  
**Validation:** `StoreCompanyRequest` — name max 150; code unique; phone regex; email valid  
**Expected Result:** Company created; appears in list and company switcher after refresh  
**Permissions:** 🔒 Super admin  
**Priority:** P2

---

### Feature: View company detail

**Module:** Companies  
**Feature:** View company detail  
**Route:** `/companies/:id`  
**User Action:** Click company row  
**API:** `GET /api/companies/{company}`  
**Required Fields:** Company ID  
**Optional Fields:** —  
**Validation:** Super admin; company exists  
**Expected Result:** Company stats and details rendered  
**Permissions:** 🔒 Super admin  
**Priority:** P2

---

### Feature: Update company

**Module:** Companies  
**Feature:** Update company  
**Route:** `/companies` (edit modal) or `/companies/:id`  
**User Action:** Edit company fields and save  
**API:** `PUT /api/companies/{company}`  
**Required Fields:** `name`  
**Optional Fields:** Same as create  
**Validation:** `UpdateCompanyRequest` (extends Store)  
**Expected Result:** Company updated; detail view reflects changes  
**Permissions:** 🔒 Super admin  
**Priority:** P2

---

### Feature: Toggle company status

**Module:** Companies  
**Feature:** Toggle company status  
**Route:** `/companies`  
**User Action:** Activate/deactivate company  
**API:** `PATCH /api/companies/{company}/status`  
**Required Fields:** Status payload  
**Optional Fields:** —  
**Validation:** Super admin  
**Expected Result:** Company active flag toggled  
**Permissions:** 🔒 Super admin  
**Priority:** P2

---

### Feature: Delete company

**Module:** Companies  
**Feature:** Delete company  
**Route:** `/companies`  
**User Action:** Delete with confirmation  
**API:** `DELETE /api/companies/{company}`  
**Required Fields:** Company ID  
**Optional Fields:** —  
**Validation:** Super admin; business rules for linked data  
**Expected Result:** Company soft-deleted or removed per backend logic  
**Permissions:** 🔒 Super admin  
**Priority:** P3

---

## Module: Suppliers

### Feature: List suppliers

**Module:** Suppliers  
**Feature:** List suppliers  
**Route:** `/suppliers`  
**User Action:** Open Suppliers page  
**API:** `GET /api/suppliers`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `search`, `status` (active/inactive), `sort` (supplier_code|name|status|outstanding|created_at), `dir`, `per_page`, `page`, `company_id`  
**Validation:** `suppliers.view`  
**Expected Result:** Paginated supplier table; filters and sort applied  
**Permissions:** `suppliers.view`  
**Priority:** P1

---

### Feature: Create supplier

**Module:** Suppliers  
**Feature:** Create supplier  
**Route:** `/suppliers` (modal)  
**User Action:** Add supplier via modal form  
**API:** `POST /api/suppliers`  
**Required Fields:** `name`  
**Optional Fields:** `supplier_code`, `gst_number`, `phone`, `email`, `address`, `state`, `payment_terms`, `notes`, `company_id` (super-admin)  
**Validation:** `StoreSupplierRequest` — name required; GST/phone formats; unique code per company  
**Expected Result:** Supplier created; visible in list and purchase form dropdowns  
**Permissions:** `suppliers.create`  
**Priority:** P1

---

### Feature: View supplier detail

**Module:** Suppliers  
**Feature:** View supplier detail  
**Route:** `/suppliers/:id`  
**User Action:** Click supplier row  
**API:** `GET /api/suppliers/{supplier}`, `GET /api/suppliers/{supplier}/purchase-history`  
**Required Fields:** Supplier UUID  
**Optional Fields:** `per_page` on history  
**Validation:** `suppliers.view`  
**Expected Result:** Supplier profile and purchase history displayed  
**Permissions:** `suppliers.view`  
**Priority:** P2

---

### Feature: Update supplier

**Module:** Suppliers  
**Feature:** Update supplier  
**Route:** `/suppliers` (edit modal)  
**User Action:** Edit and save supplier  
**API:** `PUT /api/suppliers/{supplier}`  
**Required Fields:** `name`  
**Optional Fields:** Same as create  
**Validation:** `UpdateSupplierRequest`  
**Expected Result:** Supplier updated  
**Permissions:** `suppliers.update`  
**Priority:** P1

---

### Feature: Delete supplier

**Module:** Suppliers  
**Feature:** Delete supplier  
**Route:** `/suppliers`  
**User Action:** Delete with confirmation  
**API:** `DELETE /api/suppliers/{supplier}`  
**Required Fields:** Supplier ID  
**Optional Fields:** —  
**Validation:** `suppliers.delete` or `suppliers.force-delete`  
**Expected Result:** Supplier removed or soft-deleted  
**Permissions:** `suppliers.delete`  
**Priority:** P2

---

### Feature: Toggle supplier status

**Module:** Suppliers  
**Feature:** Toggle supplier status  
**Route:** `/suppliers`  
**User Action:** Activate/deactivate supplier  
**API:** `PATCH /api/suppliers/{supplier}/status`  
**Required Fields:** `status` (active/inactive)  
**Optional Fields:** —  
**Validation:** `suppliers.update`  
**Expected Result:** Status updated; inactive excluded from purchase dropdowns  
**Permissions:** `suppliers.update`  
**Priority:** P2

---

## Module: Customers

### Feature: List customers

**Module:** Customers  
**Feature:** List customers  
**Route:** `/customers`  
**User Action:** Open Customers page; type search/filter  
**API:** `GET /api/customers`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `search`, `type`, `status`, `per_page`, `page`, `company_id`  
**Validation:** `customers.view`  
**Expected Result:** Paginated list; debounced search (300ms) on frontend  
**Permissions:** `customers.view`  
**Priority:** P1

---

### Feature: Create customer

**Module:** Customers  
**Feature:** Create customer  
**Route:** `/customers` (modal)  
**User Action:** Add customer  
**API:** `POST /api/customers`  
**Required Fields:** `name`  
**Optional Fields:** `phone`, `email`, `gst_number`, `address`, `type`, `pricing_tier`, `credit_limit`, `notes`, `company_id`  
**Validation:** `StoreCustomerRequest`  
**Expected Result:** Customer created; available in sales/advance order forms  
**Permissions:** `customers.create`  
**Priority:** P1

---

### Feature: View customer detail

**Module:** Customers  
**Feature:** View customer detail  
**Route:** `/customers/:id`  
**User Action:** Click customer  
**API:** `GET /api/customers/{customer}`, `GET /api/customers/{customer}/purchase-history`  
**Required Fields:** Customer UUID  
**Optional Fields:** —  
**Validation:** `customers.view`  
**Expected Result:** Profile, balance, purchase history shown  
**Permissions:** `customers.view`  
**Priority:** P2

---

### Feature: Update customer

**Module:** Customers  
**Feature:** Update customer  
**Route:** `/customers` (edit modal)  
**User Action:** Edit customer  
**API:** `PUT /api/customers/{customer}`  
**Required Fields:** `name`  
**Optional Fields:** Same as create  
**Validation:** `UpdateCustomerRequest`  
**Expected Result:** Customer updated  
**Permissions:** `customers.update`  
**Priority:** P1

---

### Feature: Delete customer

**Module:** Customers  
**Feature:** Delete customer  
**Route:** `/customers`  
**User Action:** Delete customer  
**API:** `DELETE /api/customers/{customer}`  
**Required Fields:** Customer ID  
**Optional Fields:** —  
**Validation:** `customers.delete`  
**Expected Result:** Customer removed  
**Permissions:** `customers.delete`  
**Priority:** P2

---

## Module: Products

### Feature: List products

**Module:** Products  
**Feature:** List products  
**Route:** `/products`  
**User Action:** Search, filter by category/status/low stock  
**API:** `GET /api/products`, `GET /api/products/form-data`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `search`, `category_id`, `status`, `low_only`, `per_page` (24 default), `page`, `company_id`  
**Validation:** `products.view`  
**Expected Result:** Paginated grid/table; low-stock badge when applicable  
**Permissions:** `products.view`  
**Priority:** P0

---

### Feature: Create product

**Module:** Products  
**Feature:** Create product  
**Route:** `/products/new`  
**User Action:** Fill ProductForm and submit  
**API:** `POST /api/products`  
**Required Fields:** `name`, `category_id`, `cost_price`, `status`  
**Optional Fields:** `sku`, `barcode`, `hsn_code`, `description`, `brand_id`, `unit_id`, `gst_rate`, `mrp`, pricing tiers, dimensions, `reorder_level`, `opening_stock`, `images`, `suppliers[]`, rental fields, `company_id` (super-admin)  
**Validation:** `StoreProductRequest` — unique name/sku per company; category active; opening_stock ≥ 0  
**Expected Result:** Product created; opening stock posted once to inventory ledger  
**Permissions:** `products.create`  
**Priority:** P0

---

### Feature: View product detail

**Module:** Products  
**Feature:** View product detail  
**Route:** `/products/:id`  
**User Action:** Click product  
**API:** `GET /api/products/{product}`, `GET /api/products/{product}/batches`  
**Required Fields:** Product UUID  
**Optional Fields:** —  
**Validation:** `products.view`  
**Expected Result:** Product details, stock, batches, supplier links  
**Permissions:** `products.view`  
**Priority:** P1

---

### Feature: Update product

**Module:** Products  
**Feature:** Update product  
**Route:** `/products/:id/edit`  
**User Action:** Edit ProductForm  
**API:** `PUT /api/products/{product}`  
**Required Fields:** `name`, `category_id`, `cost_price`, `status`  
**Optional Fields:** Same as create (except opening_stock typically create-only)  
**Validation:** `UpdateProductRequest`; super-admin may send `company_id` when reassigning  
**Expected Result:** Product updated  
**Permissions:** `products.update`  
**Priority:** P0

---

### Feature: Delete product

**Module:** Products  
**Feature:** Delete product  
**Route:** `/products`  
**User Action:** Delete product  
**API:** `DELETE /api/products/{product}`  
**Required Fields:** Product ID  
**Optional Fields:** —  
**Validation:** `products.delete`  
**Expected Result:** Product soft-deleted  
**Permissions:** `products.delete`  
**Priority:** P2

---

### Feature: Toggle product status

**Module:** Products  
**Feature:** Toggle product status  
**Route:** `/products`  
**User Action:** Activate/deactivate  
**API:** `PATCH /api/products/{product}/status`  
**Required Fields:** `status`  
**Optional Fields:** —  
**Validation:** `products.update`  
**Expected Result:** Status toggled  
**Permissions:** `products.update`  
**Priority:** P2

---

### Feature: Print barcode labels

**Module:** Products  
**Feature:** Print barcode labels  
**Route:** `/products/labels`  
**User Action:** Select products, generate labels  
**API:** `GET /api/products` (selection), batch/barcode endpoints as used by page  
**Required Fields:** Product selection  
**Optional Fields:** Label options  
**Validation:** `products.view`  
**Expected Result:** Printable barcode labels rendered  
**Permissions:** `products.view`  
**Priority:** P3

---

## Module: Master Data

### Feature: List master data (categories/brands/units)

**Module:** Master Data  
**Feature:** List master data  
**Route:** `/masters`  
**User Action:** Switch tabs (Categories, Brands, Units)  
**API:** `GET /api/masters/{type}` — type: `categories`, `brands`, `units`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `company_id` (super-admin)  
**Validation:** `categories.view` OR `subcategories.view` for categories; `brands.view`; `units.view`  
**Expected Result:** Full list ordered by name (no pagination)  
**Permissions:** Any of category/subcategory/unit view  
**Priority:** P2

---

### Feature: Create master record

**Module:** Master Data  
**Feature:** Create master record  
**Route:** `/masters` (modal)  
**User Action:** Add category/brand/unit  
**API:** `POST /api/masters/{type}`  
**Required Fields:** `name`  
**Optional Fields:** `parent_id` (subcategories), `company_id` (super-admin, integer)  
**Validation:** Inline controller validation; name required; parent exists for subcategories  
**Expected Result:** Record created; available in product form dropdowns  
**Permissions:** `{type}.create`  
**Priority:** P2

---

### Feature: Update master record

**Module:** Master Data  
**Feature:** Update master record  
**Route:** `/masters` (edit modal)  
**User Action:** Edit and save  
**API:** `PUT /api/masters/{type}/{id}`  
**Required Fields:** `name`  
**Optional Fields:** `parent_id`, `company_id` when changed  
**Validation:** Inline validation; `company_id` must be valid integer company ID  
**Expected Result:** Record updated  
**Permissions:** `{type}.update`  
**Priority:** P2

---

### Feature: Delete master record

**Module:** Master Data  
**Feature:** Delete master record  
**Route:** `/masters`  
**User Action:** Delete with confirmation  
**API:** `DELETE /api/masters/{type}/{id}`  
**Required Fields:** Record ID  
**Optional Fields:** —  
**Validation:** `{type}.delete`; blocked if referenced  
**Expected Result:** Record removed  
**Permissions:** `{type}.delete`  
**Priority:** P2

---

## Module: Purchases (GRN)

### Feature: List purchases

**Module:** Purchases  
**Feature:** List purchases  
**Route:** `/purchases`  
**User Action:** Filter by status/supplier; search GRN number  
**API:** `GET /api/purchases`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `search`, `status`, `supplier_id`, `per_page`, `page`, `company_id`  
**Validation:** `purchases.view`  
**Expected Result:** Paginated purchase list  
**Permissions:** `purchases.view`  
**Priority:** P0

---

### Feature: Create purchase (draft GRN)

**Module:** Purchases  
**Feature:** Create purchase  
**Route:** `/purchases/new`  
**User Action:** Fill PurchaseForm — supplier, lines, dates  
**API:** `GET /api/purchases/form-data`, `POST /api/purchases`  
**Required Fields:** `supplier_id`, `purchase_date`, `items[]` (product_id, qty, rate)  
**Optional Fields:** `invoice_no`, `invoice_date`, `is_interstate`, `landed_cost_total`, `notes`, bulk/split line fields, `company_id`  
**Validation:** `StorePurchaseRequest` — min 1 line; qty > 0; active supplier/product  
**Expected Result:** Draft purchase created with calculated totals  
**Permissions:** `purchases.create`  
**Priority:** P0

---

### Feature: View purchase detail

**Module:** Purchases  
**Feature:** View purchase detail  
**Route:** `/purchases/:id`  
**User Action:** Open purchase  
**API:** `GET /api/purchases/{purchase}`, `GET /api/purchases/{purchase}/batches`  
**Required Fields:** Purchase UUID  
**Optional Fields:** —  
**Validation:** `purchases.view`  
**Expected Result:** Lines, totals, status, confirm button if draft  
**Permissions:** `purchases.view`  
**Priority:** P0

---

### Feature: Update purchase (draft)

**Module:** Purchases  
**Feature:** Update purchase  
**Route:** `/purchases/:id/edit`  
**User Action:** Edit draft GRN  
**API:** `PUT /api/purchases/{purchase}`  
**Required Fields:** Same as create  
**Optional Fields:** Same as create; `company_id` only when super-admin changes company  
**Validation:** `UpdatePurchaseRequest`; only draft editable  
**Expected Result:** Purchase updated  
**Permissions:** `purchases.update`  
**Priority:** P0

---

### Feature: Confirm purchase

**Module:** Purchases  
**Feature:** Confirm purchase  
**Route:** `/purchases/:id`  
**User Action:** Click Confirm  
**API:** `POST /api/purchases/{purchase}/confirm`  
**Required Fields:** Purchase ID  
**Optional Fields:** —  
**Validation:** `purchases.confirm`; stock availability rules  
**Expected Result:** Status → confirmed; inventory increased; batches created  
**Permissions:** `purchases.confirm`  
**Priority:** P0

---

### Feature: Delete draft purchase

**Module:** Purchases  
**Feature:** Delete purchase  
**Route:** `/purchases/:id`  
**User Action:** Cancel/delete draft  
**API:** `DELETE /api/purchases/{purchase}`  
**Required Fields:** Purchase ID  
**Optional Fields:** —  
**Validation:** `purchases.delete`; draft only  
**Expected Result:** Purchase removed  
**Permissions:** `purchases.delete`  
**Priority:** P1

---

### Feature: Download purchase invoice PDF

**Module:** Purchases  
**Feature:** Download GRN PDF  
**Route:** `/purchases/:id`  
**User Action:** Download PDF  
**API:** `GET /api/purchases/{purchase}/invoice.pdf`  
**Required Fields:** Purchase ID  
**Optional Fields:** —  
**Validation:** `purchases.view`  
**Expected Result:** PDF file downloaded  
**Permissions:** `purchases.view`  
**Priority:** P2

---

## Module: Purchase Orders

### Feature: List purchase orders

**Module:** Purchase Orders  
**Feature:** List purchase orders  
**Route:** `/purchase-orders`  
**User Action:** Filter by status tab  
**API:** `GET /api/purchase-orders`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `search`, `status`, `per_page`, `page`, `company_id`  
**Validation:** `po.view`  
**Expected Result:** Paginated PO list  
**Permissions:** `po.view`  
**Priority:** P1

---

### Feature: Create purchase order

**Module:** Purchase Orders  
**Feature:** Create purchase order  
**Route:** `/purchase-orders/new`  
**User Action:** Fill PO form  
**API:** `GET /api/purchase-orders/form-data`, `POST /api/purchase-orders`  
**Required Fields:** `supplier_id`, `order_date`, `items[]`  
**Optional Fields:** `expected_date`, `notes`, line discounts  
**Validation:** `StorePurchaseOrderRequest`  
**Expected Result:** Draft PO created  
**Permissions:** `po.create`  
**Priority:** P1

---

### Feature: View PO detail

**Module:** Purchase Orders  
**Feature:** View PO detail  
**Route:** `/purchase-orders/:id`  
**User Action:** Open PO  
**API:** `GET /api/purchase-orders/{purchaseOrder}`  
**Required Fields:** PO UUID  
**Optional Fields:** —  
**Validation:** `po.view`  
**Expected Result:** PO lines and workflow actions (Send, Convert)  
**Permissions:** `po.view`  
**Priority:** P1

---

### Feature: Send PO

**Module:** Purchase Orders  
**Feature:** Send PO  
**Route:** `/purchase-orders/:id`  
**User Action:** Mark as sent  
**API:** `POST /api/purchase-orders/{purchaseOrder}/send`  
**Required Fields:** PO ID  
**Optional Fields:** —  
**Validation:** `po.send`  
**Expected Result:** Status updated to sent  
**Permissions:** `po.send`  
**Priority:** P2

---

### Feature: Convert PO to GRN

**Module:** Purchase Orders  
**Feature:** Convert PO to GRN  
**Route:** `/purchase-orders/:id`  
**User Action:** Convert to purchase  
**API:** `POST /api/purchase-orders/{purchaseOrder}/convert`  
**Required Fields:** PO ID  
**Optional Fields:** —  
**Validation:** `po.convert`  
**Expected Result:** Draft purchase created from PO lines  
**Permissions:** `po.convert`  
**Priority:** P1

---

### Feature: Reorder report & batch PO

**Module:** Purchase Orders  
**Feature:** Reorder from report  
**Route:** `/purchase-orders/reorder`  
**User Action:** Select low-stock items, batch create POs  
**API:** `GET /api/purchase-orders/reorder-report`, `POST /api/purchase-orders/batch-from-reorder`  
**Required Fields:** Selected items/suppliers per batch request  
**Optional Fields:** —  
**Validation:** `BatchPurchaseOrderRequest`, `po.create`  
**Expected Result:** One or more POs created from reorder suggestions  
**Permissions:** `po.create`  
**Priority:** P2

---

### Feature: Delete PO

**Module:** Purchase Orders  
**Feature:** Delete PO  
**Route:** `/purchase-orders/:id`  
**User Action:** Cancel PO  
**API:** `DELETE /api/purchase-orders/{purchaseOrder}`  
**Required Fields:** PO ID  
**Optional Fields:** —  
**Validation:** `po.delete`  
**Expected Result:** PO cancelled/deleted  
**Permissions:** `po.delete`  
**Priority:** P2

---

## Module: Purchase Returns

### Feature: List purchase returns

**Module:** Purchase Returns  
**Feature:** List purchase returns  
**Route:** `/purchase-returns`  
**User Action:** View returns list  
**API:** `GET /api/purchase-returns`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `search`, `status`, `per_page`, `page`, `company_id`  
**Validation:** `purchase_returns.view`  
**Expected Result:** Paginated list  
**Permissions:** `purchase_returns.view`  
**Priority:** P1

---

### Feature: Create purchase return

**Module:** Purchase Returns  
**Feature:** Create purchase return  
**Route:** `/purchase-returns/new`  
**User Action:** Select source purchase, return lines  
**API:** `GET /api/purchase-returns/source`, `POST /api/purchase-returns`  
**Required Fields:** Source purchase, `items[]` with qty  
**Optional Fields:** `return_date`, `reason`, `notes`  
**Validation:** `StorePurchaseReturnRequest`  
**Expected Result:** Draft debit note created  
**Permissions:** `purchase_returns.create`  
**Priority:** P1

---

### Feature: View purchase return

**Module:** Purchase Returns  
**Feature:** View purchase return  
**Route:** `/purchase-returns/:id`  
**User Action:** Open return detail  
**API:** `GET /api/purchase-returns/{purchaseReturn}`  
**Required Fields:** Return UUID  
**Optional Fields:** —  
**Validation:** `purchase_returns.view`  
**Expected Result:** Return lines and confirm action  
**Permissions:** `purchase_returns.view`  
**Priority:** P1

---

### Feature: Confirm purchase return

**Module:** Purchase Returns  
**Feature:** Confirm purchase return  
**Route:** `/purchase-returns/:id`  
**User Action:** Confirm return  
**API:** `POST /api/purchase-returns/{purchaseReturn}/confirm`  
**Required Fields:** Return ID  
**Optional Fields:** —  
**Validation:** `purchase_returns.confirm`  
**Expected Result:** Stock reversed; supplier payable adjusted  
**Permissions:** `purchase_returns.confirm`  
**Priority:** P1

---

### Feature: Delete purchase return

**Module:** Purchase Returns  
**Feature:** Delete purchase return  
**Route:** `/purchase-returns/:id`  
**User Action:** Cancel draft return  
**API:** `DELETE /api/purchase-returns/{purchaseReturn}`  
**Required Fields:** Return ID  
**Optional Fields:** —  
**Validation:** `purchase_returns.delete`  
**Expected Result:** Draft removed  
**Permissions:** `purchase_returns.delete`  
**Priority:** P2

---

## Module: Inventory

### Feature: View stock levels

**Module:** Inventory  
**Feature:** View stock levels  
**Route:** `/inventory` (Stock tab)  
**User Action:** Browse current stock  
**API:** `GET /api/inventory/stock`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `search`, `low_only`, `per_page`, `page`, `company_id`  
**Validation:** `inventory.view`  
**Expected Result:** Paginated stock table with qty and value  
**Permissions:** `inventory.view`  
**Priority:** P0

---

### Feature: View inventory ledger

**Module:** Inventory  
**Feature:** View inventory ledger  
**Route:** `/inventory` (Ledger tab)  
**User Action:** Filter by product, date, direction  
**API:** `GET /api/inventory/ledger/form-data`, `GET /api/inventory/ledger`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `product_id`, `reference_type`, `direction`, `from`, `to`, `search`, `per_page`, `page`  
**Validation:** `inventory.view`  
**Expected Result:** Paginated ledger entries (in/out)  
**Permissions:** `inventory.view`  
**Priority:** P0

---

### Feature: Export ledger

**Module:** Inventory  
**Feature:** Export ledger  
**Route:** `/inventory`  
**User Action:** Export CSV  
**API:** `GET /api/inventory/ledger/export`  
**Required Fields:** Same filters as ledger  
**Optional Fields:** —  
**Validation:** `inventory.view`  
**Expected Result:** CSV file download  
**Permissions:** `inventory.view`  
**Priority:** P2

---

### Feature: Stock valuation & movement

**Module:** Inventory  
**Feature:** Valuation and movement reports  
**Route:** `/inventory`  
**User Action:** View valuation/movement tabs  
**API:** `GET /api/inventory/valuation`, `GET /api/inventory/movement`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** Date range filters  
**Validation:** `inventory.view`  
**Expected Result:** Summary data displayed  
**Permissions:** `inventory.view`  
**Priority:** P2

---

### Feature: Cross-branch stock panel

**Module:** Inventory  
**Feature:** Cross-branch stock lookup  
**Route:** `/inventory` or embedded panels  
**User Action:** Search SKU across branches  
**API:** `GET /api/inventory/stock/cross-branch`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `sku`, `product_id`  
**Validation:** `inventory.view`  
**Expected Result:** Stock by company/branch  
**Permissions:** `inventory.view`  
**Priority:** P2

---

### Feature: Low stock alerts

**Module:** Inventory  
**Feature:** Low stock alerts  
**Route:** `/inventory`, Dashboard  
**User Action:** View alerts  
**API:** `GET /api/inventory/alerts`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** —  
**Validation:** `inventory.view`  
**Expected Result:** Products at/below reorder level  
**Permissions:** `inventory.view`  
**Priority:** P1

---

## Module: Batches & Barcodes

### Feature: Batches overview

**Module:** Batches  
**Feature:** Batches overview  
**Route:** `/inventory/batches`  
**User Action:** List batches  
**API:** `GET /api/inventory/batches`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** Filters per page implementation  
**Validation:** `inventory.view`  
**Expected Result:** Batch list with qty and expiry  
**Permissions:** `inventory.view`  
**Priority:** P2

---

### Feature: Scan batch barcode

**Module:** Batches  
**Feature:** Scan batch  
**Route:** `/inventory/batches`, POS forms  
**User Action:** Enter/scan barcode  
**API:** `GET /api/batches/scan?code={barcode}`  
**Required Fields:** Barcode code  
**Optional Fields:** —  
**Validation:** `inventory.view` or sales context  
**Expected Result:** Batch and product resolved  
**Permissions:** `inventory.view`  
**Priority:** P2

---

### Feature: Generate opening batches

**Module:** Batches  
**Feature:** Generate opening batches  
**Route:** `/inventory/batches`  
**User Action:** Generate batches for products with opening stock  
**API:** `POST /api/batches/generate-opening`  
**Required Fields:** Per backend payload  
**Optional Fields:** —  
**Validation:** `products.update` or inventory permission  
**Expected Result:** Opening batches created  
**Permissions:** `inventory.view` / product permissions  
**Priority:** P3

---

## Module: Damage Entry

### Feature: List damage entries

**Module:** Damage Entry  
**Feature:** List damage entries  
**Route:** `/damage-entries`  
**User Action:** View damage log  
**API:** `GET /api/damage-entries`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `product_id`, `from`, `to`, `per_page`, `page`, `company_id`  
**Validation:** `damage.view`  
**Expected Result:** Paginated damage records  
**Permissions:** `damage.view`  
**Priority:** P1

---

### Feature: Record damage

**Module:** Damage Entry  
**Feature:** Record damage  
**Route:** `/damage-entries` (modal)  
**User Action:** Select product, qty, reason  
**API:** `GET /api/damage-entries/form-data`, `POST /api/damage-entries`  
**Required Fields:** `product_id`, `qty`, `entry_date`  
**Optional Fields:** `location_id`, `reason`, `notes`, `product_batch_id`  
**Validation:** `StoreDamageEntryRequest` — qty > 0; stock available  
**Expected Result:** Stock reduced; ledger entry created  
**Permissions:** `damage.create`  
**Priority:** P1

---

## Module: Bulk Splits

### Feature: List bulk splits

**Module:** Bulk Splits  
**Feature:** List bulk splits  
**Route:** `/bulk-splits`  
**User Action:** View splits  
**API:** `GET /api/bulk-splits`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `search`, `status`, `per_page`, `page`, `company_id`  
**Validation:** `bulk_splits.view`  
**Expected Result:** Paginated split list  
**Permissions:** `bulk_splits.view`  
**Priority:** P1

---

### Feature: Create bulk split

**Module:** Bulk Splits  
**Feature:** Create bulk split  
**Route:** `/bulk-splits/new`  
**User Action:** Select source product/qty, define split lines  
**API:** `GET /api/bulk-splits/form-data`, `POST /api/bulk-splits`  
**Required Fields:** `source_product_id`, `source_qty`, `split_date`, `items[]` (qty)  
**Optional Fields:** `split_mode`, `split_param`, `auto_create_products`, `confirm_immediately`, `markup_percent`, `source_purchase_id`, `notes`  
**Validation:** `StoreBulkSplitRequest` — source qty ≤ available stock; min 1 item  
**Expected Result:** Draft split created; optional immediate confirm  
**Permissions:** `bulk_splits.create`  
**Priority:** P1

---

### Feature: View bulk split detail

**Module:** Bulk Splits  
**Feature:** View bulk split  
**Route:** `/bulk-splits/:id`  
**User Action:** Open split  
**API:** `GET /api/bulk-splits/{bulkSplit}`  
**Required Fields:** Split UUID  
**Optional Fields:** —  
**Validation:** `bulk_splits.view`  
**Expected Result:** Source/target lines and cost allocation  
**Permissions:** `bulk_splits.view`  
**Priority:** P1

---

### Feature: Confirm bulk split

**Module:** Bulk Splits  
**Feature:** Confirm bulk split  
**Route:** `/bulk-splits/:id`  
**User Action:** Confirm split  
**API:** `POST /api/bulk-splits/{bulkSplit}/confirm`  
**Required Fields:** Split ID  
**Optional Fields:** —  
**Validation:** `bulk_splits.confirm`  
**Expected Result:** Stock converted; child products created if auto-create  
**Permissions:** `bulk_splits.confirm`  
**Priority:** P1

---

### Feature: Delete bulk split

**Module:** Bulk Splits  
**Feature:** Delete bulk split  
**Route:** `/bulk-splits/:id`  
**User Action:** Cancel draft  
**API:** `DELETE /api/bulk-splits/{bulkSplit}`  
**Required Fields:** Split ID  
**Optional Fields:** —  
**Validation:** `bulk_splits.delete`  
**Expected Result:** Draft removed  
**Permissions:** `bulk_splits.delete`  
**Priority:** P2

---

## Module: Stock Transfers

### Feature: List transfers

**Module:** Stock Transfers  
**Feature:** List transfers  
**Route:** `/transfers`  
**User Action:** Filter by status  
**API:** `GET /api/transfers`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `search`, `status`, `per_page`, `page`, `company_id`  
**Validation:** `transfers.view`  
**Expected Result:** Paginated transfer list  
**Permissions:** `transfers.view`  
**Priority:** P1

---

### Feature: Create transfer

**Module:** Stock Transfers  
**Feature:** Create transfer  
**Route:** `/transfers/new`  
**User Action:** Select type (inter/intra company), locations, lines  
**API:** `GET /api/transfers/form-data`, `POST /api/transfers`  
**Required Fields:** `transfer_date`, `items[]` (product_id, qty); inter: `to_company_id`; intra: `from_location_id`, `to_location_id`  
**Optional Fields:** `transfer_type`, `product_batch_id`, `notes`  
**Validation:** `StoreTransferRequest` — qty ≤ available at source location/company  
**Expected Result:** Draft/pending transfer created  
**Permissions:** `transfers.create`  
**Priority:** P1

---

### Feature: View transfer detail

**Module:** Stock Transfers  
**Feature:** View transfer  
**Route:** `/transfers/:id`  
**User Action:** Open transfer workflow  
**API:** `GET /api/transfers/{stockTransfer}`  
**Required Fields:** Transfer UUID  
**Optional Fields:** —  
**Validation:** `transfers.view`  
**Expected Result:** Status workflow buttons (approve, dispatch, receive, reject)  
**Permissions:** `transfers.view`  
**Priority:** P1

---

### Feature: Approve / reject transfer

**Module:** Stock Transfers  
**Feature:** Approve or reject transfer  
**Route:** `/transfers/:id`  
**User Action:** HO approve or reject  
**API:** `POST /api/transfers/{id}/approve`, `POST .../reject`  
**Required Fields:** Transfer ID; reject may need reason  
**Optional Fields:** `ApproveTransferRequest` fields  
**Validation:** `transfers.approve`  
**Expected Result:** Status updated  
**Permissions:** `transfers.approve`  
**Priority:** P1

---

### Feature: Dispatch transfer

**Module:** Stock Transfers  
**Feature:** Dispatch transfer  
**Route:** `/transfers/:id`  
**User Action:** Dispatch stock  
**API:** `POST /api/transfers/{id}/dispatch`  
**Required Fields:** Transfer ID  
**Optional Fields:** —  
**Validation:** `transfers.dispatch`  
**Expected Result:** Stock deducted at source; in-transit  
**Permissions:** `transfers.dispatch`  
**Priority:** P1

---

### Feature: Receive transfer

**Module:** Stock Transfers  
**Feature:** Receive transfer  
**Route:** `/transfers/:id`  
**User Action:** Receive at destination  
**API:** `POST /api/transfers/{id}/receive`  
**Required Fields:** Transfer ID, received quantities  
**Optional Fields:** `ReceiveTransferRequest` line overrides  
**Validation:** `transfers.receive`  
**Expected Result:** Stock added at destination  
**Permissions:** `transfers.receive`  
**Priority:** P1

---

### Feature: Delete transfer

**Module:** Stock Transfers  
**Feature:** Delete transfer  
**Route:** `/transfers/:id`  
**User Action:** Cancel draft transfer  
**API:** `DELETE /api/transfers/{stockTransfer}`  
**Required Fields:** Transfer ID  
**Optional Fields:** —  
**Validation:** `transfers.delete`  
**Expected Result:** Transfer cancelled  
**Permissions:** `transfers.delete`  
**Priority:** P2

---

## Module: Stock Verification (Stock Count)

### Feature: List stock verifications

**Module:** Stock Verification  
**Feature:** List stock counts  
**Route:** `/stock-verifications`  
**User Action:** Filter by status  
**API:** `GET /api/stock-verifications`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `search`, `status`, `per_page`, `page`, `company_id`  
**Validation:** `stock_verifications.view`  
**Expected Result:** Paginated count sessions  
**Permissions:** `stock_verifications.view`  
**Priority:** P1

---

### Feature: Create stock verification

**Module:** Stock Verification  
**Feature:** Create stock count  
**Route:** `/stock-verifications/new`  
**User Action:** Select location, enter counted qty per product  
**API:** `GET /api/stock-verifications/form-data`, `POST /api/stock-verifications`  
**Required Fields:** `location_id`, `count_date`, `items[]`  
**Optional Fields:** `notes`, per-line counted qty  
**Validation:** `StoreStockVerificationRequest`  
**Expected Result:** Draft count session created  
**Permissions:** `stock_verifications.create`  
**Priority:** P1

---

### Feature: View & submit stock verification

**Module:** Stock Verification  
**Feature:** View and submit count  
**Route:** `/stock-verifications/:id`  
**User Action:** Review variances, submit for approval  
**API:** `GET /api/stock-verifications/{id}`, `POST .../submit`  
**Required Fields:** Verification ID  
**Optional Fields:** —  
**Validation:** `stock_verifications.create`  
**Expected Result:** Status → submitted  
**Permissions:** `stock_verifications.create`  
**Priority:** P1

---

### Feature: Approve / reject stock verification

**Module:** Stock Verification  
**Feature:** HO approve or reject count  
**Route:** `/stock-verifications/:id`  
**User Action:** Approve (adjust stock) or reject  
**API:** `POST /api/stock-verifications/{id}/approve`, `POST .../reject`  
**Required Fields:** Verification ID; reject reason  
**Optional Fields:** `RejectStockVerificationRequest`  
**Validation:** `stock_verifications.approve`  
**Expected Result:** Stock adjusted on approve; rejected stays draft  
**Permissions:** `stock_verifications.approve`  
**Priority:** P1

---

## Module: Production

### Feature: List BOMs and orders

**Module:** Production  
**Feature:** List production  
**Route:** `/production`  
**User Action:** Switch BOM / Orders tabs  
**API:** `GET /api/production/boms`, `GET /api/production/orders`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** Orders: `status`, `per_page`, `page`  
**Validation:** `production.view`  
**Expected Result:** BOM list (unpaginated); paginated orders  
**Permissions:** `production.view`  
**Priority:** P2

---

### Feature: Create / update BOM

**Module:** Production  
**Feature:** Manage BOM  
**Route:** `/production` (modal)  
**User Action:** Define finished product and components  
**API:** `POST /api/production/boms`, `PUT /api/production/boms/{bom}`  
**Required Fields:** Finished product, component lines with qty  
**Optional Fields:** Yield, notes  
**Validation:** `UpsertBomRequest`  
**Expected Result:** BOM saved  
**Permissions:** `production.manage_bom`  
**Priority:** P2

---

### Feature: Create production order

**Module:** Production  
**Feature:** Create production order  
**Route:** `/production` (modal)  
**User Action:** Select BOM, qty, supervisor  
**API:** `GET /api/production/form-data`, `POST /api/production/orders`  
**Required Fields:** BOM/product, quantity, dates  
**Optional Fields:** Supervisor, stages  
**Validation:** `StoreProductionOrderRequest`  
**Expected Result:** Production order with stages created  
**Permissions:** `production.create`  
**Priority:** P2

---

### Feature: View production order

**Module:** Production  
**Feature:** View production order  
**Route:** `/production/orders/:id`  
**User Action:** Track stages  
**API:** `GET /api/production/orders/{productionOrder}`  
**Required Fields:** Order UUID  
**Optional Fields:** —  
**Validation:** `production.view`  
**Expected Result:** Stage timeline and materials  
**Permissions:** `production.view`  
**Priority:** P2

---

### Feature: Complete production order

**Module:** Production  
**Feature:** Complete production  
**Route:** `/production/orders/:id`  
**User Action:** Complete order / stages  
**API:** `POST .../complete`, `POST .../stages/{id}/start`, `POST .../stages/{id}/complete`  
**Required Fields:** Order/stage IDs  
**Optional Fields:** —  
**Validation:** `production.complete`  
**Expected Result:** Components consumed; finished goods added to stock  
**Permissions:** `production.complete`  
**Priority:** P2

---

## Module: Sales (POS)

### Feature: List sales

**Module:** Sales  
**Feature:** List sales  
**Route:** `/sales`  
**User Action:** Search sale number; filter status  
**API:** `GET /api/sales`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `search`, `status`, `per_page`, `page`, `company_id`  
**Validation:** `sales.view`  
**Expected Result:** Paginated sales list  
**Permissions:** `sales.view`  
**Priority:** P0

---

### Feature: Create sale (draft / POS)

**Module:** Sales  
**Feature:** Create sale  
**Route:** `/sales/new`  
**User Action:** Add customer, lines, payment mode  
**API:** `GET /api/sales/form-data`, `POST /api/sales`  
**Required Fields:** `sale_date`, `payment_mode`, `items[]` (product_id, qty, rate)  
**Optional Fields:** `customer_id`, `location_id`, `customer_name`, `is_interstate`, `bill_kind`, `amount_paid`, `loyalty_points_redeemed`, `product_batch_id`, `price_level`, discounts  
**Validation:** `StoreSaleRequest` — complimentary requires `sales.confirm`  
**Expected Result:** Draft sale with totals and GST  
**Permissions:** `sales.create`  
**Priority:** P0

---

### Feature: View sale detail

**Module:** Sales  
**Feature:** View sale  
**Route:** `/sales/:id`  
**User Action:** Open invoice detail  
**API:** `GET /api/sales/{sale}`  
**Required Fields:** Sale UUID  
**Optional Fields:** —  
**Validation:** `sales.view`  
**Expected Result:** Lines, payment, workflow actions  
**Permissions:** `sales.view`  
**Priority:** P0

---

### Feature: Confirm sale

**Module:** Sales  
**Feature:** Confirm sale  
**Route:** `/sales/:id`  
**User Action:** Confirm invoice  
**API:** `POST /api/sales/{sale}/confirm`  
**Required Fields:** Sale ID  
**Optional Fields:** —  
**Validation:** `sales.confirm`; stock must be sufficient  
**Expected Result:** Status confirmed; stock reduced; receipt posted if cash  
**Permissions:** `sales.confirm`  
**Priority:** P0

---

### Feature: Cancel sale workflow

**Module:** Sales  
**Feature:** Cancel sale  
**Route:** `/sales/:id`  
**User Action:** Request → approve/reject cancellation  
**API:** `POST .../cancel-request`, `POST .../cancel-approve`, `POST .../cancel-reject`  
**Required Fields:** Sale ID, reason (where applicable)  
**Optional Fields:** —  
**Validation:** `sales.cancel_request`, `sales.cancel_approve`  
**Expected Result:** Sale cancelled with stock reversal on approval  
**Permissions:** `sales.cancel_request`, `sales.cancel_approve`  
**Priority:** P1

---

### Feature: Convert proforma

**Module:** Sales  
**Feature:** Convert proforma to tax invoice  
**Route:** `/sales/:id`  
**User Action:** Convert proforma  
**API:** `POST /api/sales/{sale}/convert-proforma`  
**Required Fields:** Sale ID  
**Optional Fields:** —  
**Validation:** `sales.confirm`  
**Expected Result:** Bill kind updated; stock posted if not already  
**Permissions:** `sales.confirm`  
**Priority:** P2

---

### Feature: WhatsApp invoice

**Module:** Sales  
**Feature:** Send invoice via WhatsApp  
**Route:** `/sales/:id`  
**User Action:** Send WhatsApp  
**API:** `POST /api/sales/{sale}/whatsapp`  
**Required Fields:** Sale ID  
**Optional Fields:** Phone override  
**Validation:** `sales.whatsapp`  
**Expected Result:** Message queued/sent; log entry  
**Permissions:** `sales.whatsapp`  
**Priority:** P3

---

### Feature: Delete draft sale

**Module:** Sales  
**Feature:** Delete draft sale  
**Route:** `/sales/:id`  
**User Action:** Delete draft  
**API:** `DELETE /api/sales/{sale}`  
**Required Fields:** Sale ID  
**Optional Fields:** —  
**Validation:** `sales.delete`; draft only  
**Expected Result:** Sale removed  
**Permissions:** `sales.delete`  
**Priority:** P1

---

### Feature: Download sale invoice PDF

**Module:** Sales  
**Feature:** Download sale PDF  
**Route:** `/sales/:id`  
**User Action:** Download PDF  
**API:** `GET /api/sales/{sale}/invoice.pdf`  
**Required Fields:** Sale ID  
**Optional Fields:** —  
**Validation:** `sales.view`  
**Expected Result:** PDF downloaded  
**Permissions:** `sales.view`  
**Priority:** P1

---

## Module: Sales Returns

### Feature: List sales returns

**Module:** Sales Returns  
**Feature:** List sales returns  
**Route:** `/sales-returns`  
**User Action:** View returns  
**API:** `GET /api/sales-returns`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `search`, `status`, `per_page`, `page`, `company_id`  
**Validation:** `sales_returns.view`  
**Expected Result:** Paginated list  
**Permissions:** `sales_returns.view`  
**Priority:** P1

---

### Feature: Create sales return

**Module:** Sales Returns  
**Feature:** Create sales return  
**Route:** `/sales-returns/new`  
**User Action:** Select source sale, return lines  
**API:** `GET /api/sales-returns/source`, `POST /api/sales-returns`  
**Required Fields:** Source sale, `items[]` qty  
**Optional Fields:** `return_date`, `reason`  
**Validation:** `StoreSalesReturnRequest`  
**Expected Result:** Draft return created  
**Permissions:** `sales_returns.create`  
**Priority:** P1

---

### Feature: Confirm sales return

**Module:** Sales Returns  
**Feature:** Confirm sales return  
**Route:** `/sales-returns/:id`  
**User Action:** Confirm return  
**API:** `POST /api/sales-returns/{salesReturn}/confirm`  
**Required Fields:** Return ID  
**Optional Fields:** —  
**Validation:** `sales_returns.confirm`  
**Expected Result:** Stock restored; customer balance adjusted  
**Permissions:** `sales_returns.confirm`  
**Priority:** P1

---

### Feature: Delete sales return

**Module:** Sales Returns  
**Feature:** Delete sales return  
**Route:** `/sales-returns/:id`  
**User Action:** Cancel draft  
**API:** `DELETE /api/sales-returns/{salesReturn}`  
**Required Fields:** Return ID  
**Optional Fields:** —  
**Validation:** `sales_returns.delete`  
**Expected Result:** Draft removed  
**Permissions:** `sales_returns.delete`  
**Priority:** P2

---

## Module: Advance Orders

### Feature: List advance orders

**Module:** Advance Orders  
**Feature:** List advance orders  
**Route:** `/advance-orders`  
**User Action:** View pre-bookings  
**API:** `GET /api/advance-orders`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `search`, `status`, `per_page`, `page`, `company_id`  
**Validation:** `advance.view`  
**Expected Result:** Paginated list  
**Permissions:** `advance.view`  
**Priority:** P2

---

### Feature: Create advance order

**Module:** Advance Orders  
**Feature:** Create advance order  
**Route:** `/advance-orders/new`  
**User Action:** Customer, items, advance payment  
**API:** `GET /api/advance-orders/form-data`, `POST /api/advance-orders`  
**Required Fields:** `customer_id`, `order_date`, `advance_amount`, `advance_mode`, `items[]`  
**Optional Fields:** `expected_date`, `notes`  
**Validation:** `StoreAdvanceOrderRequest`  
**Expected Result:** Advance order booked  
**Permissions:** `advance.create`  
**Priority:** P2

---

### Feature: Fulfill advance order

**Module:** Advance Orders  
**Feature:** Fulfill advance order  
**Route:** `/advance-orders/:id`  
**User Action:** Fulfill → creates linked sale  
**API:** `POST /api/advance-orders/{advanceOrder}/fulfill`  
**Required Fields:** Order ID  
**Optional Fields:** —  
**Validation:** `advance.fulfill`  
**Expected Result:** Draft sale created; advance applied  
**Permissions:** `advance.fulfill`  
**Priority:** P2

---

### Feature: Delete advance order

**Module:** Advance Orders  
**Feature:** Cancel advance order  
**Route:** `/advance-orders/:id`  
**User Action:** Cancel  
**API:** `DELETE /api/advance-orders/{advanceOrder}`  
**Required Fields:** Order ID  
**Optional Fields:** —  
**Validation:** `advance.delete`  
**Expected Result:** Order cancelled  
**Permissions:** `advance.delete`  
**Priority:** P3

---

## Module: Backorders

### Feature: List backorders

**Module:** Backorders  
**Feature:** List backorders  
**Route:** `/backorders`  
**User Action:** View shortage orders  
**API:** `GET /api/backorders`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `search`, `status`, `per_page`, `page`, `company_id`  
**Validation:** `backorder.view`  
**Expected Result:** Paginated list  
**Permissions:** `backorder.view`  
**Priority:** P2

---

### Feature: Create backorder

**Module:** Backorders  
**Feature:** Create backorder  
**Route:** `/backorders/new` or from sale  
**User Action:** Create manually or from sale shortage  
**API:** `POST /api/backorders`, `POST /api/sales/{sale}/backorder`  
**Required Fields:** Customer/product lines per `StoreBackorderRequest`  
**Optional Fields:** `expected_date`, `notes`  
**Validation:** `StoreBackorderRequest`  
**Expected Result:** Backorder recorded  
**Permissions:** `backorder.create`  
**Priority:** P2

---

### Feature: Fulfill backorder

**Module:** Backorders  
**Feature:** Fulfill backorder  
**Route:** `/backorders/:id`  
**User Action:** Fulfill when stock available  
**API:** `POST /api/backorders/{backorder}/fulfill`  
**Required Fields:** Backorder ID, fulfill items  
**Optional Fields:** `FulfillBackorderRequest` items  
**Validation:** `backorder.fulfill`  
**Expected Result:** Stock allocated; linked sale updated  
**Permissions:** `backorder.fulfill`  
**Priority:** P2

---

## Module: Plant Rental

### Feature: List rentals

**Module:** Plant Rental  
**Feature:** List rentals  
**Route:** `/rentals`  
**User Action:** Filter by status  
**API:** `GET /api/rentals`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `search`, `status`, `per_page`, `page`, `company_id`  
**Validation:** `rental.view`  
**Expected Result:** Paginated rental contracts  
**Permissions:** `rental.view`  
**Priority:** P2

---

### Feature: Create rental

**Module:** Plant Rental  
**Feature:** Create rental  
**Route:** `/rentals/new`  
**User Action:** Customer, plants, rental terms  
**API:** `GET /api/rentals/form-data`, `POST /api/rentals`  
**Required Fields:** `customer_id`, `start_date`, `items[]`  
**Optional Fields:** `end_date`, `deposit`, `delivery_address`, rates  
**Validation:** `StoreRentalRequest`  
**Expected Result:** Draft rental created  
**Permissions:** `rental.create`  
**Priority:** P2

---

### Feature: Activate rental

**Module:** Plant Rental  
**Feature:** Activate rental  
**Route:** `/rentals/:id`  
**User Action:** Issue stock to customer  
**API:** `POST /api/rentals/{rental}/activate`  
**Required Fields:** Rental ID  
**Optional Fields:** —  
**Validation:** `rental.activate`  
**Expected Result:** Stock issued; rental active  
**Permissions:** `rental.activate`  
**Priority:** P2

---

### Feature: Return rental items

**Module:** Plant Rental  
**Feature:** Return rental items  
**Route:** `/rentals/:id`  
**User Action:** Record returns  
**API:** `POST /api/rentals/{rental}/return`  
**Required Fields:** Return lines  
**Optional Fields:** `ReturnRentalRequest` fields  
**Validation:** `rental.return`  
**Expected Result:** Partial/full return recorded  
**Permissions:** `rental.return`  
**Priority:** P2

---

### Feature: Settle rental & invoice

**Module:** Plant Rental  
**Feature:** Settle and bill rental  
**Route:** `/rentals/:id`  
**User Action:** Settle contract; generate/pay invoices  
**API:** `POST .../settle`, `POST .../invoices`, `POST /api/rental-invoices/{id}/paid`  
**Required Fields:** Rental/invoice IDs  
**Optional Fields:** `GenerateRentalInvoiceRequest`  
**Validation:** `rental.bill`, `rental.settle`  
**Expected Result:** Final charges; PDF invoice; payment recorded  
**Permissions:** `rental.bill`  
**Priority:** P2

---

## Module: Loyalty

### Feature: Loyalty dashboard

**Module:** Loyalty  
**Feature:** Loyalty dashboard  
**Route:** `/loyalty`  
**User Action:** View points balances and ledger  
**API:** `GET /api/loyalty`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `per_page`, `page`, `company_id`  
**Validation:** `loyalty.view` OR `customers.view`  
**Expected Result:** Top customers by points; recent ledger (40 entries)  
**Permissions:** `loyalty.view`  
**Priority:** P3

---

### Feature: Configure loyalty rules

**Module:** Loyalty  
**Feature:** Loyalty rules  
**Route:** `/loyalty` (modal)  
**User Action:** Set earn/redeem rules  
**API:** `GET /api/loyalty/rules`, `POST /api/loyalty/rules`, `DELETE /api/loyalty/rules/{id}`  
**Required Fields:** Rule parameters (earn rate, redeem rate, etc.)  
**Optional Fields:** —  
**Validation:** Inline validation in controller  
**Expected Result:** Rules saved/deleted  
**Permissions:** `loyalty.manage`  
**Priority:** P3

---

### Feature: Manual points adjustment

**Module:** Loyalty  
**Feature:** Adjust loyalty points  
**Route:** `/loyalty` (modal)  
**User Action:** Add/deduct points with reason  
**API:** `POST /api/loyalty/adjust`  
**Required Fields:** `customer_id`, `points`, `reason`  
**Optional Fields:** —  
**Validation:** `loyalty.adjust`  
**Expected Result:** Points balance updated; ledger entry  
**Permissions:** `loyalty.adjust`  
**Priority:** P3

---

## Module: Commission

### Feature: Commission rules & payouts

**Module:** Commission  
**Feature:** Commission management  
**Route:** `/commission`  
**User Action:** Manage rules, tiers, payouts, promotions across tabs  
**API:** Multiple — `GET/POST /api/commission/rules`, `/payouts`, `/promotions`, `/seasonal-care-rules`, `/whatsapp-templates`, `/manager-rules`, `/transactions`, `/daily-summary`, `POST /send-eod`  
**Required Fields:** Varies per sub-feature (`UpsertCommissionRuleRequest`, `StoreCommissionPayoutRequest`)  
**Optional Fields:** Tier sync, daily targets, date filters  
**Validation:** Form requests + inline validation  
**Expected Result:** Rules applied; payouts recorded; EOD messages sent  
**Permissions:** `commission.view`, `commission.manage`, `commission.pay`, `whatsapp.templates`  
**Priority:** P3

---

## Module: Supplier Payments

### Feature: List supplier payments

**Module:** Supplier Payments  
**Feature:** List payments  
**Route:** `/payments`  
**User Action:** View payment history  
**API:** `GET /api/supplier-payments`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `supplier_id`, `per_page`, `page`, `company_id`  
**Validation:** `payments.view`  
**Expected Result:** Paginated payments  
**Permissions:** `payments.view`  
**Priority:** P1

---

### Feature: Record supplier payment

**Module:** Supplier Payments  
**Feature:** Record payment  
**Route:** `/payments` (modal)  
**User Action:** Pay supplier against payables  
**API:** `GET /api/supplier-payments/form-data`, `GET /api/supplier-payments/payables`, `POST /api/supplier-payments`  
**Required Fields:** `supplier_id`, `amount`, `payment_date`, `mode`  
**Optional Fields:** `purchase_id`, `reference`, `notes`  
**Validation:** `StoreSupplierPaymentRequest`  
**Expected Result:** Payment recorded; payable balance reduced  
**Permissions:** `payments.create`  
**Priority:** P1

---

### Feature: Void supplier payment

**Module:** Supplier Payments  
**Feature:** Void payment  
**Route:** `/payments`  
**User Action:** Delete/void payment  
**API:** `DELETE /api/supplier-payments/{supplierPayment}`  
**Required Fields:** Payment ID  
**Optional Fields:** —  
**Validation:** `payments.delete`  
**Expected Result:** Payment voided  
**Permissions:** `payments.delete`  
**Priority:** P2

---

## Module: Customer Receipts

### Feature: List customer receipts

**Module:** Customer Receipts  
**Feature:** List receipts  
**Route:** `/receipts`  
**User Action:** View receipt history  
**API:** `GET /api/customer-receipts`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `customer_id`, `per_page`, `page`, `company_id`  
**Validation:** `receipts.view`  
**Expected Result:** Paginated receipts  
**Permissions:** `receipts.view`  
**Priority:** P1

---

### Feature: Record customer receipt

**Module:** Customer Receipts  
**Feature:** Record receipt  
**Route:** `/receipts` (modal)  
**User Action:** Record payment against receivable  
**API:** `GET /api/customer-receipts/receivables`, `POST /api/customer-receipts`  
**Required Fields:** `customer_id`, `amount`, `receipt_date`, `mode`  
**Optional Fields:** `sale_id`, `reference`, `notes`  
**Validation:** `StoreCustomerReceiptRequest`  
**Expected Result:** Receipt recorded; balance reduced  
**Permissions:** `receipts.create`  
**Priority:** P1

---

### Feature: Void customer receipt

**Module:** Customer Receipts  
**Feature:** Void receipt  
**Route:** `/receipts`  
**User Action:** Void receipt  
**API:** `DELETE /api/customer-receipts/{customerReceipt}`  
**Required Fields:** Receipt ID  
**Optional Fields:** —  
**Validation:** `receipts.delete`  
**Expected Result:** Receipt voided  
**Permissions:** `receipts.delete`  
**Priority:** P2

---

## Module: Locations

### Feature: List locations

**Module:** Locations  
**Feature:** List locations  
**Route:** `/locations`  
**User Action:** View warehouse/branch locations  
**API:** `GET /api/locations`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `company_id`  
**Validation:** `locations.view`  
**Expected Result:** Full list (no pagination), default first  
**Permissions:** `locations.view`  
**Priority:** P2

---

### Feature: Create / update / delete location

**Module:** Locations  
**Feature:** Manage locations  
**Route:** `/locations` (modal)  
**User Action:** CRUD locations  
**API:** `POST /api/locations`, `PUT /api/locations/{location}`, `DELETE /api/locations/{location}`  
**Required Fields:** `name`, `type`  
**Optional Fields:** `is_default`, `is_active`, address fields  
**Validation:** `StoreLocationRequest`  
**Expected Result:** Location saved or removed  
**Permissions:** `locations.manage`  
**Priority:** P2

---

## Module: Users

### Feature: List users

**Module:** Users  
**Feature:** List users  
**Route:** `/users`  
**User Action:** Filter active/inactive  
**API:** `GET /api/users`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `status`, `per_page` (25), `page`, `company_id`  
**Validation:** `users.view`  
**Expected Result:** Paginated user list  
**Permissions:** `users.view`  
**Priority:** P2

---

### Feature: Create user

**Module:** Users  
**Feature:** Create user  
**Route:** `/users` (modal)  
**User Action:** Add user with role and companies  
**API:** `GET /api/users/form-data`, `POST /api/users`  
**Required Fields:** `name`, `email`, `password`, role/company assignments  
**Optional Fields:** `phone`, `is_active`  
**Validation:** `StoreUserRequest`  
**Expected Result:** User created; can log in  
**Permissions:** `users.create`  
**Priority:** P2

---

### Feature: View user detail

**Module:** Users  
**Feature:** View user  
**Route:** `/users/:id`  
**User Action:** Open user profile  
**API:** `GET /api/users/{user}`  
**Required Fields:** User ID  
**Optional Fields:** —  
**Validation:** `users.view`  
**Expected Result:** User details and assignments  
**Permissions:** `users.view`  
**Priority:** P3

---

### Feature: Update user

**Module:** Users  
**Feature:** Update user  
**Route:** `/users` (edit modal)  
**User Action:** Edit user  
**API:** `PUT /api/users/{user}`  
**Required Fields:** `name`, `email`  
**Optional Fields:** `password`, roles, companies, `phone`  
**Validation:** `UpdateUserRequest`  
**Expected Result:** User updated  
**Permissions:** `users.update`  
**Priority:** P2

---

### Feature: Delete / deactivate user

**Module:** Users  
**Feature:** Remove user  
**Route:** `/users`  
**User Action:** Delete or toggle status  
**API:** `DELETE /api/users/{user}`, `PATCH /api/users/{user}/status`  
**Required Fields:** User ID  
**Optional Fields:** —  
**Validation:** `users.delete`, `users.update`  
**Expected Result:** User removed or deactivated  
**Permissions:** `users.delete`  
**Priority:** P2

---

## Module: Roles

### Feature: List roles

**Module:** Roles  
**Feature:** List roles  
**Route:** `/roles`  
**User Action:** Search and sort roles  
**API:** `GET /api/roles`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `search`, `sort`, `dir`, `per_page`, `page`  
**Validation:** `roles.view`  
**Expected Result:** Paginated roles with permission counts  
**Permissions:** `roles.view`  
**Priority:** P2

---

### Feature: Create / update role

**Module:** Roles  
**Feature:** Manage role permissions  
**Route:** `/roles` (modal)  
**User Action:** Define role name and permission matrix  
**API:** `GET /api/roles/form-data`, `POST /api/roles`, `PUT /api/roles/{role}`  
**Required Fields:** `name`, `permissions[]`  
**Optional Fields:** `slug`, description  
**Validation:** `StoreRoleRequest`, `UpdateRoleRequest` (super_admin gate on store)  
**Expected Result:** Role saved; users with role get new permissions  
**Permissions:** `roles.create`, `roles.update`  
**Priority:** P2

---

### Feature: Delete role

**Module:** Roles  
**Feature:** Delete role  
**Route:** `/roles`  
**User Action:** Delete role  
**API:** `DELETE /api/roles/{role}`  
**Required Fields:** Role ID  
**Optional Fields:** —  
**Validation:** `roles.delete`  
**Expected Result:** Role removed if unassigned  
**Permissions:** `roles.delete`  
**Priority:** P3

---

## Module: Reports

### Feature: Reports dashboard

**Module:** Reports  
**Feature:** Reports dashboard  
**Route:** `/reports`  
**User Action:** Select report tab, date range, run report  
**API:** `GET /api/reports/form-data`, `GET /api/reports/dashboard`, plus tab-specific endpoints  
**Required Fields:** `X-Company-Id`; date range on most reports  
**Optional Fields:** `from`, `to`, `location_id`, `format` (export), `company_id`  
**Validation:** `reports.view`; HO-only for margin/profit (`reports.margin`, `reports.profit`)  
**Expected Result:** Report data rendered; export downloads file  
**Permissions:** `reports.view` (+ module-specific for rental/production/accounting sub-reports)  
**Priority:** P2

---

### Report sub-features (API inventory)

| Feature | API | Export | Extra permission |
|---------|-----|--------|------------------|
| Dashboard summary | `GET /reports/dashboard` | `/dashboard/export` | `reports.view` |
| Margin analysis | `GET /reports/margin` | `/margin/export` | HO: `reports.margin` |
| Profit report | `GET /reports/profit` | `/profit/export` | HO: `reports.profit` |
| Price levels | `GET /reports/price-levels` | — | `reports.view` |
| Rental reports | `GET /reports/rental/*` | `*/export` | `reports.view` + `rental.view` |
| Production reports | `GET /reports/production/*` | — | `reports.view` + `production.view` |
| Transfer reports | `GET /reports/transfers/*` | — | `reports.view` + `transfers.view` |
| Cash / bank book | `GET /reports/accounting/cash-book`, `bank-book` | — | Accounting access |
| Debtor / creditor ledger | `GET /reports/accounting/debtor-ledger`, `creditor-ledger` | — | `customer_id`/`supplier_id` required |
| Ageing | `GET /reports/accounting/ageing-receivables`, `ageing-payables` | — | Accounting access |
| Sales analytics | `GET /reports/sales/*` | — | `reports.view` |
| GST reconciliation | `GET /reports/gst-reconciliation` | — | `reports.view` |
| Commission report | `GET /reports/commission` | — | `reports.view` + `commission.view` |
| Leaderboard | `GET /reports/leaderboard` | — | `reports.view` |
| EOD management | `GET /reports/eod-management/preview`, `POST .../send` | — | `settings.update` or `commission.manage` |

**Priority:** P2–P3 depending on report

---

## Module: Activity Monitoring

### Feature: Activity snapshot

**Module:** Activity Monitoring  
**Feature:** HO activity dashboard  
**Route:** `/activity-monitoring`  
**User Action:** View cross-company activity snapshot  
**API:** `GET /api/activity-monitoring/form-data`, `GET /api/activity-monitoring`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** `company_id` (super-admin)  
**Validation:** Super admin OR `*` OR `activity.view`  
**Expected Result:** Recent activity metrics displayed  
**Permissions:** `activity.view`  
**Priority:** P3

---

## Module: Backups

### Feature: List backups

**Module:** Backups  
**Feature:** List database backups  
**Route:** `/backups`  
**User Action:** View backup files  
**API:** `GET /api/backups`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** —  
**Validation:** Super admin OR `*` OR `backup.view`  
**Expected Result:** Backup file list with dates/sizes  
**Permissions:** `backup.view`  
**Priority:** P3

---

### Feature: Run backup

**Module:** Backups  
**Feature:** Run manual backup  
**Route:** `/backups`  
**User Action:** Click Run backup  
**API:** `POST /api/backups/run`  
**Required Fields:** —  
**Optional Fields:** —  
**Validation:** `backup.run`  
**Expected Result:** New backup file created  
**Permissions:** `backup.run`  
**Priority:** P3

---

### Feature: Download / restore backup

**Module:** Backups  
**Feature:** Download or restore  
**Route:** `/backups`  
**User Action:** Download file or restore (destructive)  
**API:** `GET /api/backups/{filename}/download`, `POST /api/backups/{filename}/restore`  
**Required Fields:** Filename  
**Optional Fields:** —  
**Validation:** `backup.view`, `backup.restore`  
**Expected Result:** File downloaded or DB restored  
**Permissions:** `backup.view`, `backup.restore`  
**Priority:** P3

---

## Module: Settings

### Feature: View company settings

**Module:** Settings  
**Feature:** View settings  
**Route:** `/settings`  
**User Action:** Open settings page  
**API:** `GET /api/settings`  
**Required Fields:** `X-Company-Id`  
**Optional Fields:** —  
**Validation:** `settings.view` OR `*` OR super admin  
**Expected Result:** Key/value settings displayed (GST, invoice, loyalty, etc.)  
**Permissions:** `settings.view`  
**Priority:** P2

---

### Feature: Update company settings

**Module:** Settings  
**Feature:** Update settings  
**Route:** `/settings`  
**User Action:** Save settings form  
**API:** `PUT /api/settings`  
**Required Fields:** Varies by setting keys  
**Optional Fields:** All company preference keys validated inline  
**Validation:** Inline controller validation  
**Expected Result:** Settings persisted for active company  
**Permissions:** `settings.update`  
**Priority:** P2

---

## Module: File Upload

### Feature: Upload media

**Module:** File Upload  
**Feature:** Upload image/file  
**Route:** Used in product/company forms  
**User Action:** Select file to upload  
**API:** `POST /api/uploads`  
**Required Fields:** File payload per controller validation  
**Optional Fields:** —  
**Validation:** Authenticated; file type/size limits in `UploadController`  
**Expected Result:** URL returned for use in forms  
**Permissions:** Authenticated  
**Priority:** P2

---

## Cross-Cutting Features

### Feature: Company switcher

**Module:** Cross-Cutting  
**Feature:** Switch active company  
**Route:** App shell header  
**User Action:** Select company from dropdown  
**API:** `GET /api/permissions` (after switch)  
**Required Fields:** `X-Company-Id` updated client-side  
**Optional Fields:** —  
**Validation:** User must belong to company or be super admin  
**Expected Result:** All company-scoped data refreshes; sidebar permissions re-evaluated  
**Priority:** P0

---

### Feature: Super-admin company filter

**Module:** Cross-Cutting  
**Feature:** Filter lists by company  
**Route:** Most list pages (via `useCompanyFilter`)  
**User Action:** Select company or "All" on list pages  
**API:** `company_id=all|{id}` query param on GET  
**Required Fields:** Super-admin role  
**Optional Fields:** —  
**Validation:** Super admin only  
**Expected Result:** List shows data for selected company scope  
**Priority:** P1

---

### Feature: Permission-denied UI

**Module:** Cross-Cutting  
**Feature:** Route guard  
**Route:** Any protected route  
**User Action:** Navigate to unauthorized URL  
**API:** — (frontend guard)  
**Required Fields:** —  
**Optional Fields:** —  
**Validation:** `PermissionRoute` checks `can()` / `isSuperAdmin`  
**Expected Result:** "You do not have permission" card shown; no API call for page content  
**Priority:** P1

---

### Feature: Unauthenticated redirect

**Module:** Cross-Cutting  
**Feature:** 401 handling  
**Route:** Any API call  
**User Action:** Token expires or invalid  
**API:** Any endpoint returning 401  
**Required Fields:** —  
**Optional Fields:** —  
**Validation:** Axios interceptor in `api.js`  
**Expected Result:** Token cleared; redirect to `/login`  
**Priority:** P0

---

## Test Coverage Notes

| Area | Automated tests | Manual priority |
|------|-----------------|-----------------|
| Auth, Sales, Purchases, Inventory | Backend Pest (`ErpQaMatrixTest`, phase tests) | P0 |
| Transfers, Bulk splits, Stock count | Partial backend coverage | P1 |
| Commission, Loyalty, Reports | Limited / export manual | P3 |
| Frontend UI flows | **None** | All modules need manual E2E |

---

*Generated by QA analysis — read-only inspection. Refer to `QA/PROJECT_ANALYSIS.md` for stack and architecture context.*
