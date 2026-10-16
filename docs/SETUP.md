# How the models are wired into Laravel

These edits are **already applied** in this repo; this file documents them so they aren't undone.

## 1. Install Sanctum (SPA auth for the React frontend)

```bash
php artisan install:api
```

## 2. `config/auth.php` — add the tenant guard and provider

```php
'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],
    'tenant' => ['driver' => 'session', 'provider' => 'tenants'],
],

'providers' => [
    'users' => ['driver' => 'eloquent', 'model' => App\Models\User::class],
    'tenants' => ['driver' => 'accountless-eloquent', 'model' => App\Models\Tenant::class],
],
```

Tenant password resets use `tenant_auth_tokens` (keyed by tenant, not email), so no
`passwords.tenants` broker is needed. Admin passwords use two brokers on `password_reset_tokens`:
`users` (forgot password, 60 min) and `staff_invites` (team invitations, 72 h). Links point at
`ADMIN_URL/set-password` (`config('app.admin_url')`).

### Roles & permissions (spatie/laravel-permission)

`config/permission.php`: `teams => true`, `team_foreign_key => 'account_id'`, and
`team_resolver => App\Support\AccountTeamResolver`, so the Spatie team is always the
`CurrentAccount`. After adding a case to `App\Enums\Permission`, run `php artisan permissions:sync`
(also run by the migration and seeders); it creates new permissions and the built-in templates
(defaults apply only when a template is first created; Owner always gets everything).

## 3. `bootstrap/app.php` — register the middleware alias

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->statefulApi();
    $middleware->alias(['account' => \App\Http\Middleware\SetCurrentAccount::class]);
})
```

Then group routes:

```php
// routes/api.php
Route::middleware(['auth:sanctum', 'account'])->prefix('admin')->group(/* admin portal */);
Route::middleware(['auth:tenant', 'account'])->prefix('portal')->group(/* tenant portal */);
```

## How the account scope behaves

| Situation | What account-owned queries return |
|---|---|
| Staff (any role) logged in | Only their account's rows (policies then check permissions) |
| Tenant logged in | Only their landlord's rows (further filter to the tenant in controllers/policies) |
| Super admin | Everything; or one account if the request sends `X-Account-Id` |
| Queue job / console / no login | **Nothing** until the code sets a context |

Jobs and commands must choose an account explicitly:

```php
app(CurrentAccount::class)->actingAs($account, function () {
    // bill this landlord's leases
});
```

Creating a record fills `account_id` automatically, and saving a record that belongs to a
different account than the current one throws an exception.

## Where the rules live

Business rules live in services, not models — see `docs/ARCHITECTURE.md`.

- **Rates are versioned:** `ChargeRateService::change()` is the only writer; it closes the old rate.
  `ChargeRateService::resolve($unit, 'water', $date)` returns the unit override if one exists, else the property rate.
- **Arrears:** `LeaseLedgerService::balance($lease)` = opening balance + bills − confirmed payments.
- **Meter readings** can't go below the previous reading on the same meter (`MeterReadingService::record()`).
- **Payments** attach to the lease's latest bill if no invoice is given (`PaymentService::record()`); the
  `RefreshInvoicePaymentStatus` listener updates that bill's `amount_paid` and status.
- **Money** is calculated in integer cents (`App\Support\Money`) so totals never drift.

## Running billing and bill emails

Two long-running processes are needed (nothing is sent without them):

| Process | Production | Development |
|---|---|---|
| Scheduler (runs `billing:run` daily at 06:00 Nairobi) | cron: `* * * * * cd /path/to/backend && php artisan schedule:run` | `php artisan schedule:work` |
| Queue worker (billing runs, bill emails, retries) | `php artisan queue:work` under Supervisor (or Horizon once Redis is set up) | `php artisan queue:work` |

Mail settings are in `.env` (`MAIL_*`). Failed emails retry twice automatically (after 1 and 5 minutes);
after that `php artisan notifications:retry-failed` resends only the failed ones.

Try it locally: `php artisan migrate:fresh --seed`, `php artisan billing:run --date=2026-11-05`,
`php artisan queue:work --stop-when-empty`, then look in `storage/logs/laravel.log` for the email.

## API documentation (Scalar)

`php artisan serve`, then open **http://localhost:8000/docs**. The OpenAPI document is at `/docs/api.json`
(`php artisan scramble:export` writes it to `api.json` for importing into other tools).

To try requests: **Auth → Issue an API token** with `landlord@rentflow.test` / `password` (seed data),
paste the `token` into the Bearer field, then call any endpoint. Write endpoints answer `202`; billing
runs need a queue worker (`php artisan queue:work`) before their results show up.

Available in local always; elsewhere only with `API_DOCS_ENABLED=true` (default on in staging, off in
production). Scalar's request proxy and telemetry are turned off in `config/scalar.php`, so requests
go straight from the browser to this API.

`SANCTUM_STATEFUL_DOMAINS` must list only the React SPA origins, never the API's own origin: requests
from the docs page carry a bearer token, and a stateful origin would put them through CSRF (419).
For the same reason it must never contain bare `localhost`: the Android app's web view origin is
`https://localhost`, and its bearer-token requests would then need CSRF too.

## Mobile apps (Capacitor) and CORS

The React portals are also built as Android/iOS apps (`../frontend`). Their web views run on
`https://localhost` (Android) and `capacitor://localhost` (iOS), where the API's cookies don't work, so the
apps log in with API tokens (`POST /api/admin/auth/token`, `POST /api/portal/auth/token`) and call the API
cross-origin. `config/cors.php` allows the origins in `CORS_ALLOWED_ORIGINS` (default: the two app origins
plus `http://localhost`). Add the production SPA origins there if they are served from another subdomain.
For an Android emulator in dev, run `php artisan serve --host=0.0.0.0` (the emulator reaches it at
`http://10.0.2.2:8000`).

## "Continue with Google"

Optional; the button is hidden until it's configured. Google only logs in **existing** logins (landlords,
caretakers, activated tenants) whose email Google has verified; it never creates an account.

1. Google Cloud Console → APIs & Services → Credentials → **Create OAuth client ID** → *Web application*.
   Authorized JavaScript origins: `http://localhost:5173`, `http://localhost:5174` (dev) and the production
   portal origins. No redirect URI is needed (Google Identity Services returns an ID token to the page).
2. Backend `.env`: `GOOGLE_CLIENT_IDS=<web client id>`. Frontend `apps/*/.env.local` and `.env.native`:
   `VITE_GOOGLE_CLIENT_ID=<same web client id>`.
3. For the Android/iOS apps also create an *Android* client (package name + the signing key's SHA-1) and an
   *iOS* client in the same project; the apps still ask for the **web** client ID, so `aud` stays the same.

The backend verifies the ID token itself (`GoogleIdTokenVerifier`: Google's signing keys, issuer, expiry,
`aud` in `GOOGLE_CLIENT_IDS`, verified email). Endpoints: `POST /api/{admin,portal}/auth/google` (cookie
session) and `/api/{admin,portal}/auth/google/token` (apps).

## Tenant invitations (email)

Move-in (and "Resend invitation") emails the tenant a one-time link `{PORTAL_URL}/invite/{token}` (valid 3 days).
Set `PORTAL_URL` to where the tenant portal runs (default `http://localhost:5174`). The email is sent by a queued
listener (`SendTenantInvitation`), so a queue worker must run (`php artisan queue:work`), the same as for bill emails.
In dev with `MAIL_MAILER=log` the email, including the link, is written to `storage/logs/laravel.log`.
