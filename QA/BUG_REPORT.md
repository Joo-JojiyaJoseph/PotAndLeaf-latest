# PotAndLeaf ERP — Bug Report

**Report date:** 2026-08-25  
**Prepared by:** Senior QA review (automated test results + source verification)  
**Sources reviewed:** `QA/TEST_PLAN.md`, `QA/FINAL_TEST_REPORT.md`, `QA/results/*`, application source code  
**Production code modified:** No  

---

## Executive summary

| Category | Count |
|----------|-------|
| **Confirmed application bugs** | **2** |
| Critical | 0 |
| High | 1 |
| Medium | 1 |
| Low | 0 |
| **Failed automated tests** | 0 |
| **Items reviewed, not logged as bugs** | 5 (see §Excluded findings) |

The full backend suite (**298 tests**, **282 passed**, **16 skipped**, **0 failed**) is green. All logged defects were **reproduced and documented by passing tests** that assert current (incorrect) behavior, plus static review of the payment UI and service layer.

**Primary risk:** Supplier payment overpayment corrupts creditor balances and payables reporting before any release involving accounts payable.

---

## Confirmed bugs

---

### BUG-001

| Field | Value |
|-------|-------|
| **BUG ID** | BUG-001 |
| **Test Case ID** | PAYMENT-007 |
| **Module** | Supplier Payments (API) |
| **Title** | Supplier payment API accepts amount exceeding outstanding and GRN balance |
| **Severity** | **High** |
| **Priority** | **P1** |
| **Environment** | Backend API — Laravel 13, Pest 5 feature test (`PaymentModuleTest.php`), `RefreshDatabase`, Windows 10 dev runner |

**Precondition**

- Active company with `payments.create`, `purchases.create`, `purchases.confirm` permissions.
- Confirmed purchase (GRN) for supplier: 10 × ₹100 = **₹1,000** `grand_total`.
- Supplier `outstanding` = **₹1,000** after purchase confirm.

**Steps to Reproduce**

1. Confirm a purchase for the supplier (fixture: qty 10, rate 100).
2. `POST /api/supplier-payments` with body:
   - `supplier_id`: supplier UUID  
   - `purchase_id`: confirmed purchase UUID  
   - `amount`: **1500**  
   - `payment_date`: today  
   - `mode`: `cash`
3. Include valid auth token and `X-Company-Id`.
4. `GET /api/supplier-payments/payables?supplier_id={id}`.

**Expected Result**

- HTTP **422** with validation error on `amount` (payment must not exceed supplier outstanding or linked GRN balance).
- Supplier `outstanding` remains **≥ 0** (at most reduced to **0**).
- GRN `paid` ≤ invoice total; GRN `balance` ≥ **0**; status reflects true settlement state.

*Supporting specification:* `QA/FEATURE_INVENTORY.md` — Record supplier payment: *“Payment recorded; payable balance reduced”* (implies bounded reduction, not over-settlement).

**Actual Result**

- HTTP **201 Created** — payment recorded.
- Supplier `outstanding` = **−₹500** (was ₹1,000).
- Payables row: `paid` = **₹1,500**, `balance` = **−₹500**, `status` = **`paid`**.

**Evidence / Error**

- Test: `PotAndLeaf-backend/tests/Feature/PaymentModuleTest.php` — `PAYMENT-007` (assertions pass documenting defect).
- Results: `QA/results/PAYMENT_TEST_RESULTS.md` § PAYMENT-007.
- Request rules omit cap: `StoreSupplierPaymentRequest.php` — only `'amount' => ['required', 'numeric', 'gt:0']`.
- Service uncapped decrement:

```php
// PaymentService::record()
$supplier->outstanding = (float) $supplier->outstanding - (float) $data['amount'];
```

- Payables marks overpaid GRN as paid when balance ≤ 0.005:

```php
// PaymentService::payables()
$balance = round($total - $paid, 2);
$status = $balance <= 0.005 ? 'paid' : ...
```

**Possible Root Cause**

- No business-rule validation in `StoreSupplierPaymentRequest` or `PaymentService::record()` to cap `amount` at min(supplier outstanding, GRN remaining balance when `purchase_id` is set).
- Unlike sales returns / purchase returns (which cap return qty in `CreateSalesReturn` / `CreatePurchaseReturn`), payments have no equivalent guard.

**Comparison (same codebase, correct pattern exists)**

- `CreateSalesReturn` rejects qty above returnable: *“Cannot return more than {remaining}…”* → 422.
- `CreatePurchaseReturn` rejects qty above returnable similarly.
- Payments lack parallel validation despite the same financial control need.

---

### BUG-002

| Field | Value |
|-------|-------|
| **BUG ID** | BUG-002 |
| **Test Case ID** | PAYMENT-007 (UI aspect) |
| **Module** | Supplier Payments (Frontend) |
| **Title** | Payment modal submits overpayment without client-side amount validation |
| **Severity** | **Medium** |
| **Priority** | **P2** |
| **Environment** | React SPA — `PotAndLeaf-frontend/src/pages/payments/PaymentsList.jsx`; reproducible once BUG-001 backend path is hit via UI or API |

**Precondition**

- User with `payments.create`.
- Supplier with payables; modal open (Record payment).
- UI displays supplier outstanding and optional GRN balance in payables dropdown.

**Steps to Reproduce**

1. Navigate to `/payments` → **Record payment**.
2. Select supplier with known outstanding (e.g. ₹1,000).
3. Optionally select a GRN with balance ₹1,000.
4. Enter **Amount** greater than outstanding/balance (e.g. ₹1,500).
5. Click **Record payment**.

**Expected Result**

- Form validation error before submit (amount ≤ supplier outstanding; if GRN selected, amount ≤ GRN balance).
- No API call, or API returns 422 if reached.

**Actual Result**

- Modal calls `POST /supplier-payments` with `amount: Number(form.amount)` with **no comparison** to outstanding or GRN balance.
- Request succeeds when backend accepts overpayment (BUG-001).
- Outstanding label is display-only (`formatCurrency(supplier.outstanding)`); not enforced in `saveM.mutationFn`.

**Evidence / Error**

- Source: `PaymentsList.jsx` — `saveM` mutation posts amount without validation (lines 38–49).
- QA note: `QA/results/PAYMENT_TEST_RESULTS.md` — *“Frontend modal shows outstanding but does not block submit client-side either.”*
- Depends on BUG-001 for end-to-end financial corruption; UI defect is independent missing guard.

**Possible Root Cause**

- `RecordPaymentModal` implements supplier/GRN pickers and prefill from balance but never validates `form.amount` against `supplier.outstanding` or selected payable `balance`.

---

## Excluded findings (not application bugs)

These were reviewed per `QA/FINAL_TEST_REPORT.md` and **intentionally excluded** from this bug report.

| Item | Reason excluded |
|------|-----------------|
| **Missing `PUT /api/sales` (SALE-008 / SALE-023 skipped)** | `QA/FEATURE_INVENTORY.md` lists Sales **Update: ❌** — documented product scope, not a regression. TEST_PLAN `SALE-008` is boundary-value testing, not “remove line via API.” |
| **Missing `PUT /api/supplier-payments` (PAYMENT-010 skipped)** | FEATURE_INVENTORY lists Payments **Update: ❌**, **Delete: ✅ Void** — void-and-re-record is the designed workflow. |
| **Vitest / Playwright not installed (16 skipped UI tests)** | Test infrastructure gap, not application defect. |
| **PCOV / Xdebug unavailable** | CI/tooling limitation, not application defect. |
| **Customer/Product empty-form client submit** | API returns 422 correctly; backend validation is authoritative (`CUSTOMER_TEST_RESULTS.md`, `PRODUCT_TEST_RESULTS.md` observations). |

---

## Modules tested with no defects logged

The following module QA packs completed with **0 failed tests** and **no behavioral findings** meeting bug criteria:

| Module | Results file | Passed | Notes |
|--------|--------------|--------|-------|
| Login | — (in suite) | 12/12 API | Auth behaves as specified |
| Customer | `CUSTOMER_TEST_RESULTS.md` | 15 | Validation & CRUD OK |
| Product | `PRODUCT_TEST_RESULTS.md` | 20 | Calculations & validation OK |
| Supplier | `SUPPLIER_TEST_RESULTS.md` | 14 | CRUD OK |
| Purchase | `PURCHASE_TEST_RESULTS.md` | 20 | Calculator matches API |
| Inventory | `INVENTORY_TEST_RESULTS.md` | 16 | Stock guards OK (incl. oversell 422) |
| Sales | `SALES_TEST_RESULTS.md` | 24 | Calculator matches; oversell blocked on confirm |
| Sales / Purchase Return | `RETURN_TEST_RESULTS.md` | 19 | Over-return correctly rejected |
| Authorization | `AUTHORIZATION_TEST_RESULTS.md` | 14 | RBAC & cross-company isolation OK |

Additional phase tests (transfers, production, rentals, commission, accounting, backorders, etc.) — **298 total tests, 0 failures** — no evidence of application bugs in executed assertions.

---

## Failed test cases

**None.** No automated test failures in the final suite run (`QA/FINAL_TEST_REPORT.md`).

---

## Recommended fix verification (for dev team)

After fix, re-run:

```bash
cd PotAndLeaf-backend
php artisan test tests/Feature/PaymentModuleTest.php --group=PAYMENT-007
```

**Expected after fix:** PAYMENT-007 should be updated to `assertUnprocessable()` and assert supplier outstanding ≥ 0; payables balance ≥ 0.

Manual UI check: attempt ₹1,500 payment against ₹1,000 GRN — modal or API should block.

---

## Traceability

| Artifact | Path |
|----------|------|
| Full test report | `QA/FINAL_TEST_REPORT.md` |
| Payment module results | `QA/results/PAYMENT_TEST_RESULTS.md` |
| Test plan | `QA/TEST_PLAN.md` |
| Feature inventory | `QA/FEATURE_INVENTORY.md` |
| Payment test | `PotAndLeaf-backend/tests/Feature/PaymentModuleTest.php` |
| Payment service | `PotAndLeaf-backend/app/Services/PaymentService.php` |
| Payment UI | `PotAndLeaf-frontend/src/pages/payments/PaymentsList.jsx` |

---

## Sign-off

| Reviewer role | Conclusion |
|---------------|------------|
| QA | **2 confirmed defects** — 1 High (API overpayment / balance corruption), 1 Medium (UI missing guard). No Critical or Low severity application bugs evidenced by current test artifacts. |
| Release recommendation | **Do not treat AP/payments as production-safe** until BUG-001 is resolved and PAYMENT-007 expects rejection. |

*This report documents verified application behavior only. No code was modified during this review.*
