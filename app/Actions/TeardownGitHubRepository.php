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

        $repository = $site->repositoryFullName();

        // A malformed `repository` value would turn into a call against
        // `/repos//keys/{id}` — a nonsensical request against GitHub's API
        // that could never succeed. Skip it rather than fire it; report()
        // is deliberately not called here since ProvisionGitHubRepository
        // already rejects unrecognised URLs at creation time, so a site can
        // only end up here if the value was edited outside the panel.
        if ($repository === '') {
            return;
        }

        $client = $this->clients->for($user);

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
