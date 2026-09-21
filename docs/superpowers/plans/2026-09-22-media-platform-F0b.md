# Media Platform F0b — Media Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land Architecture C's shared media foundation (capability contract + resolver + provider adapters + controlled asset service + persistent job/credit flow + member/admin response split) and prove it end-to-end through one real image-generation flow.

**Architecture:** A single `CapabilityResolver` yields a uniform `MediaCapability` (per model+operation) from either a stored published revision or legacy derivation of existing `MediaModelConfig`. A `MediaProviderAdapter` interface wraps the existing `FalProtocol`/`KinoviProtocol`/OpenAI code (behavior pinned by characterization tests first). A shared coordinator validates normalized inputs against the capability, reserves tokens, persists a job carrying the capability revision, drives the adapter (sync or async submit/poll), stores results through a controlled `AssetService`, and settles/refunds credit idempotently. Member responses expose only public identity+capability+price+status+result; provider/routing/cost stay admin-only.

**Tech Stack:** Laravel 12 (PHP 8.4), Pest/PHPUnit, PostgreSQL, queue on connection `media`, React 19 + Tailwind v4 SPA (no FE work in F0b), Caddy → php-fpm.

**Spec:** `docs/superpowers/specs/2026-09-22-media-platform-hybrid-C-F0-design.md` (read it alongside this plan; the brief `xsuper_ai_brief_final_hybrid_C.md` is the product source of truth).

## Global Constraints

- Media billing stays integer **tokens** via `MediaTokenBillingService` + `TokenReservation` (unit = `AiModelProfile.token_cost`); do NOT touch the micro-USD `Wallet`/`UsageRate` path. [spec §1, §3.6]
- Auth is session-cookie under `web`; admin = `role==='admin'`; per-feature perms in `users.permissions`. Do NOT introduce Sanctum. [spec §1]
- Backend is the validation authority; the SAME rules apply to Studio and the external `/v1` API. Reject unknown fields; never forward raw member payload to a provider. [spec §4, §5]
- Capability rules are a whitelisted declarative DSL — NEVER free JS or DB-stored expressions. Unrepresentable required fields = publish blocker, never silently dropped. [spec §3.1]
- Every job persists the exact `capability_revision_id` + routing identity + price used; schema/price changes never mutate in-flight or historical jobs. [spec §3.2]
- Idempotent credit: duplicate/out-of-order callbacks never double debit/refund; submit-timeout ≠ provider failure; never refund on mere browser timeout; never auto-switch provider/model. [spec §3.6]
- Assets validated by size+type+content signature (not extension); access-checked before any URL/reference is issued; SSRF-guard app-side fetches. [spec §3.5]
- Skip project-wide formatter/lint/full-suite inside individual tasks; run the full suite once at the end. Migrations must be reversible.

---

### Task 1: Media contract enums + `MediaCapability` value object

**Files:**
- Create: `app/Media/Enums/MediaOperation.php`, `app/Media/Enums/InputRole.php`, `app/Media/Enums/OutputKind.php`
- Create: `app/Media/MediaCapability.php`, `app/Media/CapabilityInput.php`, `app/Media/CapabilityParam.php`
- Test: `tests/Unit/Media/MediaCapabilityTest.php`

**Interfaces:**
- Produces: `MediaOperation` (cases: `TextToImage,ImageEdit,TextToVideo,ImageToVideo,AudioToVideo,TalkingAvatar,TextToSpeech,Music,TextTo3d,ImageTo3d`), `InputRole` (`Prompt,ImageRef,InitFrame,EndFrame,AvatarPhoto,SpeechAudio,ReferenceVideo`), `OutputKind` (`Image,Video,Audio,Model3d`). `MediaCapability::__construct(string $modelPublicId, MediaOperation $op, OutputKind $out, int $contractVersion, CapabilityInput[] $inputs, CapabilityParam[] $params)`, `->requiredInputs(): CapabilityInput[]`, `->input(InputRole): ?CapabilityInput`, `->toArray()`, `::fromArray(array)`. `CapabilityInput` = `{role, type, single(bool), max(int), required(bool), requiredWhen(?array)}`. `CapabilityParam` = `{name, type, default, options(?array), min(?), max(?), unit(?)}`.

- [ ] **Step 1: Write failing test** — `tests/Unit/Media/MediaCapabilityTest.php`
```php
it('round-trips a capability through array and back', function () {
    $cap = new MediaCapability('kinovi-ai/gpt-image-2', MediaOperation::TextToImage, OutputKind::Image, 1,
        [new CapabilityInput(InputRole::Prompt, 'string', true, 1, true, null)],
        [new CapabilityParam('size', 'enum', '1024x1024', ['1024x1024','1024x1792'], null, null, null)]);
    expect(MediaCapability::fromArray($cap->toArray())->toArray())->toBe($cap->toArray());
    expect($cap->requiredInputs())->toHaveCount(1);
    expect($cap->input(InputRole::Prompt)->required)->toBeTrue();
});
```
- [ ] **Step 2: Run** `php artisan test --filter=MediaCapabilityTest` → FAIL (classes missing).
- [ ] **Step 3: Implement** the three enums + `CapabilityInput`/`CapabilityParam` (readonly value objects with `toArray`/`fromArray`) + `MediaCapability` (readonly, with `requiredInputs`, `input`, `toArray`, `fromArray` validating enum casts).
- [ ] **Step 4: Run** the test → PASS.
- [ ] **Step 5: Commit** `feat(media): MediaCapability contract value objects + enums`.

---

### Task 2: Declarative rule validator

**Files:**
- Create: `app/Media/CapabilityValidator.php`, `app/Media/Exceptions/CapabilityValidationException.php`
- Test: `tests/Unit/Media/CapabilityValidatorTest.php`

**Interfaces:**
- Consumes: Task 1 types.
- Produces: `CapabilityValidator::validate(MediaCapability $cap, array $normalizedInputs): array` — returns the sanitized input set (only whitelisted roles/params, coerced to declared types/enums), or throws `CapabilityValidationException` with a field→message map. Enforces: required inputs present; `requiredWhen` (e.g. operation=ImageToVideo ⇒ InitFrame required); cardinality (single vs max); param enum/min/max; **rejects unknown fields**.

- [ ] **Step 1: Write failing tests** covering: (a) missing required prompt → throws with `prompt` key; (b) ImageToVideo without `init_frame` → throws; (c) unknown param `foo` → throws; (d) valid text-to-image input → returns sanitized array without extras; (e) enum out of range → throws.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** validator (pure, no DB, no eval; `requiredWhen` interpreted from a fixed rule vocabulary: `operation_is`, `param_equals`, `has_input`).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(media): declarative capability validator`.

---

### Task 3: `media_capabilities` table + model

**Files:**
- Create: `database/migrations/2026_09_22_100001_create_media_capabilities_table.php`
- Create: `app/Models/MediaCapabilityRevision.php`
- Test: `tests/Feature/Media/MediaCapabilityRevisionTest.php`

**Interfaces:**
- Produces: table `media_capabilities` `{id, ai_model_profile_id FK, operation, contract_version, revision(uint), status(enum found|imported|needs_handling|tested|published|disabled default 'imported'), definition(json), ui_metadata(json nullable), source_schema_ref(string nullable), source_hash(string nullable), created_by(nullable FK users), timestamps, unique(ai_model_profile_id, operation, revision)}`. Model `MediaCapabilityRevision` casts definition/ui_metadata to array; `scopePublished`; relation `model()`.

- [ ] **Step 1: Write failing test** — create a profile + a published revision; assert `MediaCapabilityRevision::published()->first()` loads with array-cast definition; assert the unique(model,operation,revision) constraint rejects a duplicate.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** migration (reversible) + model.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(media): media_capabilities revisions table + model`.

---

### Task 4: `CapabilityResolver` (legacy derivation + published revision)

**Files:**
- Create: `app/Media/CapabilityResolver.php`, `app/Media/Exceptions/CapabilityConfigException.php`
- Modify: `app/Services/MediaModelConfig.php` (add a pure `deriveCapabilities(AiModelProfile): MediaCapability[]` helper that maps the existing config → operations; no behavior change to existing callers)
- Test: `tests/Feature/Media/CapabilityResolverTest.php`

**Interfaces:**
- Consumes: Tasks 1,3; `MediaModelConfig`, `AiModelProfile`, `KinoviProtocol`/`FalProtocol` MODELS maps.
- Produces: `CapabilityResolver::resolve(AiModelProfile $model, MediaOperation $op): MediaCapability` — if a `published` revision exists for (model,op) use it (throw `CapabilityConfigException` if its definition fails `MediaCapability::fromArray`), else derive from `MediaModelConfig` (identical shape). `::operationsFor(AiModelProfile): MediaOperation[]`.

- [ ] **Step 1: Write failing tests**: (a) kinovi image model → resolve(TextToImage) yields Prompt(required)+size param from SIZE_MAP; (b) a seeded published revision overrides derivation; (c) a published revision with a bad definition → `CapabilityConfigException` (NOT silent legacy fallback).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** resolver + `MediaModelConfig::deriveCapabilities` (image: TextToImage from sizes/supports_size; video: TextToVideo from durations/aspect_ratios; audio: speech/music from audio_kind).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(media): CapabilityResolver with legacy derivation + published revisions`.

---

### Task 5: `MediaProviderAdapter` interface + result value objects

**Files:**
- Create: `app/Media/Contracts/MediaProviderAdapter.php`, `app/Media/SubmitResult.php`, `app/Media/StatusResult.php`, `app/Media/AdapterSupport.php`
- Test: `tests/Unit/Media/AdapterContractShapesTest.php`

**Interfaces:**
- Produces: interface `MediaProviderAdapter` `{ support(): AdapterSupport; buildRequest(MediaCapability, array $inputs): array; submit(AiProviderProfile, array $request): SubmitResult; poll(AiProviderProfile, string $taskId, MediaCapability): StatusResult; }`. `SubmitResult` `{immediate(bool), taskId(?string), resultUrls(?array)}`. `StatusResult` `{state('processing'|'completed'|'failed'), resultUrls(?array)}`. `AdapterSupport` `{async(bool), polling(bool), webhook(bool), cancel(bool)}`.

- [ ] **Step 1: Write failing test** asserting the value objects construct + expose fields; a fake adapter implementing the interface satisfies a `instanceof` + returns a completed `StatusResult`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** interface + readonly value objects.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(media): MediaProviderAdapter interface + result value objects`.

---

### Task 6: Characterize existing protocols, then `KinoviAdapter` (image)

**Files:**
- Create: `tests/Unit/Media/KinoviProtocolCharacterizationTest.php` (pin current `KinoviProtocol::imageTask`/`outputUrls` output shapes)
- Create: `app/Media/Adapters/KinoviAdapter.php`
- Test: `tests/Feature/Media/KinoviAdapterTest.php` (HTTP-faked)

**Interfaces:**
- Consumes: Tasks 1,5; `KinoviProtocol`, `AiProviderTransport` (createTask/recordInfo shapes).
- Produces: `KinoviAdapter` implementing `MediaProviderAdapter` for image (async): `buildRequest` delegates to `KinoviProtocol::imageTask`; `submit` posts createTask → `SubmitResult(immediate:false, taskId)`; `poll` reads recordInfo → `StatusResult`. `support()` = async+polling.

- [ ] **Step 1: Write characterization test** locking `KinoviProtocol::imageTask(['model'=>'kinovi-ai/...','prompt'=>'x','size'=>'1024x1024'])` == the current documented body; run → PASS (pins behavior).
- [ ] **Step 2: Write failing adapter test** with `Http::fake` for createTask+recordInfo → adapter.submit returns taskId; adapter.poll returns completed with URLs.
- [ ] **Step 3: Run** → FAIL.
- [ ] **Step 4: Implement** `KinoviAdapter` (reuse transport send patterns; do not duplicate protocol body-building).
- [ ] **Step 5: Run** both tests → PASS. **Commit** `feat(media): KinoviAdapter (image) over existing protocol`.

---

### Task 7: `media_assets` table + model

**Files:**
- Create: `database/migrations/2026_09_22_100002_create_media_assets_table.php`, `app/Models/MediaAsset.php`
- Test: `tests/Feature/Media/MediaAssetModelTest.php`

**Interfaces:**
- Produces: table `media_assets` `{id(uuid), user_id FK, media_type, role(nullable), storage_disk, storage_path, size_bytes, mime, signature_ok(bool), metadata(json nullable), retention_status(enum active|expired|deleted default active), expires_at(nullable), timestamps, index(user_id,created_at)}`. Model casts + `scopeOwnedBy`.

- [ ] **Step 1: Write failing test** — create asset owned by a user; `MediaAsset::ownedBy($user)->first()` returns it; uuid PK works.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** migration+model. **Step 4: Run** → PASS. **Step 5: Commit** `feat(media): media_assets table + model`.

---

### Task 8: `AssetService` — upload, signature validation, access, public delivery

**Files:**
- Create: `app/Media/AssetService.php`, `app/Http/Controllers/Api/MediaAssetController.php`
- Modify: `routes/web.php` (auth'd upload + owner-gated fetch + signed public-delivery route)
- Test: `tests/Feature/Media/AssetServiceTest.php`

**Interfaces:**
- Consumes: Task 7; `Storage` (`local` private, `public` for delivery), `Illuminate\Support\Facades\URL::temporarySignedRoute`.
- Produces: `AssetService::store(User, UploadedFile, InputRole): MediaAsset` (validates size + allowed mime + magic-byte signature; stores on `local`); `::assertOwner(User, MediaAsset)`; `::publicUrl(MediaAsset, int $ttlSeconds): string` (temporary signed route OR published copy on `public` disk, chosen per need; TTL default 3600); `::deliver(MediaAsset)` for the signed route with signature+expiry check.

- [ ] **Step 1: Write failing tests**: (a) a fake PNG upload stores + `signature_ok=true`; (b) a `.png` file with non-image bytes → rejected; (c) oversize → rejected; (d) `publicUrl` returns a URL that `deliver` accepts with a valid signature and 403s a tampered/expired one; (e) `assertOwner` throws for a non-owner.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** service + controller + routes (`POST /api/media/assets`, `GET /api/media/assets/{asset}` owner-gated, `GET /api/media/public/{asset}` signed).
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(media): controlled AssetService with signed public delivery`.

---

### Task 9: `capability_revision_id` on job tables

**Files:**
- Create: `database/migrations/2026_09_22_100003_add_capability_revision_to_media_jobs.php`
- Modify: `app/Models/ImageJob.php`, `VideoJob.php`, `AudioJob.php` (fillable + cast)
- Test: `tests/Feature/Media/JobCapabilityRevisionTest.php`

**Interfaces:**
- Produces: nullable `capability_revision_id` FK (restrictOnDelete) + `routing_identity` (string, nullable) + `price_tokens` (uint, nullable) on `image_jobs`/`video_jobs`/`audio_jobs`.

- [ ] **Step 1: Write failing test** — an ImageJob persists+reads `capability_revision_id`; the referenced revision cannot be deleted while a job references it.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** migration + model fillable/casts. **Step 4: Run** → PASS. **Step 5: Commit** `feat(media): jobs carry capability revision + price`.

---

### Task 10: `MediaGenerationCoordinator` — resolve→validate→reserve→persist→dispatch (image path)

**Files:**
- Create: `app/Media/MediaGenerationCoordinator.php`
- Modify: `app/Services/ImageGenerationService.php` (route the async/kinovi image path through the coordinator; keep the existing sync fal/openai path untouched)
- Test: `tests/Feature/Media/CoordinatorImageFlowTest.php`

**Interfaces:**
- Consumes: Tasks 2,4,6,7,8,9; `MediaTokenBillingService`, `ImageJob`, `KinoviAdapter`.
- Produces: `MediaGenerationCoordinator::start(User, AiModelProfile, MediaOperation, array $inputs): ImageJob` — resolves capability, `CapabilityValidator::validate`, reserves `token_cost` tokens (idempotent ref `image:{uuid}`), creates the ImageJob with `capability_revision_id`+`routing_identity`+`price_tokens`, dispatches the existing `ProcessImageJob`. On validation failure NO reservation/job is created. `::onCompleted(ImageJob, string[] $urls)` settles + persists via AssetService; `::onFailed(ImageJob, string)` releases.

- [ ] **Step 1: Write failing tests**: (a) missing prompt → `CapabilityValidationException`, zero `TokenReservation` rows, zero `ImageJob` rows; (b) valid input → one reserved `TokenReservation`, one pending `ImageJob` with a non-null `capability_revision_id`, `ProcessImageJob` pushed (Queue::fake); (c) completion settles the reservation once (idempotent on double-call).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** coordinator; make `ImageGenerationService::generate` delegate the kinovi/async branch to it.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(media): generation coordinator (image) on capability+adapter+credit`.

---

### Task 11: Member/admin response separation + expose `last_error`

**Files:**
- Modify: `app/Services/MediaModelConfig.php` (`publicModel` adds `capability` summary + operations; never provider internals), `app/Models/AiProviderProfile.php` (`adminPayload` adds `last_error`), `app/Http/Controllers/Api/ImageController.php` (`imagePayload` member variant asserts no provider/routing leak)
- Test: `tests/Feature/Media/ResponseSeparationTest.php`

**Interfaces:**
- Consumes: Task 4 (operations), audit gap (last_error).
- Produces: member image model payload includes `id,name,capability,operations,token_cost,status` and EXCLUDES `provider`, `routing_identity`, cost-of-goods; admin payload includes `provider` + `last_error`.

- [ ] **Step 1: Write failing tests**: (a) member `/api/images/models` item has no `provider`/`routing_identity` keys and includes `capability`; (b) admin catalog payload includes `last_error` for a provider that has one.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement**. **Step 4: Run** → PASS. **Step 5: Commit** `feat(media): enforce member/admin response separation + surface provider last_error`.

---

### Task 12: Full-suite gate + prod proof-of-flow

**Files:** none (verification task).

- [ ] **Step 1:** Run the full suite: `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-local.ps1 artisan test`. Expected: all green (prior 395 + new).
- [ ] **Step 2:** `npm run build` (no FE changes expected; confirm build clean).
- [ ] **Step 3:** Deploy (git pull + `php artisan migrate --force` + config/view cache + restart `xsuper-media`/`xsuper-queue`).
- [ ] **Step 4:** Browser proof on https://xsuper.dev with a throwaway admin+tokens: submit a Kinovi `gpt-image-2` image; assert (a) required-input rejection with no prompt (FE+BE), (b) job goes pending→completed via the coordinator path, (c) reopens after refresh, (d) tokens reserve→settle once, (e) member response hides provider, admin sees full detail + capability revision on the job. Screenshot evidence.
- [ ] **Step 5:** Clean up throwaway user+assets; delete temp SSH key. **Commit** any final adjustments; report done/partial/blocked per brief §11.

---

## Self-review

- **Spec coverage:** §3.1 MediaCapability→T1/T2; §3.2 resolver+versioned+job-carries-revision→T4/T9/T10; §3.3 separation→T1/T5 (+UI metadata column T3); §3.4 adapter→T5/T6; §3.5 assets→T7/T8; §3.6 jobs/retry/credit→T9/T10; §4 member/admin→T11; §6 proof→T12; §7 testing→each task + T12. (Video/audio adapters + non-image operations land in F1–F3 on this same foundation — F0b proves the image path per brief §9.)
- **Placeholder scan:** none; each code step has real signatures/tests.
- **Type consistency:** `MediaCapability`/`CapabilityInput`/`CapabilityParam` (T1) reused verbatim in T2/T4/T10; `SubmitResult`/`StatusResult` (T5) reused in T6/T10; `MediaCapabilityRevision` (T3) referenced by T4/T9/T10.
