# Product Module — Automated Test Results

**Date:** 2026-08-25  
**Test file:** `PotAndLeaf-backend/tests/Feature/ProductModuleTest.php`  
**Framework:** Pest 5 (Laravel API feature tests)  
**Command:** `php artisan test tests/Feature/ProductModuleTest.php`  
**Production code modified:** No

---

## Summary

| Status | Count |
|--------|-------|
| **Passed** | **20** |
| **Failed** | **0** |
| **Skipped** | **3** |
| **Errors** | **0** |
| **Total** | **23** |

**Assertions:** 103  
**Duration:** ~3.5s

---

## Implementation inspected

| Layer | File | Notes |
|-------|------|-------|
| List UI | `PotAndLeaf-frontend/src/pages/products/ProductsList.jsx` | Search (300ms debounce), filters: status, category, low_only; pagination (`per_page: 24`); create navigates to `/products/new`; delete with confirm; loading spinner and empty card |
| API | `ProductController.php` | `GET/POST /api/products`, `GET /api/products/form-data`, `GET/PUT/DELETE /api/products/{id}`, `PATCH .../status` |
| Validation | `StoreProductRequest.php` / `UpdateProductRequest.php` | Required: `name`, `category_id`, `cost_price`, `status`; SKU unique per company (max 50); prices/qty `numeric`, `min:0`; name max 191; description max 2000 |
| List/query | `ProductController::index` | Filters: `search`, `category_id`, `status`, `low_only`; `per_page` default 20, max 100; ordered by name |
| Model | `Product.php` | Soft deletes; search scope on name, SKU, barcode, HSN |
| Resource | `ProductResource.php` | `cost_price` omitted unless user has `products.view_cost` or super admin |

---

## Missing frontend dependencies (not installed)

UI cases **PRODUCT-005**, **PRODUCT-018**, **PRODUCT-022** require:

```bash
cd PotAndLeaf-frontend
npm install -D vitest jsdom @testing-library/react @testing-library/jest-dom @testing-library/user-event
```

Optional for full browser E2E: `@playwright/test`

---

## Results by test case

### Passed (20)

| ID | Test case | Layer | Verification |
|----|-----------|-------|--------------|
| **PRODUCT-001** | Product list loads | API | `GET /api/products` → 200, 2 records, pagination meta |
| **PRODUCT-002** | Product search | API | `search=Rose`, by barcode, no-match → correct counts |
| **PRODUCT-003** | Product filtering | API | `status=active`, `category_id`, `low_only=1` filter correctly |
| **PRODUCT-004** | Product pagination | API | 12 records, `per_page=5`, page 1 vs 2 distinct IDs |
| **PRODUCT-006** | Create with valid data | API | `POST /api/products` → 201, DB row exists |
| **PRODUCT-007** | Submit empty form | API | `{}` → 422 on `name`, `category_id`, `cost_price`, `status` |
| **PRODUCT-008** | Missing required field | API | Missing `category_id` → 422 |
| **PRODUCT-009** | Invalid product price | API | `cost_price: not-a-price` → 422 |
| **PRODUCT-010** | Negative price | API | `cost_price: -10` → 422 |
| **PRODUCT-011** | Zero price | API | `cost_price: 0` → 201; DB stores 0 (allowed by `min:0`) |
| **PRODUCT-012** | Negative quantity | API | `opening_stock: -5` → 422 |
| **PRODUCT-013** | Invalid SKU | API | SKU length 51 → 422 |
| **PRODUCT-014** | Duplicate SKU | API | Same SKU in company → 422 unique error |
| **PRODUCT-015** | Maximum field length | API | Name 192 chars, description 2001 chars → 422 |
| **PRODUCT-016** | Edit product | API | `PUT /api/products/{id}` → 200, name/cost updated in DB |
| **PRODUCT-017** | Delete product | API | `DELETE` → soft delete (`deleted_at` set) |
| **PRODUCT-019** | Product details | API | `GET /api/products/{id}` → 200 with id, name, sku, status |
| **PRODUCT-020** | API validation error | API | Invalid `status` → 422 with `errors` object |
| **PRODUCT-021** | API server error | API | Simulated 500 on create (test-only controller binding) |
| **PRODUCT-023** | Empty state | API | No products → `data: []`, `meta.total: 0` |

---

### Skipped (3)

| ID | Test case | Reason | Install to enable |
|----|-----------|--------|-------------------|
| **PRODUCT-005** | Open create product form | UI: navigates to `/products/new` — no Vitest | vitest + RTL |
| **PRODUCT-018** | Cancel delete | UI: `useConfirm` cancel — no DELETE call | vitest + RTL or Playwright |
| **PRODUCT-022** | Loading state | UI: `isLoading` → `<Spinner />` | vitest + RTL |

---

### Failed (0)

No failures.

---

## Failure analysis

None — all executable API tests passed.

---

## Observations (informational)

1. **PRODUCT-007 (UI):** Product create/edit pages submit to API; empty submit receives 422 (matches API test).
2. **PRODUCT-011:** Zero `cost_price` is **valid** per `min:0` validation — not treated as an error.
3. **PRODUCT-014:** Duplicate detection is on **`sku`** per company (name also unique per company).
4. **PRODUCT-017:** Delete is **soft delete** — record remains in DB with `deleted_at`.
5. **PRODUCT-018:** Cancel delete is purely frontend (`confirm()` returns false); no API endpoint to test.
6. **PRODUCT-019:** API response omits `cost_price` for users without `products.view_cost`; cost verified via DB in test.
7. **PRODUCT-021:** 500 response tested via test-only mock, not a live server fault.
8. **PRODUCT-023 (UI):** Frontend shows empty card when `rows.length === 0`; API empty list test confirms backend contract.
9. **PRODUCT-004 (UI):** Frontend uses `per_page: 24`; API pagination tested with `per_page=5` to fit fixture count.

---

## How to re-run

```bash
cd PotAndLeaf-backend
php artisan test tests/Feature/ProductModuleTest.php
php artisan test --group=product
```

Filter by case ID:

```bash
php artisan test --group=PRODUCT-014
```

---

## Traceability

| Artifact | Location |
|----------|----------|
| Test plan cases | `QA/TEST_PLAN.md` (PROD-* section) |
| Feature inventory | `QA/FEATURE_INVENTORY.md` |
| Test implementation | `PotAndLeaf-backend/tests/Feature/ProductModuleTest.php` |
| Related existing tests | `PotAndLeaf-backend/tests/Feature/ProductStockTest.php` |
