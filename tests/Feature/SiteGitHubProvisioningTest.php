<?php

use App\Jobs\InstallRepository;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['forge.fake_shell' => true]);
    Queue::fake();

    $this->user = User::factory()->create();
});

function connectSiteOwner(User $user): User
{
    $user->github_token = 'gho_secret';
    $user->github_login = 'octocat';
    $user->save();

    return $user;
}

function siteAttributes(): array
{
    return [
        'domain' => 'app.example.com',
        'repository' => 'git@github.com:acme/app.git',
        'branch' => 'main',
        'php_version' => '8.4',
    ];
}

test('a connected user gets the key and webhook installed and the site installing', function () {
    Http::fake([
        'api.github.com/repos/acme/app/keys' => Http::response(['id' => 55], 201),
        'api.github.com/repos/acme/app/hooks' => Http::response(['id' => 77], 201),
    ]);

    $this->actingAs(connectSiteOwner($this->user))
        ->post(route('sites.store'), siteAttributes())
        ->assertRedirect()
        ->assertSessionHas('success');

    $site = Site::firstWhere('domain', 'app.example.com');

    expect($site->github_key_id)->toBe(55)
        ->and($site->github_hook_id)->toBe(77);

    Queue::assertPushed(InstallRepository::class);
});

test('a github failure keeps the site, skips the install and names the missing step', function () {
    Http::fake([
        'api.github.com/repos/acme/app/keys' => Http::response(['message' => 'Resource not accessible by integration'], 403),
    ]);

    $this->actingAs(connectSiteOwner($this->user))
        ->post(route('sites.store'), siteAttributes())
        ->assertRedirect()
        ->assertSessionHas('error', fn (string $error): bool => str_contains($error, 'deploy key')
            && str_contains($error, 'Resource not accessible by integration'));

    expect(Site::firstWhere('domain', 'app.example.com'))->not->toBeNull();

    Queue::assertNotPushed(InstallRepository::class);
});

test('a webhook failure keeps the site, skips the install and names the missing step', function () {
    Http::fake([
        'api.github.com/repos/acme/app/keys' => Http::response(['id' => 55], 201),
        'api.github.com/repos/acme/app/hooks' => Http::response(['message' => 'Internal Server Error'], 500),
    ]);

    $this->actingAs(connectSiteOwner($this->user))
        ->post(route('sites.store'), siteAttributes())
        ->assertRedirect()
        ->assertSessionHas('error', fn (string $error): bool => str_contains($error, 'webhook')
            && str_contains($error, 'Internal Server Error'));

    $site = Site::firstWhere('domain', 'app.example.com');

    expect($site)->not->toBeNull()
        ->and($site->github_key_id)->toBe(55)
        ->and($site->github_hook_id)->toBeNull();

    Queue::assertNotPushed(InstallRepository::class);
});

test('an unconnected user keeps the manual flow untouched', function () {
    Http::fake();

    $this->actingAs($this->user)
        ->post(route('sites.store'), siteAttributes())
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Add the deploy key'));

    Http::assertNothingSent();
    Queue::assertNotPushed(InstallRepository::class);
});
