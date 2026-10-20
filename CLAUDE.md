# Rentflow — Rental Management System

Multi-landlord rental management SaaS for Kenya. Landlords manage properties, units, tenants and
monthly bills; tenants view bills and payments in a separate portal. The first landlord account is
Kims Properties (`Database\Seeders\KimsPropertiesSeeder`).

- **Backend (this repo):** Laravel 13, PHP 8.3+, Sanctum SPA auth, MySQL 8 / MariaDB for dev, SQLite in-memory for tests
- **Frontends:** `../frontend`, a pnpm + Turborepo monorepo: `apps/admin` (:5173) and `apps/portal` (:5174), React + TypeScript,
  shared packages `@rentflow/ui|api|core|config` (see `../frontend/CLAUDE.md`)
- **Currency/locale:** KES, Africa/Nairobi (per account), phone numbers in E.164 (+2547…)
- **Installed but not used yet:** Horizon (needs Redis), Socialite, Folio

Design notes: `docs/SCHEMA.md` (tables, ERD, billing logic) and `docs/SETUP.md` (how the config is wired).

## Two portals, two guards

- **Admin portal** — `users` table, `web`/Sanctum guard, routes under `api/admin` with `auth:sanctum` + `actor:user` + `account`.
  `users.role` (`App\Enums\UserRole`) is only `super_admin` (no account, sees all) or `staff`; what staff may do comes
  from Spatie roles + permissions per account (see decision #4).
- **Tenant portal** — `tenants` table, `tenant` guard (provider `accountless-eloquent`), routes under
  `api/portal` with `auth:tenant,sanctum` + `actor:tenant` + `account` (cookie session on the web, API token
  in the Android/iOS apps). Login with email OR national ID + password.
- **`actor` middleware** (`EnsureActor`) keeps each portal to its own login type: a tenant's token can't reach
  `api/admin`, an admin token/session can't act as a tenant (both 401). Add it to any new route group.
- **API tokens** (`App\Models\PersonalAccessToken`, abilities `admin` / `portal`) load their owner without the
  account scope, because the token is what establishes the account. CORS (`config/cors.php`,
  `CORS_ALLOWED_ORIGINS`) allows the app origins `https://localhost` + `capacitor://localhost`; never put bare
  `localhost` in `SANCTUM_STATEFUL_DOMAINS`.
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
5. **Billing day, not a rolling cycle.** Each account bills on `accounts.billing_day` (1–28, default 5) every month.
   Monthly bill = rent + fixed charges for the coming month (billing day → day before the next) + metered usage up to
   the billing day + arrears.
6. **Move-in bill = deposit + prorated rent**: rent × days(move-in → next billing day) ÷ **30** (always 30), rounded to
   the shilling, max one month; moving in on the billing day = a full month. Made with the lease (`MoveInService` →
   `CreateMoveInInvoice`). Rules live in `app/Services/Billing/BillingCalendar.php` only (mirrored in
   `../frontend/packages/core/src/billing.ts`; keep `BillingCalendarTest` and `billing.test.ts` in step).

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
This is the **5 Nov monthly bill**: rent 5 Nov – 4 Dec, usage up to 4 Nov's readings, due 10 Nov. Produced end to end by
`php artisan billing:run --date=2026-11-05` after `migrate:fresh --seed` (Kims bills on the 5th; Jane moved in 1 Sep,
before Rentflow). Move-in fixture (`tests/Feature/MoveInBillTest.php`): move in 20 Oct, rent 15,000 → deposit 15,000 +
rent 20 Oct – 4 Nov (16 of 30 days) 8,000 = **23,000**.

## Done

- Billing: `App\Jobs\GenerateDueInvoices` (scheduled daily 06:00 Nairobi in `routes/console.php`; one
  `BillingRunRequested` per active account), `billing:run` by hand, and `POST /api/admin/billing-runs` →
  `BillingRunRequested` → queued `BillingService`. Idempotent; skips leases missing readings.
- **Billing day** (`accounts.billing_day`, decision #5; `LeaseService::nextBillingPeriod/advanceCycle`), **move-in bills**
  (`CreateMoveInInvoice`, `invoices.type` = `move_in`, `leases.deposit_invoiced_at` guards the deposit). Voiding a monthly
  bill rolls the lease back to that billing day; voiding a move-in bill frees the deposit but isn't re-made automatically yet.
- Bill emails: `InvoiceIssued` → `QueueInvoiceNotifications` → `DeliverNotification` → `InvoiceIssuedMail`
  (HTML + PDF via `InvoicePdf`), one `notification_deliveries` row per channel, never sent twice;
  `notifications:retry-failed` resends failures. Needs the scheduler + a queue worker (`docs/SETUP.md`).
- **API docs (Scalar)** at `/docs` (local/staging), generated by Scramble from controllers, FormRequests
  and Resources. Get a token with `POST /api/admin/auth/token` (a sync controller, see
  `docs/ARCHITECTURE.md`). Keep controller PHPDoc + rule docblocks up to date: they ARE the docs.
- **WhatsApp bills** from each landlord's OWN number (Meta WhatsApp Cloud API, `docs/WHATSAPP.md`):
  `PUT /api/admin/whatsapp` stores encrypted credentials; template `rentflow_bill_issued` (PDF header +
  7 variables, order matters); delivery webhook `/api/webhooks/whatsapp/{slug}` signed with the
  landlord's app secret. Default channels: email + WhatsApp. Meta is always mocked in tests (`Http::fake`).
- **SPA cookie auth** (Sanctum) for both portals: `POST /api/admin/auth/login|logout` (`AdminAuthService`,
  shared with the token login) and `POST /api/portal/auth/login|logout` (`TenantAuthService`; several landlords
  matching → `{choose_home: true, homes}`, post again with `tenant_id`). Logout only ends that guard and
  regenerates the session, so an admin and a tenant login can share a browser. `GET /api/portal/user` → `TenantResource`.
- **App tokens** (Android/iOS via Capacitor, `../frontend/apps/*/android|ios`): `POST|DELETE /api/portal/auth/token`
  (`TenantAuthService::issueToken`, same choose-home flow) and the existing `POST|DELETE /api/admin/auth/token`.
- **Landlord sign-up** `POST /api/admin/auth/register` → `LandlordRegistrationSubmitted` → `AccountRegistrationService`
  (new `Account` + landlord `User`, phone normalized by `App\Support\Phone`) → `LandlordRegistered`. Returns 202;
  the page then logs in normally. Tenants never sign up (they're invited).
- **Continue with Google** (existing logins only): `POST /api/{admin,portal}/auth/google[/token]`, ID token checked
  by `GoogleIdTokenVerifier` (`GOOGLE_CLIENT_IDS`, see `docs/SETUP.md`). Tests fake Google with `FakesGoogleSignIn`.
- Payments: `POST /api/admin/payments` → `PaymentSubmitted` → `PaymentService` → `PaymentRecorded`
  → `RefreshInvoicePaymentStatus`. Reads: `GET /api/admin/payments`, `GET /api/admin/invoices/{id}`.
- **Admin API** (all `api/admin`, permission policies + `Services\Properties\Visibility`):
  properties/units/meters (`PropertyService`, `UnitService`, `MeterService`; replacing a meter deactivates the old one),
  tenants (`TenantDirectory` reads, edit, resend invitation), **move-in** `POST units/{unit}/move-in` → `MoveInService`
  (tenant + lease + unit occupied + opening readings + invitation, one transaction), **move-out** `POST leases/{lease}/end`,
  **charge rates** `GET|POST properties/{property}/charge-rates` (versioned via `ChargeRateService::change`, unit overrides
  inherit the property charge), **account billing settings** `GET account`, `PUT account/billing`, **meter readings**
  `GET|POST readings` (`ReadingTasks`, batch save, same-day typo fix unless billed), **dashboard** `GET dashboard`
  (`DashboardService`). Lists use `LeaseLedgerService::balances()` (batched).
- **One reading rule for billing and the readings screen:** `MeterReadingService::pendingReading()` — previous = last billed
  reading, else the move-in reading (on/before lease start), else the meter's initial reading; so a first bill never charges
  usage from before move-in, and a lease without a newer reading is skipped.
- **Tenant invitations:** `TenantInvitationService` issues the link → `TenantInvitationIssued` → queued `SendTenantInvitation`
  → `TenantInvitationMail` (`{PORTAL_URL}/invite/{token}`, 72 h). Portal: `GET|POST /api/portal/invitations/{token}` sets the
  password and logs in (session, or token with `device_name`).
- **Meter readings & setup:** `GET readings?days=0..31|all&property_id&search` (`ReadingTasks`, any time, not just 7 days),
  `POST readings` with `reading_date` (same meter + same day = correction unless billed), `GET meters/{id}/readings`
  (history + which bill used each) and `PUT meter-readings/{id}` (`MeterReadingService::correct`, never a billed reading).
  Bulk meter setup: `GET properties/{id}/meter-setup`, `POST properties/{id}/meters` (`MeterService::setup/fitMany`, all or none,
  errors `meters.N.*`). **Existing tenants** (landlord joining Rentflow): move-in with `lease.existing` → no move-in bill,
  deposit marked held, opening readings dated today, `leases.onboarded_on` = today (first bill counts usage from that reading),
  first bill = `lease.first_bill_date` ?? next billing day. Getting-started checklist: `GET setup` (`AccountSetupProgress`).
- **Bills (admin):** `GET invoices` (`InvoiceDirectory`: search, `status=unpaid|…`, `property_id`, `month`, `meta.summary`
  totals; scoped by `Visibility`), `GET invoices/{id}` (+ `deliveries`, void info), `GET invoices/{id}/pdf` (`InvoicePdf`),
  `POST invoices/{id}/resend` (`InvoiceNotificationService::resend`, new `bill_resent` deliveries), `POST invoices/{id}/void`
  (`InvoiceVoidService`: latest bill of the lease, nothing paid; sets void + reason, clears `billing_key`, rolls the lease back
  one cycle so the next run re-bills the period). Resend/void need permission `invoices.manage` (Manager, Accountant, Owner).
  Idempotency is now `invoices.billing_key` (unique, `"{lease}:{period_start}"`, null when void), not unique(lease_id, period_start).
- **Team & roles:** `GET permissions`, `GET|POST|PUT|DELETE roles` (`RoleService`), `GET|POST team`, `PUT team/{user}`,
  `PATCH team/{user}/status`, `POST team/{user}/invitation`, `DELETE team/{user}` (`TeamService`). Invites create a user with
  status `invited` → `StaffInvited` → queued `SendStaffInvitation` → `AdminPasswordLinkMail` (`{ADMIN_URL}/set-password`,
  broker `staff_invites`, 72 h). `POST auth/forgot-password` (60 min link) and `POST auth/set-password` (sync, logs in).
- **Platform (super admins, `api/platform/*`, `super` middleware; services in `app/Services/Platform`):**
  `GET overview` (`PlatformOverview`); accounts `GET|POST accounts`, `GET|PUT accounts/{id}`, `PATCH accounts/{id}/status`
  (active/suspended/closed: revokes the account's app tokens; login + billing already refuse non-active accounts),
  `POST accounts/{id}/billing-runs` (`AccountProvisioningService`: a new account's owner is invited via `TeamService::invite`);
  `GET billing-runs[/{id}]` (`PlatformBilling`); `GET deliveries[/stats]`, `POST deliveries/{id}/retry`,
  `POST deliveries/retry-failed`, `GET whatsapp-connections` (`PlatformMessaging`, never secrets); `GET|PUT role-templates`;
  `GET|POST admins`, `PATCH admins/{id}/status`, `POST admins/{id}/invitation`, `DELETE admins/{id}` (`PlatformAdminService`:
  always one active admin, not yourself); `GET audit-log[/actions]`. Platform reads cross accounts with `DB::table` or
  `CurrentAccount::withoutScope()`. Super admins act inside an account with `X-Account-Id` on the admin API.
- **Activity log** (`audit_logs`, `AuditLogService`): a wildcard listener on `App\Events\Commands\*` (`RecordAuditEntry`,
  registered in `AppServiceProvider`) records every ACCEPTED intent (it runs after the event's own listener, so a 422 isn't
  logged) with actor, account, `via_platform`, subject and the payload with any `password|token|secret` key redacted;
  `RecordAuthEntry` logs logins/logouts/app tokens. New intents get a readable summary in `AuditLogService::SUMMARIES`.
- **CI/CD:** `.github/workflows/dev.yml` (here and in `../frontend`): PRs into `dev` run Pint + tests; a merge into `dev`
  deploys with `deploy/deploy.sh` (secret `DEPLOY_SSH_KEY`, host key in `deploy/known_hosts`; `deploy/README.md`). Server PHP is 8.4
  (`php8.4`; `/usr/bin/php` there is 8.3).

## Next steps (in order)

1. **More notifications** — SMS (Africa's Talking) sender; payment receipts (`PaymentRecorded`); tenant invitations
   by SMS/WhatsApp too (email is done).
2. **Admin API gaps** — bills list + void, reading photos.
3. **SPA auth extras** — tenant password reset (admin reset is done).
4. **Tenant portal API** — bills, payments (login with landlord choice and accepting invitations are done).
5. **M-Pesa** — STK push / C2B callbacks dispatching `PaymentSubmitted`.
6. Frontend screens for each new API (`../frontend`, designed screens show "Coming soon" until wired).

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
