# Rentflow — Rental Management System

Multi-landlord rental management SaaS for Kenya. Landlords manage properties, units, tenants and
monthly bills; tenants view bills and payments in a separate portal. The first landlord account is
Kims Properties (`Database\Seeders\KimsPropertiesSeeder`).

- **Backend (this repo):** Laravel 13, PHP 8.3+, Sanctum SPA auth, MySQL 8 / MariaDB for dev, SQLite in-memory for tests
- **Frontends:** `../admin` (admin portal) and `../portal` (tenant portal), React + TypeScript, not started yet
- **Currency/locale:** KES, Africa/Nairobi (per account), phone numbers in E.164 (+2547…)
- **Installed but not used yet:** Horizon (needs Redis), Socialite, Folio, spatie/laravel-permission (roles are the `users.role` enum, not Spatie)

Design notes: `docs/SCHEMA.md` (tables, ERD, billing logic) and `docs/SETUP.md` (how the config is wired).

## Two portals, two guards

- **Admin portal** — `users` table, `web`/Sanctum guard, routes under `api/admin` with `auth:sanctum` + `account`.
  Roles (`App\Enums\UserRole`): `super_admin` (no account, sees all), `landlord`, `caretaker`.
- **Tenant portal** — `tenants` table, `tenant` guard (provider `accountless-eloquent`), routes under
  `api/portal` with `auth:tenant` + `account`. Login with email OR national ID + password.
  Landlord creates the tenant and sends an invitation link (`tenant_auth_tokens`); the tenant sets a password from it.

## Confirmed decisions — do not change without asking

1. **Tenants are scoped per landlord account.** A person renting from two landlords has two
   `tenants` rows. `Tenant::findForPortalLogin()` returns all matches; the login UI asks which landlord.
2. **Rates are versioned, never edited.** `charge_rates` rows are frozen once created (only
   `effective_to` may change). Change a price with `ChargeRate::change()`. Unit-level rows override
   property-wide ones (`ChargeRate::resolve()`).
3. **Arrears are a running balance per lease:**
   `opening_balance + Σ invoices.current_charges (not void) − Σ confirmed payments`
   (`Lease::balance()`). Each new bill snapshots it into `invoices.balance_brought_forward`.
4. **Authorization** uses the `users.role` enum + policies (caretakers only see their properties).

Working assumption: rent is configured per occupancy on `leases.monthly_rent`
(`units.default_rent` is only a pre-fill).

## Conventions

- **Multi-tenancy:** every landlord-owned model uses `App\Models\Concerns\BelongsToAccount`.
  The `AccountScope` reads `App\Support\CurrentAccount` and **fails closed** — with no context,
  queries return nothing. HTTP requests get context from the `account` middleware
  (`SetCurrentAccount`). Jobs, commands, seeders and tests must set it explicitly:
  `app(CurrentAccount::class)->actingAs($account, fn () => ...)`.
  Use `withoutScope()` only for genuinely cross-account work (super admin, tenant login lookup).
- **Seeders must not use `WithoutModelEvents`** — model events fill `account_id` and lease billing dates.
- **Money:** never use floats for arithmetic; use `App\Support\Money` (integer cents).
  DB columns are `decimal(12,2)`.
- **Bills snapshot everything:** invoice items copy readings, quantity and rate so old bills never change.
- Status fields use backed enums in `app/Enums`.
- Morph map is enforced (`AppServiceProvider`); add new polymorphic models to it.

## Reference bill (test fixture: `tests/Feature/ReferenceBillTest.php`)

House 7-7: Rent 15,000 · Electricity 100→180 = 80 units × 30 = 2,400 · Water 70→75 = 5 × 170 = 850 ·
Garbage 250 · current charges 18,500 · Arrears 1,000 · **Total 19,500**.
The test assembles the bill by hand (`billFirstLease()`); the billing job must produce the same invoice.

## Next steps (in order)

1. **Billing job** — `GenerateDueInvoices` scheduled daily (~06:00 Nairobi). Per account, inside
   `actingAs`: create a `billing_runs` row, pick `Lease::dueForBilling()`, build rent + metered
   (from `meters` readings × resolved rate) + fixed lines (metered before fixed), set arrears from
   `Lease::balance()`, number with `Invoice::nextNumber()`, advance the lease cycle (29 days,
   `accounts.billing_cycle_days`). Skip leases missing a reading and log via `BillingRun::recordSkip()`.
   Must be idempotent (unique `lease_id + period_start`). Lift `billFirstLease()` out of the test into it.
2. **Bill notifications** — Laravel notifications on email, SMS (Africa's Talking) and WhatsApp
   (Meta Cloud API), one `notification_deliveries` row per channel, retry only failures.
3. **Admin API** — properties, units, charge rates, tenants + invitations, leases, meter readings,
   payments; policies so caretakers only see their properties.
4. **Tenant portal API** — login (with landlord choice), accept invitation, bills, payments.
5. **M-Pesa** — STK push / C2B callbacks creating `payments`.
6. React + TypeScript frontends.

<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>
