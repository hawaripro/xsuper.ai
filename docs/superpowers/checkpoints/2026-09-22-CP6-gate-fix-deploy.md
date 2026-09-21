# CP6 — gate regression fix deployed (existing Kinovi image service restored)

**Status: DONE.** Deployed RC `6e2e3b5` to production to restore the existing Kinovi image path for regular members. Read-only verification only — no `Generate`/submission calls, no new paid generation. Coordinator remains gated (not rolled out).

## Deploy
- **Deployed RC: `6e2e3b57720f358447549e496f92feec973e7008`** (`6e2e3b5`) — the authorized fix. Preflight guard confirmed prod was at `611a88d` before checkout (matched proposal); aborts if not.
- **No migration** (code + tests only; verified `611a88d..6e2e3b5` touches no `database/migrations`). No composer change; prod-local `resend/resend-php` preserved (`grep` confirmed post-checkout).
- Procedure: `php artisan down` → checkout `6e2e3b5` → `composer dump-autoload -o` (7695 classes) → `config:clear/route:clear/view:clear` + `config:cache` → restart `xsuper-media`+`xsuper-queue` → `up`. php-fpm `opcache.validate_timestamps` is unset → default 1, so fpm serves the new code (same as the T7 deploy).

## What the fix changes
`MediaActivation` now ROUTES instead of blocking:
- `assertNotPaused()` = kill switch pauses ALL new submissions.
- `usesCoordinator(user)` = pilot (or everyone when unrestricted) → coordinator; every other member → existing verified path; empty/invalid restricted id → existing path for everyone.
- `ImageGenerationService::generate()` selects the path BEFORE reservation; the removed `createAsync` (existing async Kinovi path) is restored with provider routing identity so the shared `process()/poll()` execute it via the Kinovi adapter. No cross-path retry (no double charge). Accepted jobs finish on the shared pipeline.
- Isolated tests: 451 pass (pilot/non-pilot/empty-id/unrestricted/kill-switch/permission + non-pilot legacy job completes end-to-end, settles once).

## Read-only production verification (no submission triggered)
- Prod commit: `git rev-parse --short HEAD` = **`6e2e3b5`**.
- Effective config on the www-data runtime (php-fpm + xsuper-media): `coordinator_restricted=true`, `restricted_user_id=7`, `kill_switch=false`.
- Routing probe (calls `MediaActivation` only — no `generate()`/submit):
  - Real active non-pilot member (id 1): `member_route=existing-path`, `member_gate=not-blocked` → the previous 503 is gone; they proceed on the existing path. Model/permission checks still apply inside that path.
  - Pilot (id 7): `pilot_route=coordinator`, `pilot_active=no` → still disabled (cannot log in); coordinator used by no one.
- Workers: `xsuper-media=active`, `xsuper-queue=active`. Site: `https://xsuper.dev/` → 200; `/api/images` unauth → 401. Maintenance flag absent.

## Preserved / not changed
- `MEDIA_COORDINATOR_RESTRICTED=true`; pilot user 7 stays disabled; coordinator NOT opened to all.
- Sell price, provider config, balances, and all transaction evidence unchanged. Composer local prod change preserved.

## Not done (still requires separate authorization)
- General rollout (`restricted=false`), pilot reactivation, migration, DB restore, data deletion, price/balance changes, and any new paid generation — none performed.

## Verification limitations (accurate)
- The restoration was verified by read-only routing/config checks + the isolated legacy-lifecycle test + the earlier live coordinator smoke. **No new live generation was run in this deploy**, so this does not claim every model was exercised live. Live reference→provider (image-to-image) and other providers remain unverified in production.
