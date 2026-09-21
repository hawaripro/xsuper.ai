# CP5 — T7 limited production activation + smoke report

**Status: DONE.** One authorized Kinovi image generated in production via the capability coordinator, fully verified. No customer balance, sell price, or transaction evidence was altered. Activation remains gated (not expanded to general members).

## Release
- **Deployed RC: `611a88d0645cd547228a8bbde3df1c4c0ce28f82`** (short `611a88d`) — exactly the authorized RC, deployed as a detached checkout (NOT `main`-latest `6d628f4`, which is docs-only on top).
- Previous prod commit (rollback target): **`20d45fae9e790272f6fa31702eaf3427d923ee09`** (`20d45fa`).
- Prod-local `composer.json`/`composer.lock` change (`resend/resend-php ^1.15`) was **preserved** (RC does not touch composer; checkout kept it; `grep` confirmed post-checkout).
- Deploy method: `php artisan down` → checkout RC → `composer dump-autoload -o` → guarded `migrate` → `config:clear/route:clear/view:clear` + `config:cache` → restart `xsuper-media` + `xsuper-queue` → `php artisan up`. Full-site maintenance window ~seconds (guaranteed no open-coordinator window = closed-first).

## Backup (pre-migration, verified)
- `pg_dump -Fc xsuper_db` → `/var/backups/xsuper/xsuper_preT7_20260921_210324.dump` (618 KB).
- sha256 `4582a57dba0bd5f88322c8e78b9ce283fdb5714f27e332fd324bebe93f30fd20`.
- **Restore verified** into scratch DB: row parity users 4=4, image_jobs 5=5, 49 tables; scratch dropped after.

## Migrations (exactly the 3 approved; guard enforced)
Guard aborted-and-rolled-back unless exactly 3 pending. Observed 3 pending → applied:
1. `2026_09_22_100001_create_media_capabilities_table` — DONE
2. `2026_09_22_100002_create_media_assets_table` — DONE
3. `2026_09_22_100003_add_capability_to_image_jobs` (adds `capability_revision_id` + `payload_fingerprint`) — DONE

Post: `migrate:status` pending = 0. Additive/reversible; old code runs unchanged against new schema.

## Config / worker / scheduler checks (effective, not assumed)
- Live config read on the **www-data runtime** (php-fpm + `xsuper-media` worker share it): `coordinator_restricted=true`, `restricted_user_id=7`, `kill_switch=false`.
- Workers after restart: `xsuper-media=active`, `xsuper-queue=active`. `ProcessImageJob`/`PollImageJob` run on the `media` queue (xsuper-media).
- Site: `https://xsuper.dev/` → 200; `/api/images` unauth → 401.
- Scheduler cron (every minute) lists `php artisan images:reconcile-stale` → **Q07 auto-recovery operational** (re-dispatches lost dispatches + releases stale reservations).
- **Live gate proof** (`MediaActivation::assertCanSubmit` on the live runtime): user 7 allowed; user 1 (non-test) blocked with 503 "This model is not available for your account yet." Empty/invalid id would be fail-safe CLOSED for everyone.

## Provider cost (verified before submit; ≤ cap)
- Authoritative source kinovi.ai/models/gpt-image-2: **GPT Image 2 · Low · 1K = $0.0100/image = 2.17 credits/image** (cheapest tier).
- Code sends deterministically `resolution=1k` + no quality (API default `low`) + `aspectRatio 1:1` → exactly Low·1K = **2.17 Kinovi credits ≤ 2.17 cap**. Kinovi credits are a separate operator ledger, not xsuper.ai media tokens.

## Smoke (1 member · 1 submission · 1 output)
- Test member: id **7**, `t7smoke_20260921192156@xsuper.dev`, role `member` (non-admin), default perms (`image_generator`), `expires_at=null`, funded 50 media tokens (topup).
- Browser (`https://xsuper.dev/generate-image`): model **GPT Image 2** (`kinovi-ai/gpt-image-2`), size 1024x1024, n=1, estimate **10 tokens** (existing `token_cost`, unchanged). Confirmation modal → generated.
- Result: real photorealistic image rendered on canvas, status **Selesai/completed**, 1024×1024.
- Job id **`7376b81a-f513-4bfd-87eb-350a95811357`** (image_jobs id 10).

## Evidence (job / db / ledger / asset / member payload)
- **Coordinator path**: `image_jobs.capability_revision_id = 1` (non-null → coordinator, not legacy), `payload_fingerprint` set, `provider_id=2` stored internally, `status=completed`, `billing_status=settled`.
- **Capability**: `media_capabilities` id 1 — profile 783, `text_to_image`, revision 1, status `tested` (resolved + snapshotted).
- **Billing (settle once, no double-charge)**: `token_transactions` user 7 — `topup +50 → 50`, `deduct 10 → 40` ("Reserve 1x image (kinovi-ai/gpt-image-2)"). Net −10 at existing price. No manual ledger correction.
- **Member payload** (`GET /api/images`, member session): NO `provider` key, string "Kinovi AI" absent; shows `model` id + prompt + size + `billing_status=settled` + `tokens_reserved=10` + owner-gated `result_urls`. `cost_microusd=0` (token ledger, not micro-USD — ledgers separate).
- **Asset owner-gating**: owner fetch `200 image/png` (1,424,389 bytes); anonymous fetch **401**. Stored private `generated/images/7376b81a.../0.png`.

## Cleanup (non-destructive)
- Test member id 7: `is_active=false`; 1 session + 2 device records revoked; live session now returns 401. **NOT deleted.**
- Retained: user identity, `token_transactions` (2 rows), `image_jobs` (1), `media_capabilities` (1), generated asset, balance 40. No transaction evidence removed.
- All temp scripts removed from prod (`t7_setup.php`, `/root/t7_deploy.sh`, `t7_gate.php`, `t7_cleanup.php`).

## Final activation state (NOT expanded — new auth required to roll out)
- `MEDIA_COORDINATOR_RESTRICTED=true`, `restricted_user_id=7`; user 7 now disabled → coordinator effectively **closed to everyone**.
- Scope of the gate: it restricts ONLY the Kinovi **image** coordinator path. Consequence: general members selecting a Kinovi image model now get 503 "not available for your account yet." Kinovi **video**, and openai/fal image models, are unaffected (different, non-coordinator paths).
- To roll out to all members later (separate authorization): set `MEDIA_COORDINATOR_RESTRICTED=false` + `config:cache`. To hard-pause: `MEDIA_KILL_SWITCH=true`. Application rollback (if needed): checkout `20d45fa` + `config:cache` (additive migrations stay, harmless to old code). DB restore remains out of scope.

## Remaining limitations
- Only text-to-image on one Kinovi image model was exercised live. Live reference→provider (image-to-image) and other providers remain unverified in production (isolated proof only).
- Q09 provider callback path is N/A for Kinovi (polling only).
- FE compiled bundle not rebuilt (new `idempotency_key`/`expected_price_tokens` are optional; backend accepts their absence; the studio redesign is F1).

---

## Correction (post-CP5) — gate impact on existing service, verified + fixed in isolation

**Status update (accurate):**
- Pilot coordinator live: **succeeded** (evidence above).
- General rollout: **NOT authorized.**
- Gate impact on existing service: **VERIFIED regression** (details below).
- Live reference upload + other providers: **unverified.**

### Verified impact (read-only; no paid generation)
The T7 gate DID block previously-available Kinovi image service for regular members. Evidence:
- All 8 Kinovi image models (ids 783,785,787,788,789,790,791,792) are `is_enabled+is_available` → shown to every member, before and after T7.
- Concrete prior usage: `image_jobs` id 8 — user 1 generated `seedream-5.0-pro` (a Kinovi model) successfully **before T7** via the legacy `createAsync` path (`capability_revision_id` null).
- RC `611a88d` removed `createAsync` and routes ALL Kinovi image → coordinator → `assertCanSubmit`, which throws 503 for any non-pilot **before path selection**. So the gate BLOCKS the service; no legacy path remained.
- Net: on prod (`611a88d`, restricted=true, pilot user 7 now disabled), every member gets 503 for all 8 Kinovi image models. Kinovi video + openai/fal image are unaffected (separate, non-coordinator paths).

### Fix prepared in isolation (RC `6e2e3b5`, pushed, NOT deployed)
`MediaActivation` now ROUTES instead of blocking:
- `assertNotPaused()` = kill switch pauses ALL new submissions.
- `usesCoordinator(user)` = pilot (or everyone when unrestricted) → coordinator; every other member → existing verified path; empty/invalid restricted id → existing path for everyone (coordinator opens for no one).
- `generate()` selects the path BEFORE any reservation; the removed `createAsync` (existing async Kinovi path) is restored, setting provider routing identity so the SHARED `process()/poll()` execute it via the Kinovi adapter. No "coordinator error → legacy resubmit" fallback (no double charge). Accepted jobs finish on the shared pipeline (`capabilityForJob` defaults when `capability_revision_id` is null).
- Tests (isolated, 451 pass): pilot→coordinator; non-pilot→existing path (not blocked); empty id→existing for all; unrestricted→coordinator for all; kill switch→503 both; unavailable model→503 both; non-pilot legacy job completes end-to-end, settles once (no double charge), asset owner-gated.

### Proposed production action (needs explicit authorization — NOT executed)
Deploy `6e2e3b5` to restore existing member service. Specifics + impact:
- Procedure: `php artisan down` → checkout `6e2e3b5` → `composer dump-autoload -o` → `config:cache` → restart `xsuper-media`+`xsuper-queue` → `up`. **No migration** (code+tests only). `resend` composer change preserved (unchanged across commits).
- Resulting state (keep `restricted=true`, pilot user 7 stays disabled): every member → existing verified Kinovi image path (service restored); coordinator used by no one (not rolled out). No pricing/balance/provider change.
- Verification without paid generation: read-only probe that `usesCoordinator(member)=false` and the gate raises no 503; execution already proven by the isolated legacy-lifecycle test + the earlier live coordinator smoke.
- NOT proposed: opening the coordinator to all (`restricted=false`), app rollback, DB restore, price/balance changes, or any new paid generation.
