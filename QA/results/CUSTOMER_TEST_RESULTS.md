# Customer Module — Automated Test Results

**Date:** 2026-08-25  
**Test file:** `PotAndLeaf-backend/tests/Feature/CustomerModuleTest.php`  
**Framework:** Pest 5 (Laravel API feature tests)  
**Command:** `php artisan test tests/Feature/CustomerModuleTest.php`  
**Production code modified:** No

---

## Summary

| Status | Count |
|--------|-------|
| **Passed** | **15** |
| **Failed** | **0** |
| **Skipped** | **3** |
| **Errors** | **0** |
| **Total** | **18** |

**Assertions:** 74  
**Duration:** ~2.6s

---

## Implementation inspected

| Layer | File | Notes |
|-------|------|-------|
| List UI | `PotAndLeaf-frontend/src/pages/customers/CustomersList.jsx` | Search (300ms debounce), type filter, pagination (`per_page: 25`), modal create/edit, soft delete with confirm |
| API | `CustomerController.php` | `GET/POST /api/customers`, `PUT/DELETE /api/customers/{id}` |
| Validation | `StoreCustomerRequest.php` / `UpdateCustomerRequest.php` | Required: `name`, `type`, `status`; email format; phone regex; unique name per company |
| List/query | `CustomerRepository.php` | Filters: `search`, `type`, `status`, `per_page` (max 100); ordered by name |
| Model | `Customer.php` | Soft deletes; search scope on name, code, phone, GST |

---

## Missing frontend dependencies (not installed)

UI cases **CUSTOMER-004**, **CUSTOMER-013**, **CUSTOMER-016** require:

```bash
cd PotAndLeaf-frontend
npm install -D vitest jsdom @testing-library/react @testing-library/jest-dom @testing-library/user-event
```

Optional for full browser E2E: `@playwright/test`

---

## Results by test case

### Passed (15)

| ID | Test case | Layer | Verification |
|----|-----------|-------|--------------|
| **CUSTOMER-001** | Customer list loads | API | `GET /api/customers` → 200, 2 records, pagination meta |
| **CUSTOMER-002** | Customer search | API | `search=Rose`, by phone, no-match → correct counts |
| **CUSTOMER-003** | Customer filter | API | `type=wholesale` / `dealer` filters correctly |
| **CUSTOMER-005** | Create with valid data | API | `POST /api/customers` → 201, code auto-generated, DB row exists |
| **CUSTOMER-006** | Submit empty form | API | `{}` → 422 on `name`, `type`, `status` |
| **CUSTOMER-007** | Missing required field | API | Missing `name` → 422 |
| **CUSTOMER-008** | Invalid email | API | `email: not-an-email` → 422 |
| **CUSTOMER-009** | Invalid phone | API | `phone: abc` → 422 (regex) |
| **CUSTOMER-010** | Duplicate customer | API | Duplicate `name` in company → 422 unique error |
| **CUSTOMER-011** | Edit customer | API | `PUT /api/customers/{id}` → 200, name/type updated |
| **CUSTOMER-012** | Delete customer | API | `DELETE` → soft delete (`deleted_at` set) |
| **CUSTOMER-014** | API validation error | API | Invalid `type` → 422 with `errors` object |
| **CUSTOMER-015** | API server error | API | Simulated 500 on create (test-only controller binding) |
| **CUSTOMER-017** | Empty state | API | No customers → `data: []`, `meta.total: 0` |
| **CUSTOMER-018** | Pagination | API | 12 records, `per_page=5`, page 1 vs 2 distinct IDs |

---

### Skipped (3)

| ID | Test case | Reason | Install to enable |
|----|-----------|--------|-------------------|
| **CUSTOMER-004** | Open create customer form | UI: `openNew()` opens Modal — no Vitest | vitest + RTL |
| **CUSTOMER-013** | Cancel delete | UI: `useConfirm` cancel — no DELETE call | vitest + RTL or Playwright |
| **CUSTOMER-016** | Loading state | UI: `isLoading` → `<Spinner />` | vitest + RTL |

---

### Failed (0)

No failures.

---

## Failure analysis

None — all executable API tests passed.

---

## Observations (informational)

1. **CUSTOMER-006 (UI):** `CustomersList` does not block submit client-side; empty modal submit hits API and receives 422 (matches API test).
2. **CUSTOMER-010:** Duplicate detection is on **`name`** per company (not phone/email).
3. **CUSTOMER-012:** Delete is **soft delete** — record remains in DB with `deleted_at`.
4. **CUSTOMER-013:** Cancel delete is purely frontend (`confirm()` returns false); no API endpoint to test.
5. **CUSTOMER-015:** 500 response tested via test-only mock, not a live server fault.
6. **CUSTOMER-017 (UI):** Frontend shows “No customers yet” card when `rows.length === 0`; API empty list test confirms backend contract.
7. **CUSTOMER-018 (UI):** Frontend uses `per_page: 25`; API pagination tested with `per_page=5` to fit fixture count.

---

## How to re-run

```bash
cd PotAndLeaf-backend
php artisan test tests/Feature/CustomerModuleTest.php
php artisan test --group=customer
```

---

## Traceability

| Document | Reference |
|----------|-----------|
| `QA/TEST_PLAN.md` | CUST-* manual cases |
| `QA/FEATURE_INVENTORY.md` | Customers module CRUD matrix |
| `QA/TESTING_SETUP.md` | Frontend test stack recommendations |

---

*Generated after automated Customer module test run — no production code modified.*
