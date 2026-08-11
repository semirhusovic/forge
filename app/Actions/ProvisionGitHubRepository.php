<?php

namespace App\Actions;

use App\Models\Site;
use App\Models\User;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubClientFactory;

class ProvisionGitHubRepository
{
    /**
     * Synthetic status for the empty-slug guard below — no HTTP request is
     * ever made, so no real GitHub status applies. Kept out of the 100-599
     * response range like {@see GitHubClient::send()}'s `0` (unreachable),
     * but distinct from it and from `502` ({@see GitHubClient::extractId()})
     * so callers can tell "never attempted" from "GitHub responded badly".
     */
    public const INVALID_REPOSITORY_STATUS = -1;

    public function __construct(private GitHubClientFactory $clients) {}

    /**
     * Install the site's read-only deploy key and push webhook on GitHub.
     *
     * Each id is persisted the moment its call succeeds, so a failure halfway
     * through leaves an accurate record of what exists on GitHub — both the
     * caller's error message and teardown depend on that.
     *
     * @throws GitHubApiException
     */
    public function handle(Site $site, User $user): void
    {
        $repository = $site->repositoryFullName();

        if ($repository === '') {
            throw new GitHubApiException(
                self::INVALID_REPOSITORY_STATUS,
                "\"{$site->repository}\" is not a recognised GitHub SSH URL (expected git@github.com:owner/repo.git).",
            );
        }

        $client = $this->clients->for($user);

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
