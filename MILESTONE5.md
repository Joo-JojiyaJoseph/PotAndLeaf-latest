# Milestone 5 — Commission Engine & Customer Features

## Phase 1 audit

Stack: Laravel (PHP), Eloquent, MySQL in `.env.example` (SQLite in tests), Laravel migrations, the scheduler in `routes/console.php`, WhatsApp via `App\Services\WhatsApp\WhatsAppService` (WATI, then Twilio, otherwise a log-only stub), Pest, and company settings in `company_settings` plus `.env`.

Money stays on `decimal` columns and `round(..., 2)`, which is how the rest of the ERP already stores rupees.

| Deliverable | Status before this change | Evidence |
| --- | --- | --- |
| Salesman tiered commission | PARTIAL. Marginal month-to-date slabs existed. Period (daily/monthly) and flat vs marginal were not configurable. | `app/Services/CommissionEngine.php`, `commission_tiers`, `tests/Feature/CommissionIncentiveLoyaltyTest.php` |
| Daily target bonus | PARTIAL. Fixed bonus tiers per salesman rule existed. No percentage bonus and no branch-scoped target. | `commission_daily_target_tiers`, `CommissionEngine::syncDailyTargetBonusesForDay` |
| Product / category promotion bonus | EXISTS. Date range, per unit, fixed, and percent, with line-level reversal. | `commission_promotions`, `CommissionController::storePromotion` |
| Reversals on cancel and return | EXISTS for salesman, promotion, daily target, and supervisor. | `CommissionEngine::reverseOnSaleCancel`, `reverseOnSalesReturn`, `ConfirmSalesReturn` |
| Supervisor commission on sale and inter-company transfer | PARTIAL. One rate per supervisor. Not per product or category. Intra-company transfers did not accrue. Idempotency was pending quantity plus a row lock, without a unique key. | `SupervisorCommissionService`, `TransferService` (accrual only on inter-company dispatch) |
| Manager percent of branch net sales | PARTIAL. Monthly job only. No daily rows. | `commission:accrue-manager`, `manager_commission_rules` |
| Ledger, rules, effective dates, idempotent unique key, transactions | PARTIAL. `commission_transactions` was the ledger (`accrued` / `reversed`). It had no role, branch, or rule id. Effective dates already existed. | migrations `2026_01_12_000001`, `2026_08_19_000009`, `2026_08_19_000010` |
| Role-based commission reads | PARTIAL. `commission.view_own` existed for the daily summary only. Ledger and compute required `commission.view`. | `CommissionController::dailySummary`, `StandardRolesSeeder` |
| Summary, daily breakdown, ledger filters | PARTIAL. Monthly `compute`, one-day `dailySummary`, and a ledger list. No arbitrary date-range summary. | `routes/api.php` commission routes |
| End-of-day WhatsApp | PARTIAL. Message, template, idempotent sent-log, and admin `POST commission/send-eod` existed. Send time was hard-coded `20:00`. No month-to-date line. Retry had no backoff. | `commission:send-eod`, `CommissionNotificationService`, `whatsapp:retry-failed` |
| Loyalty earn, redeem, cap, ledger, reversal | PARTIAL. Rules and checkout redemption existed. No expiry, no minimum redemption, no row lock inside the loyalty service, no points multiplier. | `LoyaltyService`, `LoyaltyEngine`, `CreateSale`, `ConfirmSale` |
| Customer balance and history | PARTIAL. Purchase history embedded a ledger. No dedicated customer loyalty endpoint. | `CustomerController::purchaseHistory` |
| Seasonal care WhatsApp | PARTIAL. Rules, a daily job, and a per sale send log existed. No named Indian season, no season-start timing, no opt-out, no daily rate limit, no send-log API. | `SeasonalCareService`, `care:send-seasonal` |

## What was added

Existing accrual on sale confirm, cancel, and return was left in place. The new behaviour is configured on the same rules.

- Salesman slabs can be `marginal` (default, unchanged) or `flat`, over a `daily` or `monthly` period (`tier_mode`, `tier_period` on `commission_rules`). Flat means the whole period total is paid at the single slab it falls in. The new sale stores only the difference from what was already accrued, so older ledger rows are not rewritten.
- Daily target tiers accept `bonus_percent` as well as a fixed `bonus_amount`. `target_scope = branch` measures the day's net sales for the rule's branch instead of the salesman's own sales. The bonus is still credited to the staff member on the rule.
- Supervisor rates can be set per product or per category (`percent` or `per_unit`), with effective dates. The supervisor's own commission rule is the fallback. Each production order and sale or transfer is stored once (`idempotency_key`). Intra-company transfer dispatch now accrues as well.
- Manager commission is written per day (`source_type = branch-day`) and the existing monthly job still writes `branch-period`. Summaries use the monthly row when it exists, otherwise the sum of the daily rows, so the two are not added together.
- Ledger rows now store `role`, `branch_id`, `rule_id`, and `period`. Status stays `accrued` (payable) and `reversed`. Paid amounts stay on `commission_payouts`, so marking ledger rows paid does not wipe the monthly total.
- Staff with only `commission.view_own` see their own summary and ledger. A manager role with a branch-specific manager rule sees that branch plus their own rows. Administrators and anyone with `commission.view` who is not limited that way see the company.
- End-of-day WhatsApp runs every 15 minutes and sends once the company-local clock reaches `eod_commission_send_time` (default 20:00, timezone `company_timezone`, default `Asia/Kolkata`). The message includes month-to-date sales and incentive. Failed sends are not duplicated by the next tick; `whatsapp:retry-failed` retries them with exponential backoff. `POST /api/commission/send-eod` with `force` is the admin re-trigger.
- Loyalty redemption enforces `loyalty_redeem_min_points`, locks the customer row, and consumes the oldest earn lots before expiry. `loyalty_points_expiry_days` (0 means never) sets `expires_at`. `loyalty:expire-points` writes `expire` ledger rows once. Product and category rules can set `points_multiplier`.
- Customers have `whatsapp_opt_in` (default on, so existing customers still receive care messages). Seasonal rules can name `summer`, `monsoon`, or `winter`, and can send on `season_start` (the first three days of 1 Mar, 1 Jun, and 1 Oct) or N days after purchase. Sends are capped per company per day and logged per customer, plant, and season.

`App\Contracts\WhatsAppSender` is the provider-agnostic send contract. `WhatsAppService` is the adapter.

## Tables

New:

- `supervisor_commission_rates`

Columns added:

- `commission_rules`: `tier_period`, `tier_mode`, `target_scope`
- `commission_daily_target_tiers`: `bonus_percent`
- `commission_transactions`: `role`, `branch_id`, `rule_id`, `period`
- `supervisor_commission_entries`: `idempotency_key` (unique)
- `loyalty_rules`: `points_multiplier`
- `loyalty_ledger`: `remaining_points`, `expires_at`, `expires_processed_at`
- `customers`: `whatsapp_opt_in`
- `seasonal_care_rules`: `season`, `timing`
- `seasonal_care_sends`: `product_id`, `season`, `season_year`
- `whatsapp_message_logs`: `next_retry_at`

The ledger table remains `commission_transactions`. It already had the idempotency unique key `commission_tx_dedupe`.

## Endpoints

All sit under the existing authenticated company API.

| Method | Path | Who |
| --- | --- | --- |
| GET | `/api/commission/summary?user_id&from&to` | Own row, or branch/company as above |
| GET | `/api/commission/transactions` | Same. Filters: `user_id`, `from`, `to`, `status`, `role`, `commission_type`, `branch_id` |
| GET | `/api/commission/supervisor-rates` | `commission.view` |
| POST | `/api/commission/supervisor-rates` | `commission.manage` |
| GET | `/api/commission/seasonal-care-sends` | `commission.manage` |
| GET | `/api/loyalty/customers/{customer}` | `loyalty.view` or `customers.view` |
| POST | `/api/commission/send-eod` | `commission.manage` (existing re-trigger; pass `force=1` to send again) |

Existing rule, tier, daily-target, promotion, manager-rule, and seasonal-care create endpoints accept the new fields. Customer create/update accepts `whatsapp_opt_in`. Settings accept the keys below.

## Jobs

| Command | Schedule |
| --- | --- |
| `commission:send-eod` | Every 15 minutes; sends after the company EOD time |
| `commission:accrue-manager-daily` | Daily at 00:20 for the previous day |
| `commission:accrue-manager` | 1st of the month at 07:00 (existing monthly rollup) |
| `loyalty:expire-points` | Daily at 00:40 |
| `care:send-seasonal` | Daily at 10:00 (existing) |
| `whatsapp:retry-failed` | Hourly, now with backoff |

The EOD sender also accrues that day's manager commission before it builds the message, so the WhatsApp text includes today's branch commission without waiting for the midnight job.

## Config and environment

Company settings (database, not secrets):

| Key | Default |
| --- | --- |
| `eod_commission_send_time` | `20:00` |
| `company_timezone` | `Asia/Kolkata` |
| `loyalty_redeem_min_points` | `0` (no minimum) |
| `loyalty_points_expiry_days` | `0` (do not expire) |
| `seasonal_care_rate_limit` | `40` sends per company per day |
| `loyalty_earn_rupees`, `loyalty_earn_points`, `loyalty_redeem_rupees`, `loyalty_redeem_cap_percent` | Already existed |

`.env.example`:

- `APP_TIMEZONE=Asia/Kolkata` (application timezone; `config/app.php` reads it)
- `WHATSAPP_TEMPLATE_EOD_COMMISSION=eod_commission`
- `WHATSAPP_TEMPLATE_SEASONAL_CARE=seasonal_care`
- Existing `WATI_*` and `TWILIO_*` keys

Rates and thresholds are not in the environment. They live on the commission, loyalty, and settings tables. `SupplementalDataSeeder` adds sample slabs and summer, monsoon, and winter care copy.

## Assumptions

- Tiers are marginal unless `tier_mode` is `flat`. The period is the calendar month unless `tier_period` is `daily`.
- A flat slab that a later sale crosses is caught up on that sale's ledger row. Previous rows stay as they were, which is the effective-date behaviour: changing a rule does not recalculate history.
- `accrued` means payable. `reversed` means taken back. A payout record is what marks commission paid.
- Branch daily targets pay the user on the commission rule, using that rule's `location_id` (or the whole company when it is null).
- A manager without a branch-specific rule, and anyone with `commission.view` who is not in the manager role, can read the whole company. Salesman and supervisor roles only have `commission.view_own`.
- Intra-company transfers accrue supervisor commission the same way inter-company dispatch already did.
- Customers are opted in to care WhatsApp until `whatsapp_opt_in` is turned off.
- Indian seasons are summer (Mar–May), monsoon (Jun–Sep), and winter (Oct–Feb). Winter uses one year key so January belongs to the previous October.
- Loyalty expiry of `0` keeps the previous "points do not expire" behaviour.
- The production summary report qualified `company_id` and `status` after a join to `products`. SQLite rejected the unqualified columns. That query was not part of the commission feature; it was the only full-suite failure.

## How to run

From `PotAndLeaf-backend`:

```bash
php artisan migrate
php artisan db:seed
php vendor/bin/pest
php artisan commission:accrue-manager-daily
php artisan commission:accrue-manager
php artisan commission:send-eod
php artisan loyalty:expire-points
php artisan care:send-seasonal
php artisan whatsapp:retry-failed
```

The scheduler is `php artisan schedule:work` (or a system cron calling `php artisan schedule:run` every minute).

## Known gaps

- There is no separate approval step between `accrued` and payout. Admins record payment on the existing payout API.
- Supervisor entries still have no branch column, so a branch manager sees the company's supervisor ledger rather than one branch's rows.
- WhatsApp outside the 24-hour session window still depends on an approved template configured in WATI or Twilio. The app stores the template body and sends session text through the existing adapter.
- Point multipliers scale a product or category rule's own points. A company-wide spend rule still runs as well, so stacking both on the same rupees adds them together.
- Seasonal care does not retry a failed customer send inside the same day beyond `whatsapp:retry-failed`.
