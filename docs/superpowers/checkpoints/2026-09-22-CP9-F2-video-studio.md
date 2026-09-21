# CP9 — F2 Video Studio (capability-driven: text-to-video + image-to-video) — local build report

**Status: text-to-video + image-to-video DONE + verified locally through the real queue/worker. audio-to-video has no provider and was NOT built. Mock, not live.** All work is local/isolated on branch `f2-video-studio` (baseline `f1b-baseline`=`ee8e715`; `main` untouched at F1+F1b so a deploy of that never carries unfinished F2). No production deploy, no live provider, no paid generation. Architecture stays Hybrid C; execution Inline + TDD.

## Scope from the actual implementation
- **text_to_video** — DELIVERED. fal `longcat-video` (text-to-video) via the coordinator; Kinovi video models also derive text-to-video.
- **image_to_video** — DELIVERED. fal `longcat-video` image-to-video; the owned reference asset is inlined as a private data-uri; routes to the provider reference model.
- **audio_to_video** — **NOT SUPPORTED / NOT BUILT.** No fal (or Kinovi/OpenAI) endpoint, protocol branch, or model accepts audio input for video. Only the unused `MediaOperation::AudioToVideo` enum case exists. No fake mode was created; it is simply not exposed.

## Delivered (commit `208e5aa` on `f2-video-studio`)

Backend
- `MediaModelConfig::deriveVideoCapabilities`: emits `text_to_video` (prompt + aspect_ratio + duration) and — only when the provider accepts a reference (`supports_reference_image`) — `image_to_video` (prompt + **required** `reference_image` asset + duration, **no aspect_ratio**, since the reference fixes the frame). Kinovi video stays text-only (it rejects reference images), so image-to-video is never offered there.
- Migration `2026_09_22_100005_add_capability_to_video_jobs`: adds the coordinator columns `image_jobs` already had (`capability_revision_id`, `routing_identity`, `price_tokens`, `dedup_key`, `payload_fingerprint`, `reference_asset_ids`) — all nullable/additive; `VideoJob` fillable/casts/hidden updated.
- `MediaGenerationCoordinator::startVideo`: mirrors `startImage` — resolve capability → validate → price/hash 409 guards → own every reference asset (existence/ownership/media-type) **before** reserving → dedup/idempotency → reserve → persist a `VideoJob` (revision + routing + price + reference ids) → dispatch `ProcessVideoJob`. `image_to_video` sets the routing identity to the provider's reference model and stores `aspect_ratio='auto'`.
- `VideoGenerationService::process`: coordinator jobs reuse the existing, verified Fal pipeline; the only difference is the reference source — an owned `MediaAsset` via `AssetService::dataUri` (private inline base64) instead of the legacy per-job store. No separate adapter was added: the transport IS the integration (avoids duplicating the mature video pipeline).
- `AssetService::dataUri`: private base64 data-uri for providers that consume an inline reference (fal `image_url`); no public URL is ever minted.
- `AiProviderTransport`: **double-gated local dev affordance** (APP_ENV=local AND `media.allow_local_providers`) lets a loopback fal `base_url` point video submit/status at a local mock instead of `queue.fal.run`. Production is never `local` and never sets the flag, so the strict `queue.fal.run`-only host guard always applies there.
- `VideoController`: a capability submission (carrying `operation`) routes to the coordinator for the pilot; a non-pilot keeps the existing verified path (text-to-video); a legacy submission (no `operation`) is untouched. This distinguishes pilot **routing** from blocking the existing service (never blocks it).

Frontend (`resources/js/pages/VideoGenerator.jsx`)
- Rewritten capability-driven, mirroring the image studio: model → mode selector (Teks ke video / Gambar ke video) → contract-rendered inputs/params (prompt, aspect ratio, duration, reference image asset) → cost → Generate → job status → video playback/download → history. Reuses `capability.js` / `CapabilityForm` / `AssetUploadField` and the shared Studio kit. A 409 (stale price/revision) keeps the draft and refreshes the catalog; refresh reopens a finished job's result from history.

Tests
- `VideoCapabilityCatalogTest` (3): `/api/v/models` exposes `text_to_video` + `image_to_video` (required reference asset, no aspect_ratio) for fal; Kinovi is text-only; no `audio_to_video`.
- `VideoReferenceLifecycleTest` (5): text/image-to-video coordinator lifecycle (aspect+duration sent; private data-uri to the reference model; stable asset id persisted), settle-once, missing → 422, other-user → 403, retry idempotency.
- `VideoCoordinatorRouteTest` (3): pilot capability submit → coordinator (revision set, routes to reference model for i2v); non-pilot text → legacy path; non-pilot image-to-video → 503.

## Verification
- Isolated backend suite (sqlite `:memory:`): **475 passed** (3281 assertions) — adds the 11 video tests; **no F1/F1b/routing regression** (`VideoStudioContractTest`, `CoordinatorRoutingTest`, image lifecycle all green). FE unit (vitest): **7 passed**. `npm run build` clean.
- **Browser e2e against a LOCAL MOCK provider (NOT live)** — local app (`:8000`) + a loopback fal mock (Node, `:9100`) + the real `queue:work media` worker (a single current-code worker; strays killed per the F1b lesson). Driven end-to-end through the **real queue/worker — no manual `process()`/`poll()`**:
  - **text_to_video**: prompt + aspect + duration → coordinator reserve (470→450) → `ProcessVideoJob` (submit to the mock) → `PollVideoJob` (COMPLETED → download) → the result served at the owner-gated `/api/v/{jobId}/asset` (`video/mp4`). Settled once.
  - **image_to_video**: uploaded a 3158-byte reference via `AssetUploadField` (`/api/media/assets`, 201); the mock, over the inline `image_url`, logged `CONSUMED REFERENCE type=image/jpeg bytes=3158` at the image-to-video endpoint — the reference round-trip (MediaAsset → private data-uri → provider) is proven. Reserve 450→430, settled once.
  - **Playback + download proven**: the result `<video>` played (duration 10s, 640×360, no fallback error) and the download link served the fixture. The fixture is a small stock clip served by the mock — **NOT provider output**.
  - Draft/mode compat (aspect_ratio drops for image-to-video), required-input validation, and refresh recovery (reload → history → reopen the played result) all confirmed.

## Honest limitations
- **Mock, not live.** All F2 provider interaction used a local mock. No live fal call and no paid generation. The result video is a stock fixture, not model output.
- **audio_to_video is unsupported** (no provider) — not exposed anywhere; not "done".
- The **fal loopback affordance is local-only** (double-gated, inert in production).
- **Non-pilot image-to-video is coordinator-only** (503 on the capability path); non-pilot text-to-video falls back to the legacy path. The legacy `pro` quality tier remains legacy-only and is not part of the capability path in F2.
- Multi-reference / other roles render structurally but aren't exercised until a model declares them.

## Schema changes
- One new migration (`2026_09_22_100005_add_capability_to_video_jobs`) — additive, nullable columns on `video_jobs`, reversible. Applied + tested locally only; **not** applied to production. No existing/applied migration was modified.

## Deploy
**Not executed and not requested.** F2 stays on `f2-video-studio`; `main` remains at F1+F1b (`ee8e715` + CP8). The CP8 F1+F1b deploy proposal remains unauthorized. A future F2 deploy would additionally require this migration + a worker restart onto current code (per the CP8 worker-race lesson).
