# F0b — CP4 evidence + T7 rollout proposal

**This document proposes T7. It does NOT authorize prod deploy, migration, worker restart, or paid generation.**

## Release-candidate commit
`88e41b7` on `main` (chain from `357cb8f`). Test env: isolated local PostgreSQL via `scripts/run-local.ps1`, `Storage::fake`, `Http::fake`. No provider was called for real; no paid generation ran.

Command: `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-local.ps1 artisan test`
Result: **436 passed / 3109 assertions** (395 baseline + 41 new media tests).

## CP4 acceptance coverage (fixture/mock unless noted; NONE are live)
| ID | Scenario | Evidence | Status |
|---|---|---|---|
| Q01 | published vs legacy capability; broken→error; disabled no-revive | `CapabilityResolverTest` | proven (feature) |
| Q02 | required/conditional input rejected before paid gen (backend) | `CapabilityValidatorTest`, `CoordinatorImageFlowTest`, `ImageLifecycleTest::rejection` | proven (feature); **FE rejection = F1** |
| Q03 | revision/price mismatch after form opened | job stores `capability_revision_id`+`price_tokens` | **partial** — mismatch re-confirm is FE (F1); unverified |
| Q04 | asset ownership/signature/size/cross-user | `AssetServiceTest` | proven (feature) |
| Q05 | provider delivery cookie-independent; invalid/expired grant rejected | `AssetServiceTest` | proven (feature); **live provider-fetch = unverified** |
| Q06 | duplicate submission → same job | `CoordinatorImageFlowTest` | proven (feature); client-idempotency-key conflict N/A (key derived from payload) |
| Q07 | transaction/dispatch failure recovery | `DB::transaction` + `afterCommit` dispatch; `reconcileStaleReservations` | **partial** — no crash-injection test; relies on existing reconcile |
| Q08 | timeout/uncertain submit → no refund/resubmit; no request id | `ImageLifecycleTest::uncertain_submit` | proven (feature); kinovi has no pre-id lookup → bounded stale-reservation reconcile (documented) |
| Q09 | duplicate/out-of-order callback | kinovi = polling only (no callback); `ownsClaim`+`complete` idempotent | **partial** — no callback path for kinovi |
| Q10 | provider done, output save failed → retry finalization, not new gen | `ImageLifecycleTest::finalization_failure` (asserts no 2nd createTask) | proven (feature) |
| Q11 | refresh/reopen; owner reads private asset | `ImageLifecycleTest::happy` (`GET /api/images/{job}` + asset serve) | proven (feature) |
| Q12 | ledger/regression (token & API paths unchanged) | full suite 436 green | proven (regression) |
| Q13 | member no provider/secret; admin sanitized | `ResponseSeparationTest` | proven (feature) |
| Q14 | F6a schema sampling; unsupported required blocks publish | `FalSchemaCompatibilityTest` | proven (fixture from real fal flux schema — not live import) |
| Q15 | rollout/rollback; limited smoke | — | **T7, not executed** |

**Live provider proof: NOT performed** (isolated option chosen). Pilot is **text-to-image**; reference upload/delivery proven at the AssetService level only — **live reference→provider is unverified**.

---

## T7 rollout proposal (awaiting authorization)

### Release commit & migrations
- Deploy exactly `88e41b7` (verify `git rev-parse HEAD` on prod matches before/after).
- Migrations introduced by F0b, to run in this order (all **additive + reversible**):
  1. `2026_09_22_100001_create_media_capabilities_table` (new table)
  2. `2026_09_22_100002_create_media_assets_table` (new table)
  3. `2026_09_22_100003_add_capability_to_image_jobs` (nullable columns + index on `image_jobs`)
- **Do NOT run any other pending migration** that is not in this list. Before `migrate --force`, run `php artisan migrate:status` and confirm only these three are pending; if others are pending, STOP and report.

### Backup & recovery (must be verified before migrate)
- Take a fresh PostgreSQL dump (`pg_dump`) of the app DB and confirm it restores to a scratch DB. Record the dump path + checksum.
- Recovery = restore the dump; do NOT rely on `migrate:rollback` for data recovery.

### Old-code / new-schema compatibility
- All new columns are nullable and all new tables are additive → the currently-deployed code keeps working against the new schema (it never reads them). Safe to migrate before deploying code.
- After deploy, `9282b79`/`88e41b7` code reads the new columns; historical `image_jobs` rows have `capability_revision_id = null` → handled (legacy path).

### Active workers / in-flight jobs
- `xsuper-media` / `xsuper-queue` hold in-flight VideoJob/AudioJob/ImageJob. Migrations are additive (no column drops/renames) → running jobs are unaffected.
- Restart workers AFTER migrate + code deploy so they load the new coordinator/adapter classes. Let running jobs finish or rely on the existing lease/reconcile; do not kill mid-write.

### Disable-without-delete (kill switch)
- The new capability-driven path only affects **kinovi image** (coordinator branch in `ImageGenerationService::generate`). To disable: set the kinovi image models `is_enabled = false` (members stop seeing them) — this removes the new flow WITHOUT deleting any `media_capabilities`, `media_assets`, jobs, or ledger rows.
- **Never** roll back by dropping `media_capabilities`/`media_assets`/columns once a job references them (restrictOnDelete would block it anyway).

### Limited smoke test (single, bounded)
- Scope: **1 throwaway test member, 1 generation request, 1 output**.
- Model `kinovi-ai/gpt-image-2`, operation text-to-image, size `1024x1024` → Kinovi inputs `aspectRatio 1:1`, `resolution 1k`, quality default `low`.
- **Cost to approve:** Kinovi `gpt-image-2` low·1K = **2.17 Kinovi credits** per image (published rate ≈ $0.01, but the **credit cost is the cap to approve**: max **1 image = 2.17 credits**). No batch, no retries-as-new-gen.
- If submit times out / status uncertain: reconcile the SAME job (poll `recordInfo` / stale-reservation), never auto-create a new paid generation.
- Verify: job completes via the coordinator path, `capability_revision_id` set, member response has no provider, tokens reserve→settle once, asset served owner-gated.

### Cleanup policy (post-smoke)
- Remove ONLY the throwaway test member + its temp assets. **Keep** every job row, capability revision, ledger entry, settlement, and audit record. Do NOT adjust any real customer balance or delete transaction evidence.

### Authorization needed to run T7
Explicit go-ahead for: (a) `migrate --force` of the 3 listed migrations, (b) code deploy of `88e41b7`, (c) worker restart, (d) one paid Kinovi image ≤ 2.17 credits.
