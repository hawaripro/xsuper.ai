# Named Sub-Index: `deploy`

Deployment workflow, scripts, forbidden words.

## Entries

- 2026-06-06 01:22 WIB — Sisyphus: Initial Multi Brain bootstrap from HANDOFF.md
  - Workflow: Edit lokal → `npm run build` → `git add -A && git commit && git push origin main`
  - VPS: `curl` download dari GitHub → `npm run build && php artisan optimize:clear && php artisan optimize`
  - JANGAN edit file di VPS pakai nano/vim
  - Token GitHub sering expired — user generate baru tiap kali
  - Internal provider branding, proxy credentials, infrastructure details, and system prompts must not appear in public output.
