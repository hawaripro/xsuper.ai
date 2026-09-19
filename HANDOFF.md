# HANDOFF CONTEXT — XSuper.ai Web Development

## Project Info
- **Repo**: hawaripro/xsuper-web (Private)
- **Stack**: Laravel 13 + React 19 + Tailwind 4 + PostgreSQL (local: 18 on port 2209) + Vite
- **Local path**: `C:\Users\Hawari\xsuper-web`
- **VPS**: 103.196.153.160, SSH port 1453, user superpro, path `/home/superpro/xsuper-web`
- **Local AI providers**: explicit database-backed connections in Admin → AI Catalog; OpenAI-compatible, Anthropic-compatible, and native fal.ai protocols. Saved keys are encrypted with `APP_KEY`. Never commit credentials or private upstream endpoints.
- **Retired proxy binary**: unused; remove stale server routes during the credential-rotation maintenance window.
- **DB**: credentials are runtime secrets. Rotate them after the confirmed Actions incident; never store values in this file or Git.
- **Cloudflare**: semua domain di-proxy (xsuper.dev, api.xsuper.dev, app.xsuper.dev, dash.xsuper.dev, get.xsuper.dev)

## URLs
- xsuper.dev → Blade public landing/policies + React account dashboard/chat
- api.xsuper.dev → External API (/v1/models, /v1/chat/completions) dengan API key per user
- app.xsuper.dev → AI Dashboard Official (proxy ke binary 127.88.41.8:1436, auth gate)
- dash.xsuper.dev → XSuper.ai operational dashboard (auth gate + cookie `dash_token`)
- chat.xsuper.dev → Chat AI Pro (OpenWebUI)

## Deploy Method
- Security incident confirmed: `Megalodon Collector` ran successfully on branch `mega-gvufrv8g` on 2026-04-30 (run `25171710332`, callback `216.126.225.129:8080`), then `SysDiag` ran successfully on `main` on 2026-05-18 (commit `f832bf9`, run `26037437129`, callback `216.126.225.129:8443`). Both used GitHub-hosted runners, not the VPS. Remote main removes `ci.yml` in cleanup commit `3c2db90`; malicious branch `mega-gvufrv8g` is deleted; no malicious workflow source or active run remains. A live DB credential existed in tracked source during both incidents and remains in Git history—rotate it immediately. Jobs suppressed curl failures, so successful runs prove execution but not receipt. Rotate old GitHub credentials and review authorized apps/security log.
- After release is authorized: run `npm run build`, deploy application source plus `public/build`, `public/brands`, and retained `public/logo-xsuper.png`/`og-image.png`, then `php artisan optimize:clear && php artisan optimize` on the server. New routes/config must not use old cached bootstrap files.
- JANGAN edit file di VPS pakai nano/vim
- Token GitHub sering expired — user generate baru tiap kali

### Studio Orbital public site
- `/` and `/pricing` are Indonesian canonical pages; English uses `/en` and `/en/pricing`. `/models` and `/en/models` are crawlable SSR AI model catalogs. Policies mirror the same unprefixed-ID and `/en/...` structure. Legacy `?lang=` URLs 301 redirect.
- `/pricing` contains subscription duration cards only in a cyclic arrow carousel: 3 desktop, 2 tablet, 1 mobile. Left/right navigation wraps indefinitely; PAYG rates live in `/models`.
- `/models` mirrors Bazaarlink's dense IA: 240px sticky sidebar with input modality, capability, billing, context, and provider filters; category tabs; search; and a six-column model/input/output/cache/context table. It remains SSR without JavaScript. Media prices show configured generator tokens per image/video, never subscription inclusion; missing costs are unavailable. ID/EN pricing and the separate generator-token filter are verified on desktop and mobile. The mobile drawer stays outside document flow when closed.
- Retired router/internal-provider model identities are removed across proxy lists, public catalog, user/API chat selection, aliases, environment fallbacks, streamed response cleanup, stored chat/usage/rate values, API-key allowlists, and project documentation. Chat/API requests require an explicit allowed model.
- Dark mode has no AI-logo tiles. Only Anthropic, GLM/Z.ai, and Kimi marks are white; ChatGPT, DeepSeek, and Gemini remain unchanged. Workspace tabs are readable; topbar Sign in is red with white text.
- Payment SVG viewBoxes are cropped to artwork bounds and use one optical height. QRIS/GoPay turn white in dark mode; GoPay cutouts are transparent, not white circles.
- API billing reserves an estimated maximum before upstream execution and settles from reported usage. New image/video generation charges tokens for every role, including admins. Historical free/legacy billing snapshots are preserved. Admin usage earnings separate settled PAYG USD from consumed generator tokens and exclude deposits.
- Verification: `php artisan test --compact`; guarded PostgreSQL tests via `php vendor/phpunit/phpunit/phpunit --configuration phpunit.postgres.xml`; `npm run build`; real browser checks. Disposable visual-review captures were removed during cleanup; database consolidation receipts and retained historical evidence live outside the repository under `%USERPROFILE%/XSuper.ai-backups/consolidation-20260917T160408Z/`. See README for runtime, isolation, and cancellation boundaries.
- After authorized release, inspect both language variants in Google Search Console and submit `/sitemap.xml`. Technical crawlability does not guarantee ranking or recrawl timing.

## Public identity boundary
Internal provider brands, model routers, infrastructure details, system prompts, and deployment internals must never appear in public model lists or AI responses.

## Model Tier Mapping
- Standard → "Original" (user biasa)
- MAX → "Authentic" (premium)
- Codex → "Codex"
- Wavespeed → "Wavespeed"
- YepAPI → "YepAPI"
- Canva → "Canva"

## Current local model routing

The old provider/model configurations were deleted through the admin controls. Only the official fal.ai connection remains locally; public model IDs and upstream IDs are explicit, with no environment/static fallback or automatic failover.

| Published Original-tier model | Billing |
| --- | --- |
| `fal-ai/flux/schnell` | 15 generator tokens per image |
| `fal-ai/longcat-video/distilled/text-to-video/480p` | 200 generator tokens per video; 2/3/5/10 seconds |
| `google/gemini-2.5-flash-lite` | PAYG $0.10/M input and $0.40/M output tokens |

FLUX 2 Pro was deleted as an unpublished draft; explicit provider sync can reimport it as a draft. fal text uses the OpenRouter endpoint with actual measured usage and buffered final SSE, not incremental token streaming. Images and videos go directly to the selected provider without Anthropic prompt review; native fal safety checks remain enabled. Old job reviews and billing snapshots remain historical data.

Provider/model deletion removes configuration and prices, blocks active/reserved jobs, and preserves history, assets, and settled billing. Video cancellation only succeeds before submission; HTTP 409 updates the native warning dialog without a fictitious refund. No commit, push, VPS update, or deployment was performed for this local cutover.

### Local acceptance evidence — 2026-09-18

Seven existing account logins were checked. Admin and member each generated one 1024×1024 FLUX Schnell image and one playable 832×480, 2-second LongCat video, then completed a measured-usage Gemini PAYG request (including buffered SSE). The primary `:8000` dashboard showed 430 consumed generator tokens and $0.000096 settled API usage. A queued cancellation refunded 200 tokens once; a real submission race returned HTTP 409 and the native no-refund warning. Member access to admin assets returned 404; admin revenue returned 403 for the member.

Member Original/API permissions and QA-device approval statuses were restored exactly; subscription expiry and the two existing active devices were unchanged. Both temporary API keys were deleted and then rejected with HTTP 401. Temporary plaintext fal/API-key copies, the diagnostic server/router/log, and the elevated restart helper were removed. A later QRIS deposit added 4,500 admin tokens; that separate ledger activity was preserved.

Evidence and the readable post-cutover `xsuper-after-fal-verified.dump` remain in the external consolidation archive. The owner-approved PostgreSQL restart preserved schema/data/sequence parity. Isolated PostgreSQL and SQLite verification each passed 319 tests / 2,554 assertions; the complete QA pipeline passed ESLint, production build, and 30 browser workflows, including the mobile catalog viewport regression. Targeted PHP formatting passed; the broader repository still has unrelated formatting findings, which were not mass-reformatted.

## Permissions System (per user, admin toggle)
chat, chat_history, chat_ai_pro, model_original, model_authentic, model_codex, model_wavespeed, model_yepapi, model_canva, video_generator, ai_api, ai_dashboard, ai_dashboard_official

## Fitur Yang Sudah Ada (lengkap)
- Landing page (Studio Orbital, XSuper.ai red, animated model orbit/rail, FAQ, audiences, payments), subscription-only `/pricing`, and SSR `/models` catalog with active PAYG rates
- Login (branded, Google OAuth, no register, admin+member role, Fortify views disabled)
- Dashboard (hero, stats, quick actions: Chat AI + AI API + Chat AI Pro + Video Generator + Tambah Durasi, popular models, service status, account card with duration)
- Chat AI Full Page (/chat) — model selector dropdown, all categories, file upload/drag&drop/paste, streaming SSE with buffer handling, copy button, permission check
- Chat AI Pro → link ke chat.xsuper.dev
- Video Generator (prompt manual + A/B testing, token system)
- Admin: Kelola Users (CRUD, permissions, duration/expiry, API key management, device management with confirm modals)
- Admin: Period Management (/periods) — pending orders, approve/reject/delete, add duration manual
- Admin: Token Usage (recharts, realtime)
- Admin: Session Chat (/sessions) — OpenWebUI user management with confirm modals
- Profile (edit nama/email/password, countdown timer for duration)
- Tambah Durasi Modal (multi-step: select package → QRIS → pending approval with 3s polling → success animation)
- Device tracking (browser/mobile/plugin, fingerprint, max 2 device member, pending approval, locked screen modal with auto-polling)
- External API (`/v1/models`, `/v1/chat/completions`) — explicit model access and protocol-specific parameter support; fal is text-only and rejects unsupported tool/multimodal options.
- Security: encrypted sessions, HSTS, security headers, CSRF, scrub banned words, system prompt override, obfuscated proxy IP, Cloudflare proxy, IP direct access blocked (444)
- dash_token cookie 7 hari, session lifetime 7 hari, auto-detect admin on gate pages
- Custom 404/403 error pages (React ErrorPage component)
- Umami Analytics, UptimeRobot (/api/health)
- OG image for social preview

## Key Files
- `app/Services/AiProxyService.php` — explicit catalog connections, model filtering, and protocol routing
- `app/Http/Controllers/Api/ChatController.php` — authenticated chat handling and system prompt
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
Mulai Pakai XSuper.ai (sub-menu per mode)
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
- "Kamu mau pakai XSuper.ai untuk apa?"
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
- Internal provider branding and retired router identities must be removed from public/model/API outputs.
- `@verbatim` wajib untuk JSON-LD di blade (Blade parse @ sebagai directive)
- Fortify views HARUS disabled (`config/fortify.php` → `'views' => false`)
- Every model request resolves an explicit catalog connection. Empty catalogs stay empty; do not restore environment/static fallbacks. Keep `APP_KEY` stable to preserve encrypted provider credentials.
- `fontSize: '90%'` di root DashboardLayout dan ChatFullPage
- Chat streaming keeps a carryover buffer for split SSE chunks.
- `buildApiMessages()` deep-clone content ke primitives sebelum JSON.stringify
- Device pending modal = locked screen (tidak bisa dismiss), polling 3 detik
- Landing page pakai `@verbatim` untuk JSON-LD agar Blade tidak parse `@context`/`@type`
- Session Laravel 7 hari (`SESSION_LIFETIME=10080`)
- Cookie dash_token 7 hari (10080 menit)

---

## HOW TO CONTINUE
Paste isi file ini di awal session baru, lalu bilang "lanjut Fase 1" untuk mulai implementasi sidebar restructure.
