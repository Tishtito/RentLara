# One-time server setup (102.212.246.251, Ubuntu)

Everything lives in `/home/tito/rentflow`:

```
/home/tito/rentflow/backend                  Laravel (uploaded by backend deploy/deploy.sh; .env and storage/ stay on the server)
/home/tito/rentflow/frontend/apps/admin/dist  admin app build (uploaded by the frontend repo's deploy/deploy.sh)
/home/tito/rentflow/frontend/apps/portal/dist tenant portal build (same)
```

## 1. Packages (as a sudo user)

```bash
sudo apt update
sudo apt install -y nginx mysql-server certbot python3-certbot-nginx unzip rsync \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
  php8.3-bcmath php8.3-intl php8.3-gd
# Composer
php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw enable
```

(Ubuntu without PHP 8.3 in its repos: `sudo add-apt-repository ppa:ondrej/php` first.)

## 2. Database

```bash
sudo mysql <<'SQL'
CREATE DATABASE rentflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rentflow'@'localhost' IDENTIFIED BY 'CHANGE-ME-strong-password';
GRANT ALL PRIVILEGES ON rentflow.* TO 'rentflow'@'localhost';
FLUSH PRIVILEGES;
SQL
```

## 3. Folders and permissions (as tito)

```bash
mkdir -p ~/rentflow/backend ~/rentflow/frontend/apps/admin/dist ~/rentflow/frontend/apps/portal/dist
chmod o+x /home/tito /home/tito/rentflow          # nginx (www-data) must reach the files
sudo usermod -aG www-data tito                   # tito and PHP-FPM share the www-data group
```

From your computer (backend repo), upload the code once: `./deploy/deploy.sh` (it stops before the
migrations if `.env` is missing). Then on the server:

```bash
cd ~/rentflow/backend
cp .env.production.example .env && nano .env      # DB password, mail, Google…
php artisan key:generate
sudo chown -R tito:www-data storage bootstrap/cache && sudo chmod -R 2775 storage bootstrap/cache
php artisan migrate --force
php artisan permissions:sync
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan event:cache
```

A first landlord: sign up at https://admin.rentflow.codepasstech.top/signup (or, for a platform admin,
`php artisan tinker` and create a `super_admin` user). Don't run `db:seed` in production (it adds test logins).

## 4. Queue worker and scheduler

The app needs PHP 8.4. On this server `/usr/bin/php` is 8.3, so the worker, the cron line and `deploy.sh` call
`php8.4` explicitly (and nginx uses `php8.4-fpm.sock`). Run artisan by hand as `php8.4 artisan …` to be safe.

```bash
sudo cp ~/rentflow/backend/deploy/server/rentflow-queue.service /etc/systemd/system/   # or copy the file from your computer
sudo systemctl daemon-reload && sudo systemctl enable --now rentflow-queue
crontab -u tito ~/rentflow/backend/deploy/server/rentflow-scheduler.cron
```

## 5. nginx + HTTPS

See `../nginx/README.md`.
