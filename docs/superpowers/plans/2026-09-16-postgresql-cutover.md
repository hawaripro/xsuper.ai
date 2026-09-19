# PostgreSQL Cutover Implementation Plan

> Historical plan, superseded by the September 17 consolidation onto the installed PostgreSQL 18 service at port 2209. The temporary cluster, MySQL project schema, and migration-only command/verifier have been retired after backup and parity verification. Do not execute this plan again; current runtime, backup locations, and test isolation are documented in `README.md`.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the completed local XSuper.ai dataset and application runtime from verified XAMPP MariaDB to local PostgreSQL 18 with complete, evidence-backed parity and an intact MySQL rollback.

**Architecture:** Laravel migrations create an empty PostgreSQL schema. A guarded local migration utility copies every source row from the exact verified MySQL instance using parameterized database connections, preserves identities/migration history, resets sequences, and produces canonical cross-engine verification receipts. Connection cutover occurs only after schema/data/application QA passes; source MySQL and its fresh backup remain untouched.

**Tech Stack:** PHP 8.5, Laravel 13 Schema/DB, PDO MySQL/PostgreSQL, PostgreSQL 18.3, XAMPP MariaDB, PHPUnit, Playwright, Vite.

**Spec:** `docs/superpowers/specs/2026-09-16-postgresql-cutover-design.md`

## Global Constraints

- PostgreSQL becomes local primary only after all parity and QA checks pass.
- Source must exactly match local XAMPP MariaDB `127.0.0.1:2207`, `xsuper_db`, data directory `C:/xampp/mysql/data`.
- Destination must be a newly provisioned local PostgreSQL 18 database/cluster; refuse non-local, non-empty, or mismatched targets.
- Never expose `.env`, database passwords, encrypted provider values, saved upstream endpoints, raw provider errors, or private identifiers.
- Never run destructive tests, `migrate:fresh`, or seed fixtures against working MySQL.
- Preserve every application row, column value, PK, FK, unique constraint, migration entry, JSON meaning, and encrypted ciphertext.
- Browser destructive QA remains guarded SQLite. Add separate PostgreSQL integration QA; never weaken existing PHPUnit/E2E guards.
- Keep the complete MySQL database plus fresh logical backup frozen for rollback. Never delete either automatically.
- No commit, push, deployment, VPS mutation, or remote database change.
- At most two concurrent subagents/tasks.

---

### Task 1: PostgreSQL Query and Schema Portability

**Files:**
- Modify: `app/Http/Controllers/Api/DashboardController.php`
- Modify: database migrations that fail PostgreSQL migration lifecycle, only where proven
- Create: `database/migrations/2026_09_16_100005_widen_image_job_size.php`
- Test: `tests/Feature/PostgresPortabilityTest.php`

**Interfaces:**
- Consumes: Laravel connection driver name (`pgsql`, `mysql`, `mariadb`, `sqlite`).
- Produces: identical dashboard period bucket strings across supported drivers and `image_jobs.size VARCHAR(32)`.

- [ ] Write focused tests for pgsql bucket SQL labels (hourly/daily/weekly/monthly) and 32-character image size persistence.
- [ ] Run tests before changes and confirm they fail for PostgreSQL-specific behavior/schema length.
- [ ] Add a `pgsql` branch using `to_char(date_trunc(...))`/ISO-week formatting with the same response labels used by existing drivers.
- [ ] Add additive reversible image size widening migration (32 up, 24 down with an explicit preflight that refuses values longer than 24).
- [ ] Run every migration from empty PostgreSQL; repair only proven PostgreSQL incompatibilities without changing domain behavior.
- [ ] Run migration down/reapply for all repaired migrations on populated PostgreSQL fixtures.
- [ ] Run focused portability tests on PostgreSQL and existing SQLite tests.

### Task 2: Guarded Cross-Database Migration Utility

**Files:**
- Create: `app/Console/Commands/MigrateLocalDatabaseToPostgres.php`
- Modify: `bootstrap/app.php` only if command discovery requires explicit registration
- Create: `tests/Feature/PostgresMigrationCommandTest.php`

**Interfaces:**
- Consumes: explicit source/destination connection names and output receipt directory; exact source and destination identity allowlists.
- Produces: copied PostgreSQL rows, reset sequences, and sanitized JSON receipt; nonzero exit with no cutover on mismatch.

- [ ] Write failing tests with isolated source/destination databases for unknown source refusal, non-empty destination refusal, complete copy, IDs/nullable/JSON/binary text preservation, FK order, and sequence reset.
- [ ] Implement identity guards: exact source host/port/database/data directory; destination `pgsql`, loopback host, dedicated database name, and empty application schema.
- [ ] Build a dependency graph from destination FK metadata and import tables in deterministic order; handle self/cycles using deferred PostgreSQL constraints where declared deferrable or a transaction-scoped constraint strategy.
- [ ] Copy in bounded parameterized batches; preserve explicit identity values and migration batch values; no values in logs.
- [ ] Normalize only driver representations: booleans, JSON strings, timestamps, and binary-safe strings. Never decrypt/re-encrypt application ciphertext.
- [ ] Reset every owned PostgreSQL sequence using catalog metadata and max imported identity.
- [ ] Create a receipt containing only connection identities without credentials, table counts, digest results, constraint/sequence outcomes, timing, and artifact hashes.
- [ ] Make any mismatch roll back destination writes and leave source untouched.
- [ ] Run command tests against disposable MySQL/MariaDB and PostgreSQL targets.

### Task 3: Canonical Cross-Engine Verification

**Files:**
- Create: `app/Services/DatabaseParityVerifier.php`
- Test: `tests/Feature/DatabaseParityVerifierTest.php`

**Interfaces:**
- Consumes: source/destination connection names plus table metadata.
- Produces: `{tables, schema, rows, constraints, sequences, domain, matched}` without sensitive values.

- [ ] Write failing tests proving canonical equality across MySQL/PostgreSQL representation differences and detecting one changed/null/missing/extra row.
- [ ] Canonicalize each row by destination/source shared column name, stable PK order, explicit scalar type tags, normalized JSON key ordering, UTC timestamps, base64 binary values, and exact decimal strings.
- [ ] Stream per-table row digests so verification does not hold the entire database in memory.
- [ ] Compare table set, shared columns/types by compatible families, row counts, PK/unique/index/FK contracts, and sequence next-value safety.
- [ ] Add domain checks: wallet/user-token balances equal ledgers, no reserved/terminal conflict, deposit credit status invariants, media billing terminal state, duration order snapshots, encrypted provider cast decryptability without plaintext output, CMS publication state, chat/message ownership, API key/device ownership.
- [ ] Ensure mismatch output reports table/key/field name and digest only, never raw content.
- [ ] Run verifier tests on isolated databases.

### Task 4: PostgreSQL Integration Harness

**Files:**
- Create: `phpunit.postgres.xml`
- Create: `tests/PostgresTestCase.php`
- Create: `tests/Feature/PostgresApplicationWorkflowTest.php`
- Modify: `.github/workflows/dashboard-qa.yml` only if a safe PostgreSQL service can be added without affecting current SQLite safety job.

**Interfaces:**
- Consumes: dedicated test PostgreSQL database/role named for XSuper.ai QA.
- Produces: isolated PostgreSQL workflow proof while existing `Tests\TestCase` still refuses non-SQLite.

- [ ] Write a PostgreSQL-specific guard that requires `testing`, `pgsql`, loopback, and an allowlisted database suffix/name; refuse the working destination database.
- [ ] Run all migrations on empty PostgreSQL test DB.
- [ ] Exercise health, login, strict aggregation, templates, provider/catalog sync fixture, media token reserve/refund, video lifecycle fixture, Deposit approval, pricing/catalog bulk transactions, CMS, chat history, and landing purchase projection.
- [ ] Verify rollback/deadlock/idempotency paths with real PostgreSQL row locks and independent processes where concurrency is material.
- [ ] Keep existing PHPUnit SQLite guard and browser SQLite guard unchanged.
- [ ] Run the PostgreSQL integration suite plus full SQLite Laravel suite.

### Task 5: Final Source Backup and Baseline

**Files:**
- Create runtime evidence only: `storage/framework/testing/postgresql-cutover/<UTC timestamp>/`

**Interfaces:**
- Consumes: exact verified working MySQL after media/deposit migrations, prompt import, and requested model configuration.
- Produces: SQL backup, SHA-256, source schema/row baseline, and preflight receipt.

- [ ] Put only the local application into maintenance mode; stop local queue/scheduler writers.
- [ ] Verify MySQL identity, migration state, health, foreign keys, and requested feature rows.
- [ ] Run `mysqldump` with transactional/safe options to a timestamped private artifact.
- [ ] Hash backup and record tool/server versions without credentials.
- [ ] Capture canonical baseline through `DatabaseParityVerifier` source mode.
- [ ] Recheck source table mutation counters/digests before import; abort if the source changed during the maintenance window.

### Task 6: Provision and Import PostgreSQL 18

**Files:**
- Runtime cluster under a private local testing/runtime directory, not repository source
- Runtime receipt under `storage/framework/testing/postgresql-cutover/<timestamp>/`

**Interfaces:**
- Consumes: PostgreSQL 18.3 binaries at `C:/Program Files/PostgreSQL/18/bin`, approved source backup/baseline.
- Produces: dedicated local PostgreSQL primary candidate containing all XSuper.ai rows.

- [ ] Initialize dedicated PostgreSQL cluster/role/database on an unused loopback port using a generated private password never printed or committed.
- [ ] Apply all Laravel migrations to the empty destination.
- [ ] Run guarded migration utility exactly once.
- [ ] Run canonical verifier; require all schema/row/constraint/sequence/domain checks match.
- [ ] Compare migration entries and expected 5,000 imported template rows plus all preserved custom rows.
- [ ] Verify selected requested media model classification/publication/token configs and provider encrypted-key cast without issuing provider calls.
- [ ] Save sanitized import/parity receipt.

### Task 7: Application QA and Atomic Cutover

**Files:**
- Modify local `.env` only at runtime after proof (never print or include in diff)
- Update: `.env.example` to PostgreSQL-safe documented defaults only if project policy chooses PostgreSQL default for new installations
- Update: `README.md` with local PostgreSQL setup/rollback and isolation rules

**Interfaces:**
- Consumes: fully verified PostgreSQL candidate.
- Produces: local Laravel app/worker/scheduler using PostgreSQL and a preserved MySQL rollback path.

- [ ] Point temporary environment overrides at candidate PostgreSQL and clear config cache.
- [ ] Run focused PostgreSQL workflows, full Laravel SQLite suite, PostgreSQL integration suite, real-route Playwright suite, ESLint, Pint, Impeccable detector, and production build.
- [ ] Start local app, media worker, and scheduler against PostgreSQL; verify `/api/health`, `/login`, `/en/login`, Overview, Chat, image/video histories, Deposit, CMS, admin catalog/pricing, and landing toast in real Chromium desktop/mobile, ID/EN, light/dark.
- [ ] Confirm generated/private media files remain accessible because file storage paths are unchanged and owner scoping still works.
- [ ] Atomically update local connection settings to PostgreSQL, clear cache, restart only local processes, and rerun smoke checks.
- [ ] Leave MySQL database untouched and retain its backup; document exact rollback connection restoration and process restart.
- [ ] Save final sanitized receipt with all command exit statuses, counts, checks, timestamp, and artifact hashes.

### Task 8: Post-Cutover Verification and Cleanup

**Files:**
- Update: `README.md`
- Remove only disposable PostgreSQL QA databases, copied encrypted provider fixture rows, and temporary scripts/assets that are not final evidence.

**Interfaces:**
- Consumes: running PostgreSQL local application and final receipt.
- Produces: clean workspace with permanent migrations/tests/docs and private evidence only.

- [ ] Reboot/restart the local PostgreSQL service/process once and verify persistence plus sequence inserts after restart.
- [ ] Re-run health and one read/write/refund-safe smoke flow.
- [ ] Confirm MySQL remains a frozen rollback source and was not modified after baseline.
- [ ] Review final source changes and receipts for secret/raw data leakage.
- [ ] Delete disposable test clusters/databases only; preserve primary PostgreSQL, MySQL backup/source, and sanitized evidence.
- [ ] Run final verification commands again and record exact results. Do not commit, push, deploy, or touch VPS.
