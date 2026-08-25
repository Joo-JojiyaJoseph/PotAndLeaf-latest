# Authentication & Authorization — Automated Test Results

**Date:** 2026-08-25  
**Test file:** `PotAndLeaf-backend/tests/Feature/AuthorizationModuleTest.php`  
**Framework:** Pest 5 (Laravel API feature tests)  
**Command:** `php artisan test tests/Feature/AuthorizationModuleTest.php`  
**Production code modified:** No

---

## Summary

| Status | Count |
|--------|-------|
| **Passed** | **14** |
| **Failed** | **0** |
| **Skipped** | **0** |
| **Errors** | **0** |
| **Total** | **14** |

**Assertions:** 153  
**Duration:** ~2.6s

---

## Roles identified (from project)

Roles are defined in `database/seeders/StandardRolesSeeder.php` plus the protected system role from `EnsureCompanyAdminRole.php`.

| # | Role | Slug | Source | `is_system` |
|---|------|------|--------|-------------|
| 1 | **Administrator** | `administrator` | `EnsureCompanyAdminRole` | Yes |
| 2 | **Manager** | `manager` | `StandardRolesSeeder` | No |
| 3 | **Cashier** | `cashier` | `StandardRolesSeeder` | No |
| 4 | **Godown Staff** | `godown-staff` | `StandardRolesSeeder` | No |
| 5 | **Supervisor** | `supervisor` | `StandardRolesSeeder` | No |
| 6 | **Salesman** | `salesman` | `StandardRolesSeeder` | No |

**Permission catalog:** `App\Support\Rbac\PermissionRegistry.php` (seeded via `PermissionSeeder`)

**Resolution rules** (`HasRolesAndPermissions`):

- `*` → full access (Administrator)
- Company-scoped via `role_user.company_id` + `X-Company-Id` header
- Super-admin bypasses all permission checks (not used in these tests)

---

## API endpoints used for access probes

| Probe | Endpoint | Permission checked |
|-------|----------|-------------------|
| Login | `POST /api/login` | Active user credentials |
| Dashboard | `GET /api/dashboard` | `reports.view` |
| Products | `GET /api/products` | `products.view` |
| Customers | `GET /api/customers` | `customers.view` |
| Suppliers | `GET /api/suppliers` | `suppliers.view` |
| Purchases | `GET /api/purchases` | `purchases.view` |
| Sales | `GET /api/sales` | `sales.view` |
| Inventory | `GET /api/inventory/stock` | `inventory.view` |
| Payments | `GET /api/supplier-payments` | `payments.view` |
| User management | `GET /api/users` | `users.view` |
| Reports | `GET /api/reports/dashboard` | `reports.view` |
| Create | `POST /api/customers` | `customers.create` |
| Edit | `PUT /api/customers/{id}` | `customers.update` |
| Delete | `DELETE /api/suppliers/{id}` | `suppliers.delete` |

**Note:** “Page access” is verified via equivalent API routes (frontend uses the same permission gates in `AuthContext.can()`).

---

## Per-role access matrix

Legend: ✅ Allowed (200 or validation 422 on mutation) · ❌ Forbidden (403)

### 1. Administrator (`administrator`)

Permission: `*` (full access)

| Check | Result |
|-------|--------|
| Login | ✅ |
| Dashboard | ✅ |
| Products | ✅ |
| Customers | ✅ |
| Suppliers | ✅ |
| Purchases | ✅ |
| Sales | ✅ |
| Inventory | ✅ |
| Payments | ✅ |
| User management | ✅ |
| Reports | ✅ |
| Create | ✅ |
| Edit | ✅ |
| Delete | ✅ |

### 2. Manager (`manager`)

Broad module access; user admin limited to view.

| Check | Result |
|-------|--------|
| Login | ✅ |
| Dashboard | ✅ |
| Products | ✅ |
| Customers | ✅ |
| Suppliers | ✅ |
| Purchases | ✅ |
| Sales | ✅ |
| Inventory | ✅ |
| Payments | ✅ |
| User management | ✅ (`users.view` only) |
| Reports | ✅ |
| Create | ✅ |
| Edit | ✅ |
| Delete | ✅ |

### 3. Cashier (`cashier`)

POS-focused: sales, customers, receipts; no supplier/purchase/payment/report access.

| Check | Result |
|-------|--------|
| Login | ✅ |
| Dashboard | ❌ |
| Products | ✅ (view) |
| Customers | ✅ |
| Suppliers | ❌ |
| Purchases | ❌ |
| Sales | ✅ |
| Inventory | ✅ (view) |
| Payments | ❌ |
| User management | ❌ |
| Reports | ❌ |
| Create | ✅ (`customers.create`) |
| Edit | ❌ |
| Delete | ❌ |

### 4. Godown Staff (`godown-staff`)

Warehouse operations: inventory, transfers, damage, stock counts; purchase view only.

| Check | Result |
|-------|--------|
| Login | ✅ |
| Dashboard | ❌ |
| Products | ✅ (view) |
| Customers | ❌ |
| Suppliers | ❌ |
| Purchases | ✅ (view) |
| Sales | ❌ |
| Inventory | ✅ |
| Payments | ❌ |
| User management | ❌ |
| Reports | ❌ |
| Create | ❌ |
| Edit | ❌ |
| Delete | ❌ |

### 5. Supervisor (`supervisor`)

Limited inventory oversight: view-only on most modules.

| Check | Result |
|-------|--------|
| Login | ✅ |
| Dashboard | ❌ |
| Products | ✅ (view) |
| Customers | ❌ |
| Suppliers | ❌ |
| Purchases | ❌ |
| Sales | ❌ |
| Inventory | ✅ (view) |
| Payments | ❌ |
| User management | ❌ |
| Reports | ❌ |
| Create | ❌ |
| Edit | ❌ |
| Delete | ❌ |

### 6. Salesman (`salesman`)

Field sales: products/inventory view, sales create, customer create, backorders.

| Check | Result |
|-------|--------|
| Login | ✅ |
| Dashboard | ❌ |
| Products | ✅ (view) |
| Customers | ✅ |
| Suppliers | ❌ |
| Purchases | ❌ |
| Sales | ✅ |
| Inventory | ✅ (view) |
| Payments | ❌ |
| User management | ❌ |
| Reports | ❌ |
| Create | ✅ (`customers.create`) |
| Edit | ❌ |
| Delete | ❌ |

---

## AUTHZ scenario results

| ID | Scenario | Layer | Result | Verification |
|----|----------|-------|--------|--------------|
| **AUTHZ-001** | Authorized user accesses permitted API | API | ✅ Pass | Cashier → `GET /api/sales`, `GET /api/customers` → 200 |
| **AUTHZ-002** | Unauthorized user blocked from restricted API | API | ✅ Pass | Cashier → `GET /api/suppliers`, `GET /api/users` → 403 |
| **AUTHZ-003** | Unauthorized mutation blocked | API | ✅ Pass | Salesman → `POST /api/supplier-payments`, `DELETE /api/suppliers/{id}` → 403 |
| **AUTHZ-004** | Cannot read another company's data | API | ✅ Pass | Manager (Company A) → `GET /api/products/{foreign}` → 404 |
| **AUTHZ-005** | Cannot edit another company's data | API | ✅ Pass | Manager → `PUT /api/customers/{foreign}` → 404; record unchanged |
| **AUTHZ-006** | Cannot delete another company's data | API | ✅ Pass | Manager → `DELETE /api/suppliers/{foreign}` → 404; record persists |

**Additional:** User with **no role/permissions** → login succeeds but all module APIs return 403.

---

## Cross-company isolation (inspected)

`AssertsRecordCompany` trait enforces:

- **Read:** Record `company_id` must match `X-Company-Id` (or super-admin / explicit `company_id` query for read)
- **Write:** Always requires header company to match record company → **404** (not 403) to avoid leaking existence

Tested modules: Products (read), Customers (update), Suppliers (delete).

---

## Edit / delete support by module

| Module | Edit API | Delete API | Notes |
|--------|----------|------------|-------|
| Products | `PUT /api/products/{id}` | `DELETE /api/products/{id}` | `products.update` / `products.delete` |
| Customers | `PUT /api/customers/{id}` | `DELETE /api/customers/{id}` | FormRequest authorize |
| Suppliers | `PUT /api/suppliers/{id}` | `DELETE /api/suppliers/{id}` | Policy + permissions |
| Purchases | `PUT /api/purchases/{id}` | `DELETE /api/purchases/{id}` | Draft only |
| Sales | — | `DELETE /api/sales/{id}` | No edit endpoint |
| Payments | — | `DELETE /api/supplier-payments/{id}` | Void only; no edit |
| Users | `PUT /api/users/{id}` | `DELETE /api/users/{id}` | Requires `users.update` / `users.delete` |

---

## Manager permission scope (from seeder)

`StandardRolesSeeder` grants Manager prefixes:

`suppliers.`, `products.`, `purchases.`, `inventory.`, `damage.`, `purchase_returns.`, `sales_returns.`, `stock_verifications.`, `bulk_splits.`, `sales.`, `customers.`, `payments.`, `receipts.`, `commission.`, `loyalty.`, `whatsapp.`, `transfers.`, `locations.`, `production.`, `rental.`, `reports.`, `activity.`, `backup.`, `po.`, `advance.`, `backorder.`, `categories.`, `brands.`, `units.`, plus `users.view` and `roles.view`.

---

## Test setup

- `RefreshDatabase` + full permission seed + `StandardRolesSeeder`
- `EnsureCompanyAdminRole` run once to register Administrator role with `*`
- Two companies (HQ + Branch) for cross-company isolation tests
- Each role user: factory user + single role attachment per company

---

## How to re-run

```bash
cd PotAndLeaf-backend
php artisan test tests/Feature/AuthorizationModuleTest.php
```

Filter by role or AUTHZ case:

```bash
php artisan test tests/Feature/AuthorizationModuleTest.php --group=role-cashier
php artisan test tests/Feature/AuthorizationModuleTest.php --group=AUTHZ-004
```

---

## Related tests

Login/authentication API coverage: `tests/Feature/LoginModuleTest.php` (LOGIN-001 … LOGIN-012)
