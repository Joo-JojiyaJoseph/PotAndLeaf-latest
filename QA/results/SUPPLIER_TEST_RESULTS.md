# Supplier Module — Automated Test Results

**Date:** 2026-08-25  
**Test file:** `PotAndLeaf-backend/tests/Feature/SupplierModuleTest.php`  
**Framework:** Pest 5 (Laravel API feature tests)  
**Command:** `php artisan test tests/Feature/SupplierModuleTest.php`  
**Production code modified:** No

---

## Summary

| Status | Count |
|--------|-------|
| **Passed** | **14** |
| **Failed** | **0** |
| **Skipped** | **1** |
| **Errors** | **0** |
| **Total** | **15** |

**Assertions:** 68  
**Duration:** ~2.7s

---

## Implementation inspected

| Layer | File | Notes |
|-------|------|-------|
| List UI | `PotAndLeaf-frontend/src/pages/suppliers/SuppliersList.jsx` | Search (400ms debounce), pagination (`per_page: 15`); modal create/edit; delete with confirm; loading spinner and empty card |
| API | `SupplierController.php` | `GET/POST /api/suppliers`, `GET/PUT/DELETE /api/suppliers/{id}`, `PATCH .../status`, purchase history |
| Validation | `StoreSupplierRequest.php` / `UpdateSupplierRequest.php` | Required: `name`, `address`, `status`; email format; phone regex; unique name and supplier_code per company |
| List/query | `SupplierRepository.php` | Filters: `search`, `status`, `sort`, `dir`; `per_page` default 15, max 100 |
| Model | `Supplier.php` | Soft deletes; search scope on name, supplier_code, email, phone; encrypted GST/PAN/bank fields |
| Create | `CreateSupplier.php` | Auto-generates `supplier_code` (SUP-00001 pattern) when omitted |

---

## Missing frontend dependencies (not installed)

UI case **SUPPLIER-011** requires:

```bash
cd PotAndLeaf-frontend
npm install -D vitest jsdom @testing-library/react @testing-library/jest-dom @testing-library/user-event
```

Optional for full browser E2E: `@playwright/test`

---

## Results by test case

### Passed (14)

| ID | Test case | Layer | Verification |
|----|-----------|-------|--------------|
| **SUPPLIER-001** | List suppliers | API | `GET /api/suppliers` → 200, 2 records, pagination meta |
| **SUPPLIER-002** | Search supplier | API | `search=Rose`, by phone, no-match → correct counts |
| **SUPPLIER-003** | Create with valid data | API | `POST /api/suppliers` → 201, code auto-generated, DB row exists |
| **SUPPLIER-004** | Submit empty form | API | `{}` → 422 on `name`, `address`, `status` |
| **SUPPLIER-005** | Missing required field | API | Missing `name` → 422 |
| **SUPPLIER-006** | Invalid email | API | `email: not-an-email` → 422 |
| **SUPPLIER-007** | Invalid phone | API | `phone: abc` → 422 (regex) |
| **SUPPLIER-008** | Duplicate supplier | API | Duplicate `name` in company → 422 unique error |
| **SUPPLIER-009** | Edit supplier | API | `PUT /api/suppliers/{id}` → 200, name/address updated |
| **SUPPLIER-010** | Delete supplier | API | `DELETE` → soft delete; message "Supplier moved to trash." |
| **SUPPLIER-012** | API validation error | API | Invalid `status` → 422 with `errors` object |
| **SUPPLIER-013** | API server error | API | Simulated 500 on create (test-only controller binding) |
| **SUPPLIER-014** | Pagination | API | 12 records, `per_page=5`, page 1 vs 2 distinct IDs |
| **SUPPLIER-015** | Empty state | API | No suppliers → `data: []`, `meta.total: 0` |

---

### Skipped (1)

| ID | Test case | Reason | Install to enable |
|----|-----------|--------|-------------------|
| **SUPPLIER-011** | Cancel delete | UI: `useConfirm` cancel — no DELETE call | vitest + RTL or Playwright |

---

### Failed (0)

No failures.

---

## Failure analysis

None — all executable API tests passed.

---

## Observations (informational)

1. **SUPPLIER-004:** Suppliers require **`address`** in addition to `name` and `status` (unlike customers which require `type`).
2. **SUPPLIER-008:** Duplicate detection is on **`name`** per company (`supplier_code` also unique).
3. **SUPPLIER-010:** Delete is **soft delete**; API message is "Supplier moved to trash." (not "Supplier deleted.").
4. **SUPPLIER-011:** Cancel delete is purely frontend (`confirm()` returns false); no API endpoint to test.
5. **SUPPLIER-013:** 500 response tested via test-only mock, not a live server fault.
6. **SUPPLIER-015 (UI):** Frontend shows empty card when `rows.length === 0`; API empty list test confirms backend contract.
7. **SUPPLIER-014 (UI):** Frontend uses `per_page: 15`; API pagination tested with `per_page=5` to fit fixture count.

---

## How to re-run

```bash
cd PotAndLeaf-backend
php artisan test tests/Feature/SupplierModuleTest.php
php artisan test --group=supplier
```

Filter by case ID:

```bash
php artisan test --group=SUPPLIER-008
```

---

## Traceability

| Artifact | Location |
|----------|----------|
| Test plan cases | `QA/TEST_PLAN.md` |
| Feature inventory | `QA/FEATURE_INVENTORY.md` |
| Test implementation | `PotAndLeaf-backend/tests/Feature/SupplierModuleTest.php` |
