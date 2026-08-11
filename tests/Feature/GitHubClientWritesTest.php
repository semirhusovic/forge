<?php

use App\Models\User;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->github_token = 'gho_secret';
    $this->user->save();

    $this->client = new GitHubClient($this->user);
});

test('a deploy key is created read only', function () {
    Http::fake(['api.github.com/repos/acme/app/keys' => Http::response(['id' => 55], 201)]);

    expect($this->client->createDeployKey('acme/app', 'forge-app.example.com', 'ssh-ed25519 AAAA'))->toBe(55);

    Http::assertSent(fn ($request) => $request['title'] === 'forge-app.example.com'
        && $request['key'] === 'ssh-ed25519 AAAA'
        && $request['read_only'] === true);
});

test('a webhook is created for push events only', function () {
    Http::fake(['api.github.com/repos/acme/app/hooks' => Http::response(['id' => 77], 201)]);

    expect($this->client->createWebhook('acme/app', 'https://panel.test/webhook/deploy/1/tok'))->toBe(77);

    Http::assertSent(fn ($request) => $request['events'] === ['push']
        && $request['config']['url'] === 'https://panel.test/webhook/deploy/1/tok'
        && $request['config']['content_type'] === 'json');
});

test('an existing webhook with the same url is reused instead of failing', function () {
    Http::fake([
        'api.github.com/repos/acme/app/hooks?*' => Http::response([
            ['id' => 12, 'config' => ['url' => 'https://panel.test/other']],
            ['id' => 99, 'config' => ['url' => 'https://panel.test/webhook/deploy/1/tok']],
        ]),
        'api.github.com/repos/acme/app/hooks' => Http::response(['message' => 'Hook already exists on this repository'], 422),
    ]);

    expect($this->client->createWebhook('acme/app', 'https://panel.test/webhook/deploy/1/tok'))->toBe(99);
});

test('a 422 with no matching hook still throws', function () {
    Http::fake([
        'api.github.com/repos/acme/app/hooks?*' => Http::response([]),
        'api.github.com/repos/acme/app/hooks' => Http::response(['message' => 'Validation failed'], 422),
    ]);

    expect(fn () => $this->client->createWebhook('acme/app', 'https://panel.test/webhook/deploy/1/tok'))
        ->toThrow(GitHubApiException::class, 'Validation failed');
});

test('deleting a key and a hook hits the right endpoints', function () {
    Http::fake([
        'api.github.com/repos/acme/app/keys/55' => Http::response(null, 204),
        'api.github.com/repos/acme/app/hooks/77' => Http::response(null, 204),
    ]);

    $this->client->deleteDeployKey('acme/app', 55);
    $this->client->deleteWebhook('acme/app', 77);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/repos/acme/app/keys/55');
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/repos/acme/app/hooks/77');
});
