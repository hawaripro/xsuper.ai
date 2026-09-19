# PostgreSQL Cutover Design

> Historical design, superseded by the September 17 consolidation onto installed PostgreSQL at port 2209 and owner-approved retirement of the MySQL project schema. The old temporary-cluster and rollback instructions below are retained as design history, not operating instructions. See `README.md` for the current runtime and external backup locations.

## Goal

Move the local XSuper.ai application database from the restored XAMPP MariaDB instance to PostgreSQL without losing application rows, relationships, encrypted provider data, billing state, media state, or migration history. PostgreSQL becomes the local primary only after parity and full QA pass. The source MySQL database and fresh SQL backup remain frozen as rollback evidence.

## Scope

- Complete the in-progress media, prompt-library, Deposit, catalog, pricing, and landing changes before the final copy.
- Make application migrations and queries work on PostgreSQL while retaining SQLite for isolated PHPUnit and browser QA.
- Provision a dedicated local PostgreSQL 18 cluster/database. Never use an unrelated or remote PostgreSQL target.
- Take a fresh logical MySQL backup plus table/schema baseline before any production-like migration.
- Build PostgreSQL schema from Laravel migrations, not converted MySQL DDL.
- Copy every application table and row, preserving primary keys, timestamps, nullable values, JSON meaning, encrypted ciphertext, immutable billing snapshots, references, and foreign-key relationships.
- Reset PostgreSQL sequences to the maximum imported identity for each sequence-backed table.
- Verify row counts, normalized row digests, schema columns, indexes, unique constraints, foreign keys, migration rows, and selected domain invariants.
- Run focused and full backend/browser/build QA against isolated PostgreSQL before cutover.
- Switch only the local Laravel database connection after parity. No deployment, VPS mutation, commit, push, or remote service change.

## Non-goals

- No dual-write period or live replication; this local project can use a bounded maintenance window.
- No deletion of the source MySQL database.
- No provider key rotation or credential disclosure.
- No conversion of browser-test SQLite to PostgreSQL; SQLite remains a destructive-test safety boundary.
- No automatic migration of an unknown remote database.

## Architecture

A guarded migration command/script reads only from the exact verified MySQL source (`127.0.0.1:2207`, database `xsuper_db`, XAMPP data directory) and writes only to a newly created, explicitly named local PostgreSQL database. Laravel migrations create the destination schema. Data copies in dependency order inside a destination transaction where possible; PostgreSQL constraints are deferred only when supported, otherwise tables are ordered by their foreign-key graph. The script never transforms application data beyond driver-required representation (for example normalized JSON encoding and booleans).

A separate verification pass reads both databases and emits a sanitized receipt. It compares canonical row payloads sorted by primary key rather than relying on engine-specific binary dumps. Sensitive ciphertext remains part of digests but never appears in output. Any mismatch aborts before connection cutover.

## Portability changes

- Extend driver-aware date bucket SQL to `pgsql` using `to_char`/`date_trunc` semantics that match existing API labels.
- Review raw SQL, aliases, case-insensitive search escapes, aggregate types, JSON columns, enum-backed columns, unsigned boundaries, and schema `change()` operations against PostgreSQL 18.
- Preserve all API response types by explicit integer/boolean/model casts where PostgreSQL PDO differs.
- Keep MySQL/MariaDB-only rollback/index workarounds behind existing driver guards.
- Widen `image_jobs.size` to 32 characters because current validated catalog sizes allow 32.
- Add a PostgreSQL integration configuration that cannot point at the working MySQL database and a guard equivalent to existing testing isolation.

## Data migration flow

1. Confirm current MySQL identity, health, schema, and migration state.
2. Create timestamped `storage/framework/testing/postgresql-cutover/<timestamp>/` evidence directory.
3. Produce fresh `mysqldump` backup and SHA-256 checksum.
4. Capture source table list, columns, indexes, foreign keys, row counts, primary-key ranges, and canonical row digests.
5. Provision a dedicated PostgreSQL 18 cluster/database on a non-default isolated port where practical, with a dedicated local role.
6. Run all Laravel migrations on an empty destination and import the approved 5,000 prompt library rows on the source before final copy, so source and destination represent the same finished application state.
7. Copy all rows using parameterized inserts/batches; preserve explicit IDs and migration batch values.
8. Set every destination sequence to a safe next value.
9. Validate schema, rows, FK integrity, uniqueness, JSON decoding, encrypted provider decryptability through the application cast (without exposing plaintext), ledger balances, reservations, deposits, duration orders, chat history, CMS state, and media jobs.
10. Exercise Laravel health, login, overview, Chat catalog/history, Deposit, admin catalog/pricing, CMS, and media status against PostgreSQL.
11. Run full isolated QA with PostgreSQL-specific integration tests plus existing SQLite safety suite and production build.
12. Update local connection configuration atomically, clear Laravel config cache, restart only local app/queue/scheduler processes, and rerun health/smoke checks.
13. Save sanitized receipt with source/destination identities, hashes, counts, checks, commands, timestamps, and rollback procedure. Keep MySQL stopped or read-only/frozen after cutover; never delete it automatically.

## Error handling and rollback

- Any source identity mismatch, source mutation during copy, destination non-empty state, failed migration, row mismatch, FK violation, sequence mismatch, application smoke failure, or test failure stops cutover.
- Before cutover, failure drops only the dedicated destination database/cluster and leaves MySQL primary unchanged.
- After cutover, rollback restores the prior local connection settings and restarts local services against the untouched MySQL source. PostgreSQL is retained for diagnosis.
- No partial application connection switch and no best-effort acceptance of mismatches.

## Verification acceptance

- Every source application table exists at destination with equal row count.
- Canonical per-row digests match for every copied column after documented engine normalization.
- All destination foreign keys validate; primary/unique constraints and indexes required by migrations exist.
- Sequence next values exceed imported IDs.
- Encrypted provider values decrypt through the application model and remain absent from receipts/logs.
- Generator token, API wallet, deposits, duration, QRIS, usage, reservations, refunds, chat, CMS, notification, support, API key, device, and media invariants pass.
- Full backend test suite, PostgreSQL integration scenarios, real-route browser suite, lint, formatting, and production build pass.
- Local `/api/health`, `/login`, `/en/login`, Overview, Chat, Deposit, and admin catalog/pricing return expected success from PostgreSQL after cutover.

## Operational constraints

- At most two concurrent subagents/tasks.
- Never run destructive tests or fresh migrations against the restored working MySQL source.
- Never print `.env`, connection passwords, encrypted provider values, upstream endpoints, or raw provider errors.
- No commit, push, deployment, or VPS mutation.
