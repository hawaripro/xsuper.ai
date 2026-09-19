# Dashboard Revision Implementation Plan

**Goal:** Correct dashboard theming, payment, locale routing/copy, landing robot/search, catalog creation/sync, global announcement delivery, and rebuild the real Chat workspace from the approved Studio mockup without changing the XSuper.ai brand palette.

**Architecture:** Keep Laravel Blade for public surfaces and React for authenticated surfaces. Standardize React theming around the root `.dark` class and existing design tokens. Keep Indonesian canonical paths and mirror every authenticated route under `/en/*`. Use existing `content_blocks` as the announcement source. Extend duration orders with immutable QRIS checkout metadata. Use `ai_model_profiles` as the editable catalog source and live sync only for provider availability. Preserve ChatController/AiProxyService/history APIs while replacing ChatFullPage presentation with the Studio workspace structure.

**Tech Stack:** Laravel, Eloquent/MySQL+SQLite tests, React, React Router, Tailwind v4, Vite, PHPUnit, Chromium.

---

## Task 1: Theme and locale contracts

**Files:** `resources/css/app.css`, `resources/js/contexts/{Theme,Locale}Context.jsx`, active member/admin pages, `resources/js/app.jsx`, `routes/web.php`, feature tests.

- Add Tailwind custom dark variant bound to `html.dark`.
- Replace light-only component surfaces with shared token classes.
- Mirror all authenticated SPA paths using a single `/en/{path?}` server route after public `/en` pages.
- Make all internal links locale-aware.
- Translate every active member/admin/chat surface through the centralized locale API; server labels use stable translation keys.
- Verify direct URL loads and in-app language switching for every route.

## Task 2: Real QRIS checkout restoration

**Files:** `resources/qrcode.PNG` moved to protected public asset location, new migration, `DurationOrder`, `PeriodController`, `Dashboard.jsx`, `PaketPerpanjangan.jsx`, feature tests.

- Add `payment_method`, `payment_reference`, `payment_expires_at`, `payment_confirmed_at` to orders.
- Return QRIS checkout configuration from backend without exposing secrets.
- Restore select → QR scan → explicit paid confirmation → pending poll → approved success.
- Store payment snapshot/reference when order is created; preserve admin approval behavior.
- Ensure expired QR checkout cannot create an order and duplicate pending orders remain rejected.

## Task 3: Landing robot and model marketplace repair

**Files:** `home.blade.php`, `landing/{home,ai-robot,models,base}.css`, `models.blade.php`, `landing.js`, catalog controller/API, AICatalog React page, tests.

- Put robot in a dedicated centered slot between heading and descriptive copy.
- Add global `.sr-only` utility and restore compact search toolbar at all breakpoints.
- Add mobile filter drawer/toggle and retain six-column horizontal table where needed.
- Add admin Create Model operation and cache invalidation after create/update/sync.
- Define public visibility (`is_enabled`) separately from provider liveness (`is_available`).
- Prove newly created admin model appears in public ID/EN catalog and Chat catalog if its category/permissions allow it.

## Task 4: Global announcement ribbon

**Files:** `ContentController`, new member content endpoint, `DashboardLayout`, `ChatFullPage`, `ContentSupport`, CSS, tests.

- Continue editing `system.announcement` in CMS for ID/EN.
- Publish one payload with message, level, optional safe action.
- Expose active locale announcement to authenticated users.
- Render a full-width, reduced-motion-safe marquee above topbar in every dashboard and Chat route.
- Avoid duplicate public landing banner unless published payload explicitly targets public; add `surfaces` to announcement contract.

## Task 5: Studio Chat workspace implementation

**Files:** `ChatFullPage.jsx`, new focused workspace components/CSS, locale dictionary, tests.

- Preserve real models/history/conversation/delete/stream/upload APIs.
- Implement Studio structure: searchable collapsible history rail, top breadcrumb/model trigger, four mode cards based on authorized models, empty sigil hero, suggestion cards, live message thread, structured composer, attachment list, drag-drop overlay, stop streaming, account/footer controls.
- Keep XSuper.ai red/neutral palette in light/dark mode rather than Studio blue.
- Make non-chat mode tabs route to real Image/Video tools when native chat streaming is unsupported; never fake media generation.
- Translate all visible copy ID/EN.

## Task 6: Real verification

- Add backend feature tests for QRIS metadata/state, announcement targeting, locale wildcard routes, model creation/update/cache invalidation/public visibility, and Chat endpoint continuity.
- Browser-run guest/auth landing, all ID/EN dashboard routes in light/dark, payment state transitions, CMS publish→ribbon, model create→public/search/chat visibility, and Chat history/send/stop/attachments.
- Run `php artisan test --compact`, `npm run build`, LSP diagnostics, and Impeccable detector once at end.
