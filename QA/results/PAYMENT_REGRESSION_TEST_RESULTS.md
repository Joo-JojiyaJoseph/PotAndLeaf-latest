# Payment Module — Regression Test Results

**Date:** 2026-08-25  
**Scope:** Post BUG-001 (API overpayment guard) and BUG-002 (frontend amount validation)  
**Production code modified during regression:** No  
**Regressions found:** None

---

## Executive summary

| Layer | Test file | Passed | Failed | Skipped | Assertions | Duration |
|-------|-----------|--------|--------|---------|------------|----------|
| **Backend — payments** | `PaymentModuleTest.php` | **15** | **0** | 1 | 125 | ~10.3s |
| **Frontend — validation** | `paymentValidation.test.js` | **9** | **0** | 0 | 9 | ~1.4s |
| **Backend — purchase (regression)** | `PurchaseModuleTest.php` | **20** | **0** | 1 | 97 | ~2.9s |
| **Backend — sales (regression)** | `SaleModuleTest.php` | **24** | **0** | 3 | 114 | ~3.7s |

**Overall regression verdict:** ✅ **PASS** — all runnable tests green; no production changes required.

---

## Commands executed

```bash
# Payment module (API)
cd PotAndLeaf-backend
php artisan test tests/Feature/PaymentModuleTest.php

# Frontend payment validation
cd PotAndLeaf-frontend
npm test

# Adjacent-module regression
cd PotAndLeaf-backend
php artisan test tests/Feature/PurchaseModuleTest.php
php artisan test tests/Feature/SaleModuleTest.php
```

---

## Regression checklist

| # | Requirement | Result | Evidence |
|---|-------------|--------|----------|
| 1 | Valid supplier payment still works | ✅ Pass | **PAYMENT-003** — ₹600 against GRN → 201; outstanding −600; payable `partial` |
| 2 | Exact outstanding payment works | ✅ Pass | **PAYMENT-014** — ₹1,000 payment on ₹1,000 outstanding → 201; outstanding = 0 |
| 3 | Partial payment works | ✅ Pass | **PAYMENT-003** (₹600 / ₹1,000); **PAYMENT-015** (₹400 → outstanding ₹600) |
| 4 | Overpayment rejected by API | ✅ Pass | **PAYMENT-007** — ₹1,500 vs ₹1,000 outstanding → 422; no payment row created |
| 5 | Overpayment blocked by frontend | ✅ Pass | `paymentValidation.test.js` — rejects amount > outstanding and amount > GRN balance; `PaymentsList.jsx` calls `validatePaymentForm()` before `saveM.mutate()` |
| 6 | GRN balance never becomes negative | ✅ Pass | **PAYMENT-007** — balance stays ₹1,000, paid ₹0 after rejected overpayment; **PAYMENT-016** — first GRN balance ₹1,000 unchanged when ₹1,500 rejected (supplier outstanding ₹2,000) |
| 7 | Supplier outstanding never becomes negative | ✅ Pass | **PAYMENT-007** / **PAYMENT-016** — outstanding unchanged on 422; **PAYMENT-014** — settles to exactly 0 (not negative) |
| 8 | Payment status is correct | ✅ Pass | **PAYMENT-003** — `partial` after ₹600 paid; **PAYMENT-007** — `unpaid` after rejected overpayment; status derived in `PaymentService::payables()` (`paid` / `partial` / `unpaid`) |
| 9 | Existing purchase functionality unaffected | ✅ Pass | **PurchaseModuleTest** — 20 passed, 0 failed (1 UI skip) |
| 10 | Existing sales / payment functionality unaffected | ✅ Pass | **SaleModuleTest** — 24 passed, 0 failed (3 UI skips); **SALE-018** payment method storage still passes |

---

## Backend payment tests — full results

### Passed (15)

| ID | Test case | Key assertions |
|----|-----------|----------------|
| **PAYMENT-001** | Payment list loads | 2 payments returned; pagination structure |
| **PAYMENT-002** | Search by supplier | Filter returns 1 matching row |
| **PAYMENT-003** | Create valid payment | 201; GRN linked; outstanding −600; payable `partial`, paid ₹600, balance ₹400 |
| **PAYMENT-004** | Empty form rejected | 422 on required fields |
| **PAYMENT-005** | Zero amount rejected | 422 on `amount` |
| **PAYMENT-006** | Negative amount rejected | 422 on `amount` |
| **PAYMENT-007** | Overpayment rejected | 422; outstanding ₹1,000 unchanged; 0 payments; GRN `unpaid`, balance ₹1,000 |
| **PAYMENT-008** | Valid payment modes | cash, bank, upi, cheque each → 201 |
| **PAYMENT-009** | Invalid payment mode | card, credit → 422 |
| **PAYMENT-011** | Void restores outstanding | DELETE → outstanding 600 → 1,000 |
| **PAYMENT-012** | Validation error shape | 422 with `errors` object |
| **PAYMENT-013** | Server error handling | Simulated 500 on create |
| **PAYMENT-014** | Payment = outstanding | 201; outstanding = 0 |
| **PAYMENT-015** | Payment < outstanding | 201; outstanding = 600 |
| **PAYMENT-016** | Over GRN balance rejected | ₹1,500 vs GRN ₹1,000 (supplier ₹2,000) → 422; no side effects |

### Skipped (1)

| ID | Reason |
|----|--------|
| **PAYMENT-010** | No edit endpoint — payments are record-only; void and re-record |

---

## Frontend validation tests — full results

**File:** `PotAndLeaf-frontend/src/lib/paymentValidation.test.js`  
**Integration:** `PaymentsList.jsx` → `handleSubmit()` → `validatePaymentForm()` → early return on failure (no API call)

| Test | Scenario | Result |
|------|----------|--------|
| accepts valid payment below outstanding | Valid payment | ✅ |
| accepts payment equal to supplier outstanding | Exact outstanding | ✅ |
| rejects payment above supplier outstanding | API overpayment mirror | ✅ |
| rejects payment above GRN balance | GRN cap | ✅ |
| rejects zero amount | Zero guard | ✅ |
| rejects negative amount | Negative guard | ✅ |
| accepts valid payment with GRN allocation | GRN-linked valid pay | ✅ |
| rejects payment above GRN via payables lookup | GRN selection path | ✅ |
| requires a supplier | Supplier required | ✅ |

---

## Scenario detail — overpayment guards (BUG-001 + BUG-002)

### API — supplier outstanding cap (PAYMENT-007)

| Stage | Supplier outstanding | Payments | GRN balance |
|-------|---------------------|----------|-------------|
| After purchase confirm (₹1,000) | ₹1,000 | 0 | ₹1,000 |
| POST ₹1,500 (rejected) | **₹1,000** (unchanged) | **0** | **₹1,000** |

Response: **422** with `amount` validation error.

### API — GRN balance cap (PAYMENT-016)

| Context | Value |
|---------|-------|
| Two GRNs @ ₹1,000 each | Supplier outstanding ₹2,000 |
| Payment ₹1,500 against first GRN | **422** — exceeds GRN balance ₹1,000 |
| After rejection | Outstanding ₹2,000; first GRN paid ₹0, balance ₹1,000 |

### Frontend — client-side mirror

| Input | Expected | Verified by |
|-------|----------|-------------|
| Amount 1,500, outstanding 1,000 | Blocked; error on Amount field | Unit test + `handleSubmit` gate |
| Amount 600, GRN balance 500, outstanding 2,000 | Blocked; GRN balance message | Unit test |
| Amount 500, outstanding 1,000 | Allowed through validation | Unit test |

Backend remains authoritative if client checks are bypassed.

---

## Payment status logic (verified)

From `PaymentService::payables()`:

```
balance = grand_total − sum(payments on GRN)
status  = paid    if balance ≤ 0.005
        = unpaid  if paid ≤ 0.005
        = partial otherwise
```

| Scenario | Test | Status observed |
|----------|------|-----------------|
| ₹600 paid on ₹1,000 GRN | PAYMENT-003 | `partial` |
| Rejected overpayment, no payment recorded | PAYMENT-007 | `unpaid` |
| Full settlement (₹1,000 / ₹1,000) | PAYMENT-014 | Outstanding 0 → payable would compute `paid` (balance 0) |

---

## Adjacent-module regression

### Purchase module (`PurchaseModuleTest.php`)

- **20 passed**, 0 failed, 1 skipped (**PURCHASE-001** — UI-only)
- Covers draft create/edit, confirm, stock posting, totals, validation, cancel
- No purchase test failures after payment validation changes

### Sales module (`SaleModuleTest.php`)

- **24 passed**, 0 failed, 3 skipped (UI-only: SALE-004, SALE-008, SALE-023)
- **SALE-018** — sale `payment_mode` (cash/card/upi/credit) still stored correctly
- Supplier payments are a separate module; sales customer payment modes unaffected

---

## Files in scope (inspected, not modified)

| Layer | File | Role |
|-------|------|------|
| API validation | `StoreSupplierPaymentRequest.php` | Outstanding + GRN caps |
| Service | `PaymentService.php` | Record, void, payables status |
| UI | `PaymentsList.jsx` | Record modal + client validation |
| FE utility | `paymentValidation.js` | Shared validation rules |
| BE tests | `PaymentModuleTest.php` | 16 cases incl. PAYMENT-014–016 |
| FE tests | `paymentValidation.test.js` | 9 unit cases |

---

## Conclusion

Regression testing confirms that BUG-001 and BUG-002 fixes work as intended:

- Valid, partial, and full-settlement payments continue to succeed.
- Overpayments are rejected at both API and frontend layers.
- Supplier outstanding and GRN balances cannot go negative through rejected or accepted payment flows covered by tests.
- Payable status (`paid` / `partial` / `unpaid`) remains consistent.
- Purchase and sales modules show no test regressions.

**No production code changes were required during this regression run.**

---

## How to re-run

```bash
# Full payment regression suite
cd PotAndLeaf-backend && php artisan test tests/Feature/PaymentModuleTest.php
cd ../PotAndLeaf-frontend && npm test
cd ../PotAndLeaf-backend && php artisan test tests/Feature/PurchaseModuleTest.php tests/Feature/SaleModuleTest.php
```

Filter a single payment case:

```bash
php artisan test tests/Feature/PaymentModuleTest.php --group=PAYMENT-007
```
