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
