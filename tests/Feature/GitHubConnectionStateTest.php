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
