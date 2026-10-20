# Deploying Rentflow

One server (`102.212.246.251`, user `tito`, everything under `/home/tito/rentflow`), three hostnames:

| Host | What | Served from |
|---|---|---|
| https://api.rentflow.codepasstech.top | Laravel API (mobile apps, webhooks, `/up`) | `backend/public` via PHP-FPM |
| https://admin.rentflow.codepasstech.top | Admin app (landlords, caretakers, platform admins) | `frontend/apps/admin/dist` + `/api/`, `/sanctum/` → Laravel |
| https://rentflow.codepasstech.top | Tenant portal | `frontend/apps/portal/dist` + `/api/`, `/sanctum/` → Laravel |

The web apps call the API **on their own host** (nginx hands `/api/` and `/sanctum/` to the same Laravel app),
so login cookies are first-party and need no CORS. `api.` is for the Android/iOS apps (bearer tokens), the
WhatsApp webhook (`/api/webhooks/whatsapp/{slug}`) and links built from `APP_URL`.

Each repo deploys itself. This folder (backend repo) holds the server, DNS and API side; the two web apps'
nginx sites and build/upload script live in the frontend repo's `deploy/` (`../frontend/deploy/README.md`).

| Folder | Contents |
|---|---|
| `cloudflare/` | `dns.sh`: creates/updates the three A records via the Cloudflare API; README for doing it by hand |
| `nginx/` | the API site + the shared snippets the web-app sites also include; install and certbot steps |
| `server/` | `setup.md` (packages, MySQL, folders, first install), queue worker (systemd), scheduler (cron) |
| `deploy.sh` | uploads this repo (incl. `deploy/`) and migrates on the server |

Production settings: `.env.production.example` here, `frontend/apps/{admin,portal}/.env.production.example`.
Commands below run from the backend repo root unless noted.

## First time, in order

1. **DNS** (your computer): `export CLOUDFLARE_API_TOKEN=…` then `./deploy/cloudflare/dns.sh --dry-run` and
   `./deploy/cloudflare/dns.sh`. Wait until `dig +short api.rentflow.codepasstech.top` shows `102.212.246.251`.
2. **Server packages, database, folders**: `deploy/server/setup.md` sections 1–3 (on the server).
3. **SSH key** so deploys don't ask for a password: `ssh-copy-id tito@102.212.246.251` (your computer).
4. **Upload the backend**: `./deploy/deploy.sh`, then on the server create `.env` and run the
   first-install commands in `setup.md` §3 (key, permissions, migrate, permissions:sync, storage:link, caches).
5. **Queue worker + scheduler**: `setup.md` §4.
6. **nginx + HTTPS**: `deploy/nginx/README.md` (snippets + API site), then the admin/portal sites from
   `../frontend/deploy/README.md`, then `certbot` for all three names (`deploy/nginx/README.md`).
7. **Frontends** (frontend repo): `./deploy/deploy.sh`.
8. **Check**: the `curl` lines at the end of `nginx/README.md`; sign up at https://admin.rentflow.codepasstech.top/signup.

## Every later release

```bash
./deploy/deploy.sh                          # backend repo: the API
(cd ../frontend && ./deploy/deploy.sh)      # frontend repo: admin + portal
```

The backend script runs `composer install --no-dev`, `migrate --force`, `permissions:sync`, rebuilds the caches, and
restarts the queue worker gracefully (`queue:restart`). The server's `backend/.env` and `backend/storage/` are never touched.

## Later: Cloudflare proxy (orange cloud)

`PROXIED=true ./deploy/cloudflare/dns.sh`, SSL/TLS mode **Full (strict)**, and `TRUSTED_PROXIES=*` in the
backend `.env` followed by `php artisan config:cache`.

## Mobile apps

Build with `frontend/apps/*/.env.production` (`VITE_API_URL=https://api.rentflow.codepasstech.top`) and
`pnpm cap:sync`; the API already allows the Capacitor origins (`CORS_ALLOWED_ORIGINS`).
