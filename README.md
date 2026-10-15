# Rentflow — Backend API

Rentflow is a rental management system for landlords in Kenya. Landlords manage their properties,
houses (units), tenants and monthly bills; tenants get their bills by email and (soon) see bills and
payments in their own portal.

This repository is the **Laravel API**. The React frontends live next to it:

```
Rentflow/
├── backend/   ← this repo (Laravel 13 API)
├── admin/     ← admin portal for landlords & caretakers (React + TypeScript, not started)
└── portal/    ← tenant portal (React + TypeScript, not started)
```

## What it does

- **Many landlords, one system.** Each landlord business is an *account*. Landlords and caretakers
  only ever see their own account's data; super admins see everything.
- **Monthly bills on a 30-day cycle.** Every day at 06:00 (Nairobi time) Rentflow bills each lease
  whose cycle has ended: rent + metered electricity and water (meter readings × rate) + fixed charges
  such as garbage, plus any arrears carried forward.
- **Bills by email and WhatsApp.** Each new bill is emailed to the tenant with a PDF copy attached,
  and sent on WhatsApp from the landlord's own WhatsApp Business number (PDF included). Failed
  messages are retried automatically.
- **Payments.** Payments (M-Pesa, bank, cash…) are recorded against a lease; the bill's status moves
  from issued → partially paid → paid (or overdue after the due date).
- **Versioned rates.** Changing the price of electricity or water never changes old bills.

Example bill (this is also the test fixture, see `tests/Feature/ReferenceBillTest.php`):

| House 7-7, 1–30 Sep 2026 | KES |
|---|---:|
| Rent | 15,000 |
| Electricity 100 → 180 = 80 units × 30 | 2,400 |
| Water 70 → 75 = 5 units × 170 | 850 |
| Garbage | 250 |
| **Current charges** | **18,500** |
| Arrears | 1,000 |
| **Total due (by 6 Oct)** | **19,500** |

## Tech stack

| | |
|---|---|
| Framework | Laravel 13, PHP 8.3+ |
| Database | MySQL 8 / MariaDB (development & production), SQLite in-memory (tests) |
| Auth | Laravel Sanctum: bearer tokens for the API, cookie sessions for the future React portals |
| Queue | Database queue (Horizon + Redis later) |
| PDF | barryvdh/laravel-dompdf |
| WhatsApp | Meta WhatsApp Cloud API (each landlord's own number) |
| API docs | Scramble (OpenAPI generation) + Scalar (interactive reference at `/docs`) |
| Code style / tests | Laravel Pint, PHPUnit |

## Requirements

- **PHP 8.3+** with the `pdo_mysql`, `mbstring`, `dom`, `xml`, `curl`, `zip` and `sqlite3` extensions
  (check with `php -m`)
- **Composer 2**
- **MySQL 8 or MariaDB 10.11+**
- **Node.js 20.19+** and npm (only for the Vite front-end assets of the welcome page)
- Optional: **Redis** (for Horizon later), **Mailpit** (to see sent emails in a browser)

## Setup

```bash
# 1. Install PHP dependencies
composer install

# 2. Environment file + app key
cp .env.example .env
php artisan key:generate
```

**3. Create the database** (MariaDB example; with MySQL use `mysql -u root -p`):

```bash
sudo mariadb -e "CREATE DATABASE rentflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'rentflow'@'localhost' IDENTIFIED BY 'choose-a-password';
  GRANT ALL ON rentflow.* TO 'rentflow'@'localhost';"
```

Then put the same values in `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rentflow
DB_USERNAME=rentflow
DB_PASSWORD=choose-a-password
```

```bash
# 4. Create the tables and load demo data
php artisan migrate --seed

# 5. Front-end assets (only needed for the welcome page)
npm install
npm run build
```

> `composer setup` runs steps 1, 2, 4 (without seeding) and 5 in one go, once the database exists
> and `.env` has the database settings.

### Demo data

`php artisan migrate --seed` (or `migrate:fresh --seed` to start over) creates the **Kims Properties**
landlord with House 7-7 set up to produce the example bill above. All passwords are `password`.

| Who | Login | Notes |
|---|---|---|
| Super admin | `admin@rentflow.test` | sees every landlord |
| Landlord | `landlord@rentflow.test` | Kims Properties |
| Caretaker | `caretaker@rentflow.test` | looks after Kims Apartments |
| Tenant | `jane@rentflow.test` or national ID `12345678` | House 7-7, lease from 1 Sep 2026 |

## Running the application

Rentflow needs **three processes** in development:

```bash
php artisan serve          # the API on http://localhost:8000
php artisan queue:work     # runs billing, sends bill emails, retries
php artisan schedule:work  # triggers billing every day at 06:00 Nairobi time
```

Without the queue worker nothing is billed or emailed; without the scheduler bills are only created
when you start a run yourself (see *Billing* below).

> **About `composer dev` / `php artisan dev`:** it starts the server, Vite, live logs (`pail`) and
> **Horizon**, but Horizon only works with Redis (`QUEUE_CONNECTION=redis`) and it doesn't start the
> scheduler. With the default database queue, use the three commands above instead.

### In production

- Web server (Nginx + PHP-FPM) pointing at `public/`
- Scheduler, via cron:
  ```cron
  * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
  ```
- Queue worker kept running by Supervisor (or systemd): `php artisan queue:work --tries=3`
- After each deploy: `php artisan migrate --force && php artisan optimize && php artisan queue:restart`

## API documentation

With the server running, open **http://localhost:8000/docs** — an interactive API reference
(Scalar) generated from the code, so it is always up to date. The raw OpenAPI document is at
`/docs/api.json`.

To try requests from the docs:

1. **Auth → Issue an API token** with `landlord@rentflow.test` / `password`.
2. Paste the returned `token` into the **Bearer** field at the top of the page.
3. Call any endpoint. Write endpoints answer `202 Accepted`; billing runs show results once the
   queue worker has processed them.

The docs are always available locally; in other environments set `API_DOCS_ENABLED=true`
(on by default in staging, off in production).

### Current endpoints

| Method | Path | What it does |
|---|---|---|
| `POST` | `/api/admin/auth/token` | Log in with email + password, get a bearer token |
| `DELETE` | `/api/admin/auth/token` | Revoke the current token |
| `GET` | `/api/admin/user` | The logged-in user |
| `GET` | `/api/admin/billing-runs` | List billing runs (bills created, leases skipped and why) |
| `POST` | `/api/admin/billing-runs` | Start a billing run now (landlords; super admins send `X-Account-Id`) |
| `GET` | `/api/admin/payments?lease_id=` | List payments |
| `POST` | `/api/admin/payments` | Record a payment |
| `GET` | `/api/admin/invoices/{id}` | One bill with its lines and balance |
| `GET` | `/api/admin/whatsapp` | The landlord's WhatsApp connection + webhook details |
| `PUT` | `/api/admin/whatsapp` | Connect / update the landlord's WhatsApp number |
| `DELETE` | `/api/admin/whatsapp` | Stop sending bills on WhatsApp |
| `GET/POST` | `/api/webhooks/whatsapp/{landlord}` | Called by Meta (verification, delivery statuses) |

Using curl:

```bash
TOKEN=$(curl -s -X POST http://localhost:8000/api/admin/auth/token \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"landlord@rentflow.test","password":"password"}' | php -r 'echo json_decode(stream_get_contents(STDIN))->token;')

curl -s http://localhost:8000/api/admin/invoices/1 -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
```

## Billing

Billing runs automatically every day at 06:00 Nairobi time (when the scheduler is running). A lease
is billed once its 30-day cycle has ended — a tenant moving in on 1 Sep is billed on 1 Oct for
1–30 Sep, due 5 days later. Each landlord can change the cycle length (`accounts.billing_cycle_days`)
and the due days (`accounts.payment_due_days`).

```bash
php artisan billing:run                         # bill every landlord as of today
php artisan billing:run --date=2026-10-01       # bill as of a specific date
php artisan billing:run --account=1             # only one landlord account
```

`billing:run` puts one job per landlord on the queue; `php artisan queue:work` does the billing.
Running it twice for the same day is safe — nothing is billed twice. A lease with a missing
electricity or water reading is skipped and listed in the billing run's `errors`, so the caretaker
can enter the reading and the next run picks it up.

Try the whole flow on the demo data:

```bash
php artisan migrate:fresh --seed
php artisan billing:run --date=2026-10-01
php artisan queue:work --stop-when-empty
# → bill INV-2026-000001 for KES 19,500, emailed to jane@rentflow.test
```

## Emails

Bills are emailed with a PDF attached. In development `MAIL_MAILER=log`, so emails are written to
`storage/logs/laravel.log` instead of being sent. For real sending set the `MAIL_*` values in `.env`
(SMTP host, port, username, password and a `MAIL_FROM_ADDRESS` on a domain you own); the landlord's
name is used as the sender name and replies go to the landlord's email.

To see emails in a browser while developing, run [Mailpit](https://mailpit.axllent.org/) and set
`MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`.

Failed emails are retried twice automatically (after 1 and 5 minutes). After that:

```bash
php artisan notifications:retry-failed             # resend all failed emails
php artisan notifications:retry-failed --account=1 # only one landlord's
```

Only failed emails are resent; a bill is never emailed twice.

## WhatsApp

Each landlord sends bills from **their own WhatsApp Business number** through their own Meta developer
app (WhatsApp messaging use case). Once connected, every new bill also goes to the tenant on WhatsApp
as an approved template message with the PDF attached; delivery and read receipts come back through a
webhook. Landlords who haven't connected keep sending email only.

Setting a landlord up, step by step — Meta app, permanent token, the `rentflow_bill_issued` template
text, connecting, webhook, local testing with a tunnel — is in **[docs/WHATSAPP.md](docs/WHATSAPP.md)**.
In short:

1. Create a Meta app with the WhatsApp use case; note the **Phone number ID** and **WhatsApp Business
   Account ID**; create a permanent **System User token** and copy the **App Secret**.
2. Create and get approval for the `rentflow_bill_issued` template (Utility, English, document header).
3. `PUT /api/admin/whatsapp` with those four values (try it in `/docs`).
4. Paste `webhook_url` and `webhook_verify_token` from `GET /api/admin/whatsapp` into the Meta app's
   webhook settings and subscribe to `messages`.

```bash
php artisan whatsapp:test 1 +254712345678   # send Meta's hello_world from account 1's number
```

## Command reference

| Command | Purpose |
|---|---|
| `composer install` | Install PHP dependencies |
| `composer setup` | Install, create `.env`, generate key, migrate, build assets |
| `composer dev` / `php artisan dev` | Server + Vite + logs + Horizon (needs Redis, see above) |
| `composer test` / `php artisan test` | Run the test suite |
| `php artisan serve` | Development server on http://localhost:8000 |
| `php artisan queue:work` | Process queued jobs (billing, emails) |
| `php artisan queue:work --stop-when-empty` | Process everything queued, then stop |
| `php artisan schedule:work` | Run the scheduler in development |
| `php artisan schedule:run` | Run due scheduled tasks once (what cron calls every minute) |
| `php artisan schedule:list` | Show scheduled tasks (`billing:run` daily 06:00 Nairobi) |
| `php artisan migrate` | Create / update database tables |
| `php artisan migrate --seed` | …and load demo data |
| `php artisan migrate:fresh --seed` | **Drop all tables**, recreate them and load demo data |
| `php artisan db:seed` | Load demo data only |
| `php artisan billing:run [--date=] [--account=]` | Request a billing run |
| `php artisan notifications:retry-failed [--account=]` | Resend failed bill emails and WhatsApp messages |
| `php artisan whatsapp:test {account} {phone}` | Check a landlord's WhatsApp credentials (sends `hello_world`) |
| `php artisan scramble:export` | Write the OpenAPI document to `api.json` |
| `php artisan route:list --path=api` | List API routes |
| `php artisan event:list` | Show which listeners handle which events |
| `php artisan queue:failed` / `queue:retry all` | Inspect / retry jobs that failed completely |
| `php artisan pail` | Follow the application log live |
| `php artisan tinker` | Interactive PHP shell with the app loaded |
| `php artisan optimize:clear` | Clear all cached config, routes and views |
| `vendor/bin/pint` | Format the code (run before committing) |
| `npm run dev` / `npm run build` | Vite dev server / production build of front-end assets |

> In `tinker`, landlord data is invisible until you pick an account:
> `app(App\Support\CurrentAccount::class)->actingAs(1, fn () => App\Models\Invoice::all())`

## Testing

```bash
php artisan test                           # everything
php artisan test --filter=ReferenceBillTest
vendor/bin/pint --dirty                    # format changed files
```

Tests use an in-memory SQLite database and fake mail, so no setup is needed. Highlights:

- `ReferenceBillTest`, `BillingServiceTest` — the example bill, re-runs, skipped leases, second cycle
- `PaymentApiTest`, `BillingRunApiTest`, `AuthTokenApiTest` — the HTTP API, permissions, validation
- `InvoiceEmailTest` — bill emails with PDF, no double sends, retries
- `WhatsAppBillTest`, `WhatsAppConnectionApiTest`, `WhatsAppWebhookTest` — WhatsApp sending, connecting
  and delivery webhooks (Meta is faked, no real messages are sent)
- `AccountScopeTest` — one landlord can never see another's data
- `ArchitectureTest` — keeps business logic out of models and controllers

## Project structure

```
app/
├── Console/Commands/      billing:run, notifications:retry-failed, whatsapp:test
├── Data/                  DTOs passed from controllers to services
├── Enums/                 statuses, roles, payment methods
├── Events/Commands/       "please do X" events dispatched by controllers and commands
├── Events/                "X happened" events dispatched by services
├── Http/Controllers/      thin: validate, authorize, dispatch an event, return 202
├── Http/Requests/         validation rules (also the API docs' request bodies)
├── Http/Resources/        JSON responses (also the API docs' response schemas)
├── Listeners/             react to events by calling services
├── Mail/                  the bill email
├── Models/                data only: columns, casts, relations
├── Policies/              who may do what (landlord, caretaker, super admin)
├── Services/              all business logic: Billing, Ledger, Payments, Rates, Meters, …
└── Support/               CurrentAccount (account scoping), Money (cents arithmetic)
docs/
├── ARCHITECTURE.md        event-driven design, services, events, adding a feature
├── SCHEMA.md              tables, relationships, billing rules
├── SETUP.md               how auth, scoping, scheduler and queue are wired
├── WHATSAPP.md            connecting a landlord's WhatsApp number (Meta setup, template, webhook)
└── api/introduction.md    intro text of the API docs
```

The flow of a request:

```
Controller → "please do X" event → Listener → Service (does the work) → "X happened" event → side effects
```

For example, recording a payment: `PaymentController` → `PaymentSubmitted` → `PaymentService`
→ `PaymentRecorded` → the bill's status is updated. Read **`docs/ARCHITECTURE.md`** before adding a
feature.

## Configuration reference

Besides the standard Laravel settings, `.env` has:

| Variable | Purpose |
|---|---|
| `DB_*` | MySQL / MariaDB connection |
| `QUEUE_CONNECTION` | `database` (default); `redis` once Horizon is set up |
| `MAIL_*` | Where bill emails go (`log` in development) |
| `SANCTUM_STATEFUL_DOMAINS` | Origins of the React portals (cookie login). Never include the API's own origin, or requests from `/docs` fail with 419 |
| `API_DOCS_ENABLED` | Show `/docs` outside local (default: on in staging, off in production) |
| `WHATSAPP_API_VERSION` | Meta Graph API version (default `v23.0`; use the latest your Meta app shows) |

## Roadmap

1. SMS (Africa's Talking); payment receipts and tenant invitations on email + WhatsApp
2. Admin API for properties, units, rates, tenants, leases and meter readings
3. Cookie login for the React portals
4. Tenant portal API
5. M-Pesa payments (STK push and C2B callbacks)
6. React + TypeScript admin and tenant portals
# RentLara
# RentLara
# RentLara
