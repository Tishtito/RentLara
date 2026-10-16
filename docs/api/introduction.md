Rentflow is a rental management API for landlords in Kenya. This reference covers the **admin portal**
API used by super admins, landlords and caretakers.

## Trying requests

1. Open **Auth → Issue an API token** and send it with your login, e.g. `landlord@rentflow.test` /
   `password` from the development seed data.
2. Copy `token` from the response into the **Bearer** authentication field at the top of this page.
3. Every other request now runs as that user.

Revoke the token with **Auth → Revoke the current token** when you're done.

## Conventions

- **Accounts.** Each landlord business is an account. Landlords and caretakers only ever see their own
  account's data; an ID from another account behaves exactly like an ID that doesn't exist.
  Super admins see every account and pick one with the optional `X-Account-Id` header
  (required for actions such as starting a billing run).
- **Writes return `202 Accepted`.** Creating something (a payment, a billing run) dispatches a request
  that is processed before the response is sent; validation problems still come back as `422`.
  Fetch the resource again to see the result, e.g. the invoice's new status after a payment.
- **Money** is a decimal string with two places, in the account's currency (KES), e.g. `"19500.00"`.
- **Dates** are `YYYY-MM-DD`; timestamps are ISO 8601 in UTC.
- **Errors**: `401` not logged in, `403` not allowed (role, suspended login or account),
  `404` not found (or another account's), `422` validation errors keyed by field.
