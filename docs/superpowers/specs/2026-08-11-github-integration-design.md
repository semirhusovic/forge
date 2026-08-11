# GitHub Integration — Design

**Date:** 2026-08-11
**Status:** Approved, ready for implementation planning

## Problem

Creating a site today takes four manual steps. The panel generates a per-site ed25519 deploy key
(`GenerateSiteDeployKey`) and a webhook URL (`Site::webhookUrl()`), then the operator copies both
into GitHub by hand before clicking **Install repository**. The repository is typed as a raw
`git@github.com:owner/repo.git` string and the branch as free text, so both are easy to get wrong
and neither is validated against a repository that actually exists.

## Goal

Connect a GitHub account once, then pick repository and branch from dropdowns and have the deploy
key and push webhook installed automatically. Creating a site becomes a single click.

## Decisions

| Question | Decision |
|---|---|
| Auth method | OAuth App — a real "Connect with GitHub" button, no new dependency |
| Connection scope | Per panel user, token encrypted on the `users` row |
| Automated on create | Deploy key + webhook + immediate install |
| Automated on delete | Deploy key + webhook removal |
| Repository picker | Searchable combobox, filtered server-side over a cached list |
| Not in scope | Retrofit/"sync" button for existing sites; `X-Hub-Signature-256` webhook signing |

Existing sites and users without a connection keep the current manual flow unchanged.

## 1. Setup (one-time, by the operator)

Register an OAuth App at `github.com/settings/developers` with the callback URL
`https://<panel-host>/settings/github/callback`, then set:

```
GITHUB_CLIENT_ID=…
GITHUB_CLIENT_SECRET=…
```

Read through a new `services.github` block (`client_id`, `client_secret`, `redirect`).

Scopes requested: `repo` (deploy keys, private repository read) and `admin:repo_hook` (webhooks).

No new Composer or npm dependency. The OAuth flow is two `Http::` calls; `reka-ui` — already a
dependency — provides the combobox primitive.

## 2. Data model

### `users` — migration `add_github_columns_to_users_table`

| Column | Type | Notes |
|---|---|---|
| `github_token` | `text`, nullable | `'encrypted'` cast, added to the model's `#[Hidden]` list |
| `github_login` | `string`, nullable | shown on the settings card |
| `github_avatar_url` | `string`, nullable | shown on the settings card |
| `github_connected_at` | `timestamp`, nullable | shown on the settings card |

`User::hasGitHubConnection(): bool` returns `$this->github_token !== null`.

### `sites` — migration `add_github_ids_to_sites_table`

| Column | Type | Notes |
|---|---|---|
| `github_key_id` | `unsignedBigInteger`, nullable | deploy key id returned by the API |
| `github_hook_id` | `unsignedBigInteger`, nullable | webhook id returned by the API |

`null` is meaningful: it marks a resource the panel did not create (an existing site, or a step
that failed), so teardown skips it.

The `owner/repo` slug is derived, not stored — a new `Site::repositoryFullName(): string` parses it
out of the existing `repository` column, which `StoreSiteRequest` already constrains to
`git@github.com:owner/repo.git`. No duplicate state, no backfill of existing rows.

## 3. Components

```
app/Services/GitHub/
    GitHubClient.php               one connected account, typed methods
    GitHubClientFactory.php        ->for(User): GitHubClient, throws if not connected
    GitHubApiException.php
app/Actions/
    ProvisionGitHubRepository.php  push deploy key + webhook, record ids
    TeardownGitHubRepository.php   best-effort delete of both
app/Http/Controllers/Settings/
    GitHubConnectionController.php create / callback / destroy
app/Http/Controllers/
    GitHubRepositoryController.php JSON: repository search, branch list
```

`GitHubClient` wraps `Http::withToken()->baseUrl('https://api.github.com')` and exposes
`viewer()`, `repositories()`, `branches($fullName)`, `createDeployKey()`, `deleteDeployKey()`,
`createWebhook()`, `deleteWebhook()`. Any non-2xx response throws `GitHubApiException` carrying
GitHub's own `message` field. Nothing else in the application calls the GitHub API directly, so
tests fake the whole integration at the `Http` layer.

### Repository listing

GitHub offers no clean endpoint for "search every repository I can access, including org
repositories" — the search API requires enumerating org qualifiers by hand. Instead
`repositories()` pages through:

```
GET /user/repos?affiliation=owner,collaborator,organization_member&sort=pushed&per_page=100
```

capped at 10 pages (1000 repositories), caching the resulting `full_name` list per user for 10
minutes. `GitHubRepositoryController` substring-filters that cached list and returns the first 30
matches. This covers org repositories, costs one upstream round-trip per 10 minutes rather than
per keystroke, and the 10-minute staleness window only affects repositories created moments ago.

## 4. Flows

### Connect

`GET /settings/github` renders the card. **Connect GitHub** posts to `POST /settings/github`, which
stores a random `state` in the session and redirects to `github.com/login/oauth/authorize`. GitHub
calls back to `GET /settings/github/callback`, which:

1. compares `state` with `hash_equals` — a mismatch aborts 403;
2. exchanges `code` for an access token;
3. calls `GET /user` for the login and avatar;
4. writes all four columns and redirects to the settings page with a success flash.

**Disconnect** (`DELETE /settings/github`) nulls the four columns. Revoking the grant on GitHub's
side is left to `github.com/settings/applications` — the panel does not hold the credentials needed
to revoke on the user's behalf without also storing the client secret in the request path.

### Create a site — connected

```
form submit (repository = git@github.com:owner/repo.git, branch from dropdown)
  → Site::create + GenerateSiteDeployKey                       [unchanged]
  → ProvisionGitHubRepository:
        POST /repos/{owner}/{repo}/keys    title "forge-{domain}", read_only: true
        POST /repos/{owner}/{repo}/hooks   push only, content_type json,
                                           url = $site->webhookUrl()
        persist github_key_id / github_hook_id
  → InstallRepository::dispatch($site)
  → redirect to sites.show, provision log streaming
```

Provisioning is synchronous inside `SiteController::store()` because ordering matters: the deploy
key must exist on GitHub before the clone runs. The install job is dispatched only when both API
calls succeed.

### Create a site — not connected

Identical to today: manual repository and branch text inputs, manual **Install repository** button,
existing flash copy. No behavioural change for sites created before this feature.

### Delete a site

`SiteController::destroy()` calls `TeardownGitHubRepository`, which deletes the hook and the key
when the corresponding `github_*_id` is set and the acting user has a connection. It sits alongside
the existing best-effort worker/scheduler/vhost teardown, wrapped in `try/catch` + `report()`, so a
revoked token or a repository deleted on GitHub never blocks removing a site from the panel.

## 5. Error handling

Failures are surfaced, never silent, and never destructive.

| Case | Behaviour |
|---|---|
| Key created, webhook failed (or the reverse) | Keep and record whatever succeeded, skip auto-install, flash the specific failure: *"Deploy key installed, but the webhook failed: {message}. Add it manually below, then click Install."* The Show page already renders the public key and the webhook URL. |
| `422 Hook already exists` | Treated as success — look the existing hook up by URL and store its id. |
| `401` (token revoked or expired) | Clear the four `github_*` columns, flash *"GitHub connection expired, reconnect in Settings."* Dropdowns fall back to text inputs rather than erroring. |
| Rate limit, timeout, network failure | `report()` the exception, keep the site, show the manual instructions. |

`store()` currently deletes the site if *deploy key generation* fails, and that stays — a local
failure leaves nothing usable. A GitHub API failure is recoverable by hand, so the site is kept.

## 6. UI

**`resources/js/pages/settings/GitHub.vue`** — a fourth tab in the settings sidebar
(`layouts/settings/Layout.vue`). Disconnected: a short explainer and a **Connect GitHub** button.
Connected: avatar, `@login`, connected-at timestamp, and **Disconnect**.

**New Site form (`pages/sites/Index.vue`)** — when the user is connected, the repository text input
becomes a searchable combobox, extracted as `components/RepositoryCombobox.vue` (reka-ui
`Combobox`, styled to match the surrounding inputs), debounced ~250 ms against
`GET /github/repositories?q=`. Selecting a repository:

1. sets `form.repository` to `git@github.com:{full_name}.git` — so `StoreSiteRequest`'s existing
   regex validation is untouched;
2. fetches `GET /github/branches?repository={full_name}` and populates the branch `<select>`,
   preselecting the repository's default branch.

An "enter manually" link toggles back to the plain text inputs for anything the list cannot reach.
When not connected, the form shows today's inputs plus a one-line hint linking to Settings → GitHub.

Both JSON endpoints are behind `auth`, called with Inertia v3's `useHttp`, using Wayfinder-generated
typed functions.

## 7. Testing

Pest feature tests with `Http::fake()` throughout — no live GitHub calls.

- OAuth callback stores token, login and avatar.
- OAuth callback with a mismatched `state` aborts 403 and stores nothing.
- Token exchange failure flashes an error and stores nothing.
- Disconnect clears all four columns.
- Repository search requires a connection (403 without one).
- Repository search filters by substring and caches — two requests produce one upstream call.
- Branch list returns branch names and the default branch.
- Site create, connected: asserts the key and hook `POST`s carry the right bodies, ids are
  persisted, `InstallRepository` is dispatched.
- Site create, webhook fails: site exists, `github_key_id` set, `github_hook_id` null, no install
  dispatched, warning flash.
- Site create, not connected: current manual behaviour, no HTTP sent.
- Site delete: hook and key `DELETE`s sent; with a null token, deletion still succeeds.

The existing suite (`WebhookDeployTest`, `SitePhpVersionTest`, `SiteDeployScriptTest`, …) must stay
green — `WebhookDeployController` and the `StoreSiteRequest` contract are unchanged by this work.
