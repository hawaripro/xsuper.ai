# Panduan Deploy XSuper.ai ke VPS (Ubuntu + Nginx + PHP-FPM + PostgreSQL)

Panduan langkah-demi-langkah memasang aplikasi ke server production dengan domain **xsuper.dev**.
Stack: Laravel 13 (PHP 8.3), PostgreSQL 18, Nginx + PHP-FPM, Reverb (WebSocket), queue & scheduler.

> Ganti `xsuper.dev` bila domainmu berbeda, dan `<SERVER_IP>` dengan IP VPS.

---

## 0. Prasyarat
- VPS Ubuntu 22.04/24.04 (root/sudo).
- Domain `xsuper.dev` sudah kamu miliki.
- Repo: `https://github.com/hawaripro/ultrai-web.git` (branch `main`).

## 1. DNS (lakukan lebih dulu, propagasi butuh waktu)
Di panel DNS domain, arahkan ke `<SERVER_IP>`:
```
A    xsuper.dev        <SERVER_IP>
A    www.xsuper.dev    <SERVER_IP>
A    api.xsuper.dev    <SERVER_IP>
```

## 2. Paket sistem
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y software-properties-common curl git unzip
sudo add-apt-repository ppa:ondrej/php -y && sudo apt update

# PHP 8.3 + ekstensi (pgsql, mbstring, bcmath, intl, gd, zip, curl, xml)
sudo apt install -y php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring \
  php8.3-bcmath php8.3-intl php8.3-gd php8.3-zip php8.3-curl php8.3-xml php8.3-redis

# PostgreSQL 18
sudo apt install -y postgresql-common
sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y
sudo apt install -y postgresql-18

# Nginx
sudo apt install -y nginx

# Node 20 (untuk build aset) + Composer
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer

# (Opsional) Media tools: ffmpeg + python untuk fitur unduhan/konverter/hapus-latar
sudo apt install -y ffmpeg python3 python3-venv python3-pip
```

## 3. Ambil kode
```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone https://github.com/hawaripro/ultrai-web.git xsuper
sudo chown -R $USER:www-data /var/www/xsuper
cd /var/www/xsuper
```

## 4. Database PostgreSQL (buat role + database)
```bash
sudo -u postgres psql <<'SQL'
CREATE ROLE xsuper_local LOGIN PASSWORD 'GANTI_PASSWORD_KUAT';
CREATE DATABASE xsuper_db OWNER xsuper_local ENCODING 'UTF8';
\c xsuper_db
GRANT ALL ON SCHEMA public TO xsuper_local;
ALTER SCHEMA public OWNER TO xsuper_local;
SQL
```
> PostgreSQL default port 5432. Kalau kamu pakai port khusus (mis. 2209), sesuaikan `DB_PORT`.

## 5. Konfigurasi `.env`
```bash
cp .env.example .env
nano .env
```
Isi minimal:
```dotenv
APP_NAME="XSuper.ai"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://xsuper.dev
APP_LOCALE=id

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=xsuper_db
DB_USERNAME=xsuper_local
DB_PASSWORD=GANTI_PASSWORD_KUAT

SESSION_DRIVER=database
SESSION_DOMAIN=.xsuper.dev
CACHE_STORE=database
QUEUE_CONNECTION=database

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=xsuper
REVERB_APP_KEY=GANTI_KEY_ACAK
REVERB_APP_SECRET=GANTI_SECRET_ACAK
REVERB_HOST=xsuper.dev
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_ALLOWED_ORIGINS=xsuper.dev

MAIL_MAILER=smtp   # isi host/port/user/pass SMTP-mu; OTP email butuh ini
MAIL_FROM_ADDRESS="no-reply@xsuper.dev"
MAIL_FROM_NAME="XSuper.ai"

# Provider AI (proxy internal) — isi sesuai layananmu
AI_PROXY_URL=
AI_PROXY_KEY=

# Google OAuth (opsional, untuk login Google)
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://xsuper.dev/auth/google/callback
```

## 6. Install dependency, key, build, migrasi
```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate            # membuat APP_KEY
npm ci && npm run build             # build aset (Vite)

# Skema + data awal (fresh — data lama tidak dipakai)
php artisan migrate --force --seed
php artisan db:seed --class=FalCatalogSeeder --force

php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```
Login admin awal hasil seeder: **admin@xsuper.dev / password** — segera ganti password setelah login.

## 7. Hak akses folder
```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
```

## 8. Nginx
`sudo nano /etc/nginx/sites-available/xsuper`:
```nginx
server {
    listen 80;
    server_name xsuper.dev www.xsuper.dev api.xsuper.dev;
    root /var/www/xsuper/public;
    index index.php;

    client_max_body_size 25M;      # untuk upload gambar referensi, dst.
    add_header X-Content-Type-Options "nosniff";

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # WebSocket (Reverb) di balik /app
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```
Aktifkan + reload:
```bash
sudo ln -s /etc/nginx/sites-available/xsuper /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

## 9. SSL (HTTPS) gratis via Let's Encrypt
```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d xsuper.dev -d www.xsuper.dev -d api.xsuper.dev
```

## 10. Queue worker + Reverb (WebSocket) sebagai service systemd
Queue worker — `sudo nano /etc/systemd/system/xsuper-queue.service`:
```ini
[Unit]
Description=XSuper queue worker
After=network.target postgresql.service

[Service]
User=www-data
WorkingDirectory=/var/www/xsuper
ExecStart=/usr/bin/php artisan queue:work --sleep=1 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```
Media worker (yt-dlp / ffmpeg / rembg) — `sudo nano /etc/systemd/system/xsuper-media.service`:
```ini
[Unit]
Description=XSuper media tool worker (yt-dlp / ffmpeg / rembg)
After=network.target postgresql.service

[Service]
# MUST run as the PHP-FPM pool user. admit() creates each job's private
# workspace as the web user; a worker running as a different user cannot read
# it, so mkdir()/permission-denied leaves download/convert/rembg jobs stuck at
# "Menunggu antrean" forever.
User=www-data
WorkingDirectory=/var/www/xsuper
ExecStart=/usr/bin/php artisan queue:work media --queue=media --sleep=1 --tries=1 --timeout=450 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```
Reverb — `sudo nano /etc/systemd/system/xsuper-reverb.service`:
```ini
[Unit]
Description=XSuper Reverb websocket
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/xsuper
ExecStart=/usr/bin/php artisan reverb:start --host=127.0.0.1 --port=8080
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```
Aktifkan:
```bash
sudo systemctl daemon-reload
sudo systemctl enable --now xsuper-queue xsuper-media xsuper-reverb
```

## 11. Scheduler (cron Laravel)
```bash
sudo crontab -u www-data -e
# tambahkan baris:
* * * * * cd /var/www/xsuper && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

## 12. Google OAuth (kalau dipakai)
Di Google Cloud Console → Credentials → OAuth Client → Authorized redirect URIs, tambahkan:
```
https://xsuper.dev/auth/google/callback
```

## 13. Backup harian (opsional tapi disarankan)
Sudah ada `scripts/backup.sh` (pg_dump + .env + uploads, izin 600). Sesuaikan `BACKUP_DIR`/`DB_*` di dalamnya, buat `~/.pgpass`, lalu:
```bash
crontab -e
0 2 * * * /var/www/xsuper/scripts/backup.sh
```

---

## Cara update kode setelah ada perubahan (deploy ulang)
```bash
cd /var/www/xsuper
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl restart xsuper-queue xsuper-reverb php8.3-fpm
```

## Checklist verifikasi setelah deploy
- `https://xsuper.dev/up` → 200 (health check).
- Buka `https://xsuper.dev/en/login` → logo & brand **XSuper.ai** muncul.
- Login admin, cek dashboard, AI Catalog (10 model fal), API Keys, Security (IP whitelist).
- Registrasi member baru → wajib aktivasi OTP email (pastikan SMTP jalan).
- Chat/generate → butuh `AI_PROXY_URL`/`AI_PROXY_KEY` valid.

## Catatan penting
- **API key**: prefix baru `xsuper-`. Key lama `ultrai-` tidak berlaku lagi (sesuai keputusan rebrand) — buat ulang dari halaman Admin → API Keys.
- **Base URL API** untuk klien OpenAI/Anthropic: `https://api.xsuper.dev/v1` (arahkan subdomain `api` ke server yang sama; Nginx sudah melayani `server_name api.xsuper.dev`).
- Jaga **APP_KEY** tetap sama bila suatu saat memindah data lama yang terenkripsi; untuk instalasi fresh ini APP_KEY baru tidak masalah.
