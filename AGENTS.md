# Rentflow — Rental Management System

Multi-landlord rental management SaaS for Kenya. Landlords manage properties, units, tenants and
monthly bills; tenants view bills and payments in a separate portal. The first landlord account is
Kims Properties (`Database\Seeders\KimsPropertiesSeeder`).

- **Backend (this repo):** Laravel 13, PHP 8.3+, Sanctum SPA auth, MySQL 8 / MariaDB for dev, SQLite in-memory for tests
- **Frontends:** `../admin` (admin portal) and `../portal` (tenant portal), React + TypeScript, not started yet
- **Currency/locale:** KES, Africa/Nairobi (per account), phone numbers in E.164 (+2547…)
- **Installed but not used yet:** Horizon (needs Redis), Socialite, Folio

Design notes: `docs/SCHEMA.md` (tables, ERD, billing logic) and `docs/SETUP.md` (how the config is wired).

## Two portals, two guards

- **Admin portal** — `users` table, `web`/Sanctum guard, routes under `api/admin` with `auth:sanctum` + `account`.
  `users.role` (`App\Enums\UserRole`) is only `super_admin` (no account, sees all) or `staff`; what staff may do comes
  from Spatie roles + permissions per account (see decision #4).
- **Tenant portal** — `tenants` table, `tenant` guard (provider `accountless-eloquent`), routes under
  `api/portal` with `auth:tenant` + `account`. Login with email OR national ID + password.
  Landlord creates the tenant and sends an invitation link (`tenant_auth_tokens`); the tenant sets a password from it.

## Confirmed decisions — do not change without asking

1. **Tenants are scoped per landlord account.** A person renting from two landlords has two
   `tenants` rows. `TenantService::findForPortalLogin()` returns all matches; the login UI asks which landlord.
2. **Rates are versioned, never edited.** `charge_rates` rows are frozen once created (only
   `effective_to` may change). Change a price with `ChargeRateService::change()`. Unit-level rows override
   property-wide ones (`ChargeRateService::resolve()`).
3. **Arrears are a running balance per lease:**
   `opening_balance + Σ invoices.current_charges (not void) − Σ confirmed payments`
   (`LeaseLedgerService::balance()`). Each new bill snapshots it into `invoices.balance_brought_forward`.
4. **Authorization = Spatie roles/permissions per landlord account** (teams, key `account_id`; `CurrentAccount` is the
   team resolver). Permissions are the `App\Enums\Permission` catalogue (`php artisan permissions:sync`). Built-in role
   templates (`App\Enums\SystemRole`: Owner locked/all, Manager, Caretaker, Accountant) have `account_id` NULL and are
   edited only by super admins; landlords (`roles.manage`) make their own roles. Policies check `$user->can(Permission::X->value)`;
   property scope = `properties.view_all`, else only properties where `caretaker_id` = them (`Services\Properties\Visibility`).
   Every account keeps an active Owner; nobody changes their own roles or suspends/removes themselves.

Working assumption: rent is configured per occupancy on `leases.monthly_rent`
(`units.default_rent` is only a pre-fill).

## Architecture — event driven (read `docs/ARCHITECTURE.md`)

- **Controllers** validate (FormRequest + `toData()` DTO), authorize, dispatch ONE intent event
  (`app/Events/Commands`, `*Requested`/`*Submitted`) and return **202**. They never write.
- **Listeners** (`app/Listeners`, auto-discovered) handle intent events by calling a service.
  Intent listeners are sync by default so service validation errors still return 422.
- **Services** (`app/Services/<Domain>`) hold ALL domain logic and writes, and dispatch past-tense
  fact events (`app/Events`, `ShouldDispatchAfterCommit`) for side effects.
- **Models** are data only: fillable, casts, relations, query-free predicates. No `booted()` hooks,
  no queries, no writes. `tests/Unit/ArchitectureTest.php` enforces this.

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
Produced end to end by `php artisan billing:run --date=2026-10-01` after `migrate:fresh --seed`
(lease from 1 Sep, 30-day cycle → bill for 1–30 Sep, due 6 Oct).

## Done

- Billing: `billing:run` (scheduled daily 06:00 Nairobi) and `POST /api/admin/billing-runs` →
  `BillingRunRequested` → queued `BillingService`. Idempotent; skips leases missing readings.
- **30-day cycle** by default (`accounts.billing_cycle_days`), billed in arrears from each lease's move-in date.
- Bill emails: `InvoiceIssued` → `QueueInvoiceNotifications` → `DeliverNotification` → `InvoiceIssuedMail`
  (HTML + PDF via `InvoicePdf`), one `notification_deliveries` row per channel, never sent twice;
  `notifications:retry-failed` resends failures. Needs the scheduler + a queue worker (`docs/SETUP.md`).
- **API docs (Scalar)** at `/docs` (local/staging), generated by Scramble from controllers, FormRequests
  and Resources. Get a token with `POST /api/admin/auth/token` (the one sync controller, see
  `docs/ARCHITECTURE.md`). Keep controller PHPDoc + rule docblocks up to date: they ARE the docs.
- **WhatsApp bills** from each landlord's OWN number (Meta WhatsApp Cloud API, `docs/WHATSAPP.md`):
  `PUT /api/admin/whatsapp` stores encrypted credentials; template `rentflow_bill_issued` (PDF header +
  7 variables, order matters); delivery webhook `/api/webhooks/whatsapp/{slug}` signed with the
  landlord's app secret. Default channels: email + WhatsApp. Meta is always mocked in tests (`Http::fake`).
- Payments: `POST /api/admin/payments` → `PaymentSubmitted` → `PaymentService` → `PaymentRecorded`
  → `RefreshInvoicePaymentStatus`. Reads: `GET /api/admin/payments`, `GET /api/admin/invoices/{id}`.

## Next steps (in order)

1. **More notifications** — SMS (Africa's Talking) sender; payment receipts (`PaymentRecorded`) and
   tenant invitations (`TenantInvitationIssued`) by email + WhatsApp (new templates).
2. **Rest of the admin API** in the same pattern — properties, units, charge rates, tenants +
   invitations, leases, meter readings; policies so caretakers only see their properties.
3. **SPA auth endpoints** (cookie login/logout for the React portals, both guards; bearer tokens for the admin API exist).
4. **Tenant portal API** — login (with landlord choice), accept invitation, bills, payments.
5. **M-Pesa** — STK push / C2B callbacks dispatching `PaymentSubmitted`.
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
