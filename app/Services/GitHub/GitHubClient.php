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
     * Capped at {@see self::MAX_REPOSITORY_PAGES} pages (1,000 repositories);
     * accounts with more than that will have the remainder silently truncated.
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
                    if (! isset($repository['full_name']) || ! is_string($repository['full_name'])) {
                        continue;
                    }

                    $repositories[] = [
                        'full_name' => $repository['full_name'],
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

    /**
     * Capped at {@see self::MAX_BRANCH_PAGES} pages (300 branches);
     * repositories with more than that will have the remainder silently truncated.
     *
     * @return array<int, string>
     */
    public function branches(string $fullName): array
    {
        $branches = [];

        for ($page = 1; $page <= self::MAX_BRANCH_PAGES; $page++) {
            $body = $this->get("/repos/{$fullName}/branches", ['per_page' => self::PER_PAGE, 'page' => $page]);

            foreach ($body as $branch) {
                if (! isset($branch['name']) || ! is_string($branch['name'])) {
                    continue;
                }

                $branches[] = $branch['name'];
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

    /** Read-only by design: the key clones the repository, it never pushes. */
    public function createDeployKey(string $fullName, string $title, string $publicKey): int
    {
        return $this->extractId($this->post("/repos/{$fullName}/keys", [
            'title' => $title,
            'key' => $publicKey,
            'read_only' => true,
        ]), 'deploy key');
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
            return $this->extractId($this->post("/repos/{$fullName}/hooks", [
                'name' => 'web',
                'active' => true,
                'events' => ['push'],
                'config' => ['url' => $url, 'content_type' => 'json', 'insecure_ssl' => '0'],
            ]), 'webhook');
        } catch (GitHubApiException $exception) {
            $existing = $exception->status === 422 ? $this->findWebhookByUrl($fullName, $url) : null;

            if ($existing === null) {
                throw $exception;
            }

            // The webhook URL embeds the site id plus a fresh 48-character
            // random token, so a recreated site never collides with a hook
            // belonging to a different site. This adoption path is only
            // reachable when re-provisioning the *same* site, meaning the
            // hook being adopted is one we created ourselves — its
            // active/push/json config already matches, so there is nothing
            // to reconcile with a PATCH.
            return $existing;
        }
    }

    public function deleteWebhook(string $fullName, int $id): void
    {
        $this->delete("/repos/{$fullName}/hooks/{$id}");
    }

    /**
     * Only the first page (100 hooks) is searched — plenty for adoption
     * lookups, and it avoids an unbounded scan for repositories with unusually
     * large hook lists.
     */
    private function findWebhookByUrl(string $fullName, string $url): ?int
    {
        foreach ($this->get("/repos/{$fullName}/hooks", ['per_page' => self::PER_PAGE]) as $hook) {
            $id = $hook['id'] ?? null;

            if (! is_int($id) || ($hook['config']['url'] ?? null) !== $url) {
                continue;
            }

            return $id;
        }

        return null;
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

    /**
     * `send()`'s docblock promises every failure surfaces as a
     * GitHubApiException; without this guard a 2xx response with an
     * unexpected body would let PHP's E_WARNING-to-ErrorException conversion
     * escape instead, breaking that promise for callers.
     *
     * @param  array<mixed>  $body
     */
    private function extractId(array $body, string $context): int
    {
        $id = $body['id'] ?? null;

        if (! is_int($id)) {
            throw new GitHubApiException(502, "GitHub did not return an id for the {$context}.");
        }

        return $id;
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
