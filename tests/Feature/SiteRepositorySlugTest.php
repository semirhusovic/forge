<?php

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeGitHubSite(array $attributes = []): Site
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
    expect(makeGitHubSite()->repositoryFullName())->toBe('acme/app');
});

test('a url without the .git suffix still parses', function () {
    expect(makeGitHubSite(['repository' => 'git@github.com:acme/my.app'])->repositoryFullName())->toBe('acme/my.app');
});

test('github resource ids are mass assignable and null by default', function () {
    $site = makeGitHubSite();

    expect($site->github_key_id)->toBeNull()
        ->and($site->github_hook_id)->toBeNull();

    $site->update(['github_key_id' => 55, 'github_hook_id' => 77]);

    expect($site->fresh()->github_key_id)->toBe(55)
        ->and($site->fresh()->github_hook_id)->toBe(77);
});
