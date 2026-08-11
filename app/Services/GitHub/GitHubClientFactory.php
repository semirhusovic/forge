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
