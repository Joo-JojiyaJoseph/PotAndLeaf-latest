# PotAndLeaf ERP — Automated Testing Setup

**Date:** 2026-08-25  
**Scope:** Inspection and recommendations only — **no packages installed**, **no production code modified**.

---

## Executive summary

| Layer | Status | Framework |
|-------|--------|-----------|
| **Backend API** | ✅ Configured and active | **Pest 5** (PHPUnit) + Laravel testing |
| **Frontend SPA** | ❌ Not configured | No test runner, no test files in `src/` |
| **E2E (full stack)** | ❌ Not configured | No Cypress, Playwright, or Dusk |

The project is a **React 19 + Vite 6** SPA (`PotAndLeaf-frontend/`) backed by a **Laravel 13 API** (`PotAndLeaf-backend/`). Backend automated testing is mature (116 passing tests). Frontend and browser E2E testing need to be added from scratch.

---

## 1. Library audit (requested checks)

Inspection covered root `package.json` files, `composer.json`, `node_modules` (transitive deps only), `src/`, `tests/`, and config files.

| Library | In project dependencies? | In app source/tests? | Notes |
|---------|--------------------------|----------------------|-------|
| **Vitest** | ❌ No | ❌ No | Present only inside `node_modules` (axios, vite plugins, etc.) |
| **Jest** | ❌ No | ❌ No | Present only in transitive deps (e.g. `gensync`) |
| **React Testing Library** | ❌ No | ❌ No | Used by `@tanstack/react-query` in its own package, not this app |
| **Cypress** | ❌ No | ❌ No | Not found |
| **Playwright** | ❌ No | ❌ No | Not found in app; transitive in some deps |
| **Vue Test Utils** | ❌ N/A | ❌ N/A | **Not applicable** — frontend is React, not Vue |
| **PHPUnit** | ✅ Yes (via Pest) | ✅ Yes | `phpunit.xml` + Pest runner |
| **Pest** | ✅ Yes | ✅ Yes | `pestphp/pest` ^5.0 |
| **Mockery** | ✅ Yes (dev) | ✅ Possible | `mockery/mockery` in composer |
| **Laravel Dusk** | ❌ No | ❌ No | Not installed |
| **MSW** | ❌ No | ❌ No | Not installed (recommended for FE integration tests) |

---

## 2. Existing test framework

### Backend — Pest 5 + PHPUnit

| Item | Location / detail |
|------|-------------------|
| Test runner | [Pest](https://pestphp.com/) 5.x |
| PHPUnit config | `PotAndLeaf-backend/phpunit.xml` |
| Pest bootstrap | `PotAndLeaf-backend/tests/Pest.php` |
| Base test case | `PotAndLeaf-backend/tests/TestCase.php` |
| Laravel plugin | `pestphp/pest-plugin-laravel` ^5.0 |
| Test DB | SQLite `:memory:` (set in `phpunit.xml`) |
| Test env | `APP_ENV=testing`; array cache/session/mail |

**Test style:** Pest functional tests (`test()`, `describe()`, `it()`) with Laravel HTTP helpers (`postJson`, `assertJsonPath`, etc.) and `RefreshDatabase`.

**Fixtures:** `tests/Support/CreatesErpFixtures.php` — seeds permissions, creates company/user/role, products, customers, suppliers, and provides `apiHeaders()` with Sanctum token + `X-Company-Id`.

**Test groups:** `ErpQaMatrixTest.php` uses `->group('qa')` for targeted runs.

### Frontend — none

The SPA has **no** declared test framework. `PotAndLeaf-frontend/package.json` scripts are only:

- `dev`
- `build`
- `preview`

There is no `vitest.config.*`, `jest.config.*`, `cypress.config.*`, or `playwright.config.*` in application source.

---

## 3. Existing test scripts

### Backend (`PotAndLeaf-backend/composer.json`)

| Script | Command | Purpose |
|--------|---------|---------|
| `composer test` | `php artisan config:clear` → `php artisan test` | Full backend suite |
| *(direct)* | `php artisan test` | Same via Artisan |
| *(direct)* | `php artisan test --group=qa` | QA matrix subset |
| *(direct)* | `php artisan test tests/Feature/SalesPhaseATest.php` | Single file |

### Frontend (`PotAndLeaf-frontend/package.json`)

| Script | Status |
|--------|--------|
| `test` | ❌ Not defined |
| `test:unit` | ❌ Not defined |
| `test:e2e` | ❌ Not defined |

### Backend root npm (`PotAndLeaf-backend/package.json`)

Used for Laravel’s own Vite asset pipeline (not the React SPA). Scripts: `build`, `dev` only — **no tests**.

### CI/CD

No `.github/workflows`, GitLab CI, or Jenkinsfile found in the repository for automated test runs.

---

## 4. Existing test files

### Backend — 22 test files (116 tests, all passing at last run)

**Unit**

| File | Purpose |
|------|---------|
| `tests/Unit/ExampleTest.php` | Smoke example |

**Feature**

| File | Module / focus |
|------|----------------|
| `tests/Feature/ExampleTest.php` | HTTP smoke (`GET /`) |
| `tests/Feature/ErpQaMatrixTest.php` | Cross-module QA flows (`--group=qa`) |
| `tests/Feature/SalesPhaseATest.php` | Sales / POS |
| `tests/Feature/PosSaleReceiptTest.php` | POS + receipts |
| `tests/Feature/PurchaseUpdateTest.php` | Purchase update |
| `tests/Feature/PurchaseOrdersPhaseDTest.php` | Purchase orders |
| `tests/Feature/ProductStockTest.php` | Product opening stock |
| `tests/Feature/MasterDataTest.php` | Master data CRUD |
| `tests/Feature/CompanyManagementTest.php` | Super-admin companies |
| `tests/Feature/TransferPhase3Test.php` | Transfers |
| `tests/Feature/TransferPhase5Test.php` | Transfers |
| `tests/Feature/TransferCancelBatchTest.php` | Transfer cancel |
| `tests/Feature/BulkSplit*` | *(via other phase tests)* |
| `tests/Feature/BackordersPhaseBTest.php` | Backorders |
| `tests/Feature/AdvanceOrder*` | *(in ErpQaMatrix)* |
| `tests/Feature/RentalPhase4Test.php` | Rentals |
| `tests/Feature/RentalReportsTest.php` | Rental reports |
| `tests/Feature/ProductionPhase1Test.php` | Production |
| `tests/Feature/ProductionPhase2Test.php` | Production |
| `tests/Feature/ProductionFormDataTest.php` | Production form-data |
| `tests/Feature/CommissionIncentiveLoyaltyTest.php` | Commission / loyalty |
| `tests/Feature/AccountingPhaseCTest.php` | Accounting |
| `tests/Feature/ReportsAnalyticsTest.php` | Reports |

**Support**

| File | Purpose |
|------|---------|
| `tests/Support/CreatesErpFixtures.php` | Shared ERP test data + auth headers |
| `tests/Pest.php` | Pest configuration |
| `tests/TestCase.php` | Laravel base test case |

### Frontend — 0 application test files

| Pattern searched | Result |
|------------------|--------|
| `src/**/*.test.{js,jsx,ts,tsx}` | None |
| `src/**/*.spec.{js,jsx,ts,tsx}` | None |
| `e2e/**` | None |
| `cypress/**` | None |
| `playwright/**` | None |

The only `*.test.js` under the frontend tree is inside `node_modules/gensync/` (dependency, not application code).

---

## 5. Missing testing dependencies

### Frontend (all missing — required for new FE automation)

| Package | Purpose | Priority |
|---------|---------|----------|
| `vitest` | Unit/integration test runner (native Vite integration) | **Required** |
| `@vitest/coverage-v8` | Code coverage reports | Recommended |
| `jsdom` | DOM environment for component tests | **Required** |
| `@testing-library/react` | React component testing | **Required** |
| `@testing-library/jest-dom` | DOM matchers (`toBeInTheDocument`, etc.) | **Required** |
| `@testing-library/user-event` | Realistic user interactions | **Required** |
| `msw` | Mock Service Worker — mock `/api` in tests | Recommended |
| `@playwright/test` | E2E browser tests | **Required for E2E** |
| `playwright` | Browser binaries (peer of `@playwright/test`) | **Required for E2E** |

**Not needed for this project**

| Package | Reason |
|---------|--------|
| `@vue/test-utils` | Vue-only; app uses React |
| `jest` | Redundant with Vitest on a Vite project |
| `cypress` | Optional alternative to Playwright; not recommended as primary (see below) |
| `@testing-library/vue` | N/A |

### Backend (optional enhancements — core stack is complete)

| Package / item | Purpose | Priority |
|----------------|---------|----------|
| *(none critical)* | Pest + Laravel testing cover API | — |
| `pestphp/pest-plugin-drift` | Detect PHPUnit-style drift | Optional |
| CI workflow (GitHub Actions) | Run `composer test` on PR | Recommended |
| Frontend job in CI | Run Vitest + Playwright after setup | Recommended once FE tests exist |

### E2E infrastructure (missing)

| Item | Status |
|------|--------|
| Playwright / Cypress config | ❌ |
| Test database seed for E2E | ❌ (can reuse Laravel seeders) |
| `playwright.config.ts` with `webServer` for Vite + API | ❌ |
| `.env.testing` for E2E API | ❌ |

---

## 6. Recommended framework for unit tests

### Frontend unit & integration: **Vitest + React Testing Library**

**Why Vitest (not Jest)**

- Project already uses **Vite 6** (`PotAndLeaf-frontend/vite.config.js`); Vitest shares the same config, aliases, and ESM pipeline.
- Faster cold start and HMR-style watch mode for tests.
- First-class `@vitejs/plugin-react` compatibility.
- Jest would require extra transforms (`babel-jest` / `ts-jest`) and duplicate config.

**Why React Testing Library**

- Aligns with React 19 and user-centric testing (matches manual QA in `QA/TEST_PLAN.md`).
- Works with Vitest via `jsdom` without ejecting from Vite.

**Suggested test layers (frontend)**

| Layer | Tool | Example targets |
|-------|------|-----------------|
| Pure functions | Vitest | `src/lib/format.js`, permission helpers |
| Hooks / context | Vitest + RTL | `AuthContext` (`can()`, login, company switch) |
| Components | Vitest + RTL | `PermissionRoute`, form validation UI, `Pagination` |
| API integration (isolated) | Vitest + MSW | `api.js` interceptors, form submit flows without backend |

**Suggested directory layout (when implemented)**

```
PotAndLeaf-frontend/
├── vitest.config.js          # or vitest section in vite.config.js
├── src/
│   ├── lib/
│   │   └── format.test.js
│   ├── context/
│   │   └── AuthContext.test.jsx
│   └── test/
│       ├── setup.js          # @testing-library/jest-dom, MSW server
│       └── handlers.js       # MSW API mocks
```

**Suggested npm scripts (future — not installed yet)**

```json
{
  "test": "vitest",
  "test:run": "vitest run",
  "test:coverage": "vitest run --coverage"
}
```

### Backend unit & integration: **keep Pest 5**

No change recommended. Existing pattern is appropriate:

- **Feature tests** = API integration (primary value for this decoupled SPA).
- **Unit tests** = pure services/actions where needed (minimal today).
- Continue using `CreatesErpFixtures` and `RefreshDatabase`.

**Expand backend tests toward:**

- Permission-denied cases (403 per module).
- Regression cases from `QA/TEST_PLAN.md` P0 items (opening stock, transfer qty, bulk split).
- Map new tests to `--group=qa` where cross-module.

---

## 7. Recommended framework for E2E tests

### **Playwright** (recommended over Cypress)

**Why Playwright**

- Multi-browser (Chromium, Firefox, WebKit) from one config.
- Strong support for **multi-origin** setups: Vite on `:5173` proxying `/api` to Laravel.
- Built-in `webServer` option to start Vite + `php artisan serve` before tests.
- API testing via `request` fixture (login, seed data) alongside UI steps.
- Better fit for ERP workflows (many tabs, PDF downloads, long forms) than Cypress for CI parallelism.
- Active ecosystem for Laravel + SPA projects.

**Why not Cypress as primary**

- Cypress can work but adds parallelization/complexity in CI.
- No existing Cypress investment in this repo.
- Playwright’s auto-wait and trace viewer suit flaky-prone ERP UIs.

**Why not Laravel Dusk alone**

- Dusk tests Blade/browser against Laravel; this app’s UI is a **separate React SPA**.
- Dusk would not exercise Vite routing, React Query, or client-side guards without coupling to served SPA assets.
- Use Dusk only if you later serve the built SPA from Laravel and want PHP-native E2E (not recommended vs Playwright for this architecture).

**Suggested E2E layout (when implemented)**

```
PotAndLeaf-frontend/
├── playwright.config.ts
└── e2e/
    ├── auth.setup.ts         # Login once, save storageState
    ├── login.spec.ts
    ├── products.spec.ts
    ├── purchases.spec.ts
    └── sales.spec.ts
```

**Suggested E2E prerequisites**

1. Backend running with seeded QA users (branch admin, super admin, restricted role).
2. Frontend dev server or production build served on fixed port (`5173`).
3. `storageState` from setup project for authenticated specs.
4. Map to `QA/TEST_PLAN.md` P0 cases: LOGIN-001, PROD-*, PUR-*, SALE-*, INV-*.

**Suggested npm scripts (future — not installed yet)**

```json
{
  "test:e2e": "playwright test",
  "test:e2e:ui": "playwright test --ui",
  "test:e2e:headed": "playwright test --headed"
}
```

---

## 8. Recommended testing pyramid

```
                    ┌─────────────┐
                    │  Playwright │  ~15–30 P0 E2E flows
                    │     E2E     │
                ┌───┴─────────────┴───┐
                │   Pest Feature/API   │  116+ tests (existing)
                │  (+ expand per QA)   │
            ┌───┴─────────────────────┴───┐
            │  Vitest + RTL + MSW (new)    │  Context, forms, guards
            └─────────────────────────────┘
```

| Tier | Tool | Owner | When to run |
|------|------|-------|-------------|
| E2E | Playwright | Frontend repo | Pre-release, nightly |
| API integration | Pest | Backend repo | Every PR, local pre-push |
| Unit / component | Vitest + RTL | Frontend repo | Every PR, watch during dev |

---

## 9. How to run existing tests today

From `PotAndLeaf-backend/` (requires `composer install` and PHP 8.3+):

```bash
# Full suite
composer test
# or
php artisan test

# QA matrix only
php artisan test --group=qa

# Single file
php artisan test tests/Feature/ProductStockTest.php
```

**Expected result:** 116 tests passed (405 assertions). Uses SQLite in-memory; no external MySQL required for tests.

**Frontend:** No automated tests to run until Vitest/Playwright are added.

---

## 10. Implementation checklist (future — do not run until approved)

### Phase A — Frontend unit tests (Vitest)

- [ ] Install: `vitest`, `jsdom`, `@testing-library/react`, `@testing-library/jest-dom`, `@testing-library/user-event`
- [ ] Add `test/setup.js` with jest-dom and optional MSW
- [ ] Add `vitest.config.js` (or extend `vite.config.js` with `test` block)
- [ ] Add npm scripts: `test`, `test:run`, `test:coverage`
- [ ] First tests: `AuthContext.test.jsx`, `PermissionRoute.test.jsx`, `lib/format.test.js`

### Phase B — E2E (Playwright)

- [ ] Install: `@playwright/test` (+ `npx playwright install` for browsers)
- [ ] Add `playwright.config.ts` with dual `webServer` (API + Vite)
- [ ] Seed QA users via Laravel seeder or Artisan command
- [ ] Auth setup + P0 specs from `QA/TEST_PLAN.md`

### Phase C — CI

- [ ] GitHub Actions: `composer test` on `PotAndLeaf-backend`
- [ ] Add job: `npm run test:run` + `npm run test:e2e` on `PotAndLeaf-frontend` after Phase A/B

### Phase D — Expand backend

- [ ] Add Pest tests for gaps in `QA/FEATURE_INVENTORY.md` (authorization 403, pagination caps)
- [ ] Tag regression tests with `->group('regression')`

---

## 11. Quick answers (requested summary)

| # | Question | Answer |
|---|----------|--------|
| 1 | **Existing test framework** | Backend: **Pest 5 + PHPUnit**. Frontend: **none**. |
| 2 | **Existing test scripts** | Backend: `composer test` / `php artisan test`. Frontend: **none**. |
| 3 | **Existing test files** | Backend: **22 files** in `PotAndLeaf-backend/tests/`. Frontend: **0** in `src/`. |
| 4 | **Missing dependencies** | Frontend: Vitest, RTL, jsdom, MSW, Playwright. Backend: core complete; CI optional. |
| 5 | **Recommended unit framework** | **Vitest + React Testing Library** (frontend); **keep Pest** (backend API). |
| 6 | **Recommended E2E framework** | **Playwright** (full-stack SPA + API). |

---

## 12. Related QA documents

| Document | Content |
|----------|---------|
| `QA/PROJECT_ANALYSIS.md` | Stack, routes, auth, backend test count |
| `QA/FEATURE_INVENTORY.md` | Per-module CRUD and API map |
| `QA/TEST_PLAN.md` | 658 manual test cases to automate selectively |

---

*Inspection complete — no packages installed, no production code modified.*
