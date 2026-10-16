# Rentflow backend architecture

Event-driven: **controllers dispatch intent events, listeners call services, services do the work
and announce what happened with fact events.** Models are plain data.

```
HTTP  → Controller ──(FormRequest, Policy, DTO)──► IntentEvent::dispatch(dto)   → 202 Accepted
CLI   → Command   ─────────────────────────────►  IntentEvent::dispatch(dto)
                                                         │
                                       Listener Handle<Intent>  (sync by default, queued where noted)
                                                         │
                                                  <Domain>Service::method(dto)
                                                   ├─ DB::transaction(...)
                                                   └─ FactEvent::dispatch(...)   (after commit)
                                                         │
                                         Side-effect listeners (refresh status, notify, audit)
```

## Layers

| Layer | Folder | Rules |
|---|---|---|
| Controller | `app/Http/Controllers` | Validate (FormRequest), authorize (`Gate::authorize`), build a DTO (`$request->toData()`), dispatch one intent event, return 202. Read actions call a service read method and return an API Resource. **Never write.** |
| FormRequest | `app/Http/Requests` | Shape + existence checks. Use `App\Rules\InCurrentAccount` instead of `exists` (the built-in rule ignores the account scope). `toData()` builds the DTO. |
| DTO | `app/Data` | `final readonly` classes. Carry `accountId` so the handler can run outside the request (queued). |
| Intent event | `app/Events/Commands` | `*Requested` / `*Submitted`. Dispatched only by controllers and commands. **Exactly one listener.** |
| Listener | `app/Listeners` | Auto-discovered from the `handle(Event $e)` type hint. Intent listeners just call a service. |
| Service | `app/Services/<Domain>` | All domain logic and every write. Sets the account context itself (`CurrentAccount::actingAs`) when not inside a request. Dispatches fact events. |
| Fact event | `app/Events` | Past tense (`PaymentRecorded`). Dispatched only by services. Implement `ShouldDispatchAfterCommit`. Any number of listeners. |
| Model | `app/Models` | `$fillable`, casts, relations, framework hooks (`routeNotificationFor*`), query-free predicates (`isSuperAdmin()`). **No `booted()` hooks, no queries, no writes.** |

`tests/Unit/ArchitectureTest.php` enforces the model and controller rules.

**Exception — synchronous controllers:** when the response must carry the result, the controller
calls the service directly. Today `AuthTokenController` and `Portal\TokenController` (the issued token has to
be returned), the two `SessionController`s (admin + tenant portal cookie login: the session has to be
started before the response), the two `GoogleLoginController`s (both) `Portal\InvitationController` (accepting an invitation logs the
tenant in) and `Admin\PasswordController` (setting a password from an invite/reset link logs the admin in). Webhook controllers
(`WhatsAppWebhookController`) are still entry points: they verify the caller, dispatch an intent
event and answer 200 the way the provider expects.
They're listed in `ArchitectureTest::SYNC_CONTROLLERS`; keep that list short.

**Exception:** the tenancy infrastructure (`BelongsToAccount`, `AccountScope`, `CurrentAccount`)
stays in the model layer. It isn't domain logic, and it must be impossible to bypass.

### Sync vs queued intent listeners

Intent listeners run **synchronously** by default, so a `ValidationException` thrown by a service
(e.g. a meter reading going backwards) still reaches the client as a 422. The 202 means
"accepted and processed"; the client re-fetches to see the result. A listener can be made
`ShouldQueue` later without touching its controller. `HandleBillingRunRequested` is queued
(one job per account).

## Events

| Intent | Listener → service |
|---|---|
| `PaymentSubmitted` | `HandlePaymentSubmitted` → `PaymentService::record()` |
| `BillingRunRequested` | `HandleBillingRunRequested` (queued) → `BillingService::run()` |
| `NotificationRetryRequested` | `HandleNotificationRetryRequested` (queued) → `NotificationSender::send()` |
| `WhatsAppConnectionSubmitted` | `HandleWhatsAppConnectionSubmitted` → `WhatsAppConnectionService::connect()` |
| `WhatsAppDisconnectRequested` | `HandleWhatsAppDisconnectRequested` → `WhatsAppConnectionService::disconnect()` |
| `WhatsAppStatusesReceived` | `HandleWhatsAppStatusesReceived` (queued) → `NotificationDeliveryService::applyWhatsAppStatus()` |

| Fact | Dispatched by | Listeners |
|---|---|---|
| `PaymentRecorded` | `PaymentService` | `RefreshInvoicePaymentStatus` |
| `InvoiceIssued` | `BillingService` | `QueueInvoiceNotifications` (queued) |
| `NotificationQueued` | `InvoiceNotificationService` | `DeliverNotification` (queued, 3 tries) |
| `NotificationSent` | `NotificationSender` | — |
| `NotificationDelivered` | `NotificationDeliveryService` (WhatsApp webhook) | — |
| `WhatsAppConnected` / `WhatsAppDisconnected` | `WhatsAppConnectionService` | — |
| `NotificationFailed` | `DeliverNotification::failed()`, `NotificationSender` (permanent errors), WhatsApp webhook | — |
| `InvoicePaymentStatusChanged` | `LeaseLedgerService` | — |
| `LeaseBillingSkipped` | `BillingService` | — |
| `BillingRunCompleted` | `BillingService` | — |
| `ChargeRateChanged` | `ChargeRateService` | — |
| `MeterReadingRecorded` | `MeterReadingService` | — |
| `TenantInvitationIssued` | `TenantAuthTokenService` | — (invitation SMS/email next) |

`php artisan event:list` shows the live wiring.

## Services

| Service | Responsibility |
|---|---|
| `Billing\BillingService` | One billing run per account: bill due leases, log skips, idempotent. |
| `Billing\InvoiceBuilder` | Build one bill: rent + metered + fixed lines (metered first), arrears, snapshot. |
| `Billing\InvoiceNumberGenerator` | `INV-2026-000001` per account and year. |
| `Ledger\LeaseLedgerService` | Lease balance, invoice payment status, balance remaining. |
| `Leases\LeaseService` | Create lease (first billing date), due leases, billing period, advance cycle. |
| `Payments\PaymentService` | Record payments (attach to the open bill), list payments. |
| `Rates\ChargeRateService` | The only writer of rates: versioned `change()`, `resolve()`, `allFor()`. |
| `Meters\MeterReadingService` | Record readings (meters only count up), readings for a date. |
| `Tenants\TenantService` | Create tenants (email normalised), portal login lookup, notification channels. |
| `Tenants\TenantAuthTokenService` | Invitation / password-reset tokens. |
| `Authorization\PermissionCatalog` | Sync the permission catalogue + built-in role templates (`permissions:sync`). |
| `Authorization\RoleService` | Account roles (templates + own), create/edit/delete, super-admin template edits. |
| `Team\TeamService` | Invite / edit / suspend / remove staff; last-Owner and self guards. |
| `Auth\AdminPasswordService` | Admin set-password links (invites 72 h, resets 60 min) and setting the password. |
| `Properties\Visibility` | Which properties a user sees (`properties.view_all` or the ones they look after). |
| `Platform\PlatformAccounts` | Landlord accounts list (search, status, last run) and one account's detail. |
| `Platform\PlatformOverview` | Platform dashboard totals and what needs attention. |
| `Platform\AccountProvisioningService` | Create an account (owner invited), suspend / close / reactivate (revokes tokens). |
| `Platform\PlatformBilling` | Billing runs across accounts, a run's skipped leases. |
| `Platform\PlatformMessaging` | Messages across accounts, stats, resend failed, WhatsApp numbers. |
| `Platform\PlatformAdminService` | Invite / suspend / remove platform admins (one always stays active). |
| `Audit\AuditLogService` | The activity log: record intents and logins (secrets redacted), search. |

## Activity log

Every intent event is also recorded by one **wildcard** listener (`Event::listen('App\Events\Commands\*', RecordAuditEntry)`
in `AppServiceProvider`). Wildcard listeners run after the event's own listeners, so when the service refuses a write
(validation error) dispatch stops and nothing is logged; queued intent listeners are logged when accepted. A new intent
event is logged automatically; add a sentence for it to `AuditLogService::SUMMARIES`. Payload keys matching
`password|token|secret` are stored as `[redacted]`. Logins, logouts and app tokens come from `RecordAuthEntry`.
| `Notifications\InvoiceNotificationService` | Which channels a new bill goes out on; one delivery row each, never twice. |
| `Notifications\NotificationSender` | Send one delivery (email now), record sent/failed, set `invoices.sent_at`. |
| `Notifications\NotificationDeliveryService` | Delivery status per message per channel; failed / retryable lists. |
| `Billing\InvoicePdf` | The printable bill attached to the email and the WhatsApp message. |
| `WhatsApp\WhatsAppClient` | Graph API calls (check number, upload media, send template). |
| `WhatsApp\WhatsAppBillSender` | The bill on WhatsApp: PDF header + 7 template variables. |
| `WhatsApp\WhatsAppConnectionService` | Connect / disconnect a landlord's own number (checked with Meta). |
| `WhatsApp\WhatsAppWebhookService` | Webhook verify token, signature, statuses. |

## Bill emails

`InvoiceIssued` → `QueueInvoiceNotifications` → `InvoiceNotificationService::queueFor()` creates one
`notification_deliveries` row per channel the tenant wants (`tenants.notification_channels`, else the
landlord's default) that has a sender (email today; SMS / WhatsApp later). A channel that already has a
row for that bill is skipped, so retries never double-send. A tenant with no email gets a `failed` row
explaining why. WhatsApp is only queued when the landlord has an active connection
(`docs/WHATSAPP.md`). Each row → `NotificationQueued` → `DeliverNotification` → `NotificationSender` sends
`App\Mail\InvoiceIssuedMail` (HTML + PDF from `InvoicePdf`) or the WhatsApp template
(`WhatsAppBillSender`). Errors implementing `PermanentDeliveryFailure` are recorded as failed without a
queue retry. Queued listeners set the account context
from the model's `account_id`; events must not carry loaded relations (they're reloaded with scopes).

## Metered billing rule

A metered line charges from the reading the lease's **last bill ended on** (first bill: the
reading before the current one, else the meter's `initial_reading`) to the latest reading on or
before the billing day. If there's no meter, or no reading newer than the last billed one, the
lease is skipped with a reason in `billing_runs.errors`, so the same reading is never billed twice.

## API documentation

Scramble generates the OpenAPI document from the code (`/docs/api.json`) and Scalar renders it at
`/docs`, so the reference updates itself. What feeds it:

- **Controller PHPDoc**: first line = summary, the rest = description. `#[Group('Payments')]` on the
  class groups endpoints; `#[QueryParameter(...)]` documents query strings.
- **FormRequest rules** become the request body; a docblock above a rule describes the field
  (multi-line, with `@example` on its own line).
- **API Resources** become the response schemas; `response()->json([...], 202)` documents a 202.
- Routes behind `auth:sanctum` get bearer auth; routes with the `account` middleware get the
  optional `X-Account-Id` header (`AppServiceProvider::configureApiDocs()`).
- The introduction text is `docs/api/introduction.md`.

## Adding a feature (checklist)

1. DTO in `app/Data`.
2. Service method in `app/Services/<Domain>` that writes in a transaction and dispatches a fact event.
3. Intent event in `app/Events/Commands` + one listener in `app/Listeners` calling the service.
4. FormRequest with `toData()`, policy, controller action dispatching the intent event → 202.
5. PHPDoc summary + description on the controller action and field docblocks on the rules (shows in `/docs`).
6. Feature test through the HTTP endpoint; assert the fact event with `Event::fake([TheFact::class])`.
