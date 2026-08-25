# PotAndLeaf ERP — QA Test Plan

**Date:** 2026-08-25
**Sources:** `QA/PROJECT_ANALYSIS.md`, `QA/FEATURE_INVENTORY.md`
**Scope:** Manual QA test plan — no production code changes.

---

## 1. Introduction

This test plan covers the PotAndLeaf ERP React SPA and Laravel API. Test cases are organized by module prefix (e.g. `LOGIN-001`, `PROD-015`, `SALE-008`).

### 1.1 Test environments

| Environment | Frontend | API | Notes |
|-------------|----------|-----|-------|
| Local | http://localhost:5173 | Vite proxy → VITE_API_PROXY | Primary QA environment |
| Staging | Built SPA | potandleafbackend-testing-internal.webfolks.in/api | Regression before release |

### 1.2 Test personas

| Persona | Role | Purpose |
|---------|------|---------|
| Super Admin | `is_super_admin=true` | Companies, cross-branch, HO approvals |
| Branch Admin | Full branch permissions | CRUD and workflows |
| Sales Staff | `sales.*`, limited | POS and sales flows |
| Read Only | View permissions only | Authorization negative tests |
| Restricted | Missing module permissions | 403 and UI guard tests |

### 1.3 Test types

| Test Type | Description |
|-----------|-------------|
| Positive | Happy path; valid data; expected success |
| Negative | Invalid business operations; wrong state |
| Required field validation | Submit with missing mandatory fields |
| Boundary values | Min/max lengths, zero qty, pagination limits |
| Invalid input | Malformed types, injection, bad UUIDs |
| API errors | 401, 403, 422, 404, 5xx handling |
| Authentication | Token, session, login, logout |
| Authorization | RBAC, permission guards, super-admin |
| CRUD operations | Create, read, update, delete lifecycle |
| Search | Text search and no-results |
| Filtering | Status, type, date, company filters |
| Pagination | Page navigation and API caps |
| Loading states | Spinners during async fetch |
| Empty states | Zero records UI |
| Duplicate submission | Double-click save protection |
| Browser refresh | Session rehydration F5 |
| Network failure | Offline mode and retry |

### 1.4 Execution priority

1. **P0** — Run every release (Auth, Products, Purchases, Sales, Inventory)
2. **P1** — Run every sprint (Returns, Transfers, Payments, Suppliers, Customers)
3. **P2** — Run monthly (Admin, PO, Production, Settings)
4. **P3** — Run as needed (Reports, Commission, Backups, Activity)

### 1.5 Test case summary

**Total test cases:** 658

| Prefix | Module | Count |
|--------|--------|-------|
| ACT | Activity Monitoring | 9 |
| ADV | Advance Orders | 22 |
| AUTH | Authentication | 6 |
| BACK | Backorders | 22 |
| BATCH | Batches | 14 |
| BKP | Backups | 16 |
| BULK | Bulk Splits | 23 |
| COMM | Commission | 14 |
| COMP | Companies | 19 |
| CUST | Customers | 21 |
| DAMAGE | Damage Entry | 17 |
| DASH | Dashboard | 9 |
| INV | Inventory | 16 |
| LABEL | Barcode Labels | 9 |
| LOC | Locations | 16 |
| LOGIN | Authentication | 10 |
| LOY | Loyalty | 16 |
| MAST | Master Data | 17 |
| PAY | Supplier Payments | 17 |
| PO | Purchase Orders | 25 |
| PRET | Purchase Returns | 22 |
| PROD | Products | 23 |
| PRODM | Production | 21 |
| PROF | Authentication | 10 |
| PUR | Purchases | 26 |
| RCPT | Customer Receipts | 17 |
| RENT | Rentals | 28 |
| ROLE | Roles | 21 |
| RPT | Reports | 13 |
| SALE | Sales | 27 |
| SET | Settings | 10 |
| SRET | Sales Returns | 22 |
| STOCK | Stock Verification | 25 |
| SUPP | Suppliers | 22 |
| USER | Users | 19 |
| XCUT | Cross-Cutting | 5 |
| XFER | Transfers | 29 |

---

## 2. Test Cases

### Module: Authentication

#### LOGIN-001: Login — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | LOGIN-001 |
| **Module** | Authentication |
| **Feature** | Login |
| **Priority** | P0 |
| **Test Type** | Positive |
| **Precondition** | App running; valid branch user seeded |
| **Test Data** | email: branch.admin@test.com, password: password |
| **Steps** | 1. Open /login<br>2. Enter valid credentials<br>3. Click Sign in |
| **Expected Result** | Redirect to dashboard; pl_token stored; default company selected; sidebar visible |

---

#### LOGIN-002: Login — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | LOGIN-002 |
| **Module** | Authentication |
| **Feature** | Login |
| **Priority** | P0 |
| **Test Type** | Negative |
| **Precondition** | App running |
| **Test Data** | email: valid@test.com, password: WrongPass123! |
| **Steps** | 1. Open /login<br>2. Enter wrong password<br>3. Submit |
| **Expected Result** | Validation error displayed; remain on /login; no token in localStorage |

---

#### LOGIN-003: Login — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | LOGIN-003 |
| **Module** | Authentication |
| **Feature** | Login |
| **Priority** | P0 |
| **Test Type** | Required field validation |
| **Precondition** | On login page |
| **Test Data** | Empty email and password |
| **Steps** | 1. Leave fields blank<br>2. Click Sign in |
| **Expected Result** | Required field errors shown for email and password |

---

#### LOGIN-004: Login — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | LOGIN-004 |
| **Module** | Authentication |
| **Feature** | Login |
| **Priority** | P0 |
| **Test Type** | Invalid input |
| **Precondition** | On login page |
| **Test Data** | email: not-an-email, password: ab |
| **Steps** | 1. Enter malformed email<br>2. Submit |
| **Expected Result** | Email format validation error; login rejected |

---

#### LOGIN-005: Login — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | LOGIN-005 |
| **Module** | Authentication |
| **Feature** | Login |
| **Priority** | P0 |
| **Test Type** | Authentication |
| **Precondition** | Inactive user exists in DB |
| **Test Data** | Deactivated user credentials |
| **Steps** | 1. Login as inactive user |
| **Expected Result** | Error: account deactivated; HTTP 422; no session |

---

#### LOGIN-006: Login — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | LOGIN-006 |
| **Module** | Authentication |
| **Feature** | Login |
| **Priority** | P0 |
| **Test Type** | Authentication |
| **Precondition** | User with zero company assignments |
| **Test Data** | Orphan user credentials |
| **Steps** | 1. Attempt login |
| **Expected Result** | Error: not assigned to any company |

---

#### LOGIN-007: Login — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | LOGIN-007 |
| **Module** | Authentication |
| **Feature** | Login |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | On login page |
| **Test Data** | password: 5000 character string |
| **Steps** | 1. Enter very long password<br>2. Submit |
| **Expected Result** | Request handled without server crash; appropriate validation or auth failure |

---

#### LOGIN-008: Login — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | LOGIN-008 |
| **Module** | Authentication |
| **Feature** | Login |
| **Priority** | P1 |
| **Test Type** | Duplicate submission |
| **Precondition** | Valid credentials; network throttled |
| **Test Data** | Valid login |
| **Steps** | 1. Double-click Sign in button rapidly |
| **Expected Result** | Single login session; no duplicate tokens or duplicate error spam |

---

#### LOGIN-009: Login — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | LOGIN-009 |
| **Module** | Authentication |
| **Feature** | Login |
| **Priority** | P1 |
| **Test Type** | Network failure |
| **Precondition** | Browser offline mode enabled |
| **Test Data** | Valid credentials |
| **Steps** | 1. Go offline<br>2. Submit login |
| **Expected Result** | Network error message; no redirect; retry succeeds when online |

---

#### LOGIN-010: Login — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | LOGIN-010 |
| **Module** | Authentication |
| **Feature** | Login |
| **Priority** | P0 |
| **Test Type** | API errors |
| **Precondition** | API server stopped |
| **Test Data** | Valid credentials |
| **Steps** | 1. Stop backend<br>2. Submit login |
| **Expected Result** | 5xx/network error surfaced in UI; app remains usable after recovery |

---

#### AUTH-001: Session rehydration — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | AUTH-001 |
| **Module** | Authentication |
| **Feature** | Session rehydration |
| **Priority** | P0 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on /products |
| **Test Data** | Valid pl_token in localStorage |
| **Steps** | 1. Press F5 to refresh |
| **Expected Result** | GET /me succeeds; user stays logged in; products page reloads |

---

#### AUTH-002: Logout — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | AUTH-002 |
| **Module** | Authentication |
| **Feature** | Logout |
| **Priority** | P0 |
| **Test Type** | Positive |
| **Precondition** | Active session |
| **Test Data** | Logged-in user |
| **Steps** | 1. Click Logout in header |
| **Expected Result** | POST /logout; token cleared; redirect to /login |

---

#### AUTH-003: Session expiry — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | AUTH-003 |
| **Module** | Authentication |
| **Feature** | Session expiry |
| **Priority** | P0 |
| **Test Type** | Authentication |
| **Precondition** | Logged in; token invalidated |
| **Test Data** | Expired or revoked token |
| **Steps** | 1. Trigger API call with invalid token |
| **Expected Result** | 401 response; redirect to /login; pl_token removed |

---

#### AUTH-004: Protected routes — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | AUTH-004 |
| **Module** | Authentication |
| **Feature** | Protected routes |
| **Priority** | P0 |
| **Test Type** | Authentication |
| **Precondition** | Not authenticated |
| **Test Data** | None |
| **Steps** | 1. Navigate directly to /sales/new |
| **Expected Result** | Redirect to /login before page content loads |

---

#### AUTH-005: API auth — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | AUTH-005 |
| **Module** | Authentication |
| **Feature** | API auth |
| **Priority** | P0 |
| **Test Type** | API errors |
| **Precondition** | Postman or curl |
| **Test Data** | No Authorization header |
| **Steps** | 1. GET /api/dashboard without token |
| **Expected Result** | 401 JSON: Unauthenticated |

---

#### AUTH-006: Company header required — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | AUTH-006 |
| **Module** | Authentication |
| **Feature** | Company header required |
| **Priority** | P0 |
| **Test Type** | API errors |
| **Precondition** | Valid token; no X-Company-Id |
| **Test Data** | Bearer token only |
| **Steps** | 1. GET /api/products without X-Company-Id |
| **Expected Result** | 422 JSON: Select a company |

---

### Module: Cross-Cutting

#### XCUT-001: Company switcher — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | XCUT-001 |
| **Module** | Cross-Cutting |
| **Feature** | Company switcher |
| **Priority** | P0 |
| **Test Type** | Positive |
| **Precondition** | User belongs to Company A and B |
| **Test Data** | Two company assignments |
| **Steps** | 1. Switch from A to B in header<br>2. Open /products |
| **Expected Result** | Product list reflects Company B data; X-Company-Id header updated |

---

#### XCUT-002: Company switcher — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | XCUT-002 |
| **Module** | Cross-Cutting |
| **Feature** | Company switcher |
| **Priority** | P0 |
| **Test Type** | Authorization |
| **Precondition** | Super admin logged in |
| **Test Data** | company_id=all query |
| **Steps** | 1. Use company filter All on products list |
| **Expected Result** | Cross-company read works per super-admin rules |

---

#### XCUT-003: Permission route guard — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | XCUT-003 |
| **Module** | Cross-Cutting |
| **Feature** | Permission route guard |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User lacks companies access; not super admin |
| **Test Data** | Branch staff role |
| **Steps** | 1. Navigate directly to /companies |
| **Expected Result** | HO restricted message; no company data loaded |

---

#### XCUT-004: Sidebar visibility — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | XCUT-004 |
| **Module** | Cross-Cutting |
| **Feature** | Sidebar visibility |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User with sales.view only |
| **Test Data** | Limited permissions |
| **Steps** | 1. Login<br>2. Inspect sidebar |
| **Expected Result** | Only permitted modules visible; others hidden |

---

#### XCUT-005: Super admin bypass — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | XCUT-005 |
| **Module** | Cross-Cutting |
| **Feature** | Super admin bypass |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | Super admin with no explicit role in branch |
| **Test Data** | is_super_admin=true |
| **Steps** | 1. Select branch company<br>2. Access any module |
| **Expected Result** | can() returns true; all UI actions visible |

---

### Module: Authentication

#### PROF-001: Profile — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | PROF-001 |
| **Module** | Authentication |
| **Feature** | Profile |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Authentication records |
| **Steps** | 1. Login<br>2. Navigate to /profile<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### PROF-002: Profile — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | PROF-002 |
| **Module** | Authentication |
| **Feature** | Profile |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /profile via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### PROF-003: Profile — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | PROF-003 |
| **Module** | Authentication |
| **Feature** | Profile |
| **Priority** | P2 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### PROF-004: Profile — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | PROF-004 |
| **Module** | Authentication |
| **Feature** | Profile |
| **Priority** | P2 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### PROF-005: Profile — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | PROF-005 |
| **Module** | Authentication |
| **Feature** | Profile |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | Authenticated with update rights |
| **Test Data** | Updated field values |
| **Steps** | 1. Open /profile<br>2. Modify fields<br>3. Save |
| **Expected Result** | Changes persisted successfully |

---

#### PROF-006: Profile — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | PROF-006 |
| **Module** | Authentication |
| **Feature** | Profile |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### PROF-007: Profile — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | PROF-007 |
| **Module** | Authentication |
| **Feature** | Profile |
| **Priority** | P2 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /profile with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### PROF-008: Profile — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | PROF-008 |
| **Module** | Authentication |
| **Feature** | Profile |
| **Priority** | P2 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /profile with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### PROF-009: Profile — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | PROF-009 |
| **Module** | Authentication |
| **Feature** | Profile |
| **Priority** | P2 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /profile<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### PROF-010: Profile — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | PROF-010 |
| **Module** | Authentication |
| **Feature** | Profile |
| **Priority** | P2 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /profile |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

### Module: Dashboard

#### DASH-001: Dashboard KPIs — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | DASH-001 |
| **Module** | Dashboard |
| **Feature** | Dashboard KPIs |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Dashboard records |
| **Steps** | 1. Login<br>2. Navigate to /<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### DASH-002: Dashboard KPIs — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | DASH-002 |
| **Module** | Dashboard |
| **Feature** | Dashboard KPIs |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open / via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### DASH-003: Dashboard KPIs — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | DASH-003 |
| **Module** | Dashboard |
| **Feature** | Dashboard KPIs |
| **Priority** | P1 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### DASH-004: Dashboard KPIs — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | DASH-004 |
| **Module** | Dashboard |
| **Feature** | Dashboard KPIs |
| **Priority** | P1 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### DASH-005: Dashboard KPIs — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | DASH-005 |
| **Module** | Dashboard |
| **Feature** | Dashboard KPIs |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### DASH-006: Dashboard KPIs — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | DASH-006 |
| **Module** | Dashboard |
| **Feature** | Dashboard KPIs |
| **Priority** | P1 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to / with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### DASH-007: Dashboard KPIs — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | DASH-007 |
| **Module** | Dashboard |
| **Feature** | Dashboard KPIs |
| **Priority** | P1 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open / with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### DASH-008: Dashboard KPIs — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | DASH-008 |
| **Module** | Dashboard |
| **Feature** | Dashboard KPIs |
| **Priority** | P1 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### DASH-009: Dashboard KPIs — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | DASH-009 |
| **Module** | Dashboard |
| **Feature** | Dashboard KPIs |
| **Priority** | P1 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on / |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

### Module: Companies

#### COMP-001: Company management — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-001 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Companies records |
| **Steps** | 1. Login<br>2. Navigate to /companies<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### COMP-002: Company management — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-002 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /companies via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### COMP-003: Company management — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-003 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### COMP-004: Company management — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-004 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (name) |
| **Steps** | 1. Open create form/modal on /companies<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### COMP-005: Company management — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-005 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | Existing record; update permission |
| **Test Data** | Modified field values |
| **Steps** | 1. Edit record on /companies<br>2. Save changes |
| **Expected Result** | PUT succeeds; UI shows updated values |

---

#### COMP-006: Company management — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-006 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | Deletable record exists |
| **Test Data** | Draft or unreferenced record |
| **Steps** | 1. Delete record<br>2. Confirm dialog |
| **Expected Result** | Record removed; list refreshed |

---

#### COMP-007: Company management — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-007 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: name |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: name |

---

#### COMP-008: Company management — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-008 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### COMP-009: Company management — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-009 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### COMP-010: Company management — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-010 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### COMP-011: Company management — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-011 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### COMP-012: Company management — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-012 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /companies<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### COMP-013: Company management — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-013 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### COMP-014: Company management — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-014 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /companies with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### COMP-015: Company management — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-015 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /companies with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### COMP-016: Company management — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-016 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /companies<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### COMP-017: Company management — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-017 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /companies |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### COMP-018: Company management — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-018 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | Branch user (not super admin) |
| **Test Data** | Standard branch credentials |
| **Steps** | 1. Attempt access /companies |
| **Expected Result** | HO restricted message; sidebar item hidden |

---

#### COMP-019: Company management — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | COMP-019 |
| **Module** | Companies |
| **Feature** | Company management |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | Super admin logged in |
| **Test Data** | Valid company form data |
| **Steps** | 1. Create company on /companies |
| **Expected Result** | Company created and listed |

---

### Module: Suppliers

#### SUPP-001: Suppliers — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-001 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Suppliers records |
| **Steps** | 1. Login<br>2. Navigate to /suppliers<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### SUPP-002: Suppliers — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-002 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /suppliers via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### SUPP-003: Suppliers — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-003 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### SUPP-004: Suppliers — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-004 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### SUPP-005: Suppliers — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-005 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (name) |
| **Steps** | 1. Open create form/modal on /suppliers<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### SUPP-006: Suppliers — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-006 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | CRUD operations |
| **Precondition** | Existing record; update permission |
| **Test Data** | Modified field values |
| **Steps** | 1. Edit record on /suppliers<br>2. Save changes |
| **Expected Result** | PUT succeeds; UI shows updated values |

---

#### SUPP-007: Suppliers — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-007 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | CRUD operations |
| **Precondition** | Deletable record exists |
| **Test Data** | Draft or unreferenced record |
| **Steps** | 1. Delete record<br>2. Confirm dialog |
| **Expected Result** | Record removed; list refreshed |

---

#### SUPP-008: Suppliers — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-008 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: name |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: name |

---

#### SUPP-009: Suppliers — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-009 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### SUPP-010: Suppliers — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-010 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### SUPP-011: Suppliers — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-011 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### SUPP-012: Suppliers — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-012 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### SUPP-013: Suppliers — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-013 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /suppliers<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### SUPP-014: Suppliers — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-014 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### SUPP-015: Suppliers — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-015 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /suppliers |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### SUPP-016: Suppliers — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-016 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Filtering |
| **Precondition** | Sortable list (suppliers/roles) |
| **Test Data** | sort=name, dir=asc |
| **Steps** | 1. Change sort if UI available; else API with sort params |
| **Expected Result** | Records ordered correctly |

---

#### SUPP-017: Suppliers — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-017 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /suppliers<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### SUPP-018: Suppliers — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-018 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### SUPP-019: Suppliers — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-019 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /suppliers with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### SUPP-020: Suppliers — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-020 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /suppliers with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### SUPP-021: Suppliers — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-021 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /suppliers<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### SUPP-022: Suppliers — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | SUPP-022 |
| **Module** | Suppliers |
| **Feature** | Suppliers |
| **Priority** | P1 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /suppliers |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

### Module: Customers

#### CUST-001: Customers — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-001 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Customers records |
| **Steps** | 1. Login<br>2. Navigate to /customers<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### CUST-002: Customers — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-002 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /customers via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### CUST-003: Customers — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-003 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### CUST-004: Customers — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-004 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### CUST-005: Customers — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-005 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (name) |
| **Steps** | 1. Open create form/modal on /customers<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### CUST-006: Customers — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-006 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | CRUD operations |
| **Precondition** | Existing record; update permission |
| **Test Data** | Modified field values |
| **Steps** | 1. Edit record on /customers<br>2. Save changes |
| **Expected Result** | PUT succeeds; UI shows updated values |

---

#### CUST-007: Customers — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-007 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | CRUD operations |
| **Precondition** | Deletable record exists |
| **Test Data** | Draft or unreferenced record |
| **Steps** | 1. Delete record<br>2. Confirm dialog |
| **Expected Result** | Record removed; list refreshed |

---

#### CUST-008: Customers — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-008 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: name |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: name |

---

#### CUST-009: Customers — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-009 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### CUST-010: Customers — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-010 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### CUST-011: Customers — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-011 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### CUST-012: Customers — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-012 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### CUST-013: Customers — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-013 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /customers<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### CUST-014: Customers — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-014 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### CUST-015: Customers — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-015 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /customers |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### CUST-016: Customers — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-016 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /customers<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### CUST-017: Customers — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-017 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### CUST-018: Customers — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-018 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /customers with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### CUST-019: Customers — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-019 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /customers with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### CUST-020: Customers — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-020 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /customers<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### CUST-021: Customers — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | CUST-021 |
| **Module** | Customers |
| **Feature** | Customers |
| **Priority** | P1 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /customers |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

### Module: Products

#### PROD-001: Products — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-001 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Products records |
| **Steps** | 1. Login<br>2. Navigate to /products<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### PROD-002: Products — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-002 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /products via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### PROD-003: Products — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-003 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### PROD-004: Products — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-004 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### PROD-005: Products — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-005 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (name, category_id, cost_price, status) |
| **Steps** | 1. Open create form/modal on /products<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### PROD-006: Products — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-006 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | CRUD operations |
| **Precondition** | Existing record; update permission |
| **Test Data** | Modified field values |
| **Steps** | 1. Edit record on /products<br>2. Save changes |
| **Expected Result** | PUT succeeds; UI shows updated values |

---

#### PROD-007: Products — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-007 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | CRUD operations |
| **Precondition** | Deletable record exists |
| **Test Data** | Draft or unreferenced record |
| **Steps** | 1. Delete record<br>2. Confirm dialog |
| **Expected Result** | Record removed; list refreshed |

---

#### PROD-008: Products — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-008 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: name, category_id, cost_price, status |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: name, category_id, cost_price, status |

---

#### PROD-009: Products — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-009 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### PROD-010: Products — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-010 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### PROD-011: Products — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-011 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### PROD-012: Products — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-012 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### PROD-013: Products — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-013 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /products<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### PROD-014: Products — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-014 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### PROD-015: Products — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-015 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /products |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### PROD-016: Products — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-016 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /products<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### PROD-017: Products — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-017 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### PROD-018: Products — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-018 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /products with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### PROD-019: Products — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-019 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /products with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### PROD-020: Products — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-020 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /products<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### PROD-021: Products — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-021 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /products |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### PROD-022: Products — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-022 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Positive |
| **Precondition** | products.create permission |
| **Test Data** | opening_stock=5 |
| **Steps** | 1. Create product with opening_stock=5<br>2. Check inventory |
| **Expected Result** | current_stock=5 exactly once (no double-count) |

---

#### PROD-023: Products — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | PROD-023 |
| **Module** | Products |
| **Feature** | Products |
| **Priority** | P0 |
| **Test Type** | Invalid input |
| **Precondition** | Create product form |
| **Test Data** | Duplicate product name in same company |
| **Steps** | 1. Create product with existing name |
| **Expected Result** | Unique name validation error |

---

### Module: Master Data

#### MAST-001: Categories Brands Units — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | MAST-001 |
| **Module** | Master Data |
| **Feature** | Categories Brands Units |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Master Data records |
| **Steps** | 1. Login<br>2. Navigate to /masters<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### MAST-002: Categories Brands Units — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | MAST-002 |
| **Module** | Master Data |
| **Feature** | Categories Brands Units |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /masters via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### MAST-003: Categories Brands Units — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | MAST-003 |
| **Module** | Master Data |
| **Feature** | Categories Brands Units |
| **Priority** | P2 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### MAST-004: Categories Brands Units — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | MAST-004 |
| **Module** | Master Data |
| **Feature** | Categories Brands Units |
| **Priority** | P2 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### MAST-005: Categories Brands Units — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | MAST-005 |
| **Module** | Master Data |
| **Feature** | Categories Brands Units |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (name) |
| **Steps** | 1. Open create form/modal on /masters<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### MAST-006: Categories Brands Units — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | MAST-006 |
| **Module** | Master Data |
| **Feature** | Categories Brands Units |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | Existing record; update permission |
| **Test Data** | Modified field values |
| **Steps** | 1. Edit record on /masters<br>2. Save changes |
| **Expected Result** | PUT succeeds; UI shows updated values |

---

#### MAST-007: Categories Brands Units — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | MAST-007 |
| **Module** | Master Data |
| **Feature** | Categories Brands Units |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | Deletable record exists |
| **Test Data** | Draft or unreferenced record |
| **Steps** | 1. Delete record<br>2. Confirm dialog |
| **Expected Result** | Record removed; list refreshed |

---

#### MAST-008: Categories Brands Units — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | MAST-008 |
| **Module** | Master Data |
| **Feature** | Categories Brands Units |
| **Priority** | P2 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: name |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: name |

---

#### MAST-009: Categories Brands Units — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | MAST-009 |
| **Module** | Master Data |
| **Feature** | Categories Brands Units |
| **Priority** | P2 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### MAST-010: Categories Brands Units — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | MAST-010 |
| **Module** | Master Data |
| **Feature** | Categories Brands Units |
| **Priority** | P2 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### MAST-011: Categories Brands Units — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | MAST-011 |
| **Module** | Master Data |
| **Feature** | Categories Brands Units |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### MAST-012: Categories Brands Units — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | MAST-012 |
| **Module** | Master Data |
| **Feature** | Categories Brands Units |
| **Priority** | P2 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### MAST-013: Categories Brands Units — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | MAST-013 |
| **Module** | Master Data |
| **Feature** | Categories Brands Units |
| **Priority** | P2 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /masters |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### MAST-014: Categories Brands Units — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | MAST-014 |
| **Module** | Master Data |
| **Feature** | Categories Brands Units |
| **Priority** | P2 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /masters with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### MAST-015: Categories Brands Units — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | MAST-015 |
| **Module** | Master Data |
| **Feature** | Categories Brands Units |
| **Priority** | P2 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /masters with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### MAST-016: Categories Brands Units — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | MAST-016 |
| **Module** | Master Data |
| **Feature** | Categories Brands Units |
| **Priority** | P2 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /masters<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### MAST-017: Categories Brands Units — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | MAST-017 |
| **Module** | Master Data |
| **Feature** | Categories Brands Units |
| **Priority** | P2 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /masters |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

### Module: Purchases

#### PUR-001: Purchases GRN — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-001 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Purchases records |
| **Steps** | 1. Login<br>2. Navigate to /purchases<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### PUR-002: Purchases GRN — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-002 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /purchases via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### PUR-003: Purchases GRN — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-003 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### PUR-004: Purchases GRN — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-004 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### PUR-005: Purchases GRN — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-005 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (supplier_id, purchase_date, items) |
| **Steps** | 1. Open create form/modal on /purchases<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### PUR-006: Purchases GRN — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-006 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | CRUD operations |
| **Precondition** | Existing record; update permission |
| **Test Data** | Modified field values |
| **Steps** | 1. Edit record on /purchases<br>2. Save changes |
| **Expected Result** | PUT succeeds; UI shows updated values |

---

#### PUR-007: Purchases GRN — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-007 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | CRUD operations |
| **Precondition** | Deletable record exists |
| **Test Data** | Draft or unreferenced record |
| **Steps** | 1. Delete record<br>2. Confirm dialog |
| **Expected Result** | Record removed; list refreshed |

---

#### PUR-008: Purchases GRN — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-008 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: supplier_id, purchase_date, items |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: supplier_id, purchase_date, items |

---

#### PUR-009: Purchases GRN — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-009 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### PUR-010: Purchases GRN — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-010 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### PUR-011: Purchases GRN — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-011 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### PUR-012: Purchases GRN — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-012 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### PUR-013: Purchases GRN — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-013 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /purchases<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### PUR-014: Purchases GRN — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-014 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### PUR-015: Purchases GRN — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-015 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /purchases |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### PUR-016: Purchases GRN — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-016 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /purchases<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### PUR-017: Purchases GRN — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-017 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### PUR-018: Purchases GRN — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-018 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /purchases with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### PUR-019: Purchases GRN — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-019 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /purchases with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### PUR-020: Purchases GRN — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-020 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /purchases<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### PUR-021: Purchases GRN — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-021 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /purchases |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### PUR-022: Purchases GRN — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-022 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has confirm permission |
| **Test Data** | Valid confirm context |
| **Steps** | 1. Open record detail<br>2. Execute confirm<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### PUR-023: Purchases GRN — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-023 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Authorization |
| **Precondition** | User lacks confirm permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt confirm action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### PUR-024: Purchases GRN — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-024 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt confirm on invalid status |
| **Expected Result** | Business rule error; no state change |

---

#### PUR-025: Purchases GRN — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-025 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | Positive |
| **Precondition** | Supplier and product exist |
| **Test Data** | Purchase qty=10 |
| **Steps** | 1. Create GRN<br>2. Confirm<br>3. Check inventory |
| **Expected Result** | Stock increased by 10; ledger IN entry |

---

#### PUR-026: Purchases GRN — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | PUR-026 |
| **Module** | Purchases |
| **Feature** | Purchases GRN |
| **Priority** | P0 |
| **Test Type** | CRUD operations |
| **Precondition** | Draft purchase exists |
| **Test Data** | Updated line qty |
| **Steps** | 1. Edit draft on /purchases/:id/edit<br>2. Save |
| **Expected Result** | Draft updated; totals recalculated |

---

### Module: Purchase Orders

#### PO-001: Purchase Orders — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-001 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Purchase Orders records |
| **Steps** | 1. Login<br>2. Navigate to /purchase-orders<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### PO-002: Purchase Orders — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-002 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /purchase-orders via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### PO-003: Purchase Orders — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-003 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### PO-004: Purchase Orders — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-004 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### PO-005: Purchase Orders — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-005 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (supplier_id, order_date, items) |
| **Steps** | 1. Open create form/modal on /purchase-orders<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### PO-006: Purchase Orders — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-006 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: supplier_id, order_date, items |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: supplier_id, order_date, items |

---

#### PO-007: Purchase Orders — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-007 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### PO-008: Purchase Orders — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-008 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### PO-009: Purchase Orders — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-009 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### PO-010: Purchase Orders — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-010 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### PO-011: Purchase Orders — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-011 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /purchase-orders<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### PO-012: Purchase Orders — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-012 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### PO-013: Purchase Orders — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-013 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /purchase-orders |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### PO-014: Purchase Orders — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-014 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /purchase-orders<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### PO-015: Purchase Orders — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-015 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### PO-016: Purchase Orders — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-016 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /purchase-orders with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### PO-017: Purchase Orders — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-017 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /purchase-orders with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### PO-018: Purchase Orders — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-018 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /purchase-orders<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### PO-019: Purchase Orders — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-019 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /purchase-orders |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### PO-020: Purchase Orders — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-020 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has send permission |
| **Test Data** | Valid send context |
| **Steps** | 1. Open record detail<br>2. Execute send<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### PO-021: Purchase Orders — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-021 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User lacks send permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt send action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### PO-022: Purchase Orders — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-022 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt send on invalid status |
| **Expected Result** | Business rule error; no state change |

---

#### PO-023: Purchase Orders — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-023 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has convert permission |
| **Test Data** | Valid convert context |
| **Steps** | 1. Open record detail<br>2. Execute convert<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### PO-024: Purchase Orders — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-024 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User lacks convert permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt convert action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### PO-025: Purchase Orders — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | PO-025 |
| **Module** | Purchase Orders |
| **Feature** | Purchase Orders |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt convert on invalid status |
| **Expected Result** | Business rule error; no state change |

---

### Module: Purchase Returns

#### PRET-001: Purchase Returns — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-001 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Purchase Returns records |
| **Steps** | 1. Login<br>2. Navigate to /purchase-returns<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### PRET-002: Purchase Returns — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-002 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /purchase-returns via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### PRET-003: Purchase Returns — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-003 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### PRET-004: Purchase Returns — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-004 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### PRET-005: Purchase Returns — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-005 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (items) |
| **Steps** | 1. Open create form/modal on /purchase-returns<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### PRET-006: Purchase Returns — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-006 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: items |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: items |

---

#### PRET-007: Purchase Returns — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-007 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### PRET-008: Purchase Returns — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-008 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### PRET-009: Purchase Returns — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-009 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### PRET-010: Purchase Returns — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-010 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### PRET-011: Purchase Returns — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-011 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /purchase-returns<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### PRET-012: Purchase Returns — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-012 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### PRET-013: Purchase Returns — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-013 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /purchase-returns |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### PRET-014: Purchase Returns — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-014 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /purchase-returns<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### PRET-015: Purchase Returns — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-015 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### PRET-016: Purchase Returns — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-016 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /purchase-returns with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### PRET-017: Purchase Returns — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-017 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /purchase-returns with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### PRET-018: Purchase Returns — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-018 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /purchase-returns<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### PRET-019: Purchase Returns — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-019 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /purchase-returns |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### PRET-020: Purchase Returns — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-020 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has confirm permission |
| **Test Data** | Valid confirm context |
| **Steps** | 1. Open record detail<br>2. Execute confirm<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### PRET-021: Purchase Returns — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-021 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User lacks confirm permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt confirm action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### PRET-022: Purchase Returns — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | PRET-022 |
| **Module** | Purchase Returns |
| **Feature** | Purchase Returns |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt confirm on invalid status |
| **Expected Result** | Business rule error; no state change |

---

### Module: Inventory

#### INV-001: Inventory stock and ledger — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | INV-001 |
| **Module** | Inventory |
| **Feature** | Inventory stock and ledger |
| **Priority** | P0 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Inventory records |
| **Steps** | 1. Login<br>2. Navigate to /inventory<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### INV-002: Inventory stock and ledger — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | INV-002 |
| **Module** | Inventory |
| **Feature** | Inventory stock and ledger |
| **Priority** | P0 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /inventory via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### INV-003: Inventory stock and ledger — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | INV-003 |
| **Module** | Inventory |
| **Feature** | Inventory stock and ledger |
| **Priority** | P0 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### INV-004: Inventory stock and ledger — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | INV-004 |
| **Module** | Inventory |
| **Feature** | Inventory stock and ledger |
| **Priority** | P0 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### INV-005: Inventory stock and ledger — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | INV-005 |
| **Module** | Inventory |
| **Feature** | Inventory stock and ledger |
| **Priority** | P0 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### INV-006: Inventory stock and ledger — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | INV-006 |
| **Module** | Inventory |
| **Feature** | Inventory stock and ledger |
| **Priority** | P0 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /inventory<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### INV-007: Inventory stock and ledger — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | INV-007 |
| **Module** | Inventory |
| **Feature** | Inventory stock and ledger |
| **Priority** | P0 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### INV-008: Inventory stock and ledger — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | INV-008 |
| **Module** | Inventory |
| **Feature** | Inventory stock and ledger |
| **Priority** | P0 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /inventory |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### INV-009: Inventory stock and ledger — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | INV-009 |
| **Module** | Inventory |
| **Feature** | Inventory stock and ledger |
| **Priority** | P0 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /inventory<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### INV-010: Inventory stock and ledger — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | INV-010 |
| **Module** | Inventory |
| **Feature** | Inventory stock and ledger |
| **Priority** | P0 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### INV-011: Inventory stock and ledger — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | INV-011 |
| **Module** | Inventory |
| **Feature** | Inventory stock and ledger |
| **Priority** | P0 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /inventory with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### INV-012: Inventory stock and ledger — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | INV-012 |
| **Module** | Inventory |
| **Feature** | Inventory stock and ledger |
| **Priority** | P0 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /inventory with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### INV-013: Inventory stock and ledger — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | INV-013 |
| **Module** | Inventory |
| **Feature** | Inventory stock and ledger |
| **Priority** | P0 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /inventory<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### INV-014: Inventory stock and ledger — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | INV-014 |
| **Module** | Inventory |
| **Feature** | Inventory stock and ledger |
| **Priority** | P0 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /inventory |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### INV-015: Inventory stock and ledger — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | INV-015 |
| **Module** | Inventory |
| **Feature** | Inventory stock and ledger |
| **Priority** | P0 |
| **Test Type** | Positive |
| **Precondition** | Products with stock movements |
| **Test Data** | product_id filter |
| **Steps** | 1. Open ledger tab<br>2. Filter by product and date |
| **Expected Result** | Ledger entries match purchase/sale confirmations |

---

#### INV-016: Inventory stock and ledger — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | INV-016 |
| **Module** | Inventory |
| **Feature** | Inventory stock and ledger |
| **Priority** | P0 |
| **Test Type** | CRUD operations |
| **Precondition** | inventory.view permission |
| **Test Data** | Export filters |
| **Steps** | 1. Export ledger CSV |
| **Expected Result** | CSV downloads with filtered entries |

---

### Module: Batches

#### BATCH-001: Batches and barcodes — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | BATCH-001 |
| **Module** | Batches |
| **Feature** | Batches and barcodes |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Batches records |
| **Steps** | 1. Login<br>2. Navigate to /inventory/batches<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### BATCH-002: Batches and barcodes — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | BATCH-002 |
| **Module** | Batches |
| **Feature** | Batches and barcodes |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /inventory/batches via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### BATCH-003: Batches and barcodes — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | BATCH-003 |
| **Module** | Batches |
| **Feature** | Batches and barcodes |
| **Priority** | P2 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### BATCH-004: Batches and barcodes — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | BATCH-004 |
| **Module** | Batches |
| **Feature** | Batches and barcodes |
| **Priority** | P2 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### BATCH-005: Batches and barcodes — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | BATCH-005 |
| **Module** | Batches |
| **Feature** | Batches and barcodes |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### BATCH-006: Batches and barcodes — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | BATCH-006 |
| **Module** | Batches |
| **Feature** | Batches and barcodes |
| **Priority** | P2 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /inventory/batches<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### BATCH-007: Batches and barcodes — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | BATCH-007 |
| **Module** | Batches |
| **Feature** | Batches and barcodes |
| **Priority** | P2 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### BATCH-008: Batches and barcodes — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | BATCH-008 |
| **Module** | Batches |
| **Feature** | Batches and barcodes |
| **Priority** | P2 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /inventory/batches with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### BATCH-009: Batches and barcodes — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | BATCH-009 |
| **Module** | Batches |
| **Feature** | Batches and barcodes |
| **Priority** | P2 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /inventory/batches with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### BATCH-010: Batches and barcodes — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | BATCH-010 |
| **Module** | Batches |
| **Feature** | Batches and barcodes |
| **Priority** | P2 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /inventory/batches<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### BATCH-011: Batches and barcodes — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | BATCH-011 |
| **Module** | Batches |
| **Feature** | Batches and barcodes |
| **Priority** | P2 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /inventory/batches |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### BATCH-012: Batches and barcodes — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | BATCH-012 |
| **Module** | Batches |
| **Feature** | Batches and barcodes |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has generate_opening permission |
| **Test Data** | Valid generate_opening context |
| **Steps** | 1. Open record detail<br>2. Execute generate_opening<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### BATCH-013: Batches and barcodes — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | BATCH-013 |
| **Module** | Batches |
| **Feature** | Batches and barcodes |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User lacks generate_opening permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt generate_opening action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### BATCH-014: Batches and barcodes — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | BATCH-014 |
| **Module** | Batches |
| **Feature** | Batches and barcodes |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt generate_opening on invalid status |
| **Expected Result** | Business rule error; no state change |

---

### Module: Barcode Labels

#### LABEL-001: Barcode label printing — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | LABEL-001 |
| **Module** | Barcode Labels |
| **Feature** | Barcode label printing |
| **Priority** | P3 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Barcode Labels records |
| **Steps** | 1. Login<br>2. Navigate to /products/labels<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### LABEL-002: Barcode label printing — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | LABEL-002 |
| **Module** | Barcode Labels |
| **Feature** | Barcode label printing |
| **Priority** | P3 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /products/labels via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### LABEL-003: Barcode label printing — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | LABEL-003 |
| **Module** | Barcode Labels |
| **Feature** | Barcode label printing |
| **Priority** | P3 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### LABEL-004: Barcode label printing — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | LABEL-004 |
| **Module** | Barcode Labels |
| **Feature** | Barcode label printing |
| **Priority** | P3 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### LABEL-005: Barcode label printing — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | LABEL-005 |
| **Module** | Barcode Labels |
| **Feature** | Barcode label printing |
| **Priority** | P3 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### LABEL-006: Barcode label printing — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | LABEL-006 |
| **Module** | Barcode Labels |
| **Feature** | Barcode label printing |
| **Priority** | P3 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /products/labels with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### LABEL-007: Barcode label printing — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | LABEL-007 |
| **Module** | Barcode Labels |
| **Feature** | Barcode label printing |
| **Priority** | P3 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /products/labels with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### LABEL-008: Barcode label printing — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | LABEL-008 |
| **Module** | Barcode Labels |
| **Feature** | Barcode label printing |
| **Priority** | P3 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /products/labels<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### LABEL-009: Barcode label printing — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | LABEL-009 |
| **Module** | Barcode Labels |
| **Feature** | Barcode label printing |
| **Priority** | P3 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /products/labels |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

### Module: Damage Entry

#### DAMAGE-001: Damage entries — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | DAMAGE-001 |
| **Module** | Damage Entry |
| **Feature** | Damage entries |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Damage Entry records |
| **Steps** | 1. Login<br>2. Navigate to /damage-entries<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### DAMAGE-002: Damage entries — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | DAMAGE-002 |
| **Module** | Damage Entry |
| **Feature** | Damage entries |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /damage-entries via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### DAMAGE-003: Damage entries — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | DAMAGE-003 |
| **Module** | Damage Entry |
| **Feature** | Damage entries |
| **Priority** | P1 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### DAMAGE-004: Damage entries — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | DAMAGE-004 |
| **Module** | Damage Entry |
| **Feature** | Damage entries |
| **Priority** | P1 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### DAMAGE-005: Damage entries — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | DAMAGE-005 |
| **Module** | Damage Entry |
| **Feature** | Damage entries |
| **Priority** | P1 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (product_id, qty, entry_date) |
| **Steps** | 1. Open create form/modal on /damage-entries<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### DAMAGE-006: Damage entries — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | DAMAGE-006 |
| **Module** | Damage Entry |
| **Feature** | Damage entries |
| **Priority** | P1 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: product_id, qty, entry_date |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: product_id, qty, entry_date |

---

#### DAMAGE-007: Damage entries — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | DAMAGE-007 |
| **Module** | Damage Entry |
| **Feature** | Damage entries |
| **Priority** | P1 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### DAMAGE-008: Damage entries — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | DAMAGE-008 |
| **Module** | Damage Entry |
| **Feature** | Damage entries |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### DAMAGE-009: Damage entries — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | DAMAGE-009 |
| **Module** | Damage Entry |
| **Feature** | Damage entries |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### DAMAGE-010: Damage entries — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | DAMAGE-010 |
| **Module** | Damage Entry |
| **Feature** | Damage entries |
| **Priority** | P1 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### DAMAGE-011: Damage entries — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | DAMAGE-011 |
| **Module** | Damage Entry |
| **Feature** | Damage entries |
| **Priority** | P1 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /damage-entries |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### DAMAGE-012: Damage entries — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | DAMAGE-012 |
| **Module** | Damage Entry |
| **Feature** | Damage entries |
| **Priority** | P1 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /damage-entries<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### DAMAGE-013: Damage entries — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | DAMAGE-013 |
| **Module** | Damage Entry |
| **Feature** | Damage entries |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### DAMAGE-014: Damage entries — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | DAMAGE-014 |
| **Module** | Damage Entry |
| **Feature** | Damage entries |
| **Priority** | P1 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /damage-entries with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### DAMAGE-015: Damage entries — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | DAMAGE-015 |
| **Module** | Damage Entry |
| **Feature** | Damage entries |
| **Priority** | P1 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /damage-entries with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### DAMAGE-016: Damage entries — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | DAMAGE-016 |
| **Module** | Damage Entry |
| **Feature** | Damage entries |
| **Priority** | P1 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /damage-entries<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### DAMAGE-017: Damage entries — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | DAMAGE-017 |
| **Module** | Damage Entry |
| **Feature** | Damage entries |
| **Priority** | P1 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /damage-entries |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

### Module: Bulk Splits

#### BULK-001: Bulk splits — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-001 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Bulk Splits records |
| **Steps** | 1. Login<br>2. Navigate to /bulk-splits<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### BULK-002: Bulk splits — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-002 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /bulk-splits via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### BULK-003: Bulk splits — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-003 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### BULK-004: Bulk splits — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-004 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### BULK-005: Bulk splits — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-005 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (source_product_id, source_qty, split_date, items) |
| **Steps** | 1. Open create form/modal on /bulk-splits<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### BULK-006: Bulk splits — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-006 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: source_product_id, source_qty, split_date, items |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: source_product_id, source_qty, split_date, items |

---

#### BULK-007: Bulk splits — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-007 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### BULK-008: Bulk splits — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-008 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### BULK-009: Bulk splits — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-009 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### BULK-010: Bulk splits — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-010 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### BULK-011: Bulk splits — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-011 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /bulk-splits<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### BULK-012: Bulk splits — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-012 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### BULK-013: Bulk splits — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-013 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /bulk-splits |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### BULK-014: Bulk splits — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-014 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /bulk-splits<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### BULK-015: Bulk splits — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-015 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### BULK-016: Bulk splits — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-016 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /bulk-splits with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### BULK-017: Bulk splits — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-017 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /bulk-splits with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### BULK-018: Bulk splits — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-018 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /bulk-splits<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### BULK-019: Bulk splits — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-019 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /bulk-splits |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### BULK-020: Bulk splits — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-020 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has confirm permission |
| **Test Data** | Valid confirm context |
| **Steps** | 1. Open record detail<br>2. Execute confirm<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### BULK-021: Bulk splits — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-021 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User lacks confirm permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt confirm action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### BULK-022: Bulk splits — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-022 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt confirm on invalid status |
| **Expected Result** | Business rule error; no state change |

---

#### BULK-023: Bulk splits — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | BULK-023 |
| **Module** | Bulk Splits |
| **Feature** | Bulk splits |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Source product available qty=5 |
| **Test Data** | source_qty=10 |
| **Steps** | 1. Create bulk split exceeding available |
| **Expected Result** | Validation error; split not created |

---

### Module: Transfers

#### XFER-001: Stock transfers — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-001 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Transfers records |
| **Steps** | 1. Login<br>2. Navigate to /transfers<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### XFER-002: Stock transfers — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-002 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /transfers via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### XFER-003: Stock transfers — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-003 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### XFER-004: Stock transfers — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-004 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### XFER-005: Stock transfers — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-005 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (transfer_date, items) |
| **Steps** | 1. Open create form/modal on /transfers<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### XFER-006: Stock transfers — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-006 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: transfer_date, items |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: transfer_date, items |

---

#### XFER-007: Stock transfers — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-007 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### XFER-008: Stock transfers — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-008 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### XFER-009: Stock transfers — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-009 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### XFER-010: Stock transfers — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-010 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### XFER-011: Stock transfers — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-011 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /transfers<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### XFER-012: Stock transfers — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-012 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### XFER-013: Stock transfers — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-013 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /transfers |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### XFER-014: Stock transfers — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-014 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /transfers<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### XFER-015: Stock transfers — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-015 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### XFER-016: Stock transfers — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-016 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /transfers with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### XFER-017: Stock transfers — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-017 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /transfers with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### XFER-018: Stock transfers — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-018 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /transfers<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### XFER-019: Stock transfers — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-019 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /transfers |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### XFER-020: Stock transfers — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-020 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has approve permission |
| **Test Data** | Valid approve context |
| **Steps** | 1. Open record detail<br>2. Execute approve<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### XFER-021: Stock transfers — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-021 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User lacks approve permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt approve action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### XFER-022: Stock transfers — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-022 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt approve on invalid status |
| **Expected Result** | Business rule error; no state change |

---

#### XFER-023: Stock transfers — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-023 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has dispatch permission |
| **Test Data** | Valid dispatch context |
| **Steps** | 1. Open record detail<br>2. Execute dispatch<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### XFER-024: Stock transfers — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-024 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User lacks dispatch permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt dispatch action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### XFER-025: Stock transfers — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-025 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt dispatch on invalid status |
| **Expected Result** | Business rule error; no state change |

---

#### XFER-026: Stock transfers — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-026 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has receive permission |
| **Test Data** | Valid receive context |
| **Steps** | 1. Open record detail<br>2. Execute receive<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### XFER-027: Stock transfers — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-027 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User lacks receive permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt receive action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### XFER-028: Stock transfers — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-028 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt receive on invalid status |
| **Expected Result** | Business rule error; no state change |

---

#### XFER-029: Stock transfers — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | XFER-029 |
| **Module** | Transfers |
| **Feature** | Stock transfers |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Location stock=3 |
| **Test Data** | transfer qty=5 |
| **Steps** | 1. Create intra-company transfer qty>available |
| **Expected Result** | Validation error on qty |

---

### Module: Stock Verification

#### STOCK-001: Stock count — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-001 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Stock Verification records |
| **Steps** | 1. Login<br>2. Navigate to /stock-verifications<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### STOCK-002: Stock count — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-002 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /stock-verifications via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### STOCK-003: Stock count — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-003 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### STOCK-004: Stock count — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-004 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### STOCK-005: Stock count — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-005 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (location_id, count_date, items) |
| **Steps** | 1. Open create form/modal on /stock-verifications<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### STOCK-006: Stock count — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-006 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: location_id, count_date, items |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: location_id, count_date, items |

---

#### STOCK-007: Stock count — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-007 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### STOCK-008: Stock count — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-008 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### STOCK-009: Stock count — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-009 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### STOCK-010: Stock count — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-010 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### STOCK-011: Stock count — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-011 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /stock-verifications<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### STOCK-012: Stock count — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-012 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### STOCK-013: Stock count — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-013 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /stock-verifications |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### STOCK-014: Stock count — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-014 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /stock-verifications<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### STOCK-015: Stock count — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-015 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### STOCK-016: Stock count — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-016 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /stock-verifications with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### STOCK-017: Stock count — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-017 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /stock-verifications with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### STOCK-018: Stock count — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-018 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /stock-verifications<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### STOCK-019: Stock count — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-019 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /stock-verifications |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### STOCK-020: Stock count — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-020 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has submit permission |
| **Test Data** | Valid submit context |
| **Steps** | 1. Open record detail<br>2. Execute submit<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### STOCK-021: Stock count — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-021 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User lacks submit permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt submit action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### STOCK-022: Stock count — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-022 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt submit on invalid status |
| **Expected Result** | Business rule error; no state change |

---

#### STOCK-023: Stock count — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-023 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has approve permission |
| **Test Data** | Valid approve context |
| **Steps** | 1. Open record detail<br>2. Execute approve<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### STOCK-024: Stock count — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-024 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User lacks approve permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt approve action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### STOCK-025: Stock count — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | STOCK-025 |
| **Module** | Stock Verification |
| **Feature** | Stock count |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt approve on invalid status |
| **Expected Result** | Business rule error; no state change |

---

### Module: Production

#### PRODM-001: Production BOM and orders — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-001 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Production records |
| **Steps** | 1. Login<br>2. Navigate to /production<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### PRODM-002: Production BOM and orders — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-002 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /production via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### PRODM-003: Production BOM and orders — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-003 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### PRODM-004: Production BOM and orders — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-004 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### PRODM-005: Production BOM and orders — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-005 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (name) |
| **Steps** | 1. Open create form/modal on /production<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### PRODM-006: Production BOM and orders — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-006 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | Authenticated with update rights |
| **Test Data** | Updated field values |
| **Steps** | 1. Open /production<br>2. Modify fields<br>3. Save |
| **Expected Result** | Changes persisted successfully |

---

#### PRODM-007: Production BOM and orders — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-007 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: name |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: name |

---

#### PRODM-008: Production BOM and orders — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-008 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### PRODM-009: Production BOM and orders — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-009 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### PRODM-010: Production BOM and orders — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-010 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### PRODM-011: Production BOM and orders — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-011 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### PRODM-012: Production BOM and orders — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-012 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /production |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### PRODM-013: Production BOM and orders — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-013 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /production<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### PRODM-014: Production BOM and orders — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-014 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### PRODM-015: Production BOM and orders — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-015 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /production with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### PRODM-016: Production BOM and orders — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-016 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /production with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### PRODM-017: Production BOM and orders — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-017 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /production<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### PRODM-018: Production BOM and orders — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-018 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /production |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### PRODM-019: Production BOM and orders — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-019 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has complete permission |
| **Test Data** | Valid complete context |
| **Steps** | 1. Open record detail<br>2. Execute complete<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### PRODM-020: Production BOM and orders — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-020 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User lacks complete permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt complete action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### PRODM-021: Production BOM and orders — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | PRODM-021 |
| **Module** | Production |
| **Feature** | Production BOM and orders |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt complete on invalid status |
| **Expected Result** | Business rule error; no state change |

---

### Module: Sales

#### SALE-001: Sales POS — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-001 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Sales records |
| **Steps** | 1. Login<br>2. Navigate to /sales<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### SALE-002: Sales POS — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-002 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /sales via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### SALE-003: Sales POS — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-003 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### SALE-004: Sales POS — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-004 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### SALE-005: Sales POS — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-005 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (sale_date, payment_mode, items) |
| **Steps** | 1. Open create form/modal on /sales<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### SALE-006: Sales POS — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-006 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: sale_date, payment_mode, items |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: sale_date, payment_mode, items |

---

#### SALE-007: Sales POS — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-007 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### SALE-008: Sales POS — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-008 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### SALE-009: Sales POS — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-009 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### SALE-010: Sales POS — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-010 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### SALE-011: Sales POS — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-011 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /sales<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### SALE-012: Sales POS — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-012 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### SALE-013: Sales POS — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-013 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /sales |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### SALE-014: Sales POS — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-014 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /sales<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### SALE-015: Sales POS — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-015 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### SALE-016: Sales POS — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-016 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /sales with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### SALE-017: Sales POS — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-017 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /sales with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### SALE-018: Sales POS — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-018 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /sales<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### SALE-019: Sales POS — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-019 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /sales |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### SALE-020: Sales POS — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-020 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has confirm permission |
| **Test Data** | Valid confirm context |
| **Steps** | 1. Open record detail<br>2. Execute confirm<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### SALE-021: Sales POS — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-021 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Authorization |
| **Precondition** | User lacks confirm permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt confirm action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### SALE-022: Sales POS — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-022 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt confirm on invalid status |
| **Expected Result** | Business rule error; no state change |

---

#### SALE-023: Sales POS — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-023 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has cancel_request permission |
| **Test Data** | Valid cancel_request context |
| **Steps** | 1. Open record detail<br>2. Execute cancel_request<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### SALE-024: Sales POS — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-024 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Authorization |
| **Precondition** | User lacks cancel_request permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt cancel_request action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### SALE-025: Sales POS — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-025 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt cancel_request on invalid status |
| **Expected Result** | Business rule error; no state change |

---

#### SALE-026: Sales POS — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-026 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Positive |
| **Precondition** | Product with stock=10 |
| **Test Data** | Sale qty=3 |
| **Steps** | 1. Create sale<br>2. Confirm<br>3. Check inventory |
| **Expected Result** | Stock reduced by 3; ledger OUT entry; receipt if cash |

---

#### SALE-027: Sales POS — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | SALE-027 |
| **Module** | Sales |
| **Feature** | Sales POS |
| **Priority** | P0 |
| **Test Type** | Negative |
| **Precondition** | Product stock=2 |
| **Test Data** | Sale qty=5 |
| **Steps** | 1. Create sale qty exceeding stock<br>2. Confirm |
| **Expected Result** | Confirmation blocked or warning; stock not negative |

---

### Module: Sales Returns

#### SRET-001: Sales returns — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-001 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Sales Returns records |
| **Steps** | 1. Login<br>2. Navigate to /sales-returns<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### SRET-002: Sales returns — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-002 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /sales-returns via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### SRET-003: Sales returns — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-003 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### SRET-004: Sales returns — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-004 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### SRET-005: Sales returns — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-005 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (items) |
| **Steps** | 1. Open create form/modal on /sales-returns<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### SRET-006: Sales returns — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-006 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: items |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: items |

---

#### SRET-007: Sales returns — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-007 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### SRET-008: Sales returns — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-008 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### SRET-009: Sales returns — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-009 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### SRET-010: Sales returns — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-010 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### SRET-011: Sales returns — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-011 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /sales-returns<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### SRET-012: Sales returns — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-012 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### SRET-013: Sales returns — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-013 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /sales-returns |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### SRET-014: Sales returns — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-014 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /sales-returns<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### SRET-015: Sales returns — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-015 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### SRET-016: Sales returns — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-016 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /sales-returns with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### SRET-017: Sales returns — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-017 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /sales-returns with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### SRET-018: Sales returns — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-018 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /sales-returns<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### SRET-019: Sales returns — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-019 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /sales-returns |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### SRET-020: Sales returns — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-020 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has confirm permission |
| **Test Data** | Valid confirm context |
| **Steps** | 1. Open record detail<br>2. Execute confirm<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### SRET-021: Sales returns — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-021 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User lacks confirm permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt confirm action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### SRET-022: Sales returns — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | SRET-022 |
| **Module** | Sales Returns |
| **Feature** | Sales returns |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt confirm on invalid status |
| **Expected Result** | Business rule error; no state change |

---

### Module: Advance Orders

#### ADV-001: Advance orders — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-001 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Advance Orders records |
| **Steps** | 1. Login<br>2. Navigate to /advance-orders<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### ADV-002: Advance orders — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-002 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /advance-orders via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### ADV-003: Advance orders — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-003 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### ADV-004: Advance orders — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-004 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### ADV-005: Advance orders — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-005 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (customer_id, order_date, advance_amount, items) |
| **Steps** | 1. Open create form/modal on /advance-orders<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### ADV-006: Advance orders — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-006 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: customer_id, order_date, advance_amount, items |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: customer_id, order_date, advance_amount, items |

---

#### ADV-007: Advance orders — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-007 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### ADV-008: Advance orders — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-008 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### ADV-009: Advance orders — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-009 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### ADV-010: Advance orders — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-010 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### ADV-011: Advance orders — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-011 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /advance-orders<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### ADV-012: Advance orders — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-012 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### ADV-013: Advance orders — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-013 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /advance-orders |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### ADV-014: Advance orders — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-014 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /advance-orders<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### ADV-015: Advance orders — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-015 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### ADV-016: Advance orders — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-016 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /advance-orders with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### ADV-017: Advance orders — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-017 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /advance-orders with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### ADV-018: Advance orders — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-018 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /advance-orders<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### ADV-019: Advance orders — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-019 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /advance-orders |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### ADV-020: Advance orders — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-020 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has fulfill permission |
| **Test Data** | Valid fulfill context |
| **Steps** | 1. Open record detail<br>2. Execute fulfill<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### ADV-021: Advance orders — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-021 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User lacks fulfill permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt fulfill action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### ADV-022: Advance orders — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | ADV-022 |
| **Module** | Advance Orders |
| **Feature** | Advance orders |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt fulfill on invalid status |
| **Expected Result** | Business rule error; no state change |

---

### Module: Backorders

#### BACK-001: Backorders — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-001 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Backorders records |
| **Steps** | 1. Login<br>2. Navigate to /backorders<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### BACK-002: Backorders — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-002 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /backorders via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### BACK-003: Backorders — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-003 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### BACK-004: Backorders — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-004 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### BACK-005: Backorders — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-005 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (name) |
| **Steps** | 1. Open create form/modal on /backorders<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### BACK-006: Backorders — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-006 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: name |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: name |

---

#### BACK-007: Backorders — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-007 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### BACK-008: Backorders — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-008 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### BACK-009: Backorders — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-009 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### BACK-010: Backorders — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-010 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### BACK-011: Backorders — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-011 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /backorders<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### BACK-012: Backorders — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-012 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### BACK-013: Backorders — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-013 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /backorders |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### BACK-014: Backorders — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-014 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /backorders<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### BACK-015: Backorders — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-015 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### BACK-016: Backorders — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-016 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /backorders with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### BACK-017: Backorders — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-017 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /backorders with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### BACK-018: Backorders — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-018 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /backorders<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### BACK-019: Backorders — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-019 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /backorders |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### BACK-020: Backorders — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-020 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has fulfill permission |
| **Test Data** | Valid fulfill context |
| **Steps** | 1. Open record detail<br>2. Execute fulfill<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### BACK-021: Backorders — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-021 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User lacks fulfill permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt fulfill action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### BACK-022: Backorders — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | BACK-022 |
| **Module** | Backorders |
| **Feature** | Backorders |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt fulfill on invalid status |
| **Expected Result** | Business rule error; no state change |

---

### Module: Rentals

#### RENT-001: Plant rental — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-001 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Rentals records |
| **Steps** | 1. Login<br>2. Navigate to /rentals<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### RENT-002: Plant rental — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-002 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /rentals via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### RENT-003: Plant rental — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-003 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### RENT-004: Plant rental — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-004 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### RENT-005: Plant rental — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-005 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (name) |
| **Steps** | 1. Open create form/modal on /rentals<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### RENT-006: Plant rental — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-006 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: name |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: name |

---

#### RENT-007: Plant rental — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-007 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### RENT-008: Plant rental — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-008 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### RENT-009: Plant rental — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-009 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### RENT-010: Plant rental — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-010 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### RENT-011: Plant rental — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-011 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /rentals<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### RENT-012: Plant rental — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-012 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### RENT-013: Plant rental — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-013 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /rentals |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### RENT-014: Plant rental — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-014 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /rentals<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### RENT-015: Plant rental — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-015 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### RENT-016: Plant rental — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-016 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /rentals with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### RENT-017: Plant rental — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-017 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /rentals with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### RENT-018: Plant rental — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-018 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /rentals<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### RENT-019: Plant rental — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-019 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /rentals |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### RENT-020: Plant rental — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-020 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has activate permission |
| **Test Data** | Valid activate context |
| **Steps** | 1. Open record detail<br>2. Execute activate<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### RENT-021: Plant rental — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-021 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User lacks activate permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt activate action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### RENT-022: Plant rental — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-022 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt activate on invalid status |
| **Expected Result** | Business rule error; no state change |

---

#### RENT-023: Plant rental — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-023 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has return permission |
| **Test Data** | Valid return context |
| **Steps** | 1. Open record detail<br>2. Execute return<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### RENT-024: Plant rental — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-024 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User lacks return permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt return action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### RENT-025: Plant rental — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-025 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt return on invalid status |
| **Expected Result** | Business rule error; no state change |

---

#### RENT-026: Plant rental — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-026 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has settle permission |
| **Test Data** | Valid settle context |
| **Steps** | 1. Open record detail<br>2. Execute settle<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### RENT-027: Plant rental — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-027 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User lacks settle permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt settle action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### RENT-028: Plant rental — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | RENT-028 |
| **Module** | Rentals |
| **Feature** | Plant rental |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt settle on invalid status |
| **Expected Result** | Business rule error; no state change |

---

### Module: Loyalty

#### LOY-001: Loyalty program — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | LOY-001 |
| **Module** | Loyalty |
| **Feature** | Loyalty program |
| **Priority** | P3 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Loyalty records |
| **Steps** | 1. Login<br>2. Navigate to /loyalty<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### LOY-002: Loyalty program — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | LOY-002 |
| **Module** | Loyalty |
| **Feature** | Loyalty program |
| **Priority** | P3 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /loyalty via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### LOY-003: Loyalty program — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | LOY-003 |
| **Module** | Loyalty |
| **Feature** | Loyalty program |
| **Priority** | P3 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### LOY-004: Loyalty program — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | LOY-004 |
| **Module** | Loyalty |
| **Feature** | Loyalty program |
| **Priority** | P3 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### LOY-005: Loyalty program — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | LOY-005 |
| **Module** | Loyalty |
| **Feature** | Loyalty program |
| **Priority** | P3 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (name) |
| **Steps** | 1. Open create form/modal on /loyalty<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### LOY-006: Loyalty program — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | LOY-006 |
| **Module** | Loyalty |
| **Feature** | Loyalty program |
| **Priority** | P3 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: name |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: name |

---

#### LOY-007: Loyalty program — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | LOY-007 |
| **Module** | Loyalty |
| **Feature** | Loyalty program |
| **Priority** | P3 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### LOY-008: Loyalty program — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | LOY-008 |
| **Module** | Loyalty |
| **Feature** | Loyalty program |
| **Priority** | P3 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### LOY-009: Loyalty program — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | LOY-009 |
| **Module** | Loyalty |
| **Feature** | Loyalty program |
| **Priority** | P3 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### LOY-010: Loyalty program — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | LOY-010 |
| **Module** | Loyalty |
| **Feature** | Loyalty program |
| **Priority** | P3 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### LOY-011: Loyalty program — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | LOY-011 |
| **Module** | Loyalty |
| **Feature** | Loyalty program |
| **Priority** | P3 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /loyalty<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### LOY-012: Loyalty program — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | LOY-012 |
| **Module** | Loyalty |
| **Feature** | Loyalty program |
| **Priority** | P3 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### LOY-013: Loyalty program — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | LOY-013 |
| **Module** | Loyalty |
| **Feature** | Loyalty program |
| **Priority** | P3 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /loyalty with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### LOY-014: Loyalty program — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | LOY-014 |
| **Module** | Loyalty |
| **Feature** | Loyalty program |
| **Priority** | P3 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /loyalty with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### LOY-015: Loyalty program — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | LOY-015 |
| **Module** | Loyalty |
| **Feature** | Loyalty program |
| **Priority** | P3 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /loyalty<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### LOY-016: Loyalty program — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | LOY-016 |
| **Module** | Loyalty |
| **Feature** | Loyalty program |
| **Priority** | P3 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /loyalty |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

### Module: Commission

#### COMM-001: Commission rules and payouts — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | COMM-001 |
| **Module** | Commission |
| **Feature** | Commission rules and payouts |
| **Priority** | P3 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Commission records |
| **Steps** | 1. Login<br>2. Navigate to /commission<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### COMM-002: Commission rules and payouts — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | COMM-002 |
| **Module** | Commission |
| **Feature** | Commission rules and payouts |
| **Priority** | P3 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /commission via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### COMM-003: Commission rules and payouts — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | COMM-003 |
| **Module** | Commission |
| **Feature** | Commission rules and payouts |
| **Priority** | P3 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### COMM-004: Commission rules and payouts — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | COMM-004 |
| **Module** | Commission |
| **Feature** | Commission rules and payouts |
| **Priority** | P3 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### COMM-005: Commission rules and payouts — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | COMM-005 |
| **Module** | Commission |
| **Feature** | Commission rules and payouts |
| **Priority** | P3 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (name) |
| **Steps** | 1. Open create form/modal on /commission<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### COMM-006: Commission rules and payouts — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | COMM-006 |
| **Module** | Commission |
| **Feature** | Commission rules and payouts |
| **Priority** | P3 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: name |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: name |

---

#### COMM-007: Commission rules and payouts — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | COMM-007 |
| **Module** | Commission |
| **Feature** | Commission rules and payouts |
| **Priority** | P3 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### COMM-008: Commission rules and payouts — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | COMM-008 |
| **Module** | Commission |
| **Feature** | Commission rules and payouts |
| **Priority** | P3 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### COMM-009: Commission rules and payouts — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | COMM-009 |
| **Module** | Commission |
| **Feature** | Commission rules and payouts |
| **Priority** | P3 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### COMM-010: Commission rules and payouts — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | COMM-010 |
| **Module** | Commission |
| **Feature** | Commission rules and payouts |
| **Priority** | P3 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### COMM-011: Commission rules and payouts — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | COMM-011 |
| **Module** | Commission |
| **Feature** | Commission rules and payouts |
| **Priority** | P3 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /commission with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### COMM-012: Commission rules and payouts — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | COMM-012 |
| **Module** | Commission |
| **Feature** | Commission rules and payouts |
| **Priority** | P3 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /commission with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### COMM-013: Commission rules and payouts — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | COMM-013 |
| **Module** | Commission |
| **Feature** | Commission rules and payouts |
| **Priority** | P3 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /commission<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### COMM-014: Commission rules and payouts — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | COMM-014 |
| **Module** | Commission |
| **Feature** | Commission rules and payouts |
| **Priority** | P3 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /commission |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

### Module: Supplier Payments

#### PAY-001: Supplier payments — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | PAY-001 |
| **Module** | Supplier Payments |
| **Feature** | Supplier payments |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Supplier Payments records |
| **Steps** | 1. Login<br>2. Navigate to /payments<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### PAY-002: Supplier payments — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | PAY-002 |
| **Module** | Supplier Payments |
| **Feature** | Supplier payments |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /payments via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### PAY-003: Supplier payments — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | PAY-003 |
| **Module** | Supplier Payments |
| **Feature** | Supplier payments |
| **Priority** | P1 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### PAY-004: Supplier payments — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | PAY-004 |
| **Module** | Supplier Payments |
| **Feature** | Supplier payments |
| **Priority** | P1 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### PAY-005: Supplier payments — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | PAY-005 |
| **Module** | Supplier Payments |
| **Feature** | Supplier payments |
| **Priority** | P1 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (supplier_id, amount, payment_date, mode) |
| **Steps** | 1. Open create form/modal on /payments<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### PAY-006: Supplier payments — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | PAY-006 |
| **Module** | Supplier Payments |
| **Feature** | Supplier payments |
| **Priority** | P1 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: supplier_id, amount, payment_date, mode |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: supplier_id, amount, payment_date, mode |

---

#### PAY-007: Supplier payments — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | PAY-007 |
| **Module** | Supplier Payments |
| **Feature** | Supplier payments |
| **Priority** | P1 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### PAY-008: Supplier payments — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | PAY-008 |
| **Module** | Supplier Payments |
| **Feature** | Supplier payments |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### PAY-009: Supplier payments — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | PAY-009 |
| **Module** | Supplier Payments |
| **Feature** | Supplier payments |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### PAY-010: Supplier payments — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | PAY-010 |
| **Module** | Supplier Payments |
| **Feature** | Supplier payments |
| **Priority** | P1 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### PAY-011: Supplier payments — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | PAY-011 |
| **Module** | Supplier Payments |
| **Feature** | Supplier payments |
| **Priority** | P1 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /payments |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### PAY-012: Supplier payments — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | PAY-012 |
| **Module** | Supplier Payments |
| **Feature** | Supplier payments |
| **Priority** | P1 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /payments<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### PAY-013: Supplier payments — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | PAY-013 |
| **Module** | Supplier Payments |
| **Feature** | Supplier payments |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### PAY-014: Supplier payments — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | PAY-014 |
| **Module** | Supplier Payments |
| **Feature** | Supplier payments |
| **Priority** | P1 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /payments with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### PAY-015: Supplier payments — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | PAY-015 |
| **Module** | Supplier Payments |
| **Feature** | Supplier payments |
| **Priority** | P1 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /payments with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### PAY-016: Supplier payments — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | PAY-016 |
| **Module** | Supplier Payments |
| **Feature** | Supplier payments |
| **Priority** | P1 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /payments<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### PAY-017: Supplier payments — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | PAY-017 |
| **Module** | Supplier Payments |
| **Feature** | Supplier payments |
| **Priority** | P1 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /payments |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

### Module: Customer Receipts

#### RCPT-001: Customer receipts — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | RCPT-001 |
| **Module** | Customer Receipts |
| **Feature** | Customer receipts |
| **Priority** | P1 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Customer Receipts records |
| **Steps** | 1. Login<br>2. Navigate to /receipts<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### RCPT-002: Customer receipts — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | RCPT-002 |
| **Module** | Customer Receipts |
| **Feature** | Customer receipts |
| **Priority** | P1 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /receipts via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### RCPT-003: Customer receipts — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | RCPT-003 |
| **Module** | Customer Receipts |
| **Feature** | Customer receipts |
| **Priority** | P1 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### RCPT-004: Customer receipts — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | RCPT-004 |
| **Module** | Customer Receipts |
| **Feature** | Customer receipts |
| **Priority** | P1 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### RCPT-005: Customer receipts — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | RCPT-005 |
| **Module** | Customer Receipts |
| **Feature** | Customer receipts |
| **Priority** | P1 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (customer_id, amount, receipt_date, mode) |
| **Steps** | 1. Open create form/modal on /receipts<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### RCPT-006: Customer receipts — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | RCPT-006 |
| **Module** | Customer Receipts |
| **Feature** | Customer receipts |
| **Priority** | P1 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: customer_id, amount, receipt_date, mode |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: customer_id, amount, receipt_date, mode |

---

#### RCPT-007: Customer receipts — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | RCPT-007 |
| **Module** | Customer Receipts |
| **Feature** | Customer receipts |
| **Priority** | P1 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### RCPT-008: Customer receipts — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | RCPT-008 |
| **Module** | Customer Receipts |
| **Feature** | Customer receipts |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### RCPT-009: Customer receipts — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | RCPT-009 |
| **Module** | Customer Receipts |
| **Feature** | Customer receipts |
| **Priority** | P1 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### RCPT-010: Customer receipts — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | RCPT-010 |
| **Module** | Customer Receipts |
| **Feature** | Customer receipts |
| **Priority** | P1 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### RCPT-011: Customer receipts — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | RCPT-011 |
| **Module** | Customer Receipts |
| **Feature** | Customer receipts |
| **Priority** | P1 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /receipts |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### RCPT-012: Customer receipts — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | RCPT-012 |
| **Module** | Customer Receipts |
| **Feature** | Customer receipts |
| **Priority** | P1 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /receipts<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### RCPT-013: Customer receipts — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | RCPT-013 |
| **Module** | Customer Receipts |
| **Feature** | Customer receipts |
| **Priority** | P1 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### RCPT-014: Customer receipts — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | RCPT-014 |
| **Module** | Customer Receipts |
| **Feature** | Customer receipts |
| **Priority** | P1 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /receipts with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### RCPT-015: Customer receipts — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | RCPT-015 |
| **Module** | Customer Receipts |
| **Feature** | Customer receipts |
| **Priority** | P1 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /receipts with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### RCPT-016: Customer receipts — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | RCPT-016 |
| **Module** | Customer Receipts |
| **Feature** | Customer receipts |
| **Priority** | P1 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /receipts<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### RCPT-017: Customer receipts — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | RCPT-017 |
| **Module** | Customer Receipts |
| **Feature** | Customer receipts |
| **Priority** | P1 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /receipts |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

### Module: Locations

#### LOC-001: Locations — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | LOC-001 |
| **Module** | Locations |
| **Feature** | Locations |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Locations records |
| **Steps** | 1. Login<br>2. Navigate to /locations<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### LOC-002: Locations — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | LOC-002 |
| **Module** | Locations |
| **Feature** | Locations |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /locations via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### LOC-003: Locations — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | LOC-003 |
| **Module** | Locations |
| **Feature** | Locations |
| **Priority** | P2 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### LOC-004: Locations — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | LOC-004 |
| **Module** | Locations |
| **Feature** | Locations |
| **Priority** | P2 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### LOC-005: Locations — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | LOC-005 |
| **Module** | Locations |
| **Feature** | Locations |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (name, type) |
| **Steps** | 1. Open create form/modal on /locations<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### LOC-006: Locations — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | LOC-006 |
| **Module** | Locations |
| **Feature** | Locations |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | Existing record; update permission |
| **Test Data** | Modified field values |
| **Steps** | 1. Edit record on /locations<br>2. Save changes |
| **Expected Result** | PUT succeeds; UI shows updated values |

---

#### LOC-007: Locations — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | LOC-007 |
| **Module** | Locations |
| **Feature** | Locations |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | Deletable record exists |
| **Test Data** | Draft or unreferenced record |
| **Steps** | 1. Delete record<br>2. Confirm dialog |
| **Expected Result** | Record removed; list refreshed |

---

#### LOC-008: Locations — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | LOC-008 |
| **Module** | Locations |
| **Feature** | Locations |
| **Priority** | P2 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: name, type |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: name, type |

---

#### LOC-009: Locations — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | LOC-009 |
| **Module** | Locations |
| **Feature** | Locations |
| **Priority** | P2 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### LOC-010: Locations — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | LOC-010 |
| **Module** | Locations |
| **Feature** | Locations |
| **Priority** | P2 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### LOC-011: Locations — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | LOC-011 |
| **Module** | Locations |
| **Feature** | Locations |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### LOC-012: Locations — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | LOC-012 |
| **Module** | Locations |
| **Feature** | Locations |
| **Priority** | P2 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### LOC-013: Locations — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | LOC-013 |
| **Module** | Locations |
| **Feature** | Locations |
| **Priority** | P2 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /locations with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### LOC-014: Locations — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | LOC-014 |
| **Module** | Locations |
| **Feature** | Locations |
| **Priority** | P2 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /locations with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### LOC-015: Locations — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | LOC-015 |
| **Module** | Locations |
| **Feature** | Locations |
| **Priority** | P2 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /locations<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### LOC-016: Locations — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | LOC-016 |
| **Module** | Locations |
| **Feature** | Locations |
| **Priority** | P2 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /locations |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

### Module: Users

#### USER-001: Users — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-001 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Users records |
| **Steps** | 1. Login<br>2. Navigate to /users<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### USER-002: Users — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-002 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /users via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### USER-003: Users — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-003 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### USER-004: Users — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-004 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### USER-005: Users — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-005 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (name, email, password) |
| **Steps** | 1. Open create form/modal on /users<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### USER-006: Users — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-006 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | Existing record; update permission |
| **Test Data** | Modified field values |
| **Steps** | 1. Edit record on /users<br>2. Save changes |
| **Expected Result** | PUT succeeds; UI shows updated values |

---

#### USER-007: Users — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-007 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | Deletable record exists |
| **Test Data** | Draft or unreferenced record |
| **Steps** | 1. Delete record<br>2. Confirm dialog |
| **Expected Result** | Record removed; list refreshed |

---

#### USER-008: Users — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-008 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: name, email, password |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: name, email, password |

---

#### USER-009: Users — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-009 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### USER-010: Users — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-010 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### USER-011: Users — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-011 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### USER-012: Users — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-012 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### USER-013: Users — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-013 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /users |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### USER-014: Users — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-014 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /users<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### USER-015: Users — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-015 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### USER-016: Users — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-016 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /users with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### USER-017: Users — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-017 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /users with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### USER-018: Users — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-018 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /users<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### USER-019: Users — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | USER-019 |
| **Module** | Users |
| **Feature** | Users |
| **Priority** | P2 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /users |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

### Module: Roles

#### ROLE-001: Roles — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-001 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Roles records |
| **Steps** | 1. Login<br>2. Navigate to /roles<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### ROLE-002: Roles — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-002 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /roles via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### ROLE-003: Roles — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-003 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### ROLE-004: Roles — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-004 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### ROLE-005: Roles — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-005 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | User with create permission |
| **Test Data** | Valid minimal payload (name, permissions) |
| **Steps** | 1. Open create form/modal on /roles<br>2. Fill required fields<br>3. Submit |
| **Expected Result** | Record created; success toast; appears in list |

---

#### ROLE-006: Roles — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-006 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | Existing record; update permission |
| **Test Data** | Modified field values |
| **Steps** | 1. Edit record on /roles<br>2. Save changes |
| **Expected Result** | PUT succeeds; UI shows updated values |

---

#### ROLE-007: Roles — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-007 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | Deletable record exists |
| **Test Data** | Draft or unreferenced record |
| **Steps** | 1. Delete record<br>2. Confirm dialog |
| **Expected Result** | Record removed; list refreshed |

---

#### ROLE-008: Roles — Required field validation

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-008 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | Required field validation |
| **Precondition** | Create form open |
| **Test Data** | Empty form; missing: name, permissions |
| **Steps** | 1. Open create<br>2. Submit without filling required fields |
| **Expected Result** | 422 validation errors on: name, permissions |

---

#### ROLE-009: Roles — Invalid input

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-009 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | Invalid input |
| **Precondition** | Create form open |
| **Test Data** | Negative qty; invalid UUID; SQL injection in text fields |
| **Steps** | 1. Enter invalid values<br>2. Submit |
| **Expected Result** | Field errors displayed; no record created; no SQL injection |

---

#### ROLE-010: Roles — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-010 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | Boundary values |
| **Precondition** | Create form open |
| **Test Data** | qty=0; qty=0.001; max-length strings; gst_rate=100 |
| **Steps** | 1. Enter boundary values<br>2. Submit |
| **Expected Result** | gt:0 fields reject zero; max length enforced; valid boundaries accepted |

---

#### ROLE-011: Roles — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-011 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### ROLE-012: Roles — Duplicate submission

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-012 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | Duplicate submission |
| **Precondition** | Create form complete |
| **Test Data** | Valid payload |
| **Steps** | 1. Click Save/submit twice in quick succession |
| **Expected Result** | Only one record created; submit button disabled during request |

---

#### ROLE-013: Roles — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-013 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | Search |
| **Precondition** | 10+ records in list |
| **Test Data** | Known keyword from record name/number |
| **Steps** | 1. Open /roles<br>2. Enter search term<br>3. Wait for debounce |
| **Expected Result** | List shows only matching records |

---

#### ROLE-014: Roles — Search

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-014 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | Search |
| **Precondition** | Populated list |
| **Test Data** | Search: ZZZZNOMATCH999 |
| **Steps** | 1. Search nonsense string |
| **Expected Result** | Empty state; zero results; no error |

---

#### ROLE-015: Roles — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-015 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | Filtering |
| **Precondition** | Sortable list (suppliers/roles) |
| **Test Data** | sort=name, dir=asc |
| **Steps** | 1. Change sort if UI available; else API with sort params |
| **Expected Result** | Records ordered correctly |

---

#### ROLE-016: Roles — Pagination

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-016 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | Pagination |
| **Precondition** | More records than per_page default |
| **Test Data** | page 1 and page 2 |
| **Steps** | 1. Open /roles<br>2. Click next page |
| **Expected Result** | Different records on page 2; pagination meta correct |

---

#### ROLE-017: Roles — Boundary values

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-017 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | Boundary values |
| **Precondition** | API direct test |
| **Test Data** | per_page=1000, page=99999 |
| **Steps** | 1. Request API with extreme pagination params |
| **Expected Result** | per_page capped at 100; graceful empty/last page |

---

#### ROLE-018: Roles — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-018 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /roles with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### ROLE-019: Roles — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-019 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /roles with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### ROLE-020: Roles — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-020 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /roles<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### ROLE-021: Roles — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | ROLE-021 |
| **Module** | Roles |
| **Feature** | Roles |
| **Priority** | P2 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /roles |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

### Module: Reports

#### RPT-001: Reports and exports — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | RPT-001 |
| **Module** | Reports |
| **Feature** | Reports and exports |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Reports records |
| **Steps** | 1. Login<br>2. Navigate to /reports<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### RPT-002: Reports and exports — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | RPT-002 |
| **Module** | Reports |
| **Feature** | Reports and exports |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /reports via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### RPT-003: Reports and exports — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | RPT-003 |
| **Module** | Reports |
| **Feature** | Reports and exports |
| **Priority** | P2 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### RPT-004: Reports and exports — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | RPT-004 |
| **Module** | Reports |
| **Feature** | Reports and exports |
| **Priority** | P2 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### RPT-005: Reports and exports — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | RPT-005 |
| **Module** | Reports |
| **Feature** | Reports and exports |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### RPT-006: Reports and exports — Filtering

| Field | Value |
|-------|-------|
| **Test Case ID** | RPT-006 |
| **Module** | Reports |
| **Feature** | Reports and exports |
| **Priority** | P2 |
| **Test Type** | Filtering |
| **Precondition** | Mixed status/type records |
| **Test Data** | status=active or type filter value |
| **Steps** | 1. Apply filter on /reports |
| **Expected Result** | Only matching records shown; correct query param sent to API |

---

#### RPT-007: Reports and exports — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | RPT-007 |
| **Module** | Reports |
| **Feature** | Reports and exports |
| **Priority** | P2 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /reports with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### RPT-008: Reports and exports — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | RPT-008 |
| **Module** | Reports |
| **Feature** | Reports and exports |
| **Priority** | P2 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /reports with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### RPT-009: Reports and exports — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | RPT-009 |
| **Module** | Reports |
| **Feature** | Reports and exports |
| **Priority** | P2 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /reports<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### RPT-010: Reports and exports — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | RPT-010 |
| **Module** | Reports |
| **Feature** | Reports and exports |
| **Priority** | P2 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /reports |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### RPT-011: Reports and exports — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | RPT-011 |
| **Module** | Reports |
| **Feature** | Reports and exports |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | reports.view permission; date range selected |
| **Test Data** | from/to last 30 days |
| **Steps** | 1. Open /reports<br>2. Select tab<br>3. Run report |
| **Expected Result** | Report data displayed for date range |

---

#### RPT-012: Reports and exports — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | RPT-012 |
| **Module** | Reports |
| **Feature** | Reports and exports |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | Export enabled on report tab |
| **Test Data** | format=csv or pdf |
| **Steps** | 1. Click Export on report |
| **Expected Result** | File downloads with correct data |

---

#### RPT-013: Reports and exports — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | RPT-013 |
| **Module** | Reports |
| **Feature** | Reports and exports |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User without reports.margin |
| **Test Data** | Standard branch user |
| **Steps** | 1. Attempt margin report API |
| **Expected Result** | 403 or report tab hidden for HO-only reports |

---

### Module: Activity Monitoring

#### ACT-001: Activity monitoring — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | ACT-001 |
| **Module** | Activity Monitoring |
| **Feature** | Activity monitoring |
| **Priority** | P3 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Activity Monitoring records |
| **Steps** | 1. Login<br>2. Navigate to /activity-monitoring<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### ACT-002: Activity monitoring — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | ACT-002 |
| **Module** | Activity Monitoring |
| **Feature** | Activity monitoring |
| **Priority** | P3 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /activity-monitoring via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### ACT-003: Activity monitoring — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | ACT-003 |
| **Module** | Activity Monitoring |
| **Feature** | Activity monitoring |
| **Priority** | P3 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### ACT-004: Activity monitoring — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | ACT-004 |
| **Module** | Activity Monitoring |
| **Feature** | Activity monitoring |
| **Priority** | P3 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### ACT-005: Activity monitoring — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | ACT-005 |
| **Module** | Activity Monitoring |
| **Feature** | Activity monitoring |
| **Priority** | P3 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### ACT-006: Activity monitoring — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | ACT-006 |
| **Module** | Activity Monitoring |
| **Feature** | Activity monitoring |
| **Priority** | P3 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /activity-monitoring with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### ACT-007: Activity monitoring — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | ACT-007 |
| **Module** | Activity Monitoring |
| **Feature** | Activity monitoring |
| **Priority** | P3 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /activity-monitoring with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### ACT-008: Activity monitoring — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | ACT-008 |
| **Module** | Activity Monitoring |
| **Feature** | Activity monitoring |
| **Priority** | P3 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /activity-monitoring<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### ACT-009: Activity monitoring — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | ACT-009 |
| **Module** | Activity Monitoring |
| **Feature** | Activity monitoring |
| **Priority** | P3 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /activity-monitoring |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

### Module: Backups

#### BKP-001: Database backups — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | BKP-001 |
| **Module** | Backups |
| **Feature** | Database backups |
| **Priority** | P3 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Backups records |
| **Steps** | 1. Login<br>2. Navigate to /backups<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### BKP-002: Database backups — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | BKP-002 |
| **Module** | Backups |
| **Feature** | Database backups |
| **Priority** | P3 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /backups via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### BKP-003: Database backups — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | BKP-003 |
| **Module** | Backups |
| **Feature** | Database backups |
| **Priority** | P3 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### BKP-004: Database backups — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | BKP-004 |
| **Module** | Backups |
| **Feature** | Database backups |
| **Priority** | P3 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### BKP-005: Database backups — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | BKP-005 |
| **Module** | Backups |
| **Feature** | Database backups |
| **Priority** | P3 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### BKP-006: Database backups — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | BKP-006 |
| **Module** | Backups |
| **Feature** | Database backups |
| **Priority** | P3 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /backups with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### BKP-007: Database backups — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | BKP-007 |
| **Module** | Backups |
| **Feature** | Database backups |
| **Priority** | P3 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /backups with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### BKP-008: Database backups — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | BKP-008 |
| **Module** | Backups |
| **Feature** | Database backups |
| **Priority** | P3 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /backups<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### BKP-009: Database backups — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | BKP-009 |
| **Module** | Backups |
| **Feature** | Database backups |
| **Priority** | P3 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /backups |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

#### BKP-010: Database backups — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | BKP-010 |
| **Module** | Backups |
| **Feature** | Database backups |
| **Priority** | P3 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has run permission |
| **Test Data** | Valid run context |
| **Steps** | 1. Open record detail<br>2. Execute run<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### BKP-011: Database backups — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | BKP-011 |
| **Module** | Backups |
| **Feature** | Database backups |
| **Priority** | P3 |
| **Test Type** | Authorization |
| **Precondition** | User lacks run permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt run action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### BKP-012: Database backups — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | BKP-012 |
| **Module** | Backups |
| **Feature** | Database backups |
| **Priority** | P3 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt run on invalid status |
| **Expected Result** | Business rule error; no state change |

---

#### BKP-013: Database backups — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | BKP-013 |
| **Module** | Backups |
| **Feature** | Database backups |
| **Priority** | P3 |
| **Test Type** | Positive |
| **Precondition** | Draft/pending record; user has restore permission |
| **Test Data** | Valid restore context |
| **Steps** | 1. Open record detail<br>2. Execute restore<br>3. Confirm if prompted |
| **Expected Result** | Workflow completes; status updated; stock/ledger side effects correct |

---

#### BKP-014: Database backups — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | BKP-014 |
| **Module** | Backups |
| **Feature** | Database backups |
| **Priority** | P3 |
| **Test Type** | Authorization |
| **Precondition** | User lacks restore permission |
| **Test Data** | Restricted user |
| **Steps** | 1. Attempt restore action on eligible record |
| **Expected Result** | 403 or action button hidden |

---

#### BKP-015: Database backups — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | BKP-015 |
| **Module** | Backups |
| **Feature** | Database backups |
| **Priority** | P3 |
| **Test Type** | Negative |
| **Precondition** | Invalid state for workflow |
| **Test Data** | Already completed/cancelled record |
| **Steps** | 1. Attempt restore on invalid status |
| **Expected Result** | Business rule error; no state change |

---

#### BKP-016: Database backups — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | BKP-016 |
| **Module** | Backups |
| **Feature** | Database backups |
| **Priority** | P3 |
| **Test Type** | Negative |
| **Precondition** | backup.restore permission |
| **Test Data** | Invalid backup filename |
| **Steps** | 1. Attempt restore with bad filename |
| **Expected Result** | Error; database unchanged |

---

### Module: Settings

#### SET-001: Company settings — Positive

| Field | Value |
|-------|-------|
| **Test Case ID** | SET-001 |
| **Module** | Settings |
| **Feature** | Company settings |
| **Priority** | P2 |
| **Test Type** | Positive |
| **Precondition** | User with view permission; active company; seed data exists |
| **Test Data** | Valid Settings records |
| **Steps** | 1. Login<br>2. Navigate to /settings<br>3. Verify list or detail loads |
| **Expected Result** | HTTP 200; data rendered correctly; no console errors |

---

#### SET-002: Company settings — Authorization

| Field | Value |
|-------|-------|
| **Test Case ID** | SET-002 |
| **Module** | Settings |
| **Feature** | Company settings |
| **Priority** | P2 |
| **Test Type** | Authorization |
| **Precondition** | User without module view permission |
| **Test Data** | Restricted role credentials |
| **Steps** | 1. Login as restricted user<br>2. Open /settings via direct URL |
| **Expected Result** | Permission denied UI OR 403 from API; no sensitive data exposed |

---

#### SET-003: Company settings — Authentication

| Field | Value |
|-------|-------|
| **Test Case ID** | SET-003 |
| **Module** | Settings |
| **Feature** | Company settings |
| **Priority** | P2 |
| **Test Type** | Authentication |
| **Precondition** | Unauthenticated client |
| **Test Data** | No bearer token |
| **Steps** | 1. Call module API endpoint without Authorization |
| **Expected Result** | 401 Unauthenticated JSON response |

---

#### SET-004: Company settings — API errors

| Field | Value |
|-------|-------|
| **Test Case ID** | SET-004 |
| **Module** | Settings |
| **Feature** | Company settings |
| **Priority** | P2 |
| **Test Type** | API errors |
| **Precondition** | Authenticated; missing company scope |
| **Test Data** | Token without X-Company-Id |
| **Steps** | 1. GET module list API without X-Company-Id header |
| **Expected Result** | 422: X-Company-Id header is required |

---

#### SET-005: Company settings — CRUD operations

| Field | Value |
|-------|-------|
| **Test Case ID** | SET-005 |
| **Module** | Settings |
| **Feature** | Company settings |
| **Priority** | P2 |
| **Test Type** | CRUD operations |
| **Precondition** | Authenticated with update rights |
| **Test Data** | Updated field values |
| **Steps** | 1. Open /settings<br>2. Modify fields<br>3. Save |
| **Expected Result** | Changes persisted successfully |

---

#### SET-006: Company settings — Negative

| Field | Value |
|-------|-------|
| **Test Case ID** | SET-006 |
| **Module** | Settings |
| **Feature** | Company settings |
| **Priority** | P2 |
| **Test Type** | Negative |
| **Precondition** | Confirmed or locked record if applicable |
| **Test Data** | Confirmed document ID |
| **Steps** | 1. Attempt edit/delete on confirmed record (where update not allowed) |
| **Expected Result** | Operation blocked with clear error message |

---

#### SET-007: Company settings — Loading states

| Field | Value |
|-------|-------|
| **Test Case ID** | SET-007 |
| **Module** | Settings |
| **Feature** | Company settings |
| **Priority** | P2 |
| **Test Type** | Loading states |
| **Precondition** | Chrome DevTools Slow 3G |
| **Test Data** | Any |
| **Steps** | 1. Navigate to /settings with throttled network |
| **Expected Result** | Spinner or loading indicator until data arrives |

---

#### SET-008: Company settings — Empty states

| Field | Value |
|-------|-------|
| **Test Case ID** | SET-008 |
| **Module** | Settings |
| **Feature** | Company settings |
| **Priority** | P2 |
| **Test Type** | Empty states |
| **Precondition** | Company with zero module records OR no search matches |
| **Test Data** | Empty dataset |
| **Steps** | 1. Open /settings with no data |
| **Expected Result** | Friendly empty message; create button if user has create permission |

---

#### SET-009: Company settings — Browser refresh

| Field | Value |
|-------|-------|
| **Test Case ID** | SET-009 |
| **Module** | Settings |
| **Feature** | Company settings |
| **Priority** | P2 |
| **Test Type** | Browser refresh |
| **Precondition** | Logged in on module page |
| **Test Data** | Active session |
| **Steps** | 1. Open /settings<br>2. Refresh browser |
| **Expected Result** | Session persists via GET /me; page reloads without login redirect |

---

#### SET-010: Company settings — Network failure

| Field | Value |
|-------|-------|
| **Test Case ID** | SET-010 |
| **Module** | Settings |
| **Feature** | Company settings |
| **Priority** | P2 |
| **Test Type** | Network failure |
| **Precondition** | Page loaded; then go offline |
| **Test Data** | Any save or refresh action |
| **Steps** | 1. Go offline in DevTools<br>2. Trigger API action on /settings |
| **Expected Result** | Error toast; no corrupt UI state; succeeds on retry when online |

---

## 3. Traceability

| Document | Purpose |
|----------|---------|
| QA/PROJECT_ANALYSIS.md | Stack, routes, auth, existing tests |
| QA/FEATURE_INVENTORY.md | Feature-level CRUD matrix and API mapping |
| QA/TEST_PLAN.md | This document — executable test cases |

## 4. Out of scope

- Automated test implementation (frontend E2E not yet configured)
- Performance/load testing
- Third-party WhatsApp delivery verification (manual spot-check only)

*Generated by QA test plan generator — read-only; no application code modified.*
