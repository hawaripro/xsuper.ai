# UltrAI Dashboard Control Center Implementation Plan

> **For UltrAI:** REQUIRED SUB-SKILL: Use subagent-driven-development to execute this plan task-by-task.

**Goal:** Replace the broken member/admin dashboard with one compact, professional Control Center whose visible workflows use real Laravel APIs; retire Chat AI Pro/OpenWebUI; make former placeholder features operational.

**Architecture:** Laravel remains the source of truth. New operational domains use focused Eloquent models/controllers: overview, notifications, referrals, feedback/support, content, analytics/audit, AI catalog, image generation. React consumes a small shared request/state/component layer and consolidates navigation into member/admin modules. No secret is editable or returned by UI. Provider credentials stay env-only; DB metadata controls presentation/availability.

**Tech Stack:** Laravel 13, Eloquent/PostgreSQL-compatible migrations, React 19, React Router 7, Tailwind 4, Recharts, PHPUnit 12.

---

## File map

**Shared backend**
- `database/migrations/2026_09_14_000004_create_control_center_tables.php`: notifications, referrals, feedback/support, content, analytics, audit, AI source metadata, image jobs; remove persisted `chat_ai_pro`.
- `app/Models/{Notification,Referral,ReferralReward,Feedback,SupportTicket,ContentBlock,AnalyticsEvent,AuditEvent,AiProviderProfile,AiModelProfile,ImageJob}.php`: focused domain records.
- `app/Services/{AuditService,ReferralService,ImageGenerationService}.php`: transactional domain operations.
- `app/Http/Controllers/Api/{DashboardController,NotificationController,ReferralController,FeedbackController,SupportController,ContentController,AnalyticsController,AuditController,AiCatalogController,ImageController}.php`: typed JSON contracts.
- `routes/web.php`: real member/admin routes; remove OpenWebUI routes/import.

**Member frontend**
- `resources/js/lib/api.js`: one JSON request helper with normalized 401/403/419/422/5xx errors.
- `resources/js/components/dashboard/{PageHeader,AsyncState,StatCard,DataTable,StatusBadge,EmptyState}.jsx`: compact shared primitives.
- `resources/js/pages/{Dashboard,ChatHistory,GenerateImage,TokenPemakaian,Referral,Bantuan}.jsx`: real workflows.
- `resources/js/pages/notifications/Inbox.jsx`: in-app notification inbox.

**Admin frontend**
- `resources/js/pages/admin/{AdminOverview,Operations,ContentSupport,AICatalog,SystemActivity}.jsx`: consolidated professional modules.
- Existing mature pages (`AdminUsers`, `PeriodManagement`, `TokenUsage`, `Settings`) remain but adopt shared states/type scale.
- Placeholder/duplicate admin pages and `SessionChat.jsx` are deleted after all routes/callers migrate.

**Shell/removal**
- `resources/js/layouts/{DashboardLayout,SidebarIcons}.jsx`, `resources/js/app.jsx`, `resources/css/app.css`: consolidated IA, compact typography, responsive shell.
- `app/Models/User.php`, `app/Http/Controllers/Api/AdminController.php`, `config/services.php`, `.env.example`: delete Chat AI Pro/OpenWebUI integration.

## Task 1: Operational schema and audit foundation

**Files:**
- Create: migration/models listed in Shared backend.
- Create: `app/Services/AuditService.php`
- Test: `tests/Feature/ControlCenterFoundationTest.php`

**Consumes:** existing `users`, `duration_orders`, wallet/usage/video tables.
**Produces:** domain tables and `AuditService::record(User $actor, string $action, ?Model $subject, array $metadata = []): AuditEvent`.

- [ ] Write failing feature tests proving schema relations, JSON casts, audit actor/subject fields, and migration removal of `chat_ai_pro` from existing user permissions.
- [ ] Run `php artisan test --compact tests/Feature/ControlCenterFoundationTest.php`; expect missing tables/classes.
- [ ] Implement migration, models, relationships, casts, indexes, foreign-key deletion behavior, and `AuditService`.
- [ ] Run the same test; expect pass.
- [ ] Commit `feat: add control center operational schema`.

## Task 2: Member overview, notification inbox, usage observability

**Files:**
- Create controllers: `DashboardController`, `NotificationController` under `Api`.
- Modify: `routes/web.php`, `app/Models/User.php`.
- Test: `tests/Feature/MemberControlCenterTest.php`.

**Consumes:** operational schema; existing chats, usage, wallet, orders, devices.
**Produces:**
- `GET /api/dashboard` → `{account,usage,wallet,activity,services,actions}`.
- `GET /api/notifications`, `PATCH /api/notifications/{notification}/read`, `POST /api/notifications/read-all`.
- `GET /api/usage/me?period=...` → only authenticated user's aggregate/timeline/model data.

- [ ] Write failing authorization and response-contract tests, including member isolation and unread counts.
- [ ] Run targeted tests; expect route failures.
- [ ] Implement query-efficient aggregates and notification read state; no N+1 loops.
- [ ] Run targeted tests; expect pass.
- [ ] Commit `feat: add member control center APIs`.

## Task 3: Real referrals with fraud-safe rewards

**Files:**
- Create: `app/Services/ReferralService.php`, `app/Http/Controllers/Api/ReferralController.php`.
- Modify: user creation paths, Google callback, order approval path, routes.
- Test: `tests/Feature/ReferralProgramTest.php`.

**Consumes:** referral tables, users, approved orders.
**Produces:** stable random referral code; attribution cookie/session at entry; one referrer per account; reward only after referred user's first approved order; idempotent reward ledger; admin program config/status and member stats.

- [ ] Write failing tests for attribution, self-referral rejection, duplicate prevention, first-approved-order trigger, idempotency, member/admin access.
- [ ] Run targeted tests; expect failures.
- [ ] Implement `ReferralService::{capture,attribute,rewardFirstPurchase}` and hook all registration/approval paths.
- [ ] Run targeted tests; expect pass.
- [ ] Commit `feat: implement referral rewards`.

## Task 4: Feedback, support, broadcasts, and notification operations

**Files:**
- Create controllers: `FeedbackController`, `SupportController`.
- Create admin operation methods for broadcast and queues.
- Modify: routes.
- Test: `tests/Feature/EngagementOperationsTest.php`.

**Consumes:** feedback, support ticket, notification, audit tables.
**Produces:** member feedback submission/history, support ticket create/thread/status, admin feedback moderation/testimonial approval, segmented broadcast (`all|active|expired`) creating durable in-app notifications, broadcast audit record.

- [ ] Write failing tests for validation, ownership, admin moderation, target segmentation, durable notification creation, and audit trail.
- [ ] Run targeted tests; expect failures.
- [ ] Implement transactional operations; never fake-send or sleep.
- [ ] Run targeted tests; expect pass.
- [ ] Commit `feat: add support and engagement operations`.

## Task 5: Content management, analytics ledger, and real audit API

**Files:**
- Create controllers: `ContentController`, `AnalyticsController`, `AuditController`.
- Modify: `PublicSiteController.php`, public Blade views where required, routes.
- Test: `tests/Feature/AdminIntelligenceTest.php`.

**Consumes:** content/analytics/audit tables; current marketing config provides defaults.
**Produces:** versioned editable hero/FAQ/announcement content with publish state; public render uses published DB override then source-controlled fallback; dashboard event ingest with strict allowlist; funnel aggregation; paginated/filterable audit records.

- [ ] Write failing tests for draft vs published visibility, fallback semantics, validation, event allowlist, member data minimization, admin-only aggregates/audit.
- [ ] Run targeted tests; expect failures.
- [ ] Implement APIs and server-rendered content overlay; record operational audit events.
- [ ] Run targeted tests and existing public-site tests; expect pass.
- [ ] Commit `feat: add content and operational intelligence`.

## Task 6: AI catalog, provider health, global media queues, image generation

**Files:**
- Create: `AiCatalogController`, `ImageController`, `ImageGenerationService`.
- Modify: `AiProxyService.php`, `UsageBillingService.php`, routes.
- Test: `tests/Feature/AIOperationsTest.php`.

**Consumes:** AI metadata/image jobs, upstream `/v1/models`, wallet/rates, existing video jobs.
**Produces:** admin sync/status/enable/label metadata without API keys; model capability catalog; global admin image/video queue; member image generation/status/history. Upstream call uses `/v1/images/generations`; unavailable/unsupported upstream returns explicit 502/503 and refunds reservation—never mock output.

- [ ] Write failing tests with `Http::fake()` for model sync, secret exclusion, generation success, upstream errors, wallet reserve/refund, ownership, global queue scope.
- [ ] Run targeted tests; expect failures.
- [ ] Implement services/controllers and rate lookup for `image` service.
- [ ] Run targeted tests; expect pass.
- [ ] Commit `feat: add AI catalog and image operations`.

## Task 7: Retire Chat AI Pro/OpenWebUI cleanly

**Files:**
- Delete: `app/Http/Controllers/Api/ChatProController.php`, `resources/js/pages/SessionChat.jsx`.
- Modify: `AdminController.php`, `User.php`, routes, `services.php`, `.env.example`, frontend shell/icon/admin permissions.
- Test: `tests/Feature/ChatProRemovalTest.php`.

**Consumes:** permission-removal migration from Task 1.
**Produces:** zero `openwebui`, `Chat AI Pro`, `chat_ai_pro`, or `chat.ultrai.id` product integration references; user CRUD has no external synchronization.

- [ ] Write failing tests for absence of routes/permission and successful user create/delete independent of external HTTP.
- [ ] Run targeted tests; expect failures.
- [ ] Delete integration and every caller/config/env/UI reference.
- [ ] Run targeted tests plus repository pattern scan; expect pass/zero matches (excluding migration historical removal key assertion where required).
- [ ] Commit `refactor: remove Chat AI Pro integration`.

## Task 8: Shared compact dashboard system and shell

**Files:**
- Create: `resources/js/lib/api.js`, shared dashboard components.
- Modify: `DashboardLayout.jsx`, `SidebarIcons.jsx`, `app.jsx`, `app.css`.
- Test: throwaway browser smoke, retained PHP route-access tests only.

**Consumes:** API contracts from Tasks 2–6.
**Produces:** compact type tokens (`page-title 20/24`, section 14/20, body 13/20, meta 11/16), 40px controls, shared async/error/empty states, responsive consolidated member/admin navigation, notification badge/inbox, no root percentage font hacks.

- [ ] Build shared request error normalization and primitives.
- [ ] Replace shell navigation with approved IA and remove dead/duplicate routes.
- [ ] Apply keyboard-visible focus, labels, reduced-motion respect, mobile drawer focus/scroll behavior, light/dark parity.
- [ ] Run `npm run build`; expect pass.
- [ ] Launch browser smoke at desktop/mobile and exercise navigation/theme/error retry.
- [ ] Commit `feat: build compact control center shell`.

## Task 9: Rebuild member surfaces

**Files:**
- Modify: `Dashboard.jsx`, `ChatHistory.jsx`, `GenerateImage.jsx`, `TokenPemakaian.jsx`, `Referral.jsx`, `Bantuan.jsx`, `TemplatePrompt.jsx`, `PaketPerpanjangan.jsx`.
- Create: `resources/js/pages/notifications/Inbox.jsx` and focused member components as needed.

**Consumes:** member APIs and shared UI.
**Produces:** truthful overview; searchable/open/delete chat history; working image create/history; token/wallet usage; stable referral stats; support/feedback workflow; durable notification inbox; no Coming Soon or fabricated status/model/referral values.

- [ ] Implement each route with loading/empty/success/403/422/operational error/retry states.
- [ ] Ensure permission-gated actions disappear rather than leading to dead screens.
- [ ] Keep existing working chat/video/profile/templates/billing behavior and migrate request handling to shared helper where touched.
- [ ] Run build and real browser workflows as member on desktop/mobile and light/dark.
- [ ] Commit `feat: rebuild member control center`.

## Task 10: Rebuild consolidated admin surfaces

**Files:**
- Create consolidated pages listed under Admin frontend.
- Modify mature admin pages to shared states/type scale.
- Delete obsolete placeholder/duplicate pages after route migration.

**Consumes:** all admin APIs/shared UI.
**Produces:** Overview; People & Access; Orders & Billing; Usage; AI Catalog & queues; Content & Support; System Activity. Every control mutates durable state or is a real filter/export/open action. CSV exports are generated from current authorized API data with explicit columns.

- [ ] Implement overview alerts and actionable pending queues.
- [ ] Consolidate users/devices/API keys/permissions; orders/durations/pricing/wallet; usage/cost; AI catalog/media queue; broadcasts/feedback/support/content; analytics/audit.
- [ ] Add confirmation for destructive actions, optimistic state only where reversible, explicit success/failure feedback.
- [ ] Run build and real browser CRUD/filters/export workflows as admin at desktop/mobile and light/dark.
- [ ] Commit `feat: rebuild admin operations center`.

## Task 11: Hardening, cleanup, and end-to-end proof

**Files:** all changed files; remove throwaway scripts/accounts/build output.

**Consumes:** completed control center.
**Produces:** clean branch, no placeholder/dead integration, verified behavior.

- [ ] Run targeted feature tests for each new domain.
- [ ] Run `php artisan test --compact`; expect all pass.
- [ ] Run `npm run build`; expect pass.
- [ ] Run LSP diagnostics on changed PHP/JS files; resolve actionable errors.
- [ ] Run Impeccable detector over every authenticated route and fix findings.
- [ ] Browser-verify member/admin workflows, permission denied, validation, upstream error, empty states, theme, and responsive shell.
- [ ] Remove temporary audit accounts and stop temporary services.
- [ ] Perform final code review; fix Critical/Important findings.
- [ ] Commit `chore: harden dashboard control center`.

## Scope rulings

- Professional “out of the box” means operational visibility, durable inbox/support, truthful service health, auditability, segmentation, actionable queues, content publishing, referral attribution/rewards, and useful exports—not decorative widgets.
- No push/deploy. Secrets remain env-only. Provider management controls public metadata/enablement/health; never secret values.
- Image generation is implemented against the compatible upstream contract; lack of upstream capability is a real operational error with billing rollback, not a mock.
- Public landing content remains safe by default: source-controlled config fallback; only explicitly published allowlisted blocks override it.
- Existing working chat/video/billing contracts remain authoritative.