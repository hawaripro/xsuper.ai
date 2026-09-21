# F0b — CP4 evidence + T7 rollout proposal (final)

**This document proposes T7. It does NOT authorize prod deploy, migration, worker restart, DB restore, or paid generation.**

## Release candidate
- **RC SHA: `611a88d`** on `main` (supersedes `168649b`/`88e41b7`). Any further code change → new RC + new test run; earlier RC results do NOT carry over.
- RC artifact = the git repo at `611a88d`; migration files under `database/migrations/` are present before code activation.
- Isolated test env (local PostgreSQL via `scripts/run-local.ps1`, `Storage::fake`, `Http::fake`). No real provider call; no paid generation.
- `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-local.ps1 artisan test` → **446 passed / 3133 assertions**.

## CP4 acceptance (fixture/mock unless noted; NONE live)
| ID | Scenario | Evidence | Status |
|---|---|---|---|
| Q01 | published/legacy; broken→error; disabled no-revive | `CapabilityResolverTest` | proven |
| Q02 | required/conditional input rejected pre-charge (backend) | `CapabilityValidatorTest`, `CoordinatorImageFlowTest`, `ImageLifecycleTest` | proven; FE feedback = F1 |
| Q03 | stale revision/price rejected before reserve+provider | `CoordinatorGuardsTest`; FE sends `expected_price_tokens` | proven |
| Q04 | asset ownership/signature/size | `AssetServiceTest` | proven |
| Q05 | provider delivery cookie-independent; invalid/expired rejected | `AssetServiceTest` | proven; live provider-fetch unverified |
| Q06 | retry→same job; new action same input→new job; same key diff input→conflict | `CoordinatorImageFlowTest` | proven |
| Q07 | dispatch-failure recovery, no duplicate charge | `DispatchRecoveryTest` (method + scheduled `images:reconcile-stale` command) | proven |
| Q08 | timeout/uncertain submit → no refund/resubmit | `ImageLifecycleTest::uncertain_submit` | proven |
| Q09 | duplicate/out-of-order callback | kinovi = polling only; idempotent poll | partial (no callback path) |
| Q10 | provider done, save failed → retry finalization, not new gen | `ImageLifecycleTest::finalization_failure` | proven |
| Q11 | refresh/reopen; owner reads private asset | `ImageLifecycleTest::happy` | proven |
| Q12 | ledger/regression | full suite 446 | proven |
| Q13 | member no provider; admin sanitized | `ResponseSeparationTest` | proven |
| Q14 | F6a schema; unsupported required blocks publish | `FalSchemaCompatibilityTest` | proven (fixture) |
| Q15 | rollout/rollback; limited smoke | this plan | **T7, not executed** |

Kill switch + fail-safe limited activation proven: `CoordinatorGuardsTest` (kill switch → new 503, in-flight untouched; restricted → only test member; **empty/invalid id → CLOSED for everyone**).

**Live provider + live reference→provider: unverified** (isolated only; pilot = text-to-image). No Kinovi webhook / live reference upload added.

---

## T7 rollout proposal (awaiting authorization)

### Migrations (approved list = 3 files)
1. `2026_09_22_100001_create_media_capabilities_table`
2. `2026_09_22_100002_create_media_assets_table`
3. `2026_09_22_100003_add_capability_to_image_jobs` — reported change: also adds nullable `payload_fingerprint`. Still one file; additive + reversible.
- Before `migrate --force`: `php artisan migrate:status`; proceed only if exactly these three are pending, else STOP.

### Recovery (operational — now real, no auto-claim beyond what is wired)
- **Dispatch recovery is scheduler-wired:** `images:reconcile-stale` (every minute, `withoutOverlapping`) now both releases stale reservations AND re-dispatches the idempotent processor for stale undispatched jobs. `process()` acts only on a still-queued job → no duplicate charge; uncertain-acceptance submissions are never turned into a new paid request.
- **Rollback = application-level, non-destructive:** kill switch (`MEDIA_KILL_SWITCH=true`) halts NEW submissions; and/or redeploy the previous prod commit. All schema is additive + nullable, so the old code runs unchanged against the new schema — no table/column drops, no data loss. Accepted jobs keep completing.
- **DB restore is NOT part of this T7 authorization.** If a restore is ever required, STOP and submit a separate recovery plan — do not assume exporting post-backup rows recovers all transactions and balance changes.

### Backup (pre-migration, verified)
- `pg_dump` the app DB; record path + checksum; verify it restores into a scratch DB before migrating. (Backup is insurance; the rollback path above is app-level, not restore.)

### Limited activation (closed-first) + kill switch
1. Set `MEDIA_COORDINATOR_RESTRICTED=true` and `MEDIA_COORDINATOR_USER_ID=<test member id>` BEFORE the release serves requests.
2. Run `php artisan config:cache` (existing deployment uses cached config) and **verify effective config on BOTH the web (php-fpm) process and the queue workers** (e.g. a bootstrap read of `config('media.*')` under each runtime).
3. Fail-safe: with `coordinator_restricted=true`, an empty/invalid `restricted_user_id` is CLOSED for everyone — it never opens the coordinator for all users. Other members keep existing verified behavior throughout.
4. After verification the path is open only for the test member. **After smoke, do not expand activation without new authorization.**
- Kill switch rejects NEW coordinator submissions (503) while accepted jobs keep being polled/finalized.

### Release + workers
- Record current prod commit (`git rev-parse HEAD`) before pulling.
- Sequence: verify backup → set closed-first activation env + `config:cache` + verify effective → `migrate:status` → `migrate --force` (3 additive) → deploy `611a88d` → `config:cache`/`view:cache` → graceful restart `xsuper-media`/`xsuper-queue`. Additive schema → in-flight VideoJob/AudioJob/ImageJob unaffected; restart after migrate+deploy so workers load new classes; let leases finish or resume via reconcile — no mid-write kill.

### Bounded smoke test (no pricing change)
- **1 test member, 1 submission, 1 output.**
- **Do NOT change any model's `token_cost` / sell price or any user ledger.** The member's app-token reservation uses the EXISTING sell price unchanged. Pricing changes require separate approval. Kinovi credits ≠ xsuper.ai media credits — they are different ledgers.
- Before submit: **verify the actual Kinovi credit cost from the real model + request parameters** on the account. Cap to approve: **one image ≤ 2.17 Kinovi credits**. If the cost cannot be confirmed or would exceed the cap, **do not submit**.
- If submit times out / status uncertain: reconcile the SAME job; never auto-create a new paid generation.
- Verify: coordinator path (`capability_revision_id` set), member response hides provider, existing sell-price token reserve→settle once, asset served owner-gated.

### Cleanup (non-destructive)
- Do NOT hard-delete the test member. Disable access (`is_active=false`) + revoke its sessions/API tokens. Keep identity + audit relations, and every job/revision/ledger/settlement/smoke record. Remove only safe temporary artifacts. Never alter a real customer balance or delete transaction evidence.

### Authorization needed for T7
(a) `pg_dump` + scratch-restore verify; (b) closed-first activation env + `config:cache` + verify; (c) `migrate --force` of the 3 migrations; (d) deploy `611a88d`; (e) graceful worker restart; (f) one paid Kinovi image ≤ 2.17 Kinovi credits scoped to the test member.

## Remaining limitations
- Live provider + live reference→provider: unverified (isolated proof only).
- Q09 callback path: N/A for Kinovi (polling only).
