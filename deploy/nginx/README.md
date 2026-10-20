# nginx for Rentflow

| File | Host | Root |
|---|---|---|
| `rentflow-api.conf` | api.rentflow.codepasstech.top | `/home/tito/rentflow/backend/public` (Laravel) |
| `snippets/rentflow-php.conf` | shared | PHP-FPM (`/run/php/php8.4-fpm.sock`, change if needed) |
| `snippets/rentflow-common.conf` | shared | gzip, security headers, upload size, ACME challenge, dotfile block |

The admin and tenant sites (`rentflow-admin.conf`, `rentflow-portal.conf`) live in the frontend repo's
`deploy/nginx/` (`../frontend/deploy/README.md`) and include both snippets above, so install these first.

## Install (on the server, as a sudo user)

`deploy.sh` uploads this folder with the backend, so it's already at `~/rentflow/backend/deploy/nginx`:

```bash
cd ~/rentflow/backend
sudo cp deploy/nginx/snippets/rentflow-*.conf /etc/nginx/snippets/
sudo cp deploy/nginx/rentflow-api.conf /etc/nginx/sites-available/
sudo ln -sf /etc/nginx/sites-available/rentflow-api.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Then add the admin and portal sites from the frontend repo before running certbot.

nginx (user `www-data`) must be able to read `/home/tito/rentflow`: `chmod o+x /home/tito` (or `sudo usermod -aG tito www-data`
and `chmod g+x /home/tito`), see `deploy/server/setup.md`.

## HTTPS (Let's Encrypt)

Once the DNS records point at the server (`dig +short api.rentflow.codepasstech.top` → `102.212.246.251`):

```bash
sudo certbot --nginx \
  -d rentflow.codepasstech.top -d admin.rentflow.codepasstech.top -d api.rentflow.codepasstech.top \
  --redirect -m you@example.com --agree-tos --no-eff-email
sudo certbot renew --dry-run      # renewal runs from certbot's systemd timer
```

certbot adds the `listen 443 ssl` lines, the certificate paths and an http → https redirect to each site.
Afterwards you can uncomment `Strict-Transport-Security` in `snippets/rentflow-common.conf` and reload.

## Checks

```bash
curl -I https://api.rentflow.codepasstech.top/up                       # 200 (Laravel health)
curl -I https://admin.rentflow.codepasstech.top/                       # 200, index.html
curl -I https://admin.rentflow.codepasstech.top/admin/bills            # 200 (SPA fallback)
curl -s -o /dev/null -w '%{http_code}\n' https://admin.rentflow.codepasstech.top/sanctum/csrf-cookie   # 204
curl -s https://rentflow.codepasstech.top/api/portal/user -H 'Accept: application/json'                # {"message":"Unauthenticated."}
```
