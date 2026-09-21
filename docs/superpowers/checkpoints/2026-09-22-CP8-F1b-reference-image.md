# CP8 — F1b Reference / Image-to-Image (`image_edit`) — local build report

**Status: reference/image-to-image DONE + verified locally through the real queue/worker. Mock, not live.** All work is local/isolated. No production deploy, no live provider call, no paid generation. Architecture stays Hybrid C; execution Inline + TDD. Baseline was CP7 `e9b9489`; no F0b redo and no F1 text-to-image rewrite.

## Delivered (commit `ee8e715` on `main`)

Backend
- `KinoviProtocol`: `REFERENCE_IMAGE` model set + `supports_reference_image` in the image config; `imageTask` accepts `uploadedUrls`.
- `MediaModelConfig::deriveCapabilities`: emits an `image_edit` capability (prompt + **required** `reference_image` asset input + size) for models that support a reference. Non-supporting models never expose it.
- Migration `2026_09_22_100004_add_reference_assets_to_image_jobs` adds nullable json `image_jobs.reference_asset_ids`; `ImageJob` fillable/casts/hidden updated.
- `MediaGenerationCoordinator`: `resolveReferenceAssets` validates existence + ownership (`AssetService::assertOwner`) + `media_type=image` for every declared asset **before** any reservation, persists the **stable asset id** (never a URL), and folds it into the idempotency fingerprint (`fingerprintPayload` hashes the validated inputs). A `CapabilityValidationException` is surfaced as a 422.
- `KinoviAdapter::buildRequest`: mints a short-lived, **cookie-independent** signed grant (`media.asset.deliver` = `/api/media/public/{asset}`) per reference into `uploadedUrls`; never a raw path or storage key.
- `ImageController`: `models` hides coordinator-only operations from non-coordinator users (no dead controls); `generate` validates `operation` + `reference_image`.
- `ImageGenerationService`: routes operation + reference on the coordinator path; the legacy (non-coordinator) path rejects any non-text operation with 503 rather than silently dropping a requested reference.
- Reuses the F0b `AssetService` (`store`/`assertOwner`/`signedUrl`) + the existing `POST /api/media/assets` upload endpoint — no parallel asset system.

Frontend (`resources/js/components/studios/`)
- `AssetUploadField.jsx`: capability-driven reference uploader. Uploads to `/api/media/assets`, keeps only the **internal asset id** (never a raw/signed URL), previews via a transient object URL, enforces JPG/PNG/WebP + ≤15 MB with actionable errors (the specific upload error now takes precedence over the generic required-field message), and supports remove/replace. A cleared value (model/mode change dropping an incompatible reference) resets the preview.
- `capability.js` / `CapabilityForm.jsx`: render asset inputs from the declared contract and reconcile drafts across model/mode change (a still-valid asset id is kept, an incompatible one is emptied); `capabilitySubmission` emits the stable asset id.
- `GenerateImage.jsx`: adds the operation-mode selector ("Teks ke gambar" / "Edit dengan referensi"); a submit error keeps the draft (values are never reset), so a 409 stale-price/revision keeps the user's work.

Tests
- `ImageReferenceLifecycleTest` (6): grant round-trip with the asset id + `signature=` in `uploadedUrls`, stable id persisted, `process()`→`poll()`→completed settled **once** (no double charge); missing → 422, other-user → 403, unknown → 422; grant cookie-independent + signature-enforced + **expiry-enforced**; retry with the same key + same reference reuses the job (no second reserve) while a **swapped reference → 409**.
- `capability.test.js` (vitest, 7): declarative rules (`param_equals`/`has_input`/`required_when`), the client error mirror, draft reconciliation (drops an incompatible asset), and stable-id submission.

## Verification
- Isolated backend suite (sqlite `:memory:`): **464 passed** (3218 assertions) — adds the reference lifecycle + retry + expiry coverage; no regression. FE unit (vitest): **7 passed**. `npm run build` clean.
- **Browser e2e against a LOCAL MOCK provider (NOT live)** — local app (`:8000`) + a loopback mock Kinovi (Node, `:9000`) + the real `queue:work media` worker. Driven end-to-end through the **real queue/worker — no manual `process()`/`complete()`**:
  - Upload → `/api/media/assets` (201) → `image_edit` submit with the asset id → coordinator reserves (balance 490→480) and dispatches to the `media` queue.
  - `ProcessImageJob` (worker) minted the grant and called the mock; the mock, **with no cookies/session**, actually fetched the grant and logged `FETCH REFERENCE OK status=200 type=image/jpeg bytes=5954 url=http://localhost:8000/api/media/public/<asset-id>?expires=…` — the exact 5954-byte reference. Grant round-trip proven.
  - `PollImageJob` (worker) → completed; `billing=settled` **once**. The result served at the owner-gated `/api/images/{job}/assets/0` is the echoed reference (byte-identical, 5954 B, `image/jpeg`) — visible proof the provider fetched the reference via the grant.
  - Draft persistence: reload restores mode + the reference asset id + prompt; the history card reopens the completed result (same as text-to-image).
  - Uploader: an invalid type shows "Gunakan berkas JPG, PNG, atau WebP."; a valid image stages ("Referensi siap" + "Ganti"); remove returns the file input.
  - **No text-to-image regression**: a text-to-image submit completes through the same worker; switching to "Teks ke gambar" drops the reference field.

## Worker "flakiness" — diagnosed, not a code defect
Earlier stuck submits had two mundane causes, both evidenced:
1. **Idempotency working as designed.** Re-submitting the *identical* reference + prompt returned the earlier (stuck) job by its idempotency key — a test-procedure error, not a bug. A fresh prompt (new key) completed immediately.
2. **A competing old-code media worker.** An externally-supervised project worker (`ultrai-media-2209`, `queue:work media --timeout=450`) was running **pre-F1b / pre-loopback-affordance** code and raced the current-code worker. Jobs it grabbed never reached the mock and timed out; jobs the current-code worker grabbed completed. Reducing to a single **current-code** media worker made every submit reliable (`ProcessImageJob` + `PollImageJob` on each). Earlier `php -S` mock artifacts (an `STDERR` fatal; `forking is not supported on this platform` on Windows) were separately eliminated by the Node mock.

**Deploy-relevant takeaway:** media workers must be **restarted onto the new code** as part of any rollout, or a stale long-running worker will silently fail the new operation.

## Acceptance matrix
valid (lifecycle test + browser e2e) · missing → 422 · invalid/unknown → 422 + FE type reject · other-user → 403 · expired grant → 403 · retry same key → same job (+ swapped reference → 409) · new key → new job · price/revision change → 409 with the draft kept (`CoordinatorGuardsTest` + FE) · refresh reopens via history · no text-to-image/routing regression (full suite + `CoordinatorRoutingTest`).

## Honest limitations
- **Mock, not live.** All F1b provider interaction used a local mock. No live Kinovi reference call and no paid generation were performed. Live reference behavior is unverified.
- Local Windows harness needs a single current-code media worker (see diagnosis); this is an environment/rollout note, not a code issue.
- The reference contract is proven for the single-image `image_edit` shape; multi-reference/other roles render structurally but aren't exercised until a model declares them.

## Proposed deploy (separate, NOT executed — needs your go)
Ship F1 (CP7, code+FE only, no migration) and F1b together, or F1 first then F1b. **F1b has a schema change** (`image_jobs.reference_asset_ids`), so unlike F1 it requires a migration and a DB backup.
1. Backup DB (F1b changes schema).
2. Maintenance on → `git checkout` the release → `composer install` → `npm ci && npm run build`.
3. `php artisan migrate` (adds the nullable column; additive, reversible).
4. `config:cache` / `route:cache` / `view:cache`.
5. **Restart every media/queue worker so they load the new code** (critical — see diagnosis).
6. Maintenance off; smoke-check text-to-image and `image_edit` against the real provider on a pilot account only.
The local-provider affordance stays inert in production (env ≠ local, flag unset). Keep `MEDIA_COORDINATOR_RESTRICTED` as-is unless rollout is separately authorized. No price/paid-generation changes in this checkpoint.
