# CP7 — F1 Image Studio (capability-driven, text-to-image) — local build report

**Status: text-to-image DONE + verified locally. Reference/image-to-image = proposed next increment (not faked).** All work is local/isolated. No production deploy, no live provider call, no paid generation. Architecture stays Hybrid C; execution Inline + TDD.

## Delivered (commit `e9b9489` on `main`)
Backend
- `MediaCapabilityPresenter` + `CapabilityUi`: `/api/images/models` now returns, per model+operation, the resolved capability the FE renders from — `{operation, output_kind, inputs[], params[], ui{label/help/control/order}, source_hash, price_tokens}`. Only genuinely-supported operations are emitted (a broken/unsupported one is omitted, never a dead control).
- `ImageController::models` enriches each model with `capabilities` (presenter resolved internally, so direct calls + the route both work).

Frontend (`resources/js/components/studios/`)
- `capability.js`: a mirror of the backend contract (required + `required_when: param_equals|has_input`, enum options, numeric range) that drives rendering, draft reconciliation, and pre-submit validation. The backend remains the authority; there is **no second hardcoded per-model ruleset**.
- `CapabilityForm.jsx`: renders the declared inputs/params as labelled, typed controls (textarea, select, slider, number+unit, toggle) in the `ui.order` the backend specifies, reusing the existing studio kit + tokens.
- `GenerateImage.jsx`: rewritten to be capability-driven — model select → operation selector (when >1) → contract-rendered fields → cost from `price_tokens` → Generate. Submits `idempotency_key` + `expected_price_tokens` + `expected_capability_hash` + the capability values. Draft survives model changes (compatible values kept); a 409 (stale price/revision) keeps the draft and refreshes the catalog — never an automatic paid resubmit. Result canvas / variations / download / history preserved.

Local dev affordance (double-gated, production-safe)
- `AiProviderEndpoint` + `GeneratedImageStore` permit a loopback (127.0.0.0/8, ::1, localhost) mock over http ONLY when `APP_ENV=local` AND `media.allow_local_providers=true`. Production is never `local` and never sets the flag, so the strict public-HTTPS SSRF guard always applies there. `LocalProviderGuardTest` proves a non-local environment rejects loopback even with the flag.

## Verification
- Isolated suite (sqlite `:memory:`): **457 passed** (adds capability-exposure + hash round-trip + the guard tests). Design detector on the changed studio files: `[]` (no craft-floor violations). Mobile layout verified (single-column `@container` collapse); reduced-motion + focus handled by the existing system.
- **Browser e2e against a LOCAL MOCK provider (NOT live)** — local app + a loopback mock Kinovi + media worker:
  - Capability-driven form renders prompt (required input) + size (param) from the contract; required validation shows before submit.
  - Selecting the mock model updates the form + cost to 10 tokens (`price_tokens`).
  - Captured POST `/api/images` payload: `{model, n, idempotency_key(UUID), expected_price_tokens:10, expected_capability_hash:<source_hash>, prompt, size}` — the full contract, derived from the capability, not hardcoded.
  - Full flow completed (driven reliably via CLI through the mock; the long-running `queue:work` worker showed intermittent loopback flakiness — a local `php -S`/worker harness artifact, not a code defect): coordinator path (`capability_revision_id=1`), `process()`→mock submit→poll→download loopback image→save→`complete`, `billing=settled` (reserve→settle once, balance 490→480). The completed image renders on the canvas via the owner-gated `/api/images/{job}/assets/0`, with download + variations.
- Gate regression (pilot/non-pilot routing, kill switch, permissions) stays green via `CoordinatorRoutingTest` + the media suite.

## Honest limitations
- **Mock, not live.** All F1 provider interaction used a local mock. No live provider call and no paid generation were performed in F1. Live provider behavior remains as proven earlier only for the prod pilot (CP5) and remains otherwise unverified.
- **Reference / image-to-image not wired** (shown to nobody, per "display only genuinely-supported operations"). It needs: derive an `image_to_image` capability for reference-supporting models; a reference-upload endpoint creating a private `MediaAsset`; an `AssetService` short-lived signed provider-fetch grant; the coordinator/`KinoviAdapter` forwarding `uploadedUrls`; and a FE asset-upload control (the form + `capability.js` already handle asset inputs structurally). Proposed as the next increment.
- FE additional param types beyond the current image capability (prompt+size) render generically but aren't exercised live until a model declares them.

## Proposed next increments (need your go — NOT executed)
1. **F1b reference/image-to-image** end-to-end (the pipeline above), verified locally against the mock.
2. **Deploy proposal (separate, not auto-applied):** ship F1 to production. It has **no migration** (code + FE only); requires a production `npm run build`; the local-provider affordance is inert in prod (env≠local, flag unset). Would follow the same guarded procedure as CP6 (backup optional since no schema change, maintenance → checkout → build → caches → restart workers → up) and keep `MEDIA_COORDINATOR_RESTRICTED` as-is unless you separately authorize rollout.
