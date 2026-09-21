# XSuper.ai Media Platform — Architecture C (Hybrid) — F0 Foundation Design

Status: DRAFT for review · Date: 2026-09-22 · Scope: F0a (audit result) + F0b (media core) contract design.
Source of truth for product requirements: `xsuper_ai_brief_final_hybrid_C.md` (this doc translates it into concrete repo decisions; it does not restate it).

---

## 1. Audit result (F0a) — what already exists

Grounded facts from the repo audit (Laravel 12 + React 19 + Tailwind v4 SPA; web server = **Caddy** → php_fastcgi; prod https://xsuper.dev):

- **Credit/billing (media = tokens).** `MediaTokenBillingService::reserve/settle/release` on `UserToken.balance` (integer tokens), idempotent via `TokenReservation` (unique `user_id`+`reference_id`; statuses reserved/settled/released; `payload()` = reference_id, amount_tokens, unit_tokens, billing_mode). Unit price = `AiModelProfile.token_cost` (admin-set integer), **not** `UsageRate`. API/chat uses a separate micro-USD `Wallet`/`UsageBillingService`/`UsageRate` ledger. → F0 keeps the token ledger for media unchanged.
- **Auth/access.** Session-cookie (Fortify + `web` group under `/api`); no Sanctum. Admin = `role==='admin'` via `admin` middleware (+`admin.ip`). Per-feature member perms in `users.permissions` JSON (`image_generator`, `video_generator`, `audio_generator`, …), enforced by `CheckExpiry` (path-based) + `User::hasPermission()`. External `/v1/*` uses Bearer API keys (`VerifyApiKey`).
- **Assets.** All generated media on `Storage::disk('local')` (`storage/app/private`), served only via authenticated controller routes with `abort_unless(admin||owner,404)`. `VideoReferenceStore` = private, `dataUri()` base64 (used to feed fal). **No public/signed URL is used today**, BUT `public` + `s3` disks are configured (+ `storage:link`) — infra for public delivery exists, currently unused.
- **Studios (frontend).** One shared kit: `resources/js/components/studios/{StudioUI.jsx, studios.css, useMediaStudio.js, AudioPlayer.jsx}`. Tokens: global `--ui-*` in `resources/css/app.css` (brand `#ef4444`) + studio-scoped `--studio-*` in `studios.css` (action `#ba2943`). **A rich motion system already exists** (~40 `--animate-*` tokens + ~35 keyframes in app.css) but studios only use a plain spinner — the animated `GenerationProgress` orbit is wired to chat only. Dark mode = `.dark` class (ThemeContext). Icons = two inline-SVG registries (`SidebarIcons` nav, `StudioIcon` studios); no icon lib. Adding a studio = 6 touchpoints (endpoints map, page, route in app.jsx, nav item in DashboardLayout, StudioIcon+SidebarIcons glyphs, `.studio-color-*`).
- **Admin.** Target already largely built: `AICatalog.jsx` = provider **card grid** → React route `/admin/ai/:providerId` → dedicated `ProviderDetail.jsx` page (tabs Model&Harga / Koneksi / Pengaturan / Aktivitas); `ModelBulkTable.jsx` = search + 4 filters + client pagination + bulk/inline edit. Member/admin split exists (`MediaModelConfig::publicModel` vs `AiCatalogController::modelPayload`/`adminPayload`). **Gaps:** no column sort; `last_error` stored but hidden from adminPayload; auto-pricing (`ModelAutoPricer`) covers chat only (media gets flat config default `token_cost`); category is free-form string (no avatar/3D); `/admin/ai/catalog` loads ALL models (no server pagination); provider/media gating is hard-coded by protocol string (no capability/adapter registry).
- **fal discovery.** `GET https://api.fal.ai/v1/models` supports list/find (`endpoint_id` 1–50)/search (`q`,`category`,`status`), cursor pagination (`next_cursor`/`has_more`), and `expand=openapi-3.0` inlining each model's full OpenAPI 3.0.4 schema (`models[].openapi` — types, `required`, `enum`, min/max, `default`, `x-fal-order-properties`, `x-fal-metadata`). Auth optional (raises limits). **Gap:** asset URL fields (`image_url`, `image_urls`, `audio_url`, …) are plain `string`/`array<string>` with no `format:uri` — **input-role inference must be heuristic** (name+title+description+examples+category) + curation overrides. Current fal integration is fully curated/hardcoded (9-model map); importer is greenfield.

---

## 2. F0 goal & non-goals

**Goal (F0b):** introduce Architecture C's shared foundation and prove ONE real generation flow (image) through it end-to-end (FE + BE + DB + credit + asset), without rebuilding billing/auth/storage and without waiting for the full catalog or all studios.

**Non-goals for F0:** new studios (F1–F5), full fal import (F6b), 3D viewer, avatar dual-upload UI. F6a (schema-compat probe) runs alongside but is a test, not a product surface.

---

## 3. Core contracts

### 3.1 MediaCapability (internal, versioned)
Per **model + operation** (NOT per model alone). Minimum fields:
- `contract_version` (format version, separate from model config revision).
- `model_public_id`, `operation` (e.g. `text_to_image`, `image_edit`, `text_to_video`, `image_to_video`, `audio_to_video`, `talking_avatar`, `text_to_speech`, `music`, `text_to_3d`, `image_to_3d`).
- `output_kind` (`image|video|audio|model3d`).
- `inputs[]`: each `{ role, type, cardinality(single|multi + max), required, required_when(operation/param condition), constraints }`. **Roles are explicit and never merged:** `prompt`, `image_ref`, `init_frame`, `end_frame`, `avatar_photo`, `speech_audio`, `reference_video`, etc.
- `params[]`: `{ name, type, default, options|min/max, unit }` (size, aspect_ratio, duration, resolution, quality, voice, …).
- Declarative, validated rules only (a whitelisted rule DSL — NOT free JS / DB expressions). Unsupported schema features are recorded as explicit limitations, never silently dropped (a model with an un-representable required field is a publish blocker).

### 3.2 CapabilityResolver (single source)
- Every Studio + submit path calls ONE `CapabilityResolver::resolve(model, operation) -> MediaCapability`.
- Migrated models: use the stored **published** capability revision. Legacy models: explicit derivation from existing `MediaModelConfig`/protocol config → identical contract shape. A broken published config = actionable admin error, never a silent fallback to legacy.
- Each **job persists the capability revision id + routing identity + pricing** used, so later schema/price changes never mutate in-flight or historical jobs. If capability/price changed after the form opened, backend detects the mismatch and requires re-confirm.

### 3.3 Separation of concerns
- **Capability** = valid inputs (authority).
- **UI metadata** = labels, field order, groups, help text, widget, units, advanced section. May NOT change input obligations. Provider schema is normalization input, not a form shown raw; `uploadedUrls`-style fields render as role-labelled uploaders.
- **Adapter** = normalized inputs → provider request.
- Shared field renderer for common inputs; bespoke components only where interaction genuinely differs. Adding a model on supported capability+widgets requires no new Studio page.

### 3.4 MediaProviderAdapter (integration boundary)
Interface each provider implements (wrap existing `FalProtocol`/`KinoviProtocol`/openai after behavior is test-pinned):
`buildRequest(normalizedInputs, capability)`, `submit()`, `pollStatus()`, `parseResult()`, `normalizeError()`, plus declared support flags (`sync|async`, `polling`, `webhook`, `cancel`). Shared flow owns authz, resolver, validation, job coordination, assets, credit. Per-model exceptions use declarative mapping/tested handlers, not adapter duplication.

### 3.5 Controlled asset service
- Internal asset IDs with `{owner, media_type, size, metadata, storage_path, retention_status}`; backend checks access before returning a URL or sending a reference to a provider.
- Validate size + allowed type + content signature (not just extension/Content-Type); permission-gated uploads + quotas.
- **Public delivery for providers that need it** (e.g. Kinovi image-to-video): choose signed URL / controlled public asset route / provider upload per verified integration — using the already-configured `public` disk or a signed route. TTL covers provider queue+fetch. Changing delivery method must not change the internal asset ID. SSRF guard on any app-side fetch of external URLs (protocol/host allowlist, block internal ranges, follow-redirect checks). Callback/endpoint URLs never member-controlled.

### 3.6 Persistent jobs, safe retry, consistent credit
- Persist job `{owner, validated_inputs, capability_revision_id, provider_request_id?, status, result, credit refs}`. Upload status ≠ generation status.
- Reopenable after refresh/navigation/close; polling/webhook per provider support + reconciliation for uncertain jobs. Verify status via provider API before mutating result/credit when callback auth is weak.
- Submit timeout ≠ provider failure: never auto-create a new paid request on uncertain submit; separate status-retry from new generation. User submit dedup + idempotent internal job/transaction updates; duplicate/out-of-order callbacks never double debit/refund or regress final status.
- Reserve/settle/refund via existing `MediaTokenBillingService`; never refund on mere browser timeout, never silently switch provider/model that changes cost/result.

---

## 4. Member vs admin response (enforced, not cosmetic)
- **Member** responses: public model id + display name, public capability, sell price (tokens), status, result, safe error only. Provider/routing/credentials/cost/internal endpoints/raw debug stay server-side, admin-only. Enforced at endpoint + job + asset access (not CSS).
- Display rule: member sees model **id + title**; admin additionally sees provider. (Names still follow public config; we don't promise the origin is unguessable.)

---

## 5. Planned changes (F0b) — concrete

**DB (safe, reversible migrations):**
- `media_capabilities` (+ revisions): `{ id, model_id/provider_id, operation, contract_version, revision, status(found|imported|needs_handling|tested|published|disabled), definition(json: inputs/params/rules), ui_metadata(json), source_schema_ref?, source_hash?, created_by, timestamps }`. Keep revisions referenced by jobs.
- `media_assets`: `{ id, user_id, media_type, role?, storage_disk, storage_path, size_bytes, mime, signature_ok, metadata(json), retention_status, expires_at?, timestamps }`.
- `image_jobs`/`video_jobs`/`audio_jobs`: add `capability_revision_id` (nullable FK) + asset-reference columns as needed. (image_jobs already gained async lifecycle columns.)
- Expose provider `last_error` to `adminPayload` (audit gap).

**Backend:**
- `App\Media\MediaCapability` (value object) + `CapabilityResolver` + a declarative rule validator.
- `App\Media\Contracts\MediaProviderAdapter` + `FalAdapter`/`KinoviAdapter`/`OpenAiAdapter` wrapping current protocol classes (behavior test-pinned first).
- `App\Media\AssetService` (upload, validate, access-check, public-delivery, retention) generalizing `VideoReferenceStore`.
- Shared submit/validate flow used by controllers + the external `/v1` API (same backend rules).
- Media-aware pricing seed for admin (gap): media models get a real default, not just flat config.

**Frontend:** none required to PROVE the flow (existing image studio + `useMediaStudio` already poll pending jobs). Capability-driven rendering + animation land in F1.

---

## 6. Proof-of-flow (F0b acceptance)
Image generation (one model) routed through capability → resolver → adapter → job → asset → credit, verified in a real browser on prod: required-input rejection (FE + BE), real job, reopen after refresh, credit reserve→settle, member response hides provider, admin sees full detail. Then F1 builds the full image studio on this foundation.

## 7. Testing (per brief §10)
Unit (resolver/mapping/validation), contract (adapter with representative fixtures), integration (asset/job/credit), e2e (one image flow + failure paths). Live paid runs only within authorized budget; label live vs mock; never claim live e2e from mocks.

## 8. Risks / open decisions
- fal asset-field role inference is heuristic → needs curation-override table (F6a validates coverage).
- Public asset delivery method (signed route vs public disk vs provider upload) — pick per verified provider (Kinovi supports both public URL + their upload).
- Keep media on `local` disk for results; only references/inputs needing provider fetch get public delivery.
- `max_children` prod tuning done (5→30) — unrelated infra note, already applied.
