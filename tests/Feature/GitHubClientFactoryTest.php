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
