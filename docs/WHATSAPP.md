# WhatsApp bills (Meta WhatsApp Cloud API)

Every landlord sends bills from **their own WhatsApp Business number**, through their own Meta app.
Once connected, each new bill goes to the tenant on WhatsApp — a message with the PDF bill attached —
as well as by email. Landlords who haven't connected WhatsApp keep sending email only.

```
billing:run → InvoiceIssued → one delivery per channel (email, whatsapp)
  whatsapp: upload bill PDF to Meta → send template "rentflow_bill_issued" with the PDF as header
  Meta → POST /api/webhooks/whatsapp/{landlord-slug} → delivery becomes delivered / failed
```

## 1. Create the Meta app (once per landlord)

1. Go to <https://developers.facebook.com/apps> → **Create app**.
2. Use case: **Connect with customers through WhatsApp** (the WhatsApp messaging use case).
   Link it to the landlord's **Meta Business portfolio** (create one at business.facebook.com if needed).
3. In the app: **WhatsApp → API Setup**.
   - Meta gives a free **test number** to start with. It can only message up to **5 recipient numbers**
     that you add and verify under "To". Good for trying it out.
   - For real use, **Add phone number** to register the landlord's own business number
     (it must not be in use on the WhatsApp app; the business display name needs Meta approval).
4. Note the **Phone number ID** and the **WhatsApp Business Account ID** shown on that page.

## 2. Get a permanent access token

The token on the API Setup page expires after 24 hours — use it only for a first test.

1. **Business settings → Users → System users → Add** (role: Admin).
2. **Assign assets**: the app (full control) and the WhatsApp account (full control).
3. **Generate new token** for the app, expiry **Never**, permissions:
   `whatsapp_business_messaging` and `whatsapp_business_management`.
4. Copy the token (it's shown once).

Also copy the **App Secret**: app dashboard → **App settings → Basic → App secret → Show**.
Rentflow uses it to check that delivery webhooks really come from this app.

## 3. Create the bill template

Business-initiated WhatsApp messages must use a template approved by Meta.
**WhatsApp Manager → Message templates → Create template**:

| Field | Value |
|---|---|
| Category | **Utility** |
| Name | `rentflow_bill_issued` |
| Language | **English (US)** (`en_US`). Meta treats `en` and `en_US` as different translations, so this must match `template_language` |
| Header | **Document** (upload any sample PDF, e.g. a past bill) |
| Body | `Hello {{1}}, your bill from {{2}} for house {{3}} ({{4}}) is ready. Total due: {{5}}, payable by {{6}}. Invoice number: {{7}}. The bill is attached.` |
| Footer | `Sent via Rentflow` |

Sample values for the review: `Jane` · `Kims Properties` · `7-7` · `1 Sep – 30 Sep 2026` ·
`KES 19,500.00` · `6 Oct 2026` · `INV-2026-000001`.

Rentflow fills the variables in exactly this order. Approval usually takes minutes to a few hours.
A different name or language can be set when connecting (`template_name`, `template_language`), but
the variables must stay in this order.

## 4. Connect it in Rentflow

As the landlord (in the API docs at `/docs` → **WhatsApp → Connect a WhatsApp number**, or any client):

```http
PUT /api/admin/whatsapp
Authorization: Bearer <landlord token>

{
  "phone_number_id": "106540352242922",
  "business_account_id": "102290129340398",
  "access_token": "EAAG…",
  "app_secret": "…"
}
```

Rentflow checks the credentials with Meta (a `422` explains what Meta rejected), then stores the token
and secret **encrypted**. `GET /api/admin/whatsapp` shows the connected number and the
**webhook URL + verify token** for the next step. It never returns the token or secret.

Check that sending works (Meta's pre-approved `hello_world` template, needs no approval):

```bash
php artisan whatsapp:test 1 +254712345678     # account ID, recipient
```

To see the real bill template, run billing for that landlord (`php artisan billing:run` + `queue:work`).

## 5. Delivery webhook

In the Meta app: **WhatsApp → Configuration → Webhook → Edit**

- **Callback URL**: the `webhook_url` from `GET /api/admin/whatsapp`
  (`https://<your-domain>/api/webhooks/whatsapp/<landlord-slug>`)
- **Verify token**: the `webhook_verify_token` from the same response
- Then **Manage → subscribe to `messages`**.

Meta then reports each message as `sent` → `delivered` → `read`, or `failed` with a reason; Rentflow
updates the bill's delivery record (read counts as delivered). Calls without a valid
`X-Hub-Signature-256` from the landlord's app secret are rejected.

**Developing locally:** Meta can only call a public HTTPS URL. Expose `php artisan serve` with a tunnel
(e.g. `cloudflared tunnel --url http://localhost:8000` or `ngrok http 8000`), set `APP_URL` to the tunnel
URL so the webhook URL is right, and use that URL in Meta. Bills still send without the webhook — you
just won't see delivered / failed updates.

## Who gets WhatsApp

- By default every tenant gets bills by **email and WhatsApp** (landlords can change the default in
  `accounts.settings.notification_channels`, and per tenant in `tenants.notification_channels`).
- The number used is the tenant's `whatsapp_phone`, else their `phone` (E.164, e.g. `+254712345678`).
- WhatsApp is skipped while the landlord's connection is missing or disconnected
  (`DELETE /api/admin/whatsapp`); email continues.

## Failures and retries

| Meta says | What Rentflow does |
|---|---|
| Rate limit / temporarily unavailable (`130429`, `131016`, `131000`, 5xx…) | Retries automatically (after 1 and 5 min), then `php artisan notifications:retry-failed` |
| Not a WhatsApp number (`131026`), number not in the test app's list (`131030`), template missing / wrong (`132000`, `132001`), expired token (`190`) | Marked **failed** with Meta's reason, no automatic retry. Fix the cause, then `notifications:retry-failed` |
| Webhook reports `failed` later | Marked failed with Meta's reason |

The reason is stored in `notification_deliveries.error`, e.g. `WhatsApp error 131026: Message undeliverable`.

## Configuration

| `.env` | Default | |
|---|---|---|
| `WHATSAPP_API_VERSION` | `v23.0` | Graph API version — use the latest your Meta app shows |
| `WHATSAPP_BILL_TEMPLATE` | `rentflow_bill_issued` | Default template for new connections |
| `WHATSAPP_BILL_TEMPLATE_LANGUAGE` | `en_US` | |
| `WHATSAPP_TIMEOUT` | `20` | Seconds to wait for Meta |

Per-landlord credentials are in the `whatsapp_connections` table (token and app secret encrypted with
`APP_KEY` — rotating `APP_KEY` means reconnecting WhatsApp).

## Code map

| | |
|---|---|
| `app/Services/WhatsApp/WhatsAppClient.php` | Graph API calls: check number, upload media, send template |
| `app/Services/WhatsApp/WhatsAppBillSender.php` | Builds the bill message (PDF + 7 variables) |
| `app/Services/WhatsApp/WhatsAppConnectionService.php` | Connect / disconnect a landlord's number |
| `app/Services/WhatsApp/WhatsAppWebhookService.php` | Verify token, signature, extract statuses |
| `app/Services/Notifications/NotificationDeliveryService.php` | `applyWhatsAppStatus()` |
| `app/Http/Controllers/Admin/WhatsAppConnectionController.php` | `GET/PUT/DELETE /api/admin/whatsapp` |
| `app/Http/Controllers/WhatsAppWebhookController.php` | `GET/POST /api/webhooks/whatsapp/{slug}` |
| `tests/Feature/WhatsApp*Test.php` | Meta faked with `Http::fake` |
