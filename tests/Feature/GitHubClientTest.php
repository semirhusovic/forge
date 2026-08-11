<?php

use App\Models\User;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('cURL error 28: timed out');
    });

    expect(fn () => (new GitHubClient(connectedUser()))->viewer())
        ->toThrow(GitHubApiException::class, 'Could not reach GitHub');

    // retry(2, ...) means 2 total attempts, not 2 retries after the first.
    expect($attempts)->toBe(2);
});

test('a malformed repository entry is skipped instead of throwing', function () {
    Http::fake(['api.github.com/user/repos*' => Http::response([
        ['private' => false, 'default_branch' => 'main'],
        ['full_name' => 'acme/app', 'private' => false, 'default_branch' => 'main'],
    ])]);

    expect((new GitHubClient(connectedUser()))->repositories())
        ->toBe([['full_name' => 'acme/app', 'private' => false, 'default_branch' => 'main']]);
});

test('a malformed branch entry is skipped instead of throwing', function () {
    Http::fake(['api.github.com/repos/acme/app/branches*' => Http::response([
        ['protected' => false],
        ['name' => 'main'],
    ])]);

    expect((new GitHubClient(connectedUser()))->branches('acme/app'))->toBe(['main']);
});
