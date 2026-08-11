# GitHub Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Connect a GitHub account through OAuth so the New Site form offers repository and branch dropdowns, and the deploy key plus push webhook are installed on GitHub automatically at site creation and removed at site deletion.

**Architecture:** A single `GitHubClient` service wraps every GitHub REST call for one connected user; nothing else in the app talks to GitHub, so tests fake the whole integration with `Http::fake()`. OAuth tokens live encrypted on the `users` row. Two thin actions (`ProvisionGitHubRepository`, `TeardownGitHubRepository`) are called from `SiteController::store()` and `destroy()`. The repository picker filters a per-user cached repository list server-side.

**Tech Stack:** Laravel 13, PHP 8.4, Inertia v3 + Vue 3, Tailwind v4, Pest 4, Wayfinder.

**Spec:** `docs/superpowers/specs/2026-08-11-github-integration-design.md`

**One deviation from the spec, deliberate:** the spec named reka-ui's `Combobox` primitive for the repository picker. `resources/js/pages/sites/Index.vue` uses hand-styled native `<input>`/`<select>` elements rather than the `components/ui/*` wrappers, and there is no `components/ui/combobox` in the project. Task 12 therefore hand-rolls a ~120-line `RepositoryCombobox.vue` (native input + absolutely positioned result list + arrow-key navigation, closed via `onClickOutside` from `@vueuse/core`, already a dependency) so it matches the surrounding file. No new dependency either way.

**Conventions that apply to every task:**
- Run `vendor/bin/pint --dirty --format agent` after touching PHP, before committing.
- `tests/Pest.php` does **not** apply `RefreshDatabase` globally — every new test file needs `uses(RefreshDatabase::class);`.
- Tests create sites with `Site::create([...])` (see `tests/Feature/WebhookDeployTest.php`); there is no `SiteFactory` and this plan does not add one.
- `config(['forge.fake_shell' => true])` is required in any test that creates a site, so `GenerateSiteDeployKey` does not shell out.
- **Wayfinder output is gitignored.** `.gitignore` lists `/resources/js/actions`, `/resources/js/routes` and `/resources/js/wayfinder`, and no generated file has ever been tracked. Run `php artisan wayfinder:generate` after adding routes so the TypeScript helpers exist locally for the build, but do **not** `git add` them. (Corrected during execution — earlier task steps below said to commit them; ignore that.)

---

### Task 1: Config, environment, and the user's GitHub columns

**Files:**
- Modify: `config/services.php`
- Modify: `.env.example`
- Create: `database/migrations/2026_08_11_120000_add_github_columns_to_users_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/GitHubConnectionStateTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/GitHubConnectionStateTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('a user without a token has no github connection', function () {
    expect(User::factory()->create()->hasGitHubConnection())->toBeFalse();
});

test('a user with a token has a github connection', function () {
    $user = User::factory()->create();
    $user->github_token = 'gho_secret';
    $user->save();

    expect($user->fresh()->hasGitHubConnection())->toBeTrue();
});

test('the github token is encrypted at rest and hidden from serialization', function () {
    $user = User::factory()->create();
    $user->github_token = 'gho_secret';
    $user->save();

    $stored = DB::table('users')->where('id', $user->id)->value('github_token');

    expect($stored)->not->toBe('gho_secret')
        ->and($user->fresh()->github_token)->toBe('gho_secret')
        ->and($user->fresh()->toArray())->not->toHaveKey('github_token');
});

test('clearing the connection nulls every column and forgets the cached repositories', function () {
    $user = User::factory()->create();
    $user->github_token = 'gho_secret';
    $user->github_login = 'octocat';
    $user->github_avatar_url = 'https://example.com/a.png';
    $user->github_connected_at = now();
    $user->save();

    Cache::put($user->githubRepositoryCacheKey(), ['cached'], 600);

    $user->clearGitHubConnection();

    $user->refresh();

    expect($user->github_token)->toBeNull()
        ->and($user->github_login)->toBeNull()
        ->and($user->github_avatar_url)->toBeNull()
        ->and($user->github_connected_at)->toBeNull()
        ->and(Cache::has($user->githubRepositoryCacheKey()))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=GitHubConnectionStateTest`
Expected: FAIL — `Call to undefined method App\Models\User::hasGitHubConnection()`

- [ ] **Step 3: Add the migration**

Create `database/migrations/2026_08_11_120000_add_github_columns_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('github_token')->nullable();
            $table->string('github_login')->nullable();
            $table->string('github_avatar_url')->nullable();
            $table->timestamp('github_connected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['github_token', 'github_login', 'github_avatar_url', 'github_connected_at']);
        });
    }
};
```

- [ ] **Step 4: Extend the User model**

In `app/Models/User.php`, add these four lines to the class-level `@property` docblock, after `$two_factor_confirmed_at`:

```php
 * @property string|null $github_token
 * @property string|null $github_login
 * @property string|null $github_avatar_url
 * @property Carbon|null $github_connected_at
```

Change the `#[Hidden]` attribute to include the token:

```php
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'github_token'])]
```

Add two cast entries inside `casts()`:

```php
            'github_token' => 'encrypted',
            'github_connected_at' => 'datetime',
```

Add these methods to the class body, and `use Illuminate\Support\Facades\Cache;` to the imports:

```php
    public function hasGitHubConnection(): bool
    {
        return $this->github_token !== null;
    }

    /** Cached repository list for the picker — see GitHubClient::repositories(). */
    public function githubRepositoryCacheKey(): string
    {
        return "github:repositories:{$this->id}";
    }

    /**
     * Drop the stored credentials. Called on disconnect and whenever GitHub
     * answers 401, which means the token was revoked on their side.
     */
    public function clearGitHubConnection(): void
    {
        Cache::forget($this->githubRepositoryCacheKey());

        $this->forceFill([
            'github_token' => null,
            'github_login' => null,
            'github_avatar_url' => null,
            'github_connected_at' => null,
        ])->save();
    }
```

- [ ] **Step 5: Add the config block**

In `config/services.php`, add before the closing `];`:

```php
    /*
    |--------------------------------------------------------------------------
    | GitHub OAuth App
    |--------------------------------------------------------------------------
    |
    | Credentials of the OAuth App used to connect a GitHub account to the
    | panel. Register one at github.com/settings/developers with the callback
    | URL <panel-url>/settings/github/callback. The redirect URI itself is
    | derived from the github.callback route, so it always matches the host
    | the panel is served from.
    |
    */

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
    ],
```

Append to `.env.example`:

```
# GitHub OAuth App — callback URL: <APP_URL>/settings/github/callback
GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact --filter=GitHubConnectionStateTest`
Expected: PASS (4 tests)

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/services.php .env.example database/migrations app/Models/User.php tests/Feature/GitHubConnectionStateTest.php
git commit -m "feat: store an encrypted GitHub OAuth token on the user"
```

---

### Task 2: GitHubApiException and the read side of GitHubClient

**Files:**
- Create: `app/Services/GitHub/GitHubApiException.php`
- Create: `app/Services/GitHub/GitHubClient.php`
- Test: `tests/Feature/GitHubClientTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/GitHubClientTest.php`:

```php
<?php

use App\Models\User;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function connectedUser(): User
{
    $user = User::factory()->create();
    $user->github_token = 'gho_secret';
    $user->github_login = 'octocat';
    $user->github_connected_at = now();
    $user->save();

    return $user;
}

function repositoryPage(int $count, string $prefix): array
{
    return collect(range(1, $count))
        ->map(fn (int $index): array => [
            'full_name' => "acme/{$prefix}-{$index}",
            'private' => false,
            'default_branch' => 'main',
        ])
        ->all();
}

test('viewer returns the authenticated github account', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['login' => 'octocat', 'avatar_url' => 'https://example.com/a.png']),
    ]);

    expect((new GitHubClient(connectedUser()))->viewer())
        ->toBe(['login' => 'octocat', 'avatar_url' => 'https://example.com/a.png']);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer gho_secret'));
});

test('repositories pages through the api until a short page arrives', function () {
    Http::fakeSequence('api.github.com/user/repos*')
        ->push(repositoryPage(100, 'first'))
        ->push(repositoryPage(3, 'second'));

    $repositories = (new GitHubClient(connectedUser()))->repositories();

    expect($repositories)->toHaveCount(103)
        ->and($repositories[0])->toBe(['full_name' => 'acme/first-1', 'private' => false, 'default_branch' => 'main']);

    Http::assertSentCount(2);
});

test('repositories are cached per user', function () {
    Http::fake(['api.github.com/user/repos*' => Http::response(repositoryPage(2, 'repo'))]);

    $client = new GitHubClient(connectedUser());
    $client->repositories();
    $client->repositories();

    Http::assertSentCount(1);
});

test('branches returns every branch name', function () {
    Http::fake([
        'api.github.com/repos/acme/app/branches*' => Http::response([
            ['name' => 'main'],
            ['name' => 'develop'],
        ]),
    ]);

    expect((new GitHubClient(connectedUser()))->branches('acme/app'))->toBe(['main', 'develop']);
});

test('default branch comes from the repository record', function () {
    Http::fake(['api.github.com/repos/acme/app' => Http::response(['default_branch' => 'trunk'])]);

    expect((new GitHubClient(connectedUser()))->defaultBranch('acme/app'))->toBe('trunk');
});

test('a failed request throws with github\'s own message', function () {
    Http::fake(['api.github.com/repos/acme/app' => Http::response(['message' => 'Not Found'], 404)]);

    expect(fn () => (new GitHubClient(connectedUser()))->defaultBranch('acme/app'))
        ->toThrow(GitHubApiException::class, 'Not Found');
});

test('a 401 clears the stored connection before throwing', function () {
    Http::fake(['api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401)]);

    $user = connectedUser();

    expect(fn () => (new GitHubClient($user))->viewer())->toThrow(GitHubApiException::class);

    expect($user->fresh()->hasGitHubConnection())->toBeFalse();
});

test('a network failure surfaces as a GitHubApiException', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

    expect(fn () => (new GitHubClient(connectedUser()))->viewer())
        ->toThrow(GitHubApiException::class, 'Could not reach GitHub');
});
```

Add `use Illuminate\Http\Client\ConnectionException;` to that file's imports.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=GitHubClientTest`
Expected: FAIL — `Class "App\Services\GitHub\GitHubClient" not found`

- [ ] **Step 3: Write the exception**

Create `app/Services/GitHub/GitHubApiException.php`:

```php
<?php

namespace App\Services\GitHub;

use Illuminate\Http\Client\Response;
use RuntimeException;

class GitHubApiException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $message = $response->json('message');

        return new self($response->status(), is_string($message) ? $message : 'The GitHub request failed.');
    }
}
```

- [ ] **Step 4: Write the client's read side**

Create `app/Services/GitHub/GitHubClient.php`:

```php
<?php

namespace App\Services\GitHub;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Every GitHub REST call the panel makes, for one connected user. Keeping the
 * API surface in a single class is what lets the whole integration be faked at
 * the Http layer in tests.
 */
class GitHubClient
{
    private const API = 'https://api.github.com';

    private const REPOSITORY_CACHE_TTL = 600;

    private const MAX_REPOSITORY_PAGES = 10;

    private const MAX_BRANCH_PAGES = 3;

    private const PER_PAGE = 100;

    public function __construct(private User $user) {}

    /** @return array{login: string, avatar_url: string} */
    public function viewer(): array
    {
        $body = $this->get('/user');

        return [
            'login' => (string) ($body['login'] ?? ''),
            'avatar_url' => (string) ($body['avatar_url'] ?? ''),
        ];
    }

    /**
     * Every repository the account can reach, most recently pushed first.
     *
     * GitHub has no endpoint for "search all repositories I can access,
     * including org repositories" — the search API needs each org spelled out
     * as a qualifier. Paging the full list and caching it costs one round trip
     * per ten minutes instead of one per keystroke, and covers org repos.
     *
     * @return array<int, array{full_name: string, private: bool, default_branch: string}>
     */
    public function repositories(): array
    {
        return Cache::remember($this->user->githubRepositoryCacheKey(), self::REPOSITORY_CACHE_TTL, function (): array {
            $repositories = [];

            for ($page = 1; $page <= self::MAX_REPOSITORY_PAGES; $page++) {
                $body = $this->get('/user/repos', [
                    'affiliation' => 'owner,collaborator,organization_member',
                    'sort' => 'pushed',
                    'per_page' => self::PER_PAGE,
                    'page' => $page,
                ]);

                foreach ($body as $repository) {
                    $repositories[] = [
                        'full_name' => (string) $repository['full_name'],
                        'private' => (bool) ($repository['private'] ?? false),
                        'default_branch' => (string) ($repository['default_branch'] ?? 'main'),
                    ];
                }

                if (count($body) < self::PER_PAGE) {
                    break;
                }
            }

            return $repositories;
        });
    }

    /** @return array<int, string> */
    public function branches(string $fullName): array
    {
        $branches = [];

        for ($page = 1; $page <= self::MAX_BRANCH_PAGES; $page++) {
            $body = $this->get("/repos/{$fullName}/branches", ['per_page' => self::PER_PAGE, 'page' => $page]);

            foreach ($body as $branch) {
                $branches[] = (string) $branch['name'];
            }

            if (count($body) < self::PER_PAGE) {
                break;
            }
        }

        return $branches;
    }

    public function defaultBranch(string $fullName): string
    {
        return (string) ($this->get("/repos/{$fullName}")['default_branch'] ?? 'main');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->send(fn (): Response => $this->request()->get(self::API.$path, $query));
    }

    /**
     * Single funnel for every call, so callers only ever have to catch
     * GitHubApiException — a timeout or DNS failure would otherwise escape as
     * a ConnectionException and turn a recoverable step into a 500.
     *
     * @param  callable(): Response  $send
     * @return array<mixed>
     */
    private function send(callable $send): array
    {
        try {
            return $this->handle($send());
        } catch (ConnectionException $exception) {
            throw new GitHubApiException(0, "Could not reach GitHub: {$exception->getMessage()}");
        }
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->user->github_token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->timeout(15)
            // Retry transport failures only — retrying a 404 or a 422 just
            // burns rate limit and delays the error the caller needs to see.
            ->retry(2, 200, fn (\Throwable $exception): bool => $exception instanceof ConnectionException, throw: false);
    }

    /**
     * A 401 means the token was revoked or expired on GitHub's side, so the
     * stored credentials are dead — drop them and let callers fall back to the
     * manual flow instead of failing on every subsequent request.
     *
     * @return array<mixed>
     */
    private function handle(Response $response): array
    {
        if ($response->status() === 401) {
            $this->user->clearGitHubConnection();
        }

        if ($response->failed()) {
            throw GitHubApiException::fromResponse($response);
        }

        return $response->json() ?? [];
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=GitHubClientTest`
Expected: PASS (7 tests)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/GitHub tests/Feature/GitHubClientTest.php
git commit -m "feat: add GitHubClient with repository and branch reads"
```

---

### Task 3: Deploy key and webhook writes

**Files:**
- Modify: `app/Services/GitHub/GitHubClient.php`
- Test: `tests/Feature/GitHubClientWritesTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/GitHubClientWritesTest.php`:

```php
<?php

use App\Models\User;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->github_token = 'gho_secret';
    $this->user->save();

    $this->client = new GitHubClient($this->user);
});

test('a deploy key is created read only', function () {
    Http::fake(['api.github.com/repos/acme/app/keys' => Http::response(['id' => 55], 201)]);

    expect($this->client->createDeployKey('acme/app', 'forge-app.example.com', 'ssh-ed25519 AAAA'))->toBe(55);

    Http::assertSent(fn ($request) => $request['title'] === 'forge-app.example.com'
        && $request['key'] === 'ssh-ed25519 AAAA'
        && $request['read_only'] === true);
});

test('a webhook is created for push events only', function () {
    Http::fake(['api.github.com/repos/acme/app/hooks' => Http::response(['id' => 77], 201)]);

    expect($this->client->createWebhook('acme/app', 'https://panel.test/webhook/deploy/1/tok'))->toBe(77);

    Http::assertSent(fn ($request) => $request['events'] === ['push']
        && $request['config']['url'] === 'https://panel.test/webhook/deploy/1/tok'
        && $request['config']['content_type'] === 'json');
});

test('an existing webhook with the same url is reused instead of failing', function () {
    Http::fake([
        'api.github.com/repos/acme/app/hooks?*' => Http::response([
            ['id' => 12, 'config' => ['url' => 'https://panel.test/other']],
            ['id' => 99, 'config' => ['url' => 'https://panel.test/webhook/deploy/1/tok']],
        ]),
        'api.github.com/repos/acme/app/hooks' => Http::response(['message' => 'Hook already exists on this repository'], 422),
    ]);

    expect($this->client->createWebhook('acme/app', 'https://panel.test/webhook/deploy/1/tok'))->toBe(99);
});

test('a 422 with no matching hook still throws', function () {
    Http::fake([
        'api.github.com/repos/acme/app/hooks?*' => Http::response([]),
        'api.github.com/repos/acme/app/hooks' => Http::response(['message' => 'Validation failed'], 422),
    ]);

    expect(fn () => $this->client->createWebhook('acme/app', 'https://panel.test/webhook/deploy/1/tok'))
        ->toThrow(GitHubApiException::class, 'Validation failed');
});

test('deleting a key and a hook hits the right endpoints', function () {
    Http::fake([
        'api.github.com/repos/acme/app/keys/55' => Http::response(null, 204),
        'api.github.com/repos/acme/app/hooks/77' => Http::response(null, 204),
    ]);

    $this->client->deleteDeployKey('acme/app', 55);
    $this->client->deleteWebhook('acme/app', 77);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/repos/acme/app/keys/55');
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/repos/acme/app/hooks/77');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=GitHubClientWritesTest`
Expected: FAIL — `Call to undefined method App\Services\GitHub\GitHubClient::createDeployKey()`

- [ ] **Step 3: Add the write methods**

In `app/Services/GitHub/GitHubClient.php`, add these public methods after `defaultBranch()`:

```php
    /** Read-only by design: the key clones the repository, it never pushes. */
    public function createDeployKey(string $fullName, string $title, string $publicKey): int
    {
        return (int) $this->post("/repos/{$fullName}/keys", [
            'title' => $title,
            'key' => $publicKey,
            'read_only' => true,
        ])['id'];
    }

    public function deleteDeployKey(string $fullName, int $id): void
    {
        $this->delete("/repos/{$fullName}/keys/{$id}");
    }

    /**
     * GitHub answers 422 when a hook with the same URL already exists, which
     * happens whenever a site is recreated against the same repository. The
     * existing hook is the one we would have made, so adopt it.
     */
    public function createWebhook(string $fullName, string $url): int
    {
        try {
            return (int) $this->post("/repos/{$fullName}/hooks", [
                'name' => 'web',
                'active' => true,
                'events' => ['push'],
                'config' => ['url' => $url, 'content_type' => 'json', 'insecure_ssl' => '0'],
            ])['id'];
        } catch (GitHubApiException $exception) {
            $existing = $exception->status === 422 ? $this->findWebhookByUrl($fullName, $url) : null;

            if ($existing === null) {
                throw $exception;
            }

            return $existing;
        }
    }

    public function findWebhookByUrl(string $fullName, string $url): ?int
    {
        foreach ($this->get("/repos/{$fullName}/hooks", ['per_page' => self::PER_PAGE]) as $hook) {
            if (($hook['config']['url'] ?? null) === $url) {
                return (int) $hook['id'];
            }
        }

        return null;
    }

    public function deleteWebhook(string $fullName, int $id): void
    {
        $this->delete("/repos/{$fullName}/hooks/{$id}");
    }
```

And these private helpers next to `get()`:

```php
    /**
     * @param  array<string, mixed>  $payload
     * @return array<mixed>
     */
    private function post(string $path, array $payload): array
    {
        return $this->send(fn (): Response => $this->request()->post(self::API.$path, $payload));
    }

    private function delete(string $path): void
    {
        $this->send(fn (): Response => $this->request()->delete(self::API.$path));
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=GitHubClientWritesTest`
Expected: PASS (5 tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/GitHub/GitHubClient.php tests/Feature/GitHubClientWritesTest.php
git commit -m "feat: create and delete deploy keys and webhooks via the GitHub API"
```

---

### Task 4: GitHubClientFactory

**Files:**
- Create: `app/Services/GitHub/GitHubClientFactory.php`
- Test: `tests/Feature/GitHubClientFactoryTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/GitHubClientFactoryTest.php`:

```php
<?php

use App\Models\User;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the factory builds a client for a connected user', function () {
    $user = User::factory()->create();
    $user->github_token = 'gho_secret';
    $user->save();

    expect(app(GitHubClientFactory::class)->for($user))->toBeInstanceOf(GitHubClient::class);
});

test('the factory refuses to build a client without a connection', function () {
    expect(fn () => app(GitHubClientFactory::class)->for(User::factory()->create()))
        ->toThrow(RuntimeException::class, 'not connected to GitHub');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=GitHubClientFactoryTest`
Expected: FAIL — `Class "App\Services\GitHub\GitHubClientFactory" not found`

- [ ] **Step 3: Write the factory**

Create `app/Services/GitHub/GitHubClientFactory.php`:

```php
<?php

namespace App\Services\GitHub;

use App\Models\User;
use RuntimeException;

/**
 * Builds a client bound to one user's token. Injected wherever a GitHub call
 * is needed so tests can bind a stub in the container.
 */
class GitHubClientFactory
{
    public function for(User $user): GitHubClient
    {
        if (! $user->hasGitHubConnection()) {
            throw new RuntimeException("User {$user->id} is not connected to GitHub.");
        }

        return new GitHubClient($user);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=GitHubClientFactoryTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/GitHub/GitHubClientFactory.php tests/Feature/GitHubClientFactoryTest.php
git commit -m "feat: add GitHubClientFactory"
```

---

### Task 5: OAuth connect, callback, and disconnect

**Files:**
- Create: `app/Http/Controllers/Settings/GitHubConnectionController.php`
- Modify: `routes/settings.php`
- Test: `tests/Feature/Settings/GitHubConnectionTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Settings/GitHubConnectionTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.github.client_id' => 'client-id',
        'services.github.client_secret' => 'client-secret',
    ]);

    $this->user = User::factory()->create();
});

test('the settings page renders', function () {
    $this->actingAs($this->user)
        ->get(route('github.edit'))
        ->assertOk();
});

test('connecting redirects to github with the requested scopes', function () {
    $response = $this->actingAs($this->user)->post(route('github.connect'));

    $response->assertRedirectContains('https://github.com/login/oauth/authorize')
        ->assertRedirectContains('client_id=client-id')
        ->assertRedirectContains('scope=repo%2Cadmin%3Arepo_hook');

    expect(session('github_oauth_state'))->toBeString();
});

test('connecting without credentials configured explains what is missing', function () {
    config(['services.github.client_id' => null]);

    $this->actingAs($this->user)
        ->post(route('github.connect'))
        ->assertSessionHas('error');
});

test('the callback stores the token, login and avatar', function () {
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_token']),
        'api.github.com/user' => Http::response(['login' => 'octocat', 'avatar_url' => 'https://example.com/a.png']),
    ]);

    $this->actingAs($this->user)
        ->withSession(['github_oauth_state' => 'state-value'])
        ->get(route('github.callback', ['code' => 'the-code', 'state' => 'state-value']))
        ->assertRedirect(route('github.edit'))
        ->assertSessionHas('success');

    $user = $this->user->fresh();

    expect($user->github_token)->toBe('gho_token')
        ->and($user->github_login)->toBe('octocat')
        ->and($user->github_avatar_url)->toBe('https://example.com/a.png')
        ->and($user->github_connected_at)->not->toBeNull();
});

test('a mismatched state is rejected', function () {
    Http::fake();

    $this->actingAs($this->user)
        ->withSession(['github_oauth_state' => 'state-value'])
        ->get(route('github.callback', ['code' => 'the-code', 'state' => 'forged']))
        ->assertForbidden();

    expect($this->user->fresh()->hasGitHubConnection())->toBeFalse();
    Http::assertNothingSent();
});

test('a failed token exchange stores nothing', function () {
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['error_description' => 'The code is expired.']),
    ]);

    $this->actingAs($this->user)
        ->withSession(['github_oauth_state' => 'state-value'])
        ->get(route('github.callback', ['code' => 'the-code', 'state' => 'state-value']))
        ->assertRedirect(route('github.edit'))
        ->assertSessionHas('error');

    expect($this->user->fresh()->hasGitHubConnection())->toBeFalse();
});

test('disconnecting clears the connection', function () {
    $this->user->github_token = 'gho_token';
    $this->user->github_login = 'octocat';
    $this->user->save();

    $this->actingAs($this->user)
        ->delete(route('github.disconnect'))
        ->assertRedirect(route('github.edit'));

    expect($this->user->fresh()->hasGitHubConnection())->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=GitHubConnectionTest`
Expected: FAIL — `Route [github.edit] not defined.`

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/Settings/GitHubConnectionController.php`:

```php
<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubClientFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GitHubConnectionController extends Controller
{
    /** `repo` covers deploy keys and private clones, `admin:repo_hook` covers webhooks. */
    private const SCOPES = 'repo,admin:repo_hook';

    private const AUTHORIZE_URL = 'https://github.com/login/oauth/authorize';

    private const TOKEN_URL = 'https://github.com/login/oauth/access_token';

    public function __construct(private GitHubClientFactory $clients) {}

    public function edit(): Response
    {
        return Inertia::render('settings/GitHub', [
            'configured' => filled(config('services.github.client_id'))
                && filled(config('services.github.client_secret')),
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        if (blank(config('services.github.client_id')) || blank(config('services.github.client_secret'))) {
            return back()->with('error', 'Set GITHUB_CLIENT_ID and GITHUB_CLIENT_SECRET in the panel .env first.');
        }

        $state = Str::random(40);
        $request->session()->put('github_oauth_state', $state);

        return redirect()->away(self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('services.github.client_id'),
            'redirect_uri' => route('github.callback'),
            'scope' => self::SCOPES,
            'state' => $state,
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('github_oauth_state');
        $received = $request->query('state');

        abort_unless(
            is_string($expected) && is_string($received) && hash_equals($expected, $received),
            403,
            'Invalid OAuth state.',
        );

        $response = Http::asForm()->acceptJson()->post(self::TOKEN_URL, [
            'client_id' => config('services.github.client_id'),
            'client_secret' => config('services.github.client_secret'),
            'code' => $request->query('code'),
            'redirect_uri' => route('github.callback'),
        ]);

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            $reason = $response->json('error_description') ?? 'GitHub returned no access token.';

            return to_route('github.edit')->with('error', "GitHub authorization failed: {$reason}");
        }

        $user = $request->user();
        $user->github_token = $token;

        try {
            $viewer = $this->clients->for($user)->viewer();
        } catch (GitHubApiException $exception) {
            report($exception);

            return to_route('github.edit')->with('error', "GitHub authorization failed: {$exception->getMessage()}");
        }

        $user->github_login = $viewer['login'];
        $user->github_avatar_url = $viewer['avatar_url'];
        $user->github_connected_at = now();
        $user->save();

        return to_route('github.edit')->with('success', "Connected to GitHub as {$viewer['login']}.");
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->clearGitHubConnection();

        return to_route('github.edit')->with('success', 'GitHub disconnected. Revoke the grant at github.com/settings/applications to complete removal.');
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/settings.php`, add `use App\Http\Controllers\Settings\GitHubConnectionController;` to the imports, then add inside the existing `Route::middleware(['auth', 'verified'])->group(...)` block, after the password route:

```php
    Route::get('settings/github', [GitHubConnectionController::class, 'edit'])->name('github.edit');
    Route::post('settings/github', [GitHubConnectionController::class, 'create'])->name('github.connect');
    Route::get('settings/github/callback', [GitHubConnectionController::class, 'callback'])->name('github.callback');
    Route::delete('settings/github', [GitHubConnectionController::class, 'destroy'])->name('github.disconnect');
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=GitHubConnectionTest`
Expected: PASS (7 tests)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
php artisan wayfinder:generate
git add app/Http/Controllers/Settings/GitHubConnectionController.php routes/settings.php resources/js/actions resources/js/routes tests/Feature/Settings/GitHubConnectionTest.php
git commit -m "feat: connect and disconnect a GitHub account over OAuth"
```

---

### Task 6: GitHub settings page

**Files:**
- Create: `resources/js/pages/settings/GitHub.vue`
- Modify: `resources/js/layouts/settings/Layout.vue`
- Modify: `resources/js/types/auth.ts`

- [ ] **Step 1: Type the new user fields**

In `resources/js/types/auth.ts`, add to the `User` type, before the index signature:

```ts
    github_login?: string | null;
    github_avatar_url?: string | null;
    github_connected_at?: string | null;
```

- [ ] **Step 2: Write the page**

Create `resources/js/pages/settings/GitHub.vue`:

```vue
<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Github } from '@lucide/vue';
import { computed } from 'vue';
import GitHubConnectionController from '@/actions/App/Http/Controllers/Settings/GitHubConnectionController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/github';

defineProps<{ configured: boolean }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'GitHub', href: edit() }],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const connected = computed(() => Boolean(user.value.github_login));
</script>

<template>
    <Head title="GitHub" />

    <h1 class="sr-only">GitHub settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="GitHub"
            description="Connect your account to pick repositories from a list and install deploy keys and webhooks automatically"
        />

        <div
            v-if="connected"
            class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-border bg-card p-4"
        >
            <div class="flex items-center gap-3">
                <img
                    v-if="user.github_avatar_url"
                    :src="user.github_avatar_url"
                    alt=""
                    class="size-10 rounded-full"
                />
                <div>
                    <p class="font-mono text-sm font-semibold">
                        @{{ user.github_login }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Connected — new sites install their deploy key and
                        webhook automatically.
                    </p>
                </div>
            </div>

            <Form v-bind="GitHubConnectionController.destroy.form()">
                <Button variant="outline" type="submit">Disconnect</Button>
            </Form>
        </div>

        <div
            v-else
            class="flex flex-col items-start gap-4 rounded-xl border border-dashed border-border bg-card/40 p-6"
        >
            <div class="flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <Github class="size-5" />
            </div>
            <p class="max-w-lg text-sm text-muted-foreground">
                Connecting grants the panel the
                <code class="font-mono">repo</code> and
                <code class="font-mono">admin:repo_hook</code> scopes, so it can
                read your repository list and add a read-only deploy key plus a
                push webhook to the repositories you deploy.
            </p>

            <p v-if="!configured" class="text-sm text-destructive">
                Set <code class="font-mono">GITHUB_CLIENT_ID</code> and
                <code class="font-mono">GITHUB_CLIENT_SECRET</code> in the panel
                .env first, using
                <code class="font-mono">/settings/github/callback</code> as the
                OAuth App callback URL.
            </p>

            <Form v-bind="GitHubConnectionController.create.form()">
                <Button type="submit" :disabled="!configured">
                    <Github class="size-4" /> Connect GitHub
                </Button>
            </Form>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Add the sidebar entry**

In `resources/js/layouts/settings/Layout.vue`, add the import:

```ts
import { edit as editGitHub } from '@/routes/github';
```

and add this entry to `sidebarNavItems`, after the `Security` entry:

```ts
    {
        title: 'GitHub',
        href: editGitHub(),
    },
```

- [ ] **Step 4: Verify the page builds and renders**

Run: `npm run build`
Expected: build succeeds with no TypeScript or Vue compile errors.

Run: `php artisan test --compact --filter=GitHubConnectionTest`
Expected: PASS (7 tests) — unchanged; `Inertia::render` never needed the component to exist, but `the settings page renders` now names a real one.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/settings/GitHub.vue resources/js/layouts/settings/Layout.vue resources/js/types/auth.ts
git commit -m "feat: add the GitHub connection settings page"
```

---

### Task 7: Repository and branch JSON endpoints

**Files:**
- Create: `app/Http/Controllers/GitHubRepositoryController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/GitHubRepositoryPickerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/GitHubRepositoryPickerTest.php`:

```php
<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->github_token = 'gho_secret';
    $this->user->github_login = 'octocat';
    $this->user->save();
});

function fakeRepositories(array $names): void
{
    Http::fake([
        'api.github.com/user/repos*' => Http::response(
            collect($names)->map(fn (string $name): array => [
                'full_name' => $name,
                'private' => false,
                'default_branch' => 'main',
            ])->all(),
        ),
    ]);
}

test('the repository list requires a github connection', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('github.repositories'))
        ->assertForbidden();
});

test('repositories are returned for an empty query', function () {
    fakeRepositories(['acme/app', 'acme/site']);

    $this->actingAs($this->user)
        ->getJson(route('github.repositories'))
        ->assertOk()
        ->assertJsonCount(2, 'repositories')
        ->assertJsonPath('repositories.0.full_name', 'acme/app');
});

test('the query filters the list case insensitively', function () {
    fakeRepositories(['acme/app', 'acme/Website', 'other/thing']);

    $this->actingAs($this->user)
        ->getJson(route('github.repositories', ['q' => 'WEB']))
        ->assertOk()
        ->assertJsonCount(1, 'repositories')
        ->assertJsonPath('repositories.0.full_name', 'acme/Website');
});

test('the cached list means two searches cost one github call', function () {
    fakeRepositories(['acme/app', 'acme/site']);

    $this->actingAs($this->user)->getJson(route('github.repositories', ['q' => 'app']))->assertOk();
    $this->actingAs($this->user)->getJson(route('github.repositories', ['q' => 'site']))->assertOk();

    Http::assertSentCount(1);
});

test('branches are returned with the default branch', function () {
    Http::fake([
        'api.github.com/repos/acme/app/branches*' => Http::response([['name' => 'main'], ['name' => 'develop']]),
        'api.github.com/repos/acme/app' => Http::response(['default_branch' => 'develop']),
    ]);

    $this->actingAs($this->user)
        ->getJson(route('github.branches', ['repository' => 'acme/app']))
        ->assertOk()
        ->assertJson(['branches' => ['main', 'develop'], 'default_branch' => 'develop']);
});

test('a malformed repository slug is rejected', function () {
    $this->actingAs($this->user)
        ->getJson(route('github.branches', ['repository' => 'not-a-slug']))
        ->assertStatus(422);
});

test('a github failure is reported as a readable error', function () {
    Http::fake([
        'api.github.com/repos/acme/app/branches*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $this->actingAs($this->user)
        ->getJson(route('github.branches', ['repository' => 'acme/app']))
        ->assertStatus(422)
        ->assertJson(['message' => 'Not Found']);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=GitHubRepositoryPickerTest`
Expected: FAIL — `Route [github.repositories] not defined.`

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/GitHubRepositoryController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubClientFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Feeds the repository and branch dropdowns on the New Site form. Both
 * endpoints answer JSON and are polled from the client, so failures are
 * returned as 422 with GitHub's message rather than thrown as 500s.
 */
class GitHubRepositoryController extends Controller
{
    private const MAX_RESULTS = 30;

    public function __construct(private GitHubClientFactory $clients) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasGitHubConnection(), 403, 'Connect a GitHub account first.');

        $query = Str::lower(trim((string) $request->query('q', '')));

        try {
            $repositories = $this->clients->for($request->user())->repositories();
        } catch (GitHubApiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if ($query !== '') {
            $repositories = array_filter(
                $repositories,
                fn (array $repository): bool => str_contains(Str::lower($repository['full_name']), $query),
            );
        }

        return response()->json([
            'repositories' => array_values(array_slice($repositories, 0, self::MAX_RESULTS)),
        ]);
    }

    public function branches(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasGitHubConnection(), 403, 'Connect a GitHub account first.');

        $validated = $request->validate([
            'repository' => ['required', 'string', 'regex:/^[\w.-]+\/[\w.-]+$/D'],
        ]);

        $client = $this->clients->for($request->user());

        try {
            return response()->json([
                'branches' => $client->branches($validated['repository']),
                'default_branch' => $client->defaultBranch($validated['repository']),
            ]);
        } catch (GitHubApiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/web.php`, add `use App\Http\Controllers\GitHubRepositoryController;` to the imports, then add inside the `Route::middleware(['auth', 'verified'])->group(...)` block, right before the `Route::resource('sites', ...)` line:

```php
    Route::get('github/repositories', [GitHubRepositoryController::class, 'index'])->name('github.repositories');
    Route::get('github/branches', [GitHubRepositoryController::class, 'branches'])->name('github.branches');
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=GitHubRepositoryPickerTest`
Expected: PASS (7 tests)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
php artisan wayfinder:generate
git add app/Http/Controllers/GitHubRepositoryController.php routes/web.php tests/Feature/GitHubRepositoryPickerTest.php
git commit -m "feat: expose repository search and branch list endpoints"
```

---

### Task 8: Site GitHub resource ids and repository slug

**Files:**
- Create: `database/migrations/2026_08_11_130000_add_github_ids_to_sites_table.php`
- Modify: `app/Models/Site.php`
- Test: `tests/Feature/SiteRepositorySlugTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SiteRepositorySlugTest.php`:

```php
<?php

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeSite(array $attributes = []): Site
{
    return Site::create([
        'domain' => 'app.example.com',
        'repository' => 'git@github.com:acme/app.git',
        'branch' => 'main',
        'root_path' => '/home/forge/app.example.com',
        'webhook_token' => str_repeat('a', 48),
        'deploy_script' => 'echo deploy',
        'status' => 'pending',
        'php_version' => '8.4',
        ...$attributes,
    ]);
}

test('the owner/repo slug is parsed from the ssh url', function () {
    expect(makeSite()->repositoryFullName())->toBe('acme/app');
});

test('a url without the .git suffix still parses', function () {
    expect(makeSite(['repository' => 'git@github.com:acme/my.app'])->repositoryFullName())->toBe('acme/my.app');
});

test('github resource ids are mass assignable and null by default', function () {
    $site = makeSite();

    expect($site->github_key_id)->toBeNull()
        ->and($site->github_hook_id)->toBeNull();

    $site->update(['github_key_id' => 55, 'github_hook_id' => 77]);

    expect($site->fresh()->github_key_id)->toBe(55)
        ->and($site->fresh()->github_hook_id)->toBe(77);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=SiteRepositorySlugTest`
Expected: FAIL — `Call to undefined method App\Models\Site::repositoryFullName()`

- [ ] **Step 3: Add the migration**

Create `database/migrations/2026_08_11_130000_add_github_ids_to_sites_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Null means the panel did not create the resource — an existing
            // site, or a provisioning step that failed. Teardown skips those.
            $table->unsignedBigInteger('github_key_id')->nullable();
            $table->unsignedBigInteger('github_hook_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['github_key_id', 'github_hook_id']);
        });
    }
};
```

- [ ] **Step 4: Extend the Site model**

In `app/Models/Site.php`, add to the `@property` docblock after `$deploy_key_public`:

```php
 * @property int|null $github_key_id
 * @property int|null $github_hook_id
```

Extend the `#[Fillable]` attribute's last line so it reads:

```php
    'has_scheduler', 'provision_log', 'ssl_log', 'github_key_id', 'github_hook_id',
```

Add this method after `cloneUrl()`:

```php
    /**
     * The `owner/repo` slug the GitHub API addresses this site's repository
     * by. StoreSiteRequest constrains `repository` to the SSH form, so the
     * pattern always matches for panel-created sites.
     */
    public function repositoryFullName(): string
    {
        preg_match('/^git@github\.com:(.+?)(?:\.git)?$/D', $this->repository, $matches);

        return $matches[1] ?? '';
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=SiteRepositorySlugTest`
Expected: PASS (3 tests)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/Site.php tests/Feature/SiteRepositorySlugTest.php
git commit -m "feat: track the GitHub deploy key and webhook ids on sites"
```

---

### Task 9: ProvisionGitHubRepository action

**Files:**
- Create: `app/Actions/ProvisionGitHubRepository.php`
- Test: `tests/Feature/ProvisionGitHubRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ProvisionGitHubRepositoryTest.php`:

```php
<?php

use App\Actions\ProvisionGitHubRepository;
use App\Models\Site;
use App\Models\User;
use App\Services\GitHub\GitHubApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->github_token = 'gho_secret';
    $this->user->save();

    $this->site = Site::create([
        'domain' => 'app.example.com',
        'repository' => 'git@github.com:acme/app.git',
        'branch' => 'main',
        'root_path' => '/home/forge/app.example.com',
        'webhook_token' => str_repeat('a', 48),
        'deploy_script' => 'echo deploy',
        'status' => 'key_generated',
        'php_version' => '8.4',
        'deploy_key_public' => 'ssh-ed25519 AAAA forge',
    ]);
});

test('the deploy key and webhook are installed and recorded', function () {
    Http::fake([
        'api.github.com/repos/acme/app/keys' => Http::response(['id' => 55], 201),
        'api.github.com/repos/acme/app/hooks' => Http::response(['id' => 77], 201),
    ]);

    app(ProvisionGitHubRepository::class)->handle($this->site, $this->user);

    expect($this->site->fresh()->github_key_id)->toBe(55)
        ->and($this->site->fresh()->github_hook_id)->toBe(77);

    Http::assertSent(fn ($request) => $request['title'] === 'forge-app.example.com'
        && $request['key'] === 'ssh-ed25519 AAAA forge');
    Http::assertSent(fn ($request) => ($request['config']['url'] ?? null) === $this->site->webhookUrl());
});

test('a webhook failure keeps the key that was already created', function () {
    Http::fake([
        'api.github.com/repos/acme/app/keys' => Http::response(['id' => 55], 201),
        'api.github.com/repos/acme/app/hooks?*' => Http::response([]),
        'api.github.com/repos/acme/app/hooks' => Http::response(['message' => 'Resource not accessible'], 403),
    ]);

    expect(fn () => app(ProvisionGitHubRepository::class)->handle($this->site, $this->user))
        ->toThrow(GitHubApiException::class, 'Resource not accessible');

    expect($this->site->fresh()->github_key_id)->toBe(55)
        ->and($this->site->fresh()->github_hook_id)->toBeNull();
});

test('an already provisioned step is not repeated', function () {
    $this->site->update(['github_key_id' => 55]);

    Http::fake(['api.github.com/repos/acme/app/hooks' => Http::response(['id' => 77], 201)]);

    app(ProvisionGitHubRepository::class)->handle($this->site, $this->user);

    Http::assertSentCount(1);
    expect($this->site->fresh()->github_hook_id)->toBe(77);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=ProvisionGitHubRepositoryTest`
Expected: FAIL — `Class "App\Actions\ProvisionGitHubRepository" not found`

- [ ] **Step 3: Write the action**

Create `app/Actions/ProvisionGitHubRepository.php`:

```php
<?php

namespace App\Actions;

use App\Models\Site;
use App\Models\User;
use App\Services\GitHub\GitHubClientFactory;

class ProvisionGitHubRepository
{
    public function __construct(private GitHubClientFactory $clients) {}

    /**
     * Install the site's read-only deploy key and push webhook on GitHub.
     *
     * Each id is persisted the moment its call succeeds, so a failure halfway
     * through leaves an accurate record of what exists on GitHub — both the
     * caller's error message and teardown depend on that.
     *
     * @throws \App\Services\GitHub\GitHubApiException
     */
    public function handle(Site $site, User $user): void
    {
        $client = $this->clients->for($user);
        $repository = $site->repositoryFullName();

        if ($site->github_key_id === null) {
            $site->update([
                'github_key_id' => $client->createDeployKey(
                    $repository,
                    "forge-{$site->domain}",
                    (string) $site->deploy_key_public,
                ),
            ]);
        }

        if ($site->github_hook_id === null) {
            $site->update([
                'github_hook_id' => $client->createWebhook($repository, $site->webhookUrl()),
            ]);
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=ProvisionGitHubRepositoryTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/ProvisionGitHubRepository.php tests/Feature/ProvisionGitHubRepositoryTest.php
git commit -m "feat: add the GitHub provisioning action"
```

---

### Task 10: Auto-provision and auto-install on site creation

**Files:**
- Modify: `app/Http/Controllers/SiteController.php:21-51`
- Test: `tests/Feature/SiteGitHubProvisioningTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SiteGitHubProvisioningTest.php`:

```php
<?php

use App\Jobs\InstallRepository;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['forge.fake_shell' => true]);
    Queue::fake();

    $this->user = User::factory()->create();
});

function connectSiteOwner(User $user): User
{
    $user->github_token = 'gho_secret';
    $user->github_login = 'octocat';
    $user->save();

    return $user;
}

function siteAttributes(): array
{
    return [
        'domain' => 'app.example.com',
        'repository' => 'git@github.com:acme/app.git',
        'branch' => 'main',
        'php_version' => '8.4',
    ];
}

test('a connected user gets the key and webhook installed and the site installing', function () {
    Http::fake([
        'api.github.com/repos/acme/app/keys' => Http::response(['id' => 55], 201),
        'api.github.com/repos/acme/app/hooks' => Http::response(['id' => 77], 201),
    ]);

    $this->actingAs(connectSiteOwner($this->user))
        ->post(route('sites.store'), siteAttributes())
        ->assertRedirect()
        ->assertSessionHas('success');

    $site = Site::firstWhere('domain', 'app.example.com');

    expect($site->github_key_id)->toBe(55)
        ->and($site->github_hook_id)->toBe(77);

    Queue::assertPushed(InstallRepository::class);
});

test('a github failure keeps the site, skips the install and names the missing step', function () {
    Http::fake([
        'api.github.com/repos/acme/app/keys' => Http::response(['message' => 'Resource not accessible by integration'], 403),
    ]);

    $this->actingAs(connectSiteOwner($this->user))
        ->post(route('sites.store'), siteAttributes())
        ->assertRedirect()
        ->assertSessionHas('error', fn (string $error): bool => str_contains($error, 'deploy key')
            && str_contains($error, 'Resource not accessible by integration'));

    expect(Site::firstWhere('domain', 'app.example.com'))->not->toBeNull();

    Queue::assertNotPushed(InstallRepository::class);
});

test('an unconnected user keeps the manual flow untouched', function () {
    Http::fake();

    $this->actingAs($this->user)
        ->post(route('sites.store'), siteAttributes())
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Add the deploy key'));

    Http::assertNothingSent();
    Queue::assertNotPushed(InstallRepository::class);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=SiteGitHubProvisioningTest`
Expected: FAIL — the first test fails because no HTTP request is sent and `github_key_id` is null.

- [ ] **Step 3: Wire the action into the controller**

In `app/Http/Controllers/SiteController.php`, add these imports:

```php
use App\Actions\ProvisionGitHubRepository;
use App\Jobs\InstallRepository;
use App\Services\GitHub\GitHubApiException;
```

Change `index()`'s signature to accept the request and expose the connection state:

```php
    public function index(Request $request): Response
    {
        return Inertia::render('sites/Index', [
            'sites' => Site::query()->latest()->get(['id', 'domain', 'repository', 'branch', 'status', 'ssl_enabled', 'php_version']),
            'phpVersions' => config('forge.php_versions'),
            'defaultPhpVersion' => config('forge.default_php_version'),
            'githubConnected' => $request->user()->hasGitHubConnection(),
        ]);
    }
```

Replace the `return to_route(...)` line at the end of `store()` — everything up to and including the `catch` block that deletes the site stays exactly as it is — with:

```php
        $user = $request->user();

        if (! $user->hasGitHubConnection()) {
            return to_route('sites.show', $site)
                ->with('success', 'Site created. Add the deploy key and webhook to GitHub, then install the repository.');
        }

        try {
            $provision->handle($site, $user);
        } catch (GitHubApiException $exception) {
            report($exception);

            return to_route('sites.show', $site)->with('error', $this->provisionFailureMessage($site, $exception));
        }

        InstallRepository::dispatch($site);

        return to_route('sites.show', $site)
            ->with('success', 'Site created — deploy key and webhook installed on GitHub. Installing now.');
```

Change the `store()` signature to inject the action:

```php
    public function store(
        StoreSiteRequest $request,
        GenerateSiteDeployKey $generateKey,
        ProvisionGitHubRepository $provision,
    ): RedirectResponse {
```

Add this private method at the end of the class:

```php
    /**
     * Name the step that did not land, so the operator knows which of the two
     * panels on the site page they still need to copy across by hand.
     */
    private function provisionFailureMessage(Site $site, GitHubApiException $exception): string
    {
        $missing = $site->github_key_id === null ? 'deploy key' : 'webhook';

        return "Site created, but the {$missing} could not be added to GitHub: {$exception->getMessage()} "
            .'Add it manually below, then click Install.';
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=SiteGitHubProvisioningTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Verify nothing else regressed**

Run: `php artisan test --compact`
Expected: the whole suite passes.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SiteController.php tests/Feature/SiteGitHubProvisioningTest.php
git commit -m "feat: install the deploy key and webhook when a site is created"
```

---

### Task 11: Remove the key and webhook when a site is deleted

**Files:**
- Create: `app/Actions/TeardownGitHubRepository.php`
- Modify: `app/Http/Controllers/SiteController.php` (`destroy()`)
- Test: `tests/Feature/SiteGitHubTeardownTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SiteGitHubTeardownTest.php`:

```php
<?php

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['forge.fake_shell' => true]);

    $this->user = User::factory()->create();

    $this->site = Site::create([
        'domain' => 'app.example.com',
        'repository' => 'git@github.com:acme/app.git',
        'branch' => 'main',
        'root_path' => '/home/forge/app.example.com',
        'webhook_token' => str_repeat('a', 48),
        'deploy_script' => 'echo deploy',
        'status' => 'installed',
        'php_version' => '8.4',
        'github_key_id' => 55,
        'github_hook_id' => 77,
    ]);
});

function connectUser(User $user): User
{
    $user->github_token = 'gho_secret';
    $user->save();

    return $user;
}

test('deleting a site removes its key and webhook from github', function () {
    Http::fake([
        'api.github.com/repos/acme/app/keys/55' => Http::response(null, 204),
        'api.github.com/repos/acme/app/hooks/77' => Http::response(null, 204),
    ]);

    $this->actingAs(connectUser($this->user))
        ->delete(route('sites.destroy', $this->site))
        ->assertRedirect(route('sites.index'));

    expect(Site::find($this->site->id))->toBeNull();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/repos/acme/app/keys/55');
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/repos/acme/app/hooks/77');
});

test('a github failure does not block deleting the site', function () {
    Http::fake([
        'api.github.com/repos/acme/app/hooks/77' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/repos/acme/app/keys/55' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $this->actingAs(connectUser($this->user))
        ->delete(route('sites.destroy', $this->site))
        ->assertRedirect(route('sites.index'));

    expect(Site::find($this->site->id))->toBeNull();
});

test('an unconnected user deletes the site without calling github', function () {
    Http::fake();

    $this->actingAs($this->user)
        ->delete(route('sites.destroy', $this->site))
        ->assertRedirect(route('sites.index'));

    expect(Site::find($this->site->id))->toBeNull();
    Http::assertNothingSent();
});

test('a site with no recorded github resources calls nothing', function () {
    Http::fake();

    $this->site->update(['github_key_id' => null, 'github_hook_id' => null]);

    $this->actingAs(connectUser($this->user))
        ->delete(route('sites.destroy', $this->site))
        ->assertRedirect(route('sites.index'));

    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=SiteGitHubTeardownTest`
Expected: FAIL — `deleting a site removes its key and webhook from github` fails, no HTTP sent.

- [ ] **Step 3: Write the action**

Create `app/Actions/TeardownGitHubRepository.php`:

```php
<?php

namespace App\Actions;

use App\Models\Site;
use App\Models\User;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubClientFactory;

class TeardownGitHubRepository
{
    public function __construct(private GitHubClientFactory $clients) {}

    /**
     * Remove what the panel put on GitHub for this site.
     *
     * Best-effort by design, and each call is isolated: a revoked token, a
     * repository deleted on GitHub, or a hook someone removed by hand must
     * never stop a site being removed from the panel — nor stop the second
     * resource being cleaned up because the first one failed.
     */
    public function handle(Site $site, ?User $user): void
    {
        if ($user === null || ! $user->hasGitHubConnection()) {
            return;
        }

        if ($site->github_hook_id === null && $site->github_key_id === null) {
            return;
        }

        $client = $this->clients->for($user);
        $repository = $site->repositoryFullName();

        if ($site->github_hook_id !== null) {
            try {
                $client->deleteWebhook($repository, $site->github_hook_id);
            } catch (GitHubApiException $exception) {
                report($exception);
            }
        }

        if ($site->github_key_id !== null) {
            try {
                $client->deleteDeployKey($repository, $site->github_key_id);
            } catch (GitHubApiException $exception) {
                report($exception);
            }
        }
    }
}
```

- [ ] **Step 4: Call it from the controller**

In `app/Http/Controllers/SiteController.php`, add the import:

```php
use App\Actions\TeardownGitHubRepository;
```

Change `destroy()`'s signature and add the call as the first teardown step:

```php
    public function destroy(
        Request $request,
        Site $site,
        ApacheManager $apache,
        WorkerManager $workers,
        SchedulerManager $scheduler,
        TeardownGitHubRepository $github,
    ): RedirectResponse {
        try {
            $github->handle($site, $request->user());
        } catch (\Throwable $e) {
            report($e);
        }

        foreach ($site->workers as $worker) {
```

The rest of the method body is unchanged.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=SiteGitHubTeardownTest`
Expected: PASS (4 tests)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/TeardownGitHubRepository.php app/Http/Controllers/SiteController.php tests/Feature/SiteGitHubTeardownTest.php
git commit -m "feat: remove the deploy key and webhook when a site is deleted"
```

---

### Task 12: Repository combobox and branch dropdown on the New Site form

**Files:**
- Create: `resources/js/components/RepositoryCombobox.vue`
- Modify: `resources/js/pages/sites/Index.vue`

- [ ] **Step 1: Add the shared Repository type**

`<script setup>` blocks cannot contain `export` statements, so the type lives in the types barrel that both components import from.

Create `resources/js/types/github.ts`:

```ts
export type Repository = {
    full_name: string;
    private: boolean;
    default_branch: string;
};
```

Add to `resources/js/types/index.ts`:

```ts
export * from './github';
```

- [ ] **Step 2: Write the combobox**

Create `resources/js/components/RepositoryCombobox.vue`:

```vue
<script setup lang="ts">
import { Lock, LoaderCircle, Search } from '@lucide/vue';
import { onClickOutside, refDebounced } from '@vueuse/core';
import { ref, watch } from 'vue';
import { repositories as githubRepositories } from '@/routes/github';
import type { Repository } from '@/types';

const emit = defineEmits<{
    (event: 'selected', repository: Repository): void;
}>();

const root = ref<HTMLElement | null>(null);
const open = ref(false);
const loading = ref(false);
const failed = ref(false);
const results = ref<Repository[]>([]);
const highlighted = ref(0);
const search = ref('');
const debounced = refDebounced(search, 250);

onClickOutside(root, () => {
    open.value = false;
});

watch(debounced, () => {
    void load();
});

async function load() {
    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch(
            `${githubRepositories().url}?q=${encodeURIComponent(search.value)}`,
            { headers: { Accept: 'application/json' } },
        );
        const data = await response.json();

        results.value = response.ok ? data.repositories : [];
        failed.value = !response.ok;
    } catch {
        results.value = [];
        failed.value = true;
    } finally {
        loading.value = false;
        highlighted.value = 0;
    }
}

function focus() {
    open.value = true;

    if (!results.value.length && !loading.value) {
        void load();
    }
}

function select(repository: Repository) {
    search.value = repository.full_name;
    open.value = false;
    emit('selected', repository);
}

function move(offset: number) {
    if (!results.value.length) {
        return;
    }

    highlighted.value =
        (highlighted.value + offset + results.value.length) %
        results.value.length;
}

function choose() {
    const repository = results.value[highlighted.value];

    if (repository) {
        select(repository);
    }
}
</script>

<template>
    <div ref="root" class="relative">
        <div
            class="mt-1.5 flex items-center rounded-lg border border-input bg-background focus-within:ring-2 focus-within:ring-ring"
        >
            <Search class="ml-3 size-4 shrink-0 text-muted-foreground" />
            <input
                v-model="search"
                placeholder="Search your repositories…"
                autocomplete="off"
                class="w-full rounded-lg bg-transparent px-2.5 py-2 font-mono text-sm outline-none"
                @focus="focus"
                @keydown.down.prevent="move(1)"
                @keydown.up.prevent="move(-1)"
                @keydown.enter.prevent="choose"
                @keydown.esc="open = false"
            />
            <LoaderCircle
                v-if="loading"
                class="mr-3 size-4 shrink-0 animate-spin text-muted-foreground"
            />
        </div>

        <ul
            v-if="open"
            class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-border bg-popover p-1 shadow-lg"
        >
            <li
                v-for="(repository, index) in results"
                :key="repository.full_name"
                :class="[
                    'flex cursor-pointer items-center gap-2 rounded-md px-2.5 py-1.5 font-mono text-sm',
                    index === highlighted
                        ? 'bg-accent text-accent-foreground'
                        : '',
                ]"
                @mouseenter="highlighted = index"
                @mousedown.prevent="select(repository)"
            >
                <Lock
                    v-if="repository.private"
                    class="size-3 shrink-0 text-muted-foreground"
                />
                <span class="truncate">{{ repository.full_name }}</span>
            </li>

            <li
                v-if="!results.length && !loading"
                class="px-2.5 py-2 text-sm text-muted-foreground"
            >
                {{
                    failed
                        ? 'Could not reach GitHub. Enter the repository manually.'
                        : 'No repositories match.'
                }}
            </li>
        </ul>
    </div>
</template>
```

- [ ] **Step 3: Wire it into the New Site form**

In `resources/js/pages/sites/Index.vue`, add to the imports:

```ts
import RepositoryCombobox from '@/components/RepositoryCombobox.vue';
import {
    branches as githubBranches,
    edit as githubSettings,
} from '@/routes/github';
import type { Repository } from '@/types';
```

Add `githubConnected: boolean;` to the `defineProps` type, then add this state and handler after the existing `form` declaration:

```ts
const manualEntry = ref(!props.githubConnected);
const branches = ref<string[]>([]);
const branchesLoading = ref(false);

async function onRepositorySelected(repository: Repository) {
    form.repository = `git@github.com:${repository.full_name}.git`;
    form.branch = repository.default_branch;
    branches.value = [repository.default_branch];
    branchesLoading.value = true;

    try {
        const response = await fetch(
            `${githubBranches().url}?repository=${encodeURIComponent(repository.full_name)}`,
            { headers: { Accept: 'application/json' } },
        );
        const data = await response.json();

        if (response.ok) {
            branches.value = data.branches;
            form.branch = data.default_branch;
        }
    } catch {
        // Keep the default branch — the operator can switch to manual entry.
    } finally {
        branchesLoading.value = false;
    }
}
```

Replace the two form fields. The **branch** label (currently lines 156-173) becomes:

```html
                    <label class="text-sm font-medium">
                        Branch
                        <select
                            v-if="githubConnected && !manualEntry"
                            v-model="form.branch"
                            :disabled="branchesLoading || !branches.length"
                            class="mt-1.5 w-full rounded-lg border border-input bg-background px-3 py-2 font-mono text-sm outline-none focus:ring-2 focus:ring-ring disabled:opacity-60"
                        >
                            <option v-if="!branches.length" value="">
                                Pick a repository first
                            </option>
                            <option
                                v-for="branch in branches"
                                :key="branch"
                                :value="branch"
                            >
                                {{ branch }}
                            </option>
                        </select>
                        <div
                            v-else
                            class="mt-1.5 flex items-center rounded-lg border border-input bg-background focus-within:ring-2 focus-within:ring-ring"
                        >
                            <GitBranch
                                class="ml-3 size-4 shrink-0 text-muted-foreground"
                            />
                            <input
                                v-model="form.branch"
                                placeholder="main"
                                class="w-full rounded-lg bg-transparent px-2.5 py-2 font-mono text-sm outline-none"
                            />
                        </div>
                        <span v-if="form.errors.branch" class="field-error">{{
                            form.errors.branch
                        }}</span>
                    </label>
```

The **repository** label (currently lines 176-188) becomes:

```html
                    <label class="text-sm font-medium">
                        <span class="flex items-center justify-between gap-2">
                            Repository
                            <button
                                v-if="githubConnected"
                                type="button"
                                class="text-xs font-normal text-muted-foreground underline-offset-2 hover:underline"
                                @click="manualEntry = !manualEntry"
                            >
                                {{
                                    manualEntry
                                        ? 'Pick from GitHub'
                                        : 'Enter manually'
                                }}
                            </button>
                        </span>
                        <RepositoryCombobox
                            v-if="githubConnected && !manualEntry"
                            @selected="onRepositorySelected"
                        />
                        <input
                            v-else
                            v-model="form.repository"
                            placeholder="git@github.com:user/repo.git"
                            class="mt-1.5 w-full rounded-lg border border-input bg-background px-3 py-2 font-mono text-sm outline-none focus:ring-2 focus:ring-ring"
                        />
                        <span
                            v-if="!githubConnected"
                            class="mt-1.5 block text-xs text-muted-foreground"
                        >
                            <Link
                                :href="githubSettings().url"
                                class="underline underline-offset-2"
                                >Connect GitHub</Link
                            >
                            to pick a repository and install the deploy key and
                            webhook automatically.
                        </span>
                        <span
                            v-if="form.errors.repository"
                            class="field-error"
                            >{{ form.errors.repository }}</span
                        >
                    </label>
```

- [ ] **Step 4: Build and check types**

Run: `npm run build`
Expected: build succeeds, no TypeScript errors.

Run: `npx eslint resources/js/components/RepositoryCombobox.vue resources/js/pages/sites/Index.vue`
Expected: no errors.

- [ ] **Step 5: Confirm the backend prop is present**

Run: `php artisan test --compact --filter=SiteGitHubProvisioningTest`
Expected: PASS (3 tests) — `githubConnected` was added to `index()` in Task 10.

- [ ] **Step 6: Commit**

```bash
git add resources/js/components/RepositoryCombobox.vue resources/js/pages/sites/Index.vue resources/js/types/github.ts resources/js/types/index.ts
git commit -m "feat: pick repository and branch from GitHub in the new site form"
```

---

### Task 13: Full verification

**Files:** none created; this task only verifies.

- [ ] **Step 1: Format every touched PHP file**

Run: `vendor/bin/pint --dirty --format agent`
Expected: no remaining style issues.

- [ ] **Step 2: Static analysis**

Run: `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: no errors. If `github_key_id`/`github_hook_id`/`github_token` are reported as unknown properties, the `@property` docblocks from Tasks 1 and 8 are missing or misspelled — fix them there.

- [ ] **Step 3: Full test suite**

Run: `php artisan test --compact`
Expected: every test passes, including the pre-existing `WebhookDeployTest`, `SitePhpVersionTest`, `SiteDeployScriptTest`, `VhostEditorTest` and `WorkerCommandTest`.

- [ ] **Step 4: Frontend build and lint**

Run: `npm run build`
Expected: succeeds.

Run: `npm run lint`
Expected: no errors.

- [ ] **Step 5: Manual smoke check**

With `FORGE_FAKE_SHELL=true` and a real OAuth App configured:

1. Visit `/settings/github`, connect, confirm the avatar and `@login` appear.
2. Visit `/sites`, open **New site**, type in the repository field, confirm results appear and selecting one fills the branch dropdown with the repository's default branch preselected.
3. Create the site, confirm the flash says the key and webhook were installed, and confirm both exist under the repository's **Settings → Deploy keys** and **Settings → Webhooks** on GitHub.
4. Delete the site, confirm both are gone from GitHub.

- [ ] **Step 6: Commit anything the verification changed**

```bash
git add -A
git commit -m "chore: formatting and lint fixes for the GitHub integration"
```

(Skip if there is nothing to commit.)
