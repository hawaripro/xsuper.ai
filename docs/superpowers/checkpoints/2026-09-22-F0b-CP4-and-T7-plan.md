# F0b — CP4 evidence + T7 rollout proposal (revised)

**This document proposes T7. It does NOT authorize prod deploy, migration, worker restart, DB restore, or paid generation.**

## Release candidate
- **RC SHA: `168649b`** on `main` (supersedes `88e41b7`; T7 corrections applied). Any further code change → new RC + new test run reported; `88e41b7` results do NOT carry over.
- RC artifact = the git repo at `168649b`; migration files live under `database/migrations/` and are present before code activation.
- Test env: isolated local PostgreSQL via `scripts/run-local.ps1`, `Storage::fake`, `Http::fake`. No real provider call; no paid generation.
- Command: `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-local.ps1 artisan test` → **444 passed / 3129 assertions**.

## CP4 acceptance coverage (fixture/mock unless noted; NONE are live)
| ID | Scenario | Evidence | Status |
|---|---|---|---|
| Q01 | published vs legacy; broken→error; disabled no-revive | `CapabilityResolverTest` | proven |
| Q02 | required/conditional input rejected before paid gen (backend) | `CapabilityValidatorTest`, `CoordinatorImageFlowTest`, `ImageLifecycleTest` | proven; FE feedback = F1 |
| Q03 | stale revision/price rejected before reservation+provider | `CoordinatorGuardsTest` (stale price 409; stale capability hash 409; matching price proceeds) + FE sends `expected_price_tokens` | proven |
| Q04 | asset ownership/signature/size | `AssetServiceTest` | proven |
| Q05 | provider delivery cookie-independent; invalid/expired rejected | `AssetServiceTest` | proven; **live provider-fetch unverified** |
| Q06 | retry same action → same job; new action same input → new job; same key diff input → conflict | `CoordinatorImageFlowTest` (idempotency key: retry/new/conflict; no-key→new each) | proven |
| Q07 | dispatch failure recovery without duplicate charge | `DispatchRecoveryTest` (`redispatchStalePending` re-queues only stale undispatched) | proven |
| Q08 | timeout/uncertain submit → no refund/resubmit | `ImageLifecycleTest::uncertain_submit` | proven; kinovi no pre-id lookup → bounded reconcile (documented) |
| Q09 | duplicate/out-of-order callback | kinovi = polling only; `ownsClaim`+`complete` idempotent | partial (no callback path for kinovi) |
| Q10 | provider done, output save failed → retry finalization, not new gen | `ImageLifecycleTest::finalization_failure` (asserts no 2nd createTask) | proven |
| Q11 | refresh/reopen; owner reads private asset | `ImageLifecycleTest::happy` | proven |
| Q12 | ledger/regression | full suite 444 green | proven |
| Q13 | member no provider; admin sanitized | `ResponseSeparationTest` | proven |
| Q14 | F6a schema sampling; unsupported required blocks publish | `FalSchemaCompatibilityTest` | proven (fixture from real fal schema) |
| Q15 | rollout/rollback; limited smoke | this plan | **T7, not executed** |

**Kill switch + limited activation** proven: `CoordinatorGuardsTest` (kill switch → new submissions 503, in-flight untouched; `restricted_user_id` → only the test member may submit).

**Live provider proof: NOT performed.** Pilot = text-to-image; reference upload/delivery proven only at the AssetService level → **live reference→provider unverified**. No Kinovi webhook / live reference upload added (out of pilot scope).

---

## T7 rollout proposal (awaiting authorization)

### Migrations (unchanged approved list = 3 files)
1. `2026_09_22_100001_create_media_capabilities_table`
2. `2026_09_22_100002_create_media_assets_table`
3. `2026_09_22_100003_add_capability_to_image_jobs` — **column change reported:** now also adds `payload_fingerprint` (nullable) alongside `capability_revision_id`/`routing_identity`/`price_tokens`/`dedup_key`. Still one migration file; still additive + reversible.
- Before `migrate --force`: run `php artisan migrate:status`; proceed only if exactly these three are pending. If anything else is pending, STOP and report — do not run unrelated migrations.

### Recovery (backup + app-level rollback preferred over DB restore)
- Take a fresh `pg_dump`, record path+checksum, and verify it restores into a scratch DB before migrating.
- **Preferred rollback = application-level, non-destructive:** flip the kill switch (`MEDIA_KILL_SWITCH=true`) to halt NEW submissions, and/or redeploy the previous prod commit. Because every schema change is additive + nullable, the previous code runs against the new schema unchanged — **no need to drop tables/columns, no data loss.**
- **DB restore is a last resort only.** If ever required, data written AFTER the backup (jobs, ledger, settlements) would be lost on restore; therefore restore is not an automatic rollback. If it must happen, first export rows created since the dump (jobs/token_reservations/token_transactions) and reconcile them back manually. Never auto-restore.

### Limited activation + kill switch (server-controlled)
- `MEDIA_COORDINATOR_USER_ID=<test member id>` → only that member's submissions use the new coordinator path during smoke; everyone else keeps existing verified behavior.
- `MEDIA_KILL_SWITCH=true` → backend rejects NEW coordinator submissions (503); already-accepted jobs keep being polled/finalized (in-flight never calls the gate).

### Release + workers
- Record the current prod commit (`git rev-parse HEAD`) before pulling `168649b`.
- Sequence: verify backup → `migrate:status` → `migrate --force` (3 additive) → deploy code `168649b` → restart `xsuper-media`/`xsuper-queue`. Migrations are additive (no drop/rename) so in-flight VideoJob/AudioJob/ImageJob are unaffected; restart after migrate+deploy so workers load the new coordinator/adapter classes. Prefer graceful restart; let in-flight leases finish or resume via reconcile — do not kill mid-write.

### Bounded smoke test
- **1 test member, 1 submission, 1 output.** Set `MEDIA_COORDINATOR_USER_ID` to that member.
- Before submit: verify the account's ACTUAL `gpt-image-2` configuration + rate on Kinovi (do not assume the low/1K default) and set the app model `token_cost` to match the priced tier.
- **Cost cap to approve: ≤ 2.17 Kinovi credits** for the single image.
- If submit times out / status uncertain: reconcile the SAME job (poll `recordInfo` / stale-reservation); NEVER auto-create a new paid generation.
- Verify: coordinator path (`capability_revision_id` set), member response hides provider, tokens reserve→settle once, asset served owner-gated.

### Cleanup (non-destructive)
- Do NOT hard-delete the test member. Disable access (`is_active=false`) and revoke its sessions/API tokens.
- **Keep** the member identity + audit relations, and every job row, capability revision, ledger entry, settlement, and smoke evidence. Remove only safe temporary artifacts. Never alter a real customer balance or delete transaction evidence.

### Authorization needed for T7
Explicit go-ahead for: (a) `pg_dump` + verify, (b) `migrate --force` of the 3 listed migrations, (c) deploy `168649b`, (d) worker restart, (e) one paid Kinovi image ≤ 2.17 credits scoped to the test member via `MEDIA_COORDINATOR_USER_ID`.

## Remaining limitations
- Live provider + live reference→provider: unverified (isolated proof only).
- Q09 callback path: N/A for kinovi (polling only).
- `redispatchStalePending` scheduler hookup: method + test exist; wiring into the scheduler is a one-line follow-up to run it periodically (note for T7/ops).
