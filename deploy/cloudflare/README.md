# Cloudflare DNS for Rentflow

Three **A** records in the `codepasstech.top` zone, all → `102.212.246.251`:

| Name | Serves |
|---|---|
| `rentflow` | tenant portal (`rentflow.codepasstech.top`) |
| `admin.rentflow` | admin app (`admin.rentflow.codepasstech.top`) |
| `api.rentflow` | Laravel API (`api.rentflow.codepasstech.top`) |

## With the script

1. Cloudflare dashboard → My Profile → API Tokens → Create token → "Edit zone DNS" template →
   Zone resources: *Include · Specific zone · codepasstech.top* → Create. Copy the token.
2. On any machine with `curl` and `jq`:

   ```bash
   export CLOUDFLARE_API_TOKEN=...   # never commit it
   ./deploy/cloudflare/dns.sh --dry-run
   ./deploy/cloudflare/dns.sh
   ```

## By hand

DNS → Records → Add record: Type **A**, Name `rentflow`, IPv4 `102.212.246.251`, Proxy status **DNS only**
(grey cloud). Repeat for `admin.rentflow` and `api.rentflow`.

## Proxy (orange cloud) later

Keep **DNS only** until certbot has issued the certificates. To proxy through Cloudflare afterwards:
`PROXIED=true ./deploy/cloudflare/dns.sh`, set **SSL/TLS → Overview → Full (strict)**, and set
`TRUSTED_PROXIES=*` in the backend `.env` (then `php artisan config:cache`) so Laravel sees https and real IPs.
Certbot renewal keeps working through the proxy as long as "Always Use HTTPS" doesn't block `/.well-known/acme-challenge/`
(nginx answers it on port 80 before redirecting).
