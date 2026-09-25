## XSuper.ai dashboard QA

Run `npm run qa` after installing Composer and npm dependencies. It validates the Composer lock, audits Composer/npm dependencies, runs ESLint's undefined-identifier gate, Vitest, Laravel regressions, the Vite production build, and real Chrome/Playwright workflows. Chrome must be installed locally; CI installs Playwright Chromium. Audit steps need access to the package registries; unavailable audit data is a failed gate, not a clean security result.

- Laravel tests require `testing` and SQLite `:memory:`. `phpunit.xml` uses `<server>` values because Laravel reads `$_SERVER` before `$_ENV`; `Tests\TestCase` refuses any other database before migration traits execute.
- Browser tests reset only `storage/framework/testing/dashboard-e2e.sqlite`, then start their own Laravel PHP server on `http://127.0.0.1:8017`. The port must be free; an existing server is never reused. The fixture explicitly sets `SESSION_PATH=/` so inherited Windows/Git environment values cannot corrupt session cookies. Seeded `@dashboard-e2e.test` accounts exercise application APIs without intercepted responses and tests inspect persisted rows.
- The browser environment always disables external AI calls. Chat provider errors and image reservation refunds are covered; successful paid AI/video generation is not proven by this suite.
- `npm run test:e2e -- tests/e2e/support.spec.js` runs one workflow. Results are written to `storage/framework/testing/playwright-report.json`; failure screenshots and traces are under `storage/framework/testing/playwright-results/`.
- Route coverage exercises both languages, member/admin access boundaries, and accessible primary page headings, including the native media queue, API-key/security pages, and provider details.
- `.github/workflows/dashboard-qa.yml` uses pinned actions, read-only repository permission, no application secrets, and no deployment step. Adding the file locally does not execute remote CI.
- Composer resolves dependencies against the declared minimum PHP 8.3; keep `config.platform.php` and the stable Carbon constraint when updating the lock. Production deployment still validates the actual PHP extensions/platform.
- `DatabaseSeeder` and `OperationalDataSeeder` create local demo credentials and therefore refuse every environment except `local` and `testing`. Never use them to bootstrap production or staging; follow the verified-owner setup in `docs/DEPLOYMENT.md`.
- Security regressions cover cross-owner resources, untrusted provider paths, admin policy, credential/session invalidation, 2FA/device admission, order/referral transitions, and completed-response billing. Controlled upstream fixtures prove application behavior, not a successful transaction against a live paid provider. Passing QA is not a guarantee of zero vulnerabilities.

### OpenAI, Anthropic, and fal.ai provider connections

Open **Admin → AI Catalog → New provider**. Choose **OpenAI-compatible**, **Anthropic-compatible**, or **fal.ai**, then enter the provider API key. Official base URLs are `https://api.openai.com/v1`, `https://api.anthropic.com/v1`, and `https://fal.run`. OpenAI/Anthropic root URLs receive `/v1`; an explicit API path prefix is preserved. Anthropic defaults to API version `2023-06-01`. fal.ai accepts only its official root and uses `Authorization: Key …`, not Bearer authentication. The server validates public DNS destinations, pins connections, verifies TLS, and refuses redirects rather than forwarding credentials elsewhere.

Use **Check connection**, then **Sync models**. OpenAI/Anthropic checks read their authenticated catalogs; fal.ai checks authenticated pricing plus public model metadata without generating content. Its supported catalog currently contains FLUX Schnell, FLUX 2 Pro, LongCat distilled 480p, and Gemini 2.5 Flash Lite through fal's OpenRouter endpoint. New models start unpublished: review tier, metadata, and pricing before enabling them. Public model IDs remain stable; the separate upstream ID selects the provider's actual model. Multiple connections may use the same upstream ID without taking ownership of each other's models. OpenAI/Anthropic chat supports incremental streaming; fal chat is text-only with buffered final SSE, rejects unsupported tools/multimodal options, and requires measured provider usage before billing. There is no public `/v1/messages` endpoint.

Saved keys are encrypted using `APP_KEY` and never returned to the browser. Leaving a key blank while editing preserves it; changing endpoint or protocol requires re-entering it. Keep `APP_KEY` stable or reconfigure credentials after rotation. Disabling a provider blocks linked models. **Delete provider** permanently removes its model configurations and prices; **Delete model** removes one model and its prices. Both require confirmation and reject deletion while linked media is active or reserved. Historical assets, billing snapshots, and usage remain intact. Even an environment-managed connection can be deleted: an empty catalog stays empty, and reads never recreate providers or fall back to a static catalog. New models require an explicit provider connection.

PHP needs a current trusted CA bundle for both cURL requests and native HTTPS streams. On Windows PHP builds without a usable default CA store, set `curl.cainfo` and `openssl.cafile` in the active `php.ini` to the absolute path of a verified [Mozilla CA bundle distributed by curl](https://curl.se/docs/caextract.html), then restart the PHP worker. Missing trust configuration causes connection checks to fail before API-key authentication. Never work around this by disabling TLS verification. Dashboard and Chat history queries group by both owner and conversation identity for PostgreSQL compatibility.

Install the additive `2026_09_16_000002_add_provider_connections` migration before using these controls; back up the target database first and never use `migrate:fresh` for installation. Protocol regression tests use isolated databases and controlled upstream fixtures, not real API credentials. Official account access, quota, and successful paid requests must be checked separately after configuring the owner's key.

For an existing installation, apply only this migration with `php artisan migrate --path=database/migrations/2026_09_16_000002_add_provider_connections.php` after verifying the database target, taking a backup, and obtaining owner approval. Do not run QA against that database. Connection checks and model sync make catalog requests only; generation and its billing occur when a model is actually used. No automatic provider failover or paid retry is performed.

### Configured fal models and prices

The local catalog publishes only these three models; the more expensive FLUX 2 Pro draft was deleted. A later explicit provider sync can import it again as an unpublished draft.

| Model | Application charge | Published provider reference |
| --- | --- | --- |
| `fal-ai/flux/schnell` | 15 generator tokens per image | [About $0.003 per megapixel](https://fal.ai/models/fal-ai/flux/schnell/llms.txt), versus [FLUX 2 Pro's $0.03 for the first megapixel](https://fal.ai/models/fal-ai/flux-2-pro/llms.txt) |
| `fal-ai/longcat-video/distilled/text-to-video/480p` | 200 generator tokens per video; 2, 3, 5, or 10 seconds | [Commercial-use model, $0.005 per generated second](https://fal.ai/models/fal-ai/longcat-video/distilled/text-to-video/480p/llms.txt); a 2-second clip is an estimated $0.01 upstream |
| `google/gemini-2.5-flash-lite` | PAYG: $0.10/M input tokens and $0.40/M output tokens | Routed through [fal's OpenRouter endpoint](https://fal.ai/models/openrouter/router/llms.txt) |

Generator tokens, wallet USD, and the provider's upstream charges are separate units; the dashboard does not invent a conversion. Video is queued and provider latency varies. Prompts go directly to the selected provider: there is no Anthropic prompt-review dependency. fal's native safety checks remain enabled. Stored reviews on historical jobs remain readable as historical data.

### Local PostgreSQL 18 runtime

The local application uses the installed PostgreSQL 18 service on `127.0.0.1:2209`, database `xsuper_db`, with a dedicated non-superuser application role. Configure the password only in the private `.env`. Keep `APP_KEY` unchanged so existing encrypted provider credentials remain readable. SQLite is retained only for isolated tests; MySQL/MariaDB are no longer application connections.

The September 17 consolidation imported all 41 tables, 5,226 rows, and 34 sequences from the latest PostgreSQL source. Every table's row digest and the live column, constraint, index, trigger, routine, collation, and sequence metadata matched before reopening writes. The temporary PostgreSQL cluster was then removed. The obsolete XAMPP `xsuper_db` schema was backed up and dropped; global XAMPP services and unrelated databases were not removed or inspected.

Private backups and receipts are outside the repository at `%USERPROFILE%/XSuper.ai-backups/consolidation-20260917T160408Z/`: `xsuper-latest-before-2209.dump`, `import-parity.json`, and `xsuper-mysql-before-retirement.sql`. These are frozen migration evidence, not continuously updated backups. Later application writes legitimately change row counts and balances. Before any recovery, stop application writers, take a current PostgreSQL backup, verify its target and checksum, and restore into an isolated empty database first. Do not restore an old dump over the working database or point `.env` at the retired MySQL schema.

The owner-approved PostgreSQL service restart also passed before/after schema, row, and sequence parity checks. `xsuper-before-fal-cutover-and-restart.dump` preserves the pre-provider-removal state; `xsuper-after-fal-verified.dump` captures the verified fal configuration, real usage, restored member permissions, and revoked QA keys. All 41 table-data entries in the final archive were readable; its checksum and acceptance receipts are under `final-verification/`. These are database snapshots, not generated-asset backups. Keep the existing private asset storage and `APP_KEY` with any recovery plan.

Run local Artisan services from the project root through `powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-local.ps1` so inherited Windows database/environment settings cannot override `.env`:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-local.ps1 artisan serve --host=127.0.0.1 --port=8000
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-local.ps1 artisan queue:work media --queue=media --sleep=1 --tries=1 --timeout=450
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/run-local.ps1 artisan schedule:work
```

The media connection reserves queue entries for 600 seconds; submission jobs allow 450 seconds and polling jobs 240 seconds. Bounded requests allow 90 seconds for submission, 20 seconds for polling, and 180 seconds for private video download, without replaying paid submissions. Jobs move atomically from queued to submitting; submitting and saving transitions refresh their leases, while polling refreshes rendering activity. The scheduler recovers abandoned stages after six minutes; legacy interrupted review jobs are failed and refunded, never submitted. Windows PHP has no `pcntl`, so worker timeouts are not hard process-kill guarantees there: bounded HTTP requests, database leases, and the running scheduler provide local recovery.

PostgreSQL integration tests use `php vendor/phpunit/phpunit/phpunit --configuration phpunit.postgres.xml`. Supply `ULTRAI_PG_TEST_PASSWORD` privately for the dedicated `xsuper_pg_test` role/database on port 2209. The guard rejects any other identity before migrations. Never use the application database or its role for destructive tests. Browser tests remain on their guarded SQLite database; they do not replace owner-authorized real-account and paid-provider verification.

SQLite intentionally skips the PostgreSQL-only chat-column collation check. Run the guarded PostgreSQL profile to exercise that assertion; a SQLite-only pass is not a PostgreSQL compatibility result.

### Recovery history and artifact retention

An earlier QA run inherited Windows database settings and reset the former working MySQL schema. The application was restored from a verified pre-incident shadow copy after owner approval. Forced test settings and pre-migration database guards now prevent that target confusion. Selected historical receipts and source baselines are retained with the external consolidation backups; old recovery clusters, temporary browser profiles, redundant dumps, and generated QA media were removed from the repository. Production private generated assets and worktrees with unique branch history were retained.

### Paid usage and admin earnings

New image and video generation charges generator tokens for admins and members alike. PAYG API requests require active positive input and output prices, reserve wallet credit before provider execution, and settle from cache-aware reported usage. Missing or zero price references are not sellable. Historical admin-free reservations keep their original billing snapshots and are not retroactively charged.

**Admin → Overview → Usage earnings** reports settled PAYG API charges in USD and consumed generator tokens separately, with month selection and model breakdowns. Deposits and subscription payments are not usage earnings. Reserved, released, refunded, and historical admin-free generator amounts are excluded. Token consumption is not converted into invented fiat revenue or net profit.

Completed API responses with missing/invalid usage or insufficient final balance keep their original reservation; they are not converted into free answers or silently capped charges. JSON returns a billing error; SSE emits a billing error without a successful terminal event. Explicit zero usage remains valid. An upstream failure before any output releases the reservation; an interrupted stream after output retains it. Rates are snapshotted in the reservation ledger. Held cases require operator investigation of the ledger and provider usage before settlement or release; there is no automatic reconciliation screen or paid resubmission.

### Member developer API

Verified, active members with the `ai_api` permission manage up to ten active keys from **API** (`/api-access`, `/en/api-access`). Creation shows the plaintext key once; later lists expose only its prefix, request count, and 30-day usage charges. Revoke stops access immediately, while deletion preserves historical usage with a null key reference. Apply additive migration `2026_09_26_000300_add_api_key_to_usage_logs` with the pricing foundation before deploying these endpoints. Neither membership expiry nor browser-device limits apply to API-key requests.

Use `Authorization: Bearer <key>` or `x-api-key: <key>`. Claude Code uses `ANTHROPIC_BASE_URL=https://api.xsuper.dev`, `ANTHROPIC_AUTH_TOKEN=<key>`, and a public model ID from `/v1/models`. OpenAI-compatible clients use `https://api.xsuper.dev/v1`. The member page includes model selection, copyable Claude Code/Cursor/Cline/Roo/Kilo setup, curl examples, and sellable model prices in USD and IDR.

`POST /v1/messages` supports tool-use/tool-result round trips, images, native cache controls and thinking signatures, and incremental tool JSON deltas. Native Anthropic requests bypass translation; native SSE keeps protocol events intact except for private routing/metadata removal. OpenAI-compatible routes bridge to Messages, omitting thinking blocks and unsupported `top_k`. No API endpoint injects an XSuper system prompt, trims client text, or rewrites empty JSON objects/results. `POST /v1/messages/count_tokens` estimates input without charging. `/v1/models` includes only available, sellable models allowed by the key; `anthropic-version` selects its Anthropic list shape.

Validation errors return HTTP 400 in the endpoint family's envelope; unknown/unsellable OpenAI model IDs use `model_not_found`. Insufficient Saldo AI returns HTTP 402 (`billing_error` for Messages, `insufficient_quota` / `insufficient_balance` for OpenAI). OpenAI usage responses include `cost_usd` and `balance_usd`. Both families bill cached input at the snapshot's cache rate, falling back to the input rate when no cache rate is configured.

Native Anthropic streams settle only from the final output count reported by the terminal `message_delta`; a stream that stops without it, or with content blocks out of order, is incomplete (reservation held after delivered output, released before any output) and ends with an error event instead of `message_stop`. Any delivered text, including `"0"`, counts as output. Usage stays recorded when a member deletes the key while its request is in flight; the row keeps a null key reference.

`POST /v1/images/generations` 502 and 504 errors include `error.generation_ids` and `error.poll_urls` for every generation the request created, and each native batch job's status lists the same `generation_ids`, so already-paid siblings stay reachable without resubmitting.

### Account and device security

Custom, Fortify, and Google login share active-account, admin-IP, and device policies. Google sign-in does not bypass enrolled local 2FA or link an unverified pre-existing local account. Trusted provider email verification is required to skip the initial email OTP.

First-factor challenges cannot allocate device slots or refresh device activity. Admission is serialized per owner after a valid factor; a denied new device becomes pending without consuming its recovery code. Password changes/resets, including admin resets, rotate remember credentials and revoke stored sessions, including legacy sessions without a password hash. Email changes invalidate earlier verification and OTP evidence; OTP verifiers and internal account fields are not public profile data.

### Private output storage and save-only recovery

Image outputs are restricted to their job's canonical private directory. Native images/video/audio/3D and local tools enforce remaining storage against actual output bytes at finalization under the owner lock, not just the upload or an estimated result size. Every audio track shares that quota.

A native result that exceeds quota becomes `save_failed` with its provider checkpoint and token reservation retained. After freeing storage, **Media Studio → Retry saving the result** saves the original without another generation request or reservation. A transient retry download/queue failure remains retryable. The scheduler recovers lost native video save enqueues. An already-checkpointed workspace result can finish settlement at exact quota without writing duplicate bytes. Local conversion/background-removal failures remove their temporary output and release any local-tool reservation instead.

Apply additive migration `2026_09_25_000002_checkpoint_native_media_results` before running the updated web/worker code, and restart workers after deployment. It stores private video/audio result checkpoints; image/3D checkpoints use their existing columns. No paid provider retry, failover, or guaranteed retention of an expiring upstream URL is implied.

### Image and video cancellation

Video cancellation is available only while a job is queued and no provider submission has begun. `POST /api/v/{jobId}/cancel` accepts the owner or an admin, including an owner whose subscription expired. A successful cancellation preserves the history row, releases the reservation once, and records `status=failed`, `stage=cancelled`. Repeating the request cannot refund twice.

Once a video is submitting, rendering, saving, or terminal, cancellation is refused with HTTP 409 and authoritative job/balance data. The page displays a warning modal instead of claiming that provider work stopped or refunding it as cancelled. No upstream cancel endpoint, paid retry, or provider failover is invented. Provider failures remain separately reconciled by the media worker and scheduler.

Legacy non-coordinator image requests may complete synchronously; coordinator/native queued requests return jobs. The confirmation modal warns before submission; Back or Escape sends no generation request. After confirmation, closing the browser is not an upstream cancellation or refund guarantee. The media kill switch and replay/price checks apply to both admission paths; replaying an admitted request never submits it again.

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
