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
`passwords.tenants` broker is needed.

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
| Landlord / caretaker logged in | Only their account's rows |
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

## Rule enforcement built into the models

- **Rates are versioned:** editing a `ChargeRate`'s price or dates throws. Use
  `ChargeRate::change($property, 'electricity', 32, '2026-11-01')`, which closes the old rate.
  `ChargeRate::resolve($unit, 'water', $date)` returns the unit override if one exists, else the property rate.
- **Arrears:** `$lease->balance()` = opening balance + bills − confirmed payments.
- **Meter readings** can't go below the previous reading on the same meter.
- **Payments** attach to the lease's latest bill if no invoice is given, and update that
  bill's `amount_paid` and status (issued / partially_paid / paid / overdue) automatically.
- **Money** is calculated in integer cents (`App\Support\Money`) so totals never drift.
