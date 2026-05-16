# HANDOFF CONTEXT — UltrAI Web Development

## Project Info
- **Repo**: hawaripro/ultrai-web (Private)
- **Stack**: Laravel 13 + React 19 + Tailwind 4 + PostgreSQL 16 + Vite
- **Local path**: `C:\Users\Hawari\ultrai-web`
- **VPS**: 103.196.153.160, SSH port 1453, user superpro, path `/home/superpro/ultrai-web`
- **AI Proxy**: enowxai Docker di `127.88.41.7:1430` (aktif, streaming smooth)
- **Proxy baru** (binary): `127.88.41.8:1435` — ada tapi TIDAK dipakai (streaming batch/terpotong)
- **DB**: ultrai_db, user: ultrai, pass: UltrAI@2026!Secure
- **Cloudflare**: semua domain di-proxy (ultrai.id, api.ultrai.id, app.ultrai.id, dash.ultrai.id, get.ultrai.id)

## URLs
- ultrai.id → Laravel SPA (landing + dashboard + chat)
- api.ultrai.id → External API (/v1/models, /v1/chat/completions) dengan API key per user
- app.ultrai.id → AI Dashboard Official (proxy ke binary 127.88.41.8:1436, auth gate)
- dash.ultrai.id → enowxai dashboard (rebranded UltrAI, auth gate + cookie dash_token)
- chat.ultrai.id → Chat AI Pro (OpenWebUI)

## Deploy Method
- Edit file di lokal → `npm run build` → `git add -A && git commit && git push origin main`
- Di VPS: `curl` download file dari GitHub → `npm run build && php artisan optimize:clear && php artisan optimize`
- JANGAN edit file di VPS pakai nano/vim
- Token GitHub sering expired — user generate baru tiap kali

## Kata Terlarang (harus di-scrub dari semua output)
enowx, enowxai, enowx labs, enowxlabs, EnowX, Labs (setelah UltrAI), Kiro, system prompt, instruksi, konfigurasi, disajikan, di-serve, infrastruktur, deployment

## Model Tier Mapping
- Standard → "Original" (user biasa)
- MAX → "Authentic" (premium)
- Codex → "Codex"
- Wavespeed → "Wavespeed"
- YepAPI → "YepAPI"
- Canva → "Canva"

## Model Aliases (Original tier, route ke model lain)
| Alias ID | Tampil sebagai | Dikirim ke proxy |
|----------|---------------|-----------------|
| claude-opus-4-6 | Claude Opus 4-6 | claude-sonnet-4 |
| claude-opus-4-7 | Claude Opus 4-7 | claude-sonnet-4.5 |
| gpt-5-5 | GPT-5-5 | qwen3-coder-next |

Model asli `claude-opus-4.6`, `claude-opus-4.7`, `gpt-5.5` (dengan dot) tetap ada di Authentic tier — dikirim langsung ke proxy tanpa alias.

## Permissions System (per user, admin toggle)
chat, chat_history, chat_ai_pro, model_original, model_authentic, model_codex, model_wavespeed, model_yepapi, model_canva, video_generator, ai_api, ai_dashboard, ai_dashboard_official

## Fitur Yang Sudah Ada (lengkap)
- Landing page (13 sections, redesigned, carousel, pricing, FAQ, testimonials, dll)
- Login (branded, Google OAuth, no register, admin+member role, Fortify views disabled)
- Dashboard (hero, stats, quick actions: Chat AI + AI API + Chat AI Pro + Video Generator + Tambah Durasi, popular models, service status, account card with duration)
- Chat AI Full Page (/chat) — model selector dropdown, all categories, file upload/drag&drop/paste, streaming SSE with buffer handling, copy button, permission check
- Chat AI Pro → link ke chat.ultrai.id
- Video Generator (prompt manual + A/B testing, token system)
- Admin: Kelola Users (CRUD, permissions, duration/expiry, API key management, device management with confirm modals)
- Admin: Period Management (/periods) — pending orders, approve/reject/delete, add duration manual
- Admin: Token Usage (recharts, realtime)
- Admin: Session Chat (/sessions) — OpenWebUI user management with confirm modals
- Profile (edit nama/email/password, countdown timer for duration)
- Tambah Durasi Modal (multi-step: select package → QRIS → pending approval with 3s polling → success animation)
- Device tracking (browser/mobile/plugin, fingerprint, max 2 device member, pending approval, locked screen modal with auto-polling)
- External API (/v1/models, /v1/chat/completions) — tool calling support, all OpenAI params forwarded
- Security: encrypted sessions, HSTS, security headers, CSRF, scrub banned words, system prompt override, obfuscated proxy IP, Cloudflare proxy, IP direct access blocked (444)
- dash_token cookie 7 hari, session lifetime 7 hari, auto-detect admin on gate pages
- Custom 404/403 error pages (React ErrorPage component)
- Umami Analytics, UptimeRobot (/api/health)
- OG image for social preview

## Key Files
- `app/Services/AiProxyService.php` — proxy service, model filtering, alias injection
- `app/Http/Controllers/Api/ChatController.php` — chat send, model aliases, system prompt
- `app/Http/Controllers/Api/ExternalApiController.php` — external API, tool calling, streaming
- `app/Http/Controllers/Api/PeriodController.php` — duration orders CRUD
- `app/Http/Controllers/Api/AdminController.php` — user CRUD, duration reset
- `app/Http/Middleware/CheckExpiry.php` — route-specific permission check
- `app/Http/Middleware/TrackDevice.php` — device fingerprinting
- `resources/js/pages/ChatFullPage.jsx` — full page chat (streaming, file upload, model selector)
- `resources/js/pages/Dashboard.jsx` — dashboard with modals (tambah durasi, device pending)
- `resources/js/pages/PeriodManagement.jsx` — admin period management
- `resources/js/layouts/DashboardLayout.jsx` — sidebar navigation
- `resources/js/app.jsx` — routes + ProtectedRoute with permission check
- `resources/views/app.blade.php` — SPA entry, SEO meta, JSON-LD (@verbatim wrapped)
- `config/fortify.php` — views disabled (prevents 500 on /login)

---

## NEXT STEPS — Dashboard Restructure (3 Fases)

### Fase 1: Sidebar Restructure + Placeholder Pages

**User sidebar:**
```
Dashboard
Mulai Pakai UltrAI (sub-menu per mode)
Chat AI
Chat AI Pro
Template Prompt
Riwayat Chat
Generate Gambar
Generate Video
Token & Pemakaian (member: gambar/video usage, BUKAN token usage admin)
Paket & Perpanjangan
Referral
Bantuan/Tutorial
Profil
```

**Admin sidebar:**
```
Revenue Overview
User Management
Period Management
Orders/Payments
Token Usage
Cost & Profit
Expiring Users
Broadcast/CRM
Referral Management
Feedback & Testimoni
AI Provider Manager
Model Management
Video Job Queue
Landing Page Manager
Analytics/Funnel
Audit Log
Settings
Session Chat
```

- Buat ~15 placeholder pages (masing-masing <100 baris, "Coming Soon" card)
- Update DashboardLayout.jsx sidebar
- Update app.jsx routes
- File max 200-300 baris

### Fase 2: Onboarding Wizard + Template Prompt

**Onboarding Wizard** (tampil sekali setelah login pertama):
- "Kamu mau pakai UltrAI untuk apa?"
- 7 mode: Coding Assistant, Project Builder, Content Creator, UMKM Assistant, Marketplace Helper, Excel & Office Helper, Prompt Visual Generator
- Disimpan di database: `users.onboarding_mode`
- Dashboard disesuaikan per mode (quick actions/template berbeda)

**Mode → Template mapping:**

| Mode | Fungsi |
|------|--------|
| Coding Assistant | Debug, logic, API, database |
| Project Builder | Struktur project, flow aplikasi, dokumentasi |
| Content Creator | Ide konten, caption, hook, script |
| UMKM Assistant | Promo, katalog, balasan customer |
| Marketplace Helper | Judul produk, deskripsi, optimasi listing |
| Excel & Office Helper | Rumus, surat, laporan, proposal |
| Prompt Visual Generator | Prompt gambar/video untuk AI visual |

**Template Prompt** (halaman tersendiri):
- 10 kategori: Coding, UMKM, Konten, Marketplace, Excel, Desain, Prompt Gambar, Prompt Video, Bisnis, Belajar
- Template siap pakai (title + prompt_text)
- Tombol "Pakai Template" → buka Chat AI Full Page dengan prompt ter-isi

**Contoh template:**
- Debug Error Coding
- Buat Caption Jualan
- Buat 30 Ide Konten
- Buat Script TikTok
- Buat Deskripsi Produk
- Buat Balasan Customer
- Buat Prompt Poster
- Buat Prompt Video Reels
- Buat Rumus Excel
- Buat Copywriting Landing Page

**Database changes:**
```sql
ALTER TABLE users ADD COLUMN onboarding_mode VARCHAR(50) NULL;
CREATE TABLE prompt_templates (id, category, title, prompt_text, mode, is_active, sort_order, timestamps);
```

### Fase 3: Full Implementation (per halaman)
- Revenue Overview, Orders/Payments, Referral, Analytics, dll
- Redesign existing pages
- Backend API + database untuk semua fitur baru

---

## CONSTRAINTS
- Semua file JSX max 200-300 baris
- Kata terlarang HARUS di-scrub (enowx, Kiro, Labs, dll)
- `@verbatim` wajib untuk JSON-LD di blade (Blade parse @ sebagai directive)
- Fortify views HARUS disabled (`config/fortify.php` → `'views' => false`)
- Proxy AI pakai yang LAMA (`127.88.41.7:1430`) — yang baru streaming terpotong
- `fontSize: '90%'` di root DashboardLayout dan ChatFullPage
- Chat streaming pakai buffer handling (seperti enowxai reference: `buffer = lines.pop() || ''`)
- `buildApiMessages()` deep-clone content ke primitives sebelum JSON.stringify
- Device pending modal = locked screen (tidak bisa dismiss), polling 3 detik
- Landing page pakai `@verbatim` untuk JSON-LD agar Blade tidak parse `@context`/`@type`
- Session Laravel 7 hari (`SESSION_LIFETIME=10080`)
- Cookie dash_token 7 hari (10080 menit)

---

## HOW TO CONTINUE
Paste isi file ini di awal session baru, lalu bilang "lanjut Fase 1" untuk mulai implementasi sidebar restructure.
