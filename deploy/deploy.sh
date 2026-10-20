#!/usr/bin/env bash
# Upload the Laravel backend (this repo, deploy/ included) to the server, then install and migrate there.
#
#   ./deploy/deploy.sh
#
# The web apps are deployed from the frontend repo (../frontend/deploy/deploy.sh).
# Needs: ssh access as tito@102.212.246.251 (key-based), rsync. Never touches the server's
# backend/.env or backend/storage.
set -euo pipefail

SERVER="${SERVER:-tito@102.212.246.251}"
REMOTE="${REMOTE:-/home/tito/rentflow}"
PHP="${PHP:-php8.4}"                # the server's plain `php` (/usr/bin/php) is 8.3; the app needs 8.4
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"   # the backend repo

[[ $# -eq 0 ]] || { echo "Usage: $0"; exit 1; }

say() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }

say "Uploading the backend → $SERVER:$REMOTE/backend"
ssh "$SERVER" "mkdir -p $REMOTE/backend"
rsync -az --delete \
  --include '.env.production.example' --exclude '.env' --exclude '.env.*' \
  --exclude 'storage/' --exclude 'vendor/' --exclude 'node_modules/' --exclude 'tests/' \
  --exclude '.git/' --exclude '.idea/' --exclude 'bootstrap/cache/*.php' \
  --exclude 'database/*.sqlite' --exclude 'public/storage' \
  "$ROOT/" "$SERVER:$REMOTE/backend/"

say "Installing and migrating on the server"
ssh "$SERVER" bash -se <<REMOTE_SCRIPT
set -euo pipefail
cd "$REMOTE/backend"
mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/public bootstrap/cache
if [[ ! -f .env ]]; then
  echo "No .env yet: create it from .env.production.example (see deploy/server/setup.md), then deploy again."
  exit 0
fi
$PHP "\$(command -v composer)" install --no-dev --optimize-autoloader --no-interaction
$PHP artisan down --retry=15 || true
$PHP artisan migrate --force
$PHP artisan permissions:sync
$PHP artisan optimize:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache
$PHP artisan up
$PHP artisan queue:restart
REMOTE_SCRIPT

say "Done"
echo "API: https://api.rentflow.codepasstech.top/up"
