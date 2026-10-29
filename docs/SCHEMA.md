# Kims Properties — Database Schema (v1)

Laravel migrations live in `database/migrations/`. Copy them into a fresh Laravel 11/12 project
(they extend Laravel's default `users` table rather than replacing it) and run `php artisan migrate`.
Target database: MySQL 8 or PostgreSQL. All money is `decimal(12,2)` in the account's currency (KES).

## Entity relationships

```mermaid
erDiagram
    accounts ||--o{ users : "staff (owner, caretakers…)"
    accounts ||--o{ properties : owns
    accounts ||--o{ tenants : "has"
    properties ||--o{ units : contains
    properties ||--o{ charge_rates : "billing settings"
    units ||--o{ charge_rates : "optional override"
    units ||--o{ meters : has
    meters ||--o{ meter_readings : records
    units ||--o{ leases : "occupied via"
    tenants ||--o{ leases : signs
    tenants ||--o{ tenant_auth_tokens : "invites / resets"
    leases ||--o{ invoices : billed
    invoices ||--o{ invoice_items : lines
    leases ||--o{ payments : receives
    accounts ||--o{ billing_runs : "job log"
    billing_runs ||--o{ invoices : generates
    invoices ||--o{ notification_deliveries : "sent via"
```

## Tables at a glance

| Table | Purpose |
|---|---|
| `accounts` | One landlord business — the multi-tenancy boundary. Holds cycle length (30 days by default), due-days, currency, channel settings. |
| `invoices` (void) | `voided_at`, `voided_by`, `void_reason`; `billing_key` = `"{lease_id}:{period_start}"` is unique for live bills (null when void), so a voided period can be billed again. |
| `users` | Admin portal logins. `role` = super_admin (no account) / staff; `status` = active / invited / suspended. |
| `roles`, `permissions`, `model_has_roles`, `role_has_permissions` | Spatie. Roles with `account_id` NULL are built-in templates; others belong to one account. `model_has_roles.account_id` = the account the role applies in. |
| `properties` | Buildings/plots a landlord owns. |
| `units` | Houses inside a property (`7-7`), with asking rent and occupancy status. |
| `charge_rates` | Landlord settings: electricity & water price per unit (metered), garbage fee (fixed), plus any extra charges. Versioned by date; optional per-unit override. |
| `tenants` | Tenant portal logins: email **or** national ID + password. Password is null until the invitation is accepted. |
| `tenant_auth_tokens` | Hashed one-time links for invitations and password resets. |
| `leases` | Tenant ↔ unit occupancy. **Rent per occupied house** lives here (`monthly_rent`), as does `next_billing_date`. |
| `meters` / `meter_readings` | Electricity and water meters per unit and their readings each cycle. |
| `billing_runs` | Audit log of each billing-job execution per account. |
| `invoices` / `invoice_items` | The monthly bill and its lines, with readings and rates snapshotted. |
| `payments` | Money received (M-Pesa, bank, cash) against a lease. A confirmed payment gets `receipt_number` (`RCT-{year}-000001`, per account) and `balance_after` (the lease balance just after it, frozen for the receipt). |
| `notification_deliveries` | One row per message per channel (email / SMS / WhatsApp) with delivery status. |

## How the paper bill maps to the schema

| Paper bill | Stored as |
|---|---|
| House No 7-7 | `units.unit_number` |
| Rent 15,000 | `invoice_items` (charge_code `rent`, rate = `leases.monthly_rent`) |
| Electricity 100 → 180, 80 units × 30 = 2,400 | `invoice_items` with previous/current reading, quantity 80, rate 30 |
| Water 70 → 75, 5 units × 170 = 850 | same, charge_code `water` |
| Garbage 250 | `invoice_items` (fixed `charge_rates` row) |
| Arrears 1,000 | `invoices.balance_brought_forward` |
| Total 19,500 | `invoices.total_due` = current_charges 18,500 + arrears 1,000 |

## Billing (billing day + move-in bill)

Rules: `app/Services/Billing/BillingCalendar.php` (decisions #5 and #6 in CLAUDE.md).

**Which day a lease bills on** (`LeaseService::billingDayForUnit`): `units.billing_day` ?? `properties.billing_day` ??
`accounts.billing_day` (1–28 each; null = inherit). Changing any of them moves each affected running lease's
`next_billing_date` to the first new billing day on or after it (`LeaseService::reschedule`); a unit or property with its
own day is not moved by an account (or property) change. `last_billed_on` stays, so that next bill also carries a
**gap line** (`charge_code = rent_adjustment`, "Rent 5 Nov – 19 Nov (15 of 30 days)", quantity = days) for the days
between: rent × days ÷ 30, rounded to the shilling, max one month (`BillingCalendar::gapRent`). Voiding such a bill
rolls `last_billed_on` back over the gap too, so the corrected bill charges it again.

**Move-in bill** (`CreateMoveInInvoice`, made with the lease by `MoveInService`, `invoices.type = move_in`):
deposit (once, `leases.deposit_invoiced_at`) + rent × days(move-in → next billing day) ÷ 30, rounded to the shilling,
max one month (moving in on the billing day = a full month). Due on move-in day. Sets `next_billing_date` = first billing day.

**Monthly bill** (`GenerateDueInvoices` daily 06:00 → `BillingRunRequested` per active account → `BillingService`):
1. A `billing_runs` row per account.
2. Leases where `status IN ('active','notice') AND next_billing_date <= today`.
3. For each lease (in a DB transaction), `InvoiceBuilder`:
   - Period = `next_billing_date` → day before the following billing day (rent in advance, e.g. 5 Nov – 4 Dec).
   - **Rent** line from `leases.monthly_rent` ("Rent 5 Nov – 4 Dec"); plus a `rent_adjustment` gap line when
     `last_billed_on + 1 day` is before the period start (after a billing-day change, see above).
   - **Metered** lines: latest reading on or before the billing day vs the last billed reading (or the move-in reading,
     or `initial_reading`); rate = the `charge_rates` row effective on the billing day (unit override first). A missing
     reading skips the lease and logs it in `billing_runs.errors`; the next daily run retries it.
   - **Fixed** lines: every other effective fixed `charge_rates` row (garbage, security…) for the coming month.
   - **Per-unit utility billing:** a unit can pay a fixed monthly amount for a metered utility instead of the meter
     (unit form, `utilities`): a fixed unit override of that `charge_code`. Switching applies from the unit's next bill
     (today if vacant). Because fixed bills the coming month and metered the past month, the switch-over bill follows
     `ChargeRateService::forBill()`: metered → fixed bills the last usage AND the first fixed amount; fixed → metered
     bills neither, and the next bill counts usage from the reading on the day metering restarted
     (`ChargeRateService::meteredSince()`, used by `MeterReadingService::pendingReading()`). Fixed utilities need no
     meter, opening reading or monthly reading. A change no bill used and not started yet is discarded (not stacked)
     when edited again; billed versions never change.
   - `balance_brought_forward` = lease balance (see Payments).
   - `billing_key` = `"{lease}:{period_start}"` (unique, null when void) makes a re-run harmless.
   - `last_billed_on` = period end, `next_billing_date` = next billing day.
4. `InvoiceIssued` → one `notification_deliveries` row per channel → queued send, retried on failure.

New columns: `accounts.billing_day`, `properties.billing_day` / `units.billing_day` (nullable overrides), `invoices.type` (move_in | monthly | adjustment), `leases.deposit_invoiced_at`,
`leases.onboarded_on` (tenants already living there when the landlord joined: their first bill counts usage from the
reading taken that day; no move-in bill).

## Payments and arrears

Balances run per lease, like a statement:

```
lease balance = opening_balance + Σ invoices.current_charges (not void) − Σ payments.amount (confirmed)
```

The next invoice snapshots this as its arrears line. A payment carries the `invoice_id` of the bill
that was open when it arrived, which updates that invoice's `amount_paid` and status
(`partially_paid` / `paid`). Overpayments simply produce a negative balance (credit) on the next bill.

## Design decisions

**Confirmed (13 Oct 2026):**

1. **Tenants are scoped per landlord account.** Landlords cannot see whether a person rents elsewhere. A person renting from two landlords has two records; the portal login asks which landlord if their email/ID matches both.
2. **Rates are versioned, not edited.** Changing the electricity price closes the old `charge_rates` row and opens a new one, so past bills stay reproducible.
3. **Arrears are a running balance per lease** (see Payments and arrears above).

**Working assumptions (not yet discussed):**

4. **Rent lives on the lease**, `units.default_rent` is only the pre-fill when creating a lease.
5. **Tenant isolation in code:** every landlord-owned model should use a `BelongsToAccount` trait with a global scope on `account_id`, set from the logged-in user. Super admins bypass the scope.
6. **Auth:** two guards — `web`/Sanctum for `users` (admin portal) and a `tenant` guard for `tenants` (tenant portal), both serving the React SPA via Sanctum.

## Not yet included (natural next tables)

Expenses/maintenance requests, deposit refunds, M-Pesa C2B/STK callback logs, per-account SMS/WhatsApp credentials (currently in `accounts.settings`), and a general `audit_logs` table.
