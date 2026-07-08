# Forge — Single-Server Deploy Panel — Design

**Date:** 2026-07-08
**Status:** Approved
**Stack:** Laravel 13, Vue 3, Inertia v3, Fortify, Tailwind 4, Wayfinder

## Goal

A Laravel Forge-like panel that runs **on the same Ubuntu server it manages** and makes deploying Laravel/PHP apps easy: create Apache vhosts, issue Let's Encrypt SSL certs, deploy from GitHub via deploy key + webhook, edit `.env` files and deploy scripts, manage MySQL databases, queue workers, and the scheduler cron.

Functionality first: no tests for now, minimal design polish.

## Decisions (from brainstorming)

| Topic | Decision |
|---|---|
| Architecture | Panel runs on the managed server; commands execute locally (no SSH) |
| App types | Laravel/PHP apps only (Apache + PHP-FPM, docroot `/public`) |
| Git pipeline | Per-site SSH deploy key + GitHub push webhook, plus manual "Deploy now" |
| Scope extras | `.env` editor, deploy script editor, databases, queue workers + scheduler |
| PHP versions | Single server-wide PHP-FPM version for v1 |
| Deploy style | In-place `git pull` in `/home/forge/{domain}` (Forge-style, not zero-downtime) |
| Execution model | Queued jobs write status/logs to DB; UI polls (Inertia polling). No websockets. |

## 1. System context & privileges

- The panel is itself a Laravel app served by Apache on the managed server, running as a dedicated system user (`forge`). Sites live in `/home/forge/{domain}`.
- Privileged operations run through `sudo` restricted by a whitelisted sudoers file `/etc/sudoers.d/forge-panel` (NOPASSWD, exact commands only):
  - `a2ensite` / `a2dissite`
  - `apache2ctl configtest`
  - `systemctl reload apache2`
  - `systemctl start|stop|restart|status forge-worker-*` and `systemctl daemon-reload`
  - `certbot`
  - `cp` / `tee` limited to `/etc/apache2/sites-available/*.conf`, `/etc/systemd/system/forge-worker-*.service`, `/etc/cron.d/forge-*`
- Managed MySQL databases are created through a normal Laravel DB connection using a privileged MySQL user — no sudo involved.
- A one-time provisioning shell script (`docs/server-setup.sh`, run once as root) creates the `forge` user, directories, sudoers file, and the privileged MySQL user.
- **All shell execution goes through one `ShellRunner` service** (wraps Laravel `Process`), centralizing timeouts, working directory, env vars, and log capture.
- Local dev on Windows: `FORGE_FAKE_SHELL=true` makes `ShellRunner` log commands and return fake success so the whole flow is clickable locally.

## 2. Data model

### Site
- `domain`, `repository` (SSH URL), `branch`, `root_path` (`/home/forge/{domain}`), `web_root_suffix` (default `/public`)
- `status`: `pending` → `key_generated` → `installing` → `installed` | `failed`
- `deploy_script` (text; seeded with default script), `auto_deploy` (bool)
- `webhook_token` (random, in webhook URL), `deploy_key_public` (displayed for GitHub)
- `ssl_enabled` (bool), `ssl_expires_at` (nullable)
- `has_scheduler` (bool)

### Deployment
- `site_id`, `status` (`pending|running|success|failed`), `trigger` (`manual|webhook`)
- `commit_hash`, `commit_message` (parsed after pull), `output` (text, appended live)
- `started_at`, `finished_at`

### ManagedDatabase
- `name`, `username`. Password shown once at creation; not stored.

### Worker
- `site_id`, `command` (default `queue:work --tries=3`), `processes` (v1: 1), `status`
- Maps to generated systemd unit `forge-worker-{id}.service`.

## 3. Site lifecycle

1. **Create site** (domain, repo, branch) → job generates per-site SSH keypair `~/.ssh/site-{id}` and an SSH config entry with per-site host alias (`github.com-site-{id}`) so multiple repos/keys coexist. Status → `key_generated`.
2. UI shows the **public deploy key** and **webhook URL**; user adds both to the GitHub repo, then clicks **Install repository**.
3. **Install job**: `git clone` (via host alias, rewriting the repo URL host), `cp .env.example .env`, `composer install`, `php artisan key:generate`, render Apache vhost from a Blade template (HTTP :80 first), write via sudo `cp`, `a2ensite`, `apache2ctl configtest`, `systemctl reload apache2`. Status → `installed` (or `failed` with log).
4. **SSL** (separate button): runs `certbot --apache -d {domain} --non-interactive --agree-tos -m {email} --redirect`. Certbot creates/edits the SSL vhost and its own renewal timer handles renewals. Panel stores `ssl_expires_at`.

Vhost changes always: back up previous `.conf`, `configtest` before reload; on failure restore the backup and surface the error.

## 4. Deployments

`DeploySite` queued job (`WithoutOverlapping` per site):
1. Create/receive a `Deployment` row, mark `running`.
2. Execute the site's `deploy_script` as one bash script in the site directory (env: sane `PATH`, `COMPOSER_HOME`), appending combined stdout/stderr to `output` as it runs.
3. Parse `git log -1` for commit hash/message.
4. Mark `success`/`failed` with `finished_at`.

Default deploy script:

```bash
cd /home/forge/{domain}
git pull origin {branch}
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan optimize
```

**Webhook:** `POST /webhook/deploy/{site}/{token}` — excluded from auth/CSRF, validates token, checks pushed ref matches the site's branch, requires `auto_deploy`, dispatches `DeploySite` (trigger `webhook`). Optionally verifies `X-Hub-Signature-256` when a shared secret is set.

## 5. Managed databases, workers, scheduler

- **Databases:** create = `CREATE DATABASE` + `CREATE USER` + `GRANT` on the privileged MySQL connection; delete = drop both. Generated password displayed once.
- **Workers:** create renders a systemd unit from a Blade template (`ExecStart=php artisan queue:work ...`, `User=forge`, `Restart=always`), writes via sudo, `daemon-reload`, `systemctl start`. Buttons: restart, delete (stop + remove unit).
- **Scheduler:** toggling on writes `/etc/cron.d/forge-{site-id}` with `* * * * * forge php /home/forge/{domain}/artisan schedule:run`; toggling off removes it.

## 6. HTTP & UI

Routes (auth-protected except webhook):
- `GET /sites`, `POST /sites`, `GET /sites/{site}`, `DELETE /sites/{site}`
- `POST /sites/{site}/install`, `POST /sites/{site}/deploy`, `PUT /sites/{site}/deploy-script`
- `GET|PUT /sites/{site}/env`
- `POST /sites/{site}/ssl`
- `POST /sites/{site}/scheduler` (toggle)
- `GET /sites/{site}/deployments/{deployment}` (log view / polling payload)
- `POST|DELETE /sites/{site}/workers[/{worker}]`, `POST .../workers/{worker}/restart`
- `GET|POST|DELETE /databases[/{database}]`
- `POST /webhook/deploy/{site}/{token}`

Pages (`resources/js/pages`):
- `sites/Index.vue` — site list + create form
- `sites/Show.vue` — tabbed: **App** (status, deploy key + webhook URL, Deploy Now, deployment history with expandable live logs, deploy script editor), **Environment** (.env textarea), **SSL**, **Workers**, **Scheduler**
- `databases/Index.vue`

Polling: while a site is `installing` or a deployment is `pending|running`, poll every ~2s using Inertia polling.

Auth: existing Fortify login; registration disabled once one user exists (single-admin panel).

## 7. Error handling

- Every shell step checks exit codes; first failure stops the job and marks the site/deployment `failed`, preserving full log output.
- Apache: `configtest` gate + backup/restore of previous vhost so a bad config never takes down the server.
- Webhook returns 404 for bad tokens (no information leak), 200 with "ignored" for non-matching branches.

## Out of scope (v1)

Multi-server/SSH, multi-user/teams, per-site PHP versions, zero-downtime deploys, Node/static sites, server metrics/monitoring, firewall management, database backups, websocket log streaming.
