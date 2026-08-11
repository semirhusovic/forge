<?php

use App\Actions\ProvisionGitHubRepository;
use App\Models\Site;
use App\Models\User;
use App\Services\GitHub\GitHubApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

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

    Http::assertSent(fn ($request) => ($request['title'] ?? null) === 'forge-app.example.com'
        && ($request['key'] ?? null) === 'ssh-ed25519 AAAA forge');
    Http::assertSent(fn ($request) => ($request['config']['url'] ?? null) === $this->site->webhookUrl());
});

test('a webhook failure keeps the key that was already created', function () {
    Http::fake([
        'api.github.com/repos/acme/app/keys' => Http::response(['id' => 55], 201),
        // No hooks?* fake here: createWebhook() only consults the hook list
        // on a 422 (to adopt an existing hook), and this fake returns 403 —
        // so that lookup never fires and would be dead fixture if present.
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

test('a malformed repository value fails fast without contacting GitHub', function () {
    $this->site->update(['repository' => 'https://github.com/acme/app']);

    Http::fake();

    expect(fn () => app(ProvisionGitHubRepository::class)->handle($this->site, $this->user))
        ->toThrow(GitHubApiException::class, 'https://github.com/acme/app');

    Http::assertNothingSent();
});
