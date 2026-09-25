# Panduan Deploy XSuper.ai ke VPS (Ubuntu + Caddy + PHP-FPM + PostgreSQL)

Panduan pemasangan dan pembaruan aplikasi di server production dengan domain **xsuper.dev**.
Stack nyata: Laravel (PHP **8.4**), PostgreSQL, **Caddy** (bukan Nginx), Reverb (WebSocket), queue + media worker, scheduler.

> Ganti `xsuper.dev` bila domainmu berbeda, dan `<SERVER_IP>` dengan IP VPS.

---

## 0. Kondisi production saat ini (acuan, bukan asumsi)

| Item | Nilai nyata di server |
| --- | --- |
| Akses | `ssh xsuper` (alias `~/.ssh/config`) → `root@<SERVER_IP>` port **1453** |
| Path aplikasi | **`/home/ultrax/apps/xsuper`** (bukan `/var/www/xsuper`) |
| Pemilik berkas | `ultrax:ultrax` |
| PHP | **8.4** — `php8.4-fpm`, socket `/run/php/php8.4-fpm.sock`, pool user `www-data` |
| Web server | **Caddy** (`/etc/caddy/Caddyfile`), di belakang Cloudflare |
| Database | PostgreSQL `127.0.0.1:5432`, db `xsuper_db`, user `xsuper_local` |
| Repo | `https://github.com/hawaripro/xsuper.ai.git`, branch `main` |
| Service | `xsuper-queue`, `xsuper-media`, `xsuper-reverb` |
| Mail | `MAIL_MAILER=resend` (butuh paket `resend/resend-php`, sudah ada di `composer.json`) |

Semua perintah aplikasi dijalankan sebagai **`ultrax`** agar kepemilikan berkas tidak rusak:
```bash
sudo -H -u ultrax <perintah>
```

---

## 1. DNS
Arahkan ke `<SERVER_IP>`:
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

# PHP 8.4 + ekstensi
sudo apt install -y php8.4-fpm php8.4-cli php8.4-pgsql php8.4-mbstring \
  php8.4-bcmath php8.4-intl php8.4-gd php8.4-zip php8.4-curl php8.4-xml php8.4-redis

# PostgreSQL
sudo apt install -y postgresql-common
sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y
sudo apt install -y postgresql-18

# Caddy
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update && sudo apt install -y caddy

# Node 22 LTS untuk build frontend + Composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer

# Media tools (unduhan / konverter / hapus latar)
sudo apt install -y ffmpeg python3 python3-venv python3-pip
sudo mkdir -p /opt/xsuper-media && sudo python3 -m venv /opt/xsuper-media/venv
sudo /opt/xsuper-media/venv/bin/pip install "rembg[cpu]"
sudo mkdir -p /opt/xsuper-media/rembg-models
```

Downloader memerlukan **Node >=25.9** untuk permission mode yang menolak akses jaringan, bukan Node 22 untuk build frontend. Pasang rilis Node yang masih mendapat patch keamanan ke `/opt/xsuper-media/node`, verifikasi checksum dari distribusi resmi, dan batasi akses tulis direktori runtime kepada operator. Jangan menurunkan sandbox agar Node lama lolos. Dependency downloader dipasang dari pin repo pada langkah berikut.

## 3. Ambil kode
```bash
sudo mkdir -p /home/ultrax/apps && cd /home/ultrax/apps
sudo -u ultrax git clone https://github.com/hawaripro/xsuper.ai.git xsuper
cd /home/ultrax/apps/xsuper
sudo /opt/xsuper-media/venv/bin/pip install -r scripts/media/requirements.txt
git config --global --add safe.directory /home/ultrax/apps/xsuper
```

## 4. Database PostgreSQL
```bash
sudo -u postgres psql <<'SQL'
CREATE ROLE xsuper_local LOGIN PASSWORD 'GANTI_PASSWORD_KUAT';
CREATE DATABASE xsuper_db OWNER xsuper_local ENCODING 'UTF8';
\c xsuper_db
GRANT ALL ON SCHEMA public TO xsuper_local;
ALTER SCHEMA public OWNER TO xsuper_local;
SQL
```

## 5. Konfigurasi `.env`
```bash
sudo -u ultrax cp .env.example .env && sudo -u ultrax nano .env
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
SESSION_SECURE_COOKIE=true
SESSION_PATH=/
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

# Email transaksional (OTP aktivasi) — production memakai Resend
MAIL_MAILER=resend
RESEND_API_KEY=
MAIL_FROM_ADDRESS="no-reply@xsuper.dev"
MAIL_FROM_NAME="XSuper.ai"

# Media tools
MEDIA_TOOLS_ENABLED=true
MEDIA_PYTHON_PATH=/opt/xsuper-media/venv/bin/python
MEDIA_FFMPEG_PATH=/usr/bin/ffmpeg
MEDIA_FFPROBE_PATH=/usr/bin/ffprobe
MEDIA_REMBG_MODEL_DIR=/opt/xsuper-media/rembg-models
MEDIA_NODE_PATH=/opt/xsuper-media/node/bin/node

# Aktivasi media coordinator (lihat "Catatan penting")
MEDIA_KILL_SWITCH=false
MEDIA_COORDINATOR_RESTRICTED=false

# Sesi video realtime berharga eksplisit; boleh dipendekkan, tidak melebihi 60 detik.
REALTIME_MEDIA_MAX_SESSION_SECONDS=60

# Provider AI
AI_PROXY_URL=
AI_PROXY_KEY=

# Google OAuth (opsional)
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://xsuper.dev/auth/google/callback
```

## 6. Install dependency, key, build, migrasi
```bash
cd /home/ultrax/apps/xsuper
sudo -H -u ultrax composer install --no-dev --optimize-autoloader
sudo -H -u ultrax composer check-platform-reqs --no-dev
# Hanya instalasi baru dengan APP_KEY kosong; jangan rotasi key pada update/restorasi.
sudo -H -u ultrax php artisan key:generate
sudo -H -u ultrax npm ci --ignore-scripts && sudo -H -u ultrax npm run build

sudo -H -u ultrax php artisan migrate --force
sudo -H -u ultrax php artisan db:seed --class=FalCatalogSeeder --force

sudo -H -u ultrax php artisan storage:link
sudo -H -u ultrax php artisan config:cache && sudo -H -u ultrax php artisan route:cache && sudo -H -u ultrax php artisan view:cache
```
Tidak ada akun admin dengan password bawaan di production/staging. `DatabaseSeeder` dan `OperationalDataSeeder` sengaja menolak kedua lingkungan ini (juga lingkungan lain selain `local`/`testing`).

Untuk instalasi baru, daftarkan akun pemilik dengan alamat email yang benar-benar dikuasai dan password unik, lalu selesaikan verifikasi email. Operator server kemudian membuka `sudo -H -u ultrax php artisan tinker` dan mempromosikan **hanya akun pemilik yang telah diverifikasi**:

```php
$owner = App\Models\User::where('email', 'EMAIL_PEMILIK_TERVERIFIKASI')->whereNotNull('email_verified_at')->sole();
$owner->forceFill(['role' => 'admin'])->save();
```

Ganti placeholder dengan email pemilik, bukan akun demo. Setelah login, aktifkan 2FA dan tinjau kebijakan IP admin. Untuk instalasi lama, pertahankan akun yang ada; audit dan cabut akun demo yang pernah dibuat sebelum aturan seeder ini. Perubahan kode tidak otomatis mencabut kredensial yang sudah tersimpan.

Login Google tetap mengikuti status akun, IP admin, perangkat, dan 2FA lokal. Akun lokal yang belum terverifikasi tidak ditautkan otomatis hanya karena alamat emailnya sama. Perubahan/reset password mencabut sesi tersimpan dan remember token; perubahan email membatalkan OTP/verifikasi sebelumnya. Perangkat baru didaftarkan hanya setelah faktor autentikasi lengkap; penolakan perangkat tidak menghabiskan recovery code.

## 7. Hak akses folder
`storage/` dan `bootstrap/cache` ditulis oleh PHP-FPM (`www-data`) **dan** worker:
```bash
sudo chown -R ultrax:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
```

## 8. Caddy
`sudo nano /etc/caddy/Caddyfile`:
```caddyfile
{
	email kamu@example.com
	admin off
	servers {
		# Cloudflare di depan: percayai IP edge-nya
		trusted_proxies static 173.245.48.0/20 103.21.244.0/22 104.16.0.0/13 172.64.0.0/13 131.0.72.0/22
		client_ip_headers CF-Connecting-IP
	}
}

xsuper.dev, www.xsuper.dev, api.xsuper.dev {
	root * /home/ultrax/apps/xsuper/public
	encode gzip

	@hashed path /build/*
	header @hashed {
		X-Content-Type-Options "nosniff"
		Cache-Control "public, max-age=31536000, immutable"
	}

	# Reverb. WAJIB mencakup /apps/* — itu HTTP API yang dipakai
	# server saat mem-broadcast event (POST /apps/{id}/events).
	# Tanpa /apps/*, request jatuh ke Laravel, balas HTML, dan log
	# dipenuhi "Pusher error: <!DOCTYPE html>" sementara update
	# realtime mati (UI terpaksa polling).
	@reverb path /app /app/* /apps/*
	handle @reverb {
		reverse_proxy 127.0.0.1:8080
	}

	handle {
		php_fastcgi unix//run/php/php8.4-fpm.sock
		file_server
	}
}
```
Terapkan:
```bash
caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
sudo systemctl restart caddy        # BUKAN reload — lihat Catatan penting
```

## 9. SSL (HTTPS)
Caddy menerbitkan sertifikat Let's Encrypt otomatis. Bila memakai Cloudflare proxy, set SSL/TLS mode **Full (strict)**.

## 10. Service systemd
Queue worker — `/etc/systemd/system/xsuper-queue.service`:
```ini
[Unit]
Description=XSuper queue worker
After=network.target postgresql.service

[Service]
User=ultrax
WorkingDirectory=/home/ultrax/apps/xsuper
ExecStart=/usr/bin/php /home/ultrax/apps/xsuper/artisan queue:work --sleep=1 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```
Media worker — `/etc/systemd/system/xsuper-media.service`:
```ini
[Unit]
Description=XSuper media tool worker (yt-dlp / ffmpeg / rembg)
After=network.target postgresql.service

[Service]
# WAJIB sama dengan pool user PHP-FPM (www-data). admit() membuat workspace
# privat tiap job sebagai user web; worker dengan user berbeda tidak bisa
# membacanya sehingga job unduh/konversi/hapus-latar macet di "Menunggu antrean".
User=www-data
WorkingDirectory=/home/ultrax/apps/xsuper
ExecStart=/usr/bin/php /home/ultrax/apps/xsuper/artisan queue:work media --queue=media --sleep=1 --tries=1 --timeout=450 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```
Reverb — `/etc/systemd/system/xsuper-reverb.service`:
```ini
[Unit]
Description=XSuper Reverb websocket
After=network.target

[Service]
User=ultrax
WorkingDirectory=/home/ultrax/apps/xsuper
ExecStart=/usr/bin/php /home/ultrax/apps/xsuper/artisan reverb:start --host=127.0.0.1 --port=8080
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

## 11. Scheduler
```bash
sudo crontab -u ultrax -e
* * * * * cd /home/ultrax/apps/xsuper && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```
Scheduler juga menjalankan `media:reconcile-workspace` dan `media:reconcile-realtime` setiap menit. Yang pertama memulihkan antrean/polling/penyimpanan tanpa mengulang pengiriman berbayar; yang kedua menutup lease realtime kedaluwarsa dan merekonsiliasi admission yang terputus. Jangan menggantinya dengan loop pengiriman ulang atau refund otomatis: penerimaan provider yang belum pasti tetap ditahan.


## 12. Google OAuth
Authorized redirect URI: `https://xsuper.dev/auth/google/callback`

## 13. Backup harian
`scripts/backup.sh` (pg_dump + .env + uploads). Sesuaikan `BACKUP_DIR`/`DB_*`, buat `~/.pgpass`, lalu:
```bash
0 2 * * * /home/ultrax/apps/xsuper/scripts/backup.sh
```

---

## Update kode (deploy ulang)
```bash
cd /home/ultrax/apps/xsuper
sudo -H -u ultrax git pull origin main
sudo -H -u ultrax composer install --no-dev --optimize-autoloader
sudo -H -u ultrax composer check-platform-reqs --no-dev
sudo -H -u ultrax npm ci --ignore-scripts && sudo -H -u ultrax npm run build
sudo -H -u ultrax php artisan migrate --force
sudo -H -u ultrax php artisan config:cache
sudo -H -u ultrax php artisan route:cache
sudo -H -u ultrax php artisan view:cache
sudo systemctl restart php8.4-fpm xsuper-queue xsuper-media xsuper-reverb
```
Pastikan `git status` bersih sebelum pull. Kalau ada perubahan lokal di server, itu tanda ada sesuatu yang belum masuk repo — commit ke repo, jangan dibiarkan sebagai diff server.

## Persiapan workspace chat dan media global

Bagian ini adalah prosedur saat deployment disetujui, bukan pernyataan bahwa perubahan lokal sudah terpasang di production.

- Jalankan `composer install` dari lockfile: validator kontrak v2 membutuhkan dependency runtime `opis/json-schema`.
- Backup database sebelum migrasi. Enam migrasi `2026_09_23_120000`–`120040` (workspace/lampiran chat, operasi streaming, artefak dan revisinya, admission/job media global, nama asli aset, sesi realtime), `2026_09_25_000001` (`image_jobs.batch_key`), dan `2026_09_25_000002` (checkpoint hasil native video/audio) bersifat aditif; riwayat chat serta job native tetap digunakan. Jalankan migrasi sebelum web/worker baru melayani pekerjaan. Jangan menjalankan `migrate:fresh` di database pengguna.
- Pastikan worker antrean `media` dan scheduler berjalan. Panggilan media generik memakai job tahan-restart; sesi realtime memakai lease terbatas, bukan job polling.
- Discovery katalog bukan publikasi. Untuk membuat kandidat v2 dari skema yang sudah tersimpan, gunakan command offline berikut sebagai pemilik aplikasi; `PROVIDER_ID` dan `ADMIN_ID` adalah ID database yang sebenarnya:

```bash
sudo -H -u ultrax php artisan media:renormalize-catalog \
  --provider=PROVIDER_ID --actor=ADMIN_ID --limit=100 \
  --report=storage/logs/media-contract-coverage.ndjson
```

Lanjutkan batch dengan `--after=<next_after>` dari ringkasan sampai `next_after` bernilai `null`. Command ini tidak menghubungi provider, menetapkan harga, mengaktifkan model, atau memublikasikan kandidat. Tinjau blockers per endpoint; jangan mengarang input untuk sumber yang tidak mempunyai kontrak request.

Setiap kali importer/normalizer kontrak berubah (termasuk pada deploy pertama cabang ini), jalankan ulang command di atas untuk semua provider Fal. Kontrak yang sudah terbit tidak berubah sendiri: command hanya membuat revisi kandidat baru, dan pekerjaan lama tetap memakai revisinya. Publikasi bulk hanya untuk model baru tanpa harga positif. Untuk model yang sudah berharga (contoh: revisi Director yang field berkasnya kini menerima tautan HTTPS publik), buka **Katalog AI → provider → Tinjau capability**, pilih revisi baru, centang persetujuan harga dan unit yang berlaku, lalu **Publikasikan revisi**. Harga tidak berubah.

Admin memilih kandidat, meninjau tarif/unit serta biaya konfigurasi variabel, lalu mengonfirmasi publikasi lewat katalog. Model baru tanpa harga tetap tidak tersedia untuk member; harga positif yang sudah ada tidak ditimpa. Kontrak v2 dijalankan lewat Studio Media `/studio`, bukan endpoint formulir legacy v1. Credential/provider bindings tidak dikirim ke browser.

Realtime Director memerlukan dukungan WebRTC/data channel di browser dan akses ke layanan WMA provider. Tarif sesi dan batas waktunya harus ditinjau sebelum publikasi; `REALTIME_MEDIA_MAX_SESSION_SECONDS` hanya dapat memperpendek plafon 60 detik. Menutup koneksi tidak membuktikan refund; sesi yang penerimaannya belum pasti tidak dikirim ulang. Rekaman opsional menyimpan byte stream yang benar-benar diterima dan tetap memakai kuota akun.

Chat memakai capability server untuk lampiran, tools, stop, dan model. Web search, code interpreter, dan revisi artefak dengan AI tidak diaktifkan tanpa executor yang nyata. Voice mengikuti dukungan serta izin browser. Artefak teks memiliki revisi immutable, HTML dipreview dalam sandbox tanpa script, dan hasil biner asli diunduh melalui endpoint milik pengguna.

## Studio Media halaman penuh dan provider Runware

Bagian ini adalah prosedur saat deployment disetujui.

- **Studio Media `/studio`** adalah satu halaman penuh seperti `/chat` (tanpa sidebar dashboard). Alamat lama `/generate-image`, `/video`, `/audio`, `/avatar`, `/3d`, dan `/media` tetap dapat dibuka: semuanya dialihkan ke `/studio?kind=…` dengan parameter `job`, `track`, `model`, dan `operation` dipertahankan, sehingga tautan notifikasi, Library, dan pencarian lama tetap berfungsi. Gerbang izin per jenis tidak berubah. Sidebar dashboard hanya memiliki satu entri **Studio Media**. Setiap model tampil dengan logo resmi pembuatnya (SVG LobeHub berlisensi MIT di `public/brands/ai/`, dicocokkan dari ID/nama model); logo yang diisi admin tetap diutamakan, dan model tanpa logo resmi memakai ikon jenisnya. Satu warna flat per jenis (gambar ungu, video oranye, audio teal, avatar amber, 3D biru) mengisi pita header panel permintaan, indikator tab, filter aktif, ubin ikon, plat placeholder, dan garis progres; Generate tetap merah brand. Gerak: angka estimasi bergulir, indikator tab dan sorotan palet meluncur, hasil yang selesai "terbentuk" dari buram ke tajam; semuanya berhenti bila perangkat meminta "kurangi gerakan". Tombol bahasa/tema di halaman login, daftar, dan reset password berada di pita atas tersendiri sehingga tidak menimpa logo di layar pendek.
- **Tidak ada migrasi baru** untuk Studio Media maupun Runware: protokol provider disimpan sebagai string, dan identitas tugas Runware memakai kolom `upstream_job_id` yang sudah ada.
- **Menambahkan Runware** (admin):
  1. Admin → **Provider AI** → tambah provider, protokol **Runware**, URL `https://api.runware.ai/v1`. Isi API key dari dashboard Runware langsung di formulir; jangan kirim key lewat chat, tiket, atau commit.
  2. **Periksa akun & saldo** memanggil `accountManagement getDetails` (tidak berbayar). Saldo USD, saldo gratis, dan pemakaian hari ini/7/30 hari tampil di halaman detail provider, hanya untuk admin.
  3. Top-up dilakukan di dashboard Runware. Jika saldo habis, Runware menjawab HTTP 402: job ditolak, token member dikembalikan, dan log mencatat perlunya top-up.
  4. Impor katalog dari skema publik Runware (tanpa biaya): tombol impor per halaman di **Model & Harga** provider, atau perintah offline `php artisan media:import-runware-catalog PROVIDER_ID --from=DIR --after=0 --limit=50` yang membaca salinan katalog publik (`index.json`, `content-models.json`, `creators.json`, `schemas/`, `examples/`). Hasilnya kandidat v2 yang belum terbit; model baru nonaktif tanpa harga. Model usang, segera hadir, dan LLM (teks ke teks) dilewati. Impor ulang tidak menduplikasi revisi dan tidak menimpa label/harga kurasi. Bila skema satu model gagal diambil padahal skemanya sudah pernah tersimpan, model itu dilewati sehingga unit harga dan normalisasi ulang tetap memakai sumber lama.
  5. Tinjau kandidat. Referensi harga provider (USD dan unitnya) hanya tampil untuk admin. Tetapkan harga token dengan unit katalog model: `generation` (per hasil, dikalikan `numberResults`) atau `second` (per detik durasi, juga dikalikan `numberResults`). Operasi yang ditagih Runware per detik tetapi tidak punya input durasi (mis. lip-sync, upscaler video) ditandai perlu penanganan dan tidak dapat dipublikasikan. Catat modal top-up nyata di **Harga & Tagihan → Auto-harga → Modal efektif penyedia**; jangan mengisi nol atau kurs simulasi sebagai modal.
  6. Publikasi mensyaratkan provider aktif, sehat, dan terautentikasi.
- **Eksekusi Runware**: semua tugas dikirim async lalu dibaca dengan `getResponse`. `taskUUID` disimpan sebelum permintaan berbayar dikirim, sehingga kiriman yang hasilnya tidak pasti dapat dicocokkan kembali (berhasil → disimpan; error eksplisit → gagal dan token kembali; tidak ditemukan atau gangguan jaringan → tetap menunggu tinjauan admin). Kegagalan sebelum permintaan meninggalkan server (konfigurasi, DNS/pinning, tujuan tidak valid) langsung ditolak dan token dikembalikan, karena Runware belum menerima apa pun. Permintaan tidak pernah dikirim ulang otomatis. Runware tidak menyediakan pembatalan sisi server, dan pembatalan di sisi klien tetap ditagih Runware. Biaya aktual (`cost`) disimpan privat pada hasil job dan tidak pernah dikirim ke member. URL hasil Runware berlaku 7 hari; hasil disalin ke penyimpanan privat saat job selesai.
- **Jumlah hasil dan kegagalan sebagian (Runware)**: `numberResults` dibatasi 1–4 per permintaan. Bila salah satu hasil gagal (misalnya ditolak filter konten), seluruh job gagal dan token member dikembalikan penuh, karena ledger token hanya dapat menagih atau mengembalikan satu reservasi secara utuh. Runware tetap menagih hasil yang berhasil ke saldo Runware; biaya ini ditanggung XSuper. Pantau di pemakaian akun Runware.
- **Tarif provider yang bukan per hasil/per detik** (per karakter, per byte, per megapiksel, per langkah, dan sejenisnya, misalnya TTS): model dijual per hasil. Harga token harus menutup input terbesar yang diizinkan skema; catatan ini tampil di referensi harga admin. Jangan menebak harga tanpa landed cost.
- **Tata letak**: pengumuman CMS selalu paling atas di dashboard, Chat, Studio, dan situs publik; topbar/header turun tepat di bawahnya, dan drawer/menu mobile mulai di bawah pengumuman (sama dengan desktop). Pencarian dashboard berada di tengah topbar.
- **Aktivitas perangkat (koreksi atas perubahan QA)**: kunci baris user kini hanya dipakai saat perangkat BARU diterima, sehingga admisi perangkat tetap terserialisasi. Aktivitas perangkat yang sudah dikenal ditulis tanpa transaksi, maksimal sekali per menit atau segera saat alamat IP berubah. Sebelumnya setiap request login mengunci baris user dan request paralel satu akun saling antre.

## Harga dan akses berbasis saldo

- `pricing_settings` (baris `id=1`) menyimpan margin, buffer, fee, dan kurs jual dompet. `DEPOSIT_IDR_PER_USD` hanya dipakai untuk nilai awal saat migrasi; checkout berikutnya membaca `wallet_idr_per_usd`. Order deposit lama tetap memakai snapshot kursnya.
- Modal USD provider yang belum diisi memakai default **Rp19.000/$ sebagai estimasi**, bukan jaminan margin aktual. Biaya berdenominasi kredit tanpa modal per kredit tetap tidak diketahui. Biaya/modal provider hanya muncul dalam payload admin.
- Membership yang habis tidak mengunci Chat, Studio, API, atau saldo. Akun aktif, verifikasi email, izin fitur, serta saldo/kuota tetap diperiksa.
- Wallet menyimpan integer micro-USD. Reservasi menyimpan tarif input/output/cache; settlement memakai snapshot tersebut, bukan tarif terbaru. Cache Anthropic adalah bagian dari total input, bukan tambahan yang ditagih dua kali. Cache tanpa tarif tersendiri memakai tarif input.
- Saldo tidak cukup sebelum panggilan LLM menghasilkan HTTP **402**, tanpa request provider. Usage final hilang/tidak valid atau shortfall tetap menahan reservasi untuk pemeriksaan; jangan mengembalikan saldo seolah-olah provider belum menghasilkan jawaban.
- Migrasi benefit membership mempertahankan harga yang dikustomisasi. Hanya harga enam bulan lama Rp299.000 diturunkan menjadi Rp259.000; kolom snapshot order lama bernilai nol, tanpa bonus retroaktif.

### Mengoperasikan Auto-harga

- Buka **Harga & Tagihan → Auto-harga**. Simpan margin utama, override LLM opsional (kosong = ikut margin utama), buffer, fee, kurs jual Saldo AI, kurs modal bawaan, pembulatan token, dan batas output chat. Minimal satu paket token aktif diperlukan untuk harga media.
- Isi modal penyedia secara langsung atau lewat kalkulator **Bayar Rp / jumlah USD atau kredit yang diterima**. Nilai efektif tersimpan empat desimal; catatan modal tetap privat untuk admin.
- **Perbarui data biaya** hanya mengambil metadata harga gratis melalui Laravel HTTP: dokumentasi Runware, katalog referensi OpenRouter/LiteLLM (cache 12 jam), pricing API fal (maksimal 50 endpoint per permintaan), dan tabel kredit Kinovi. Konfigurasi sumber ada di `config/pricing.php`. Harga referensi/measured ditandai estimasi; unit GPU/compute atau konfigurasi yang tidak dapat dipetakan aman tetap belum diketahui.
- Tinjau filter LLM/Media, status, pencarian, dasar modal, serta harga lama dan baru. Modal manual tidak ditimpa refresh. **Kunci harga jual** mencegah apply pada model itu; membuka kunci tidak langsung mengubah harga.
- **Terapkan harga** memerlukan konfirmasi dan berlaku untuk seluruh katalog yang tidak dikunci, bukan hanya filter aktif. Biaya chat yang tidak diketahui menonaktifkan seluruh tarif API model, termasuk cache; harga media yang tidak diketahui tetap. Tarif input/output selalu diterbitkan berpasangan. Tampilan USD paket durasi dan storage mengikuti kurs jual.
- Apply menyamakan harga token profil dengan setiap revisi v2 yang telah ditinjau/terbit, mempertahankan batas sesi realtime, mencatat `pricing_runs` serta audit `pricing.auto.applied`, lalu menghapus cache katalog publik. Sinkronisasi provider hanya mengumpulkan/menerapkan harga untuk model baru; tidak lagi menanam tarif LLM flat atau token media buatan.
- Video native **Kinovi dan fal ditagih per detik yang dipilih**. Kinovi tetap menawarkan 5/8/10 detik. Fal mengalikan tarif per detik dengan durasi dan, bila dipilih, kualitas Pro ×2; tarif modal mengakomodasi konfigurasi/referensi/tier termahal per detik yang dapat dipilih. Harga sesi realtime tetap per sesi/permintaan, bukan per detik.


## Checklist verifikasi setelah deploy
- `https://xsuper.dev/up` → 200.
- Halaman login render (bundel `app-*.js` terbaru terpakai).
- `systemctl is-active php8.4-fpm xsuper-queue xsuper-media xsuper-reverb` → semua `active`.
- Jalankan satu job (mis. Hapus Latar) lalu `tail storage/logs/laravel.log` → tidak ada `Pusher error`.
- Studio gambar/video/audio/avatar/3D: operasi, input, tarif, dan hasil mengikuti capability model; uji operasi native yang sebelumnya tersedia serta satu kandidat v2 yang sudah ditinjau. Fitur studio lama tetap ada: video (Teks/Gambar ke video, Produk dengan sudut kamera, UGC dengan alur cerita, CTA, Jumlah video, variasi, Pro 2×), gambar (dialog konfirmasi, variasi, Bandingkan, zoom), audio (Voiceover/Musik, pilihan suara dan bahasa, lirik sendiri/instrumental/tempo, pilih track), avatar (izin wajah dan suara, aturan penggunaan), serta riwayat (hapus per baris dan Bersihkan riwayat untuk gambar/video).
- Chat: buka riwayat lama, kirim/stop respons, pindah percakapan dengan draft/lampiran, simpan notes, lalu simpan dan unduh revisi artefak.
- Library: tab **Hapus Latar** berisi hasil.
- `/studio`: model tanpa harga/publikasi/izin tidak ditawarkan; download hasil tetap privat dan kegagalan penyimpanan tidak memicu generasi baru. Alamat lama (`/generate-image?job=…`, `/video`, `/audio`, `/avatar`, `/3d`, `/media`) dialihkan ke `/studio` dengan parameter yang sama.
- Runware (bila provider sudah ditambahkan): **Periksa akun & saldo** menampilkan saldo; satu generasi gambar kecil dari model yang sudah ditinjau dan dipublikasikan selesai dan tersimpan; biaya USD tidak terlihat oleh member.
- Pengumuman CMS (bila ada): di ponsel, pengumuman berada di atas topbar/header saat halaman digulir dan saat menu/sidebar dibuka.
- Uji **Coba simpan hasil lagi** setelah kuota dibebaskan: byte asli tersimpan, saldo tidak dipotong ulang, dan tidak ada POST generasi provider baru. Checkpoint hasil serta reservasi tetap ada ketika retry unduhan/antrean gagal. Kuota dihitung dari jumlah byte hasil akhir, termasuk semua track audio.
- `php artisan schedule:list`: rekonsiliasi workspace dan realtime tercantum; batas sesi serta tarif realtime ditampilkan sebelum Start.

## Catatan penting
- **Caddy `admin off`** → `systemctl reload caddy` SELALU gagal (`localhost:2019 connection refused`). Gunakan `caddy validate` lalu `systemctl restart caddy`.
- **Reverb `/apps/*`** wajib di-proxy (lihat komentar di Caddyfile), kalau tidak update realtime mati.
- **Aktivasi coordinator — PRASYARAT DEPLOY (keputusan pemilik produk, 25 Sep 2026)**: cabang ini dideploy dengan `MEDIA_COORDINATOR_RESTRICTED=false`, sehingga semua member memakai jalur coordinator lewat workspace global (kelima studio tidak lagi memakai jalur lama). Keputusan diambil karena server belum punya member sungguhan (masih uji). Setelah mengubah `.env`, jalankan `php artisan config:cache` lalu restart `xsuper-media` dan `xsuper-queue`, dan pastikan nilai efektifnya `false` di proses web maupun worker. Jika dibiarkan `true`, workspace menolak semua user selain `MEDIA_COORDINATOR_USER_ID` (studio menampilkan "restricted"). Permintaan beberapa gambar native berjalan sebagai beberapa job (satu gambar per job) dan ditampilkan sebagai satu set variasi.
- **Hasil media yang menunggu tinjauan**: job workspace yang sudah diterima provider tetapi hasilnya tidak dapat diambil dalam 6 jam, atau yang koneksi providernya berubah (fingerprint berbeda), berpindah ke `status=uncertain`, `stage=result_uncertain`. Token tetap dicadangkan (tidak dikembalikan, tidak ditagih) dan tidak ada generasi baru. Menyimpan ulang API key yang sama tidak mengubah fingerprint. **Belum ada layar admin** untuk job workspace (`/admin/ai/queue` hanya memuat job native dan tidak memfilter `uncertain`); rekonsiliasi `workspace_media_jobs` berstatus `uncertain` (`submission_uncertain` maupun `result_uncertain`) saat ini hanya lewat kueri database manual.
- **Stream chat workspace**: batas 120 detik adalah batas DIAM (tanpa byte masuk), bukan batas total; jawaban panjang yang terus mengalir tidak dipotong.
- **Kolasi PostgreSQL**: migrasi `2026_09_23_120001` memasang ulang `COLLATE public.xsuper_unicode_ci` pada `chat_history.model`, `chat_history.content`, dan `usage_logs.model` setelah `->change()`. Setelah migrasi, periksa `information_schema.columns.collation_name` untuk ketiga kolom itu (harus `xsuper_unicode_ci`). Diverifikasi di profil tes PostgreSQL 18 lokal.
- **`MEDIA_KILL_SWITCH=true`** menghentikan SEMUA pengiriman job media baru (job berjalan tetap selesai).
- **Reservasi API yang ditahan**: hasil selesai dengan usage tidak sah/tidak lengkap atau saldo final tidak cukup tidak direfund sebagai kegagalan provider. Ledger mempertahankan reservasi dan snapshot tarif; SSE mengirim billing error tanpa terminal sukses. Operator perlu memeriksa usage provider dan ledger sebelum settlement/release manual. Tidak ada layar atau rekonsiliasi otomatis untuk kasus ini.
- **Kuota hasil native**: `save_failed` menyimpan checkpoint privat dan menahan token. Pengguna membebaskan kuota lalu menyimpan hasil asli lewat `/studio`; jangan mengubah job menjadi `queued` atau mengirim ulang generasi. URL provider dapat kedaluwarsa, sehingga jangan menjanjikan pemulihan tanpa batas waktu.
- **Runtime media**: pastikan path FFmpeg/ffprobe benar-benar dapat dieksekusi oleh user worker, Python memakai dependency downloader yang dipin, Node media memenuhi batas versi, dan bobot rembg tersedia. Path contoh atau lolosnya build frontend tidak membuktikan runtime ini tersedia. Verifikasi satu konversi dan hapus-latar nyata; downloader juga memerlukan uji jaringan yang diizinkan.
- **Mail**: `MAIL_MAILER=resend` membutuhkan `resend/resend-php` (sudah menjadi dependency repo) dan `RESEND_API_KEY`.
- **API key**: prefix `xsuper-`; key lama `ultrai-` tidak berlaku.
- Jaga **APP_KEY** tetap sama bila memindahkan data terenkripsi.
