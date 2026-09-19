# Brand Dashboard and Catalog Implementation Plan

**Goal:** Deliver a unified XSuper.ai public/member experience with one premium robot accent, auth-aware public navigation, bilingual dashboard URLs, brand typography, colorful navigation, and a database-managed BazaarLink-style AI catalog.

**Architecture:** Public pages remain Laravel Blade with modular CSS. Authenticated pages remain React but gain a locale context keyed by `/en/*`. AI model metadata lives in `ai_model_profiles`; usage prices remain in `usage_rates`. Admin edits update those records transactionally and public `/models` joins them into the live provider catalog.

**Tech Stack:** Laravel 12, Eloquent, React 19, React Router, Tailwind v4, Vite, PHPUnit/Pest, Chromium.

---

## Task 1: Landing cleanup, auth state, and robot

**Files:** `resources/views/public/home.blade.php`, `resources/views/public/partials/{header,ai-robot}.blade.php`, `database/seeders/OperationalDataSeeder.php`, `resources/css/landing/ai-robot.css`, feature tests.

- Assert guest header shows Masuk; authenticated header shows Dashboard; unpublished announcement is absent.
- Add one decorative, aria-hidden robot beside “Banyak cara berpikir”.
- Integrate all animation with existing motion-state and reduced-motion rules.
- Verify both `/` and `/en` in Chromium.

## Task 2: Modular public CSS

**Files:** `resources/css/landing.css`, `resources/css/landing/{base,home,home-motion,pricing,models,ai-robot,dark}.css`.

- Keep `landing.css` as import-only entry.
- Preserve cascade order byte-for-byte by splitting at top-level rule boundaries.
- Build and browser-check home, pricing, models, and policy surfaces.

## Task 3: Dashboard visual and locale shell

**Files:** `resources/css/app.css`, `resources/views/app.blade.php`, `resources/js/contexts/LocaleContext.jsx`, `resources/js/app.jsx`, `resources/js/layouts/DashboardLayout.jsx`, `resources/js/pages/Dashboard.jsx`, `routes/web.php`.

- Register local Manrope/DM Sans and apply the public brand hierarchy.
- Add colored icon badges per navigation domain.
- Support mirrored authenticated URLs under `/en/*`; locale switch preserves current route.
- Translate shell/login/overview copy via one locale context while preserving API routes.
- Add route and browser tests for locale switching and auth redirects.

## Task 4: Database-managed BazaarLink-style catalog

**Files:** new migration, `AiModelProfile`, `AiCatalogController`, `PublicSiteController`, `AICatalog.jsx`, `models.blade.php`, operational seeder, feature tests.

- Extend model profiles with provider label, descriptions ID/EN, logo URL, context window, max output, input/output modalities, badges, and sort order.
- Keep prices in `usage_rates`; expose input, output, cache read, and cache write per 1M tokens.
- Admin edit accepts only validated metadata and rates, performs one transaction, and emits audit events.
- Provider sync updates availability and live capabilities without overwriting curated admin fields.
- Public `/models` uses enabled model profiles and joined prices; live upstream is only an availability supplement.
- Test admin authorization, validation, persistence, audit, locale descriptions, public filtering, and pricing output.

## Task 5: Real verification

- Run focused feature tests for landing auth state, locale routes, catalog CRUD, public rendering.
- Run full `php artisan test --compact`.
- Run `npm run build`.
- Use Chromium to exercise `/`, `/en`, `/dashboard`, `/en/dashboard`, `/models`, `/en/models`, admin catalog edit, locale switch, and authenticated public header.
- Confirm no console errors, no layout overflow, reduced-motion behavior, real DB persistence, and API response consistency.
