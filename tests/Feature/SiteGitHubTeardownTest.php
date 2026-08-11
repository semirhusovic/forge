<?php

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['forge.fake_shell' => true]);
    Http::preventStrayRequests();

    $this->user = User::factory()->create();

    $this->site = Site::create([
        'domain' => 'app.example.com',
        'repository' => 'git@github.com:acme/app.git',
        'branch' => 'main',
        'root_path' => '/home/forge/app.example.com',
        'webhook_token' => str_repeat('a', 48),
        'deploy_script' => 'echo deploy',
        'status' => 'installed',
        'php_version' => '8.4',
        'github_key_id' => 55,
        'github_hook_id' => 77,
    ]);
});

function connectUser(User $user): User
{
    $user->github_token = 'gho_secret';
    $user->save();

    return $user;
}

test('deleting a site removes its key and webhook from github', function () {
    Http::fake([
        'api.github.com/repos/acme/app/keys/55' => Http::response(null, 204),
        'api.github.com/repos/acme/app/hooks/77' => Http::response(null, 204),
    ]);

    $this->actingAs(connectUser($this->user))
        ->delete(route('sites.destroy', $this->site))
        ->assertRedirect(route('sites.index'));

    expect(Site::find($this->site->id))->toBeNull();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/repos/acme/app/keys/55');
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/repos/acme/app/hooks/77');
});

test('a github failure does not block deleting the site', function () {
    Http::fake([
        'api.github.com/repos/acme/app/hooks/77' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/repos/acme/app/keys/55' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $this->actingAs(connectUser($this->user))
        ->delete(route('sites.destroy', $this->site))
        ->assertRedirect(route('sites.index'));

    expect(Site::find($this->site->id))->toBeNull();

    // Both deletes must be attempted independently — one failing must not
    // stop the other from being tried.
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/repos/acme/app/keys/55');
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/repos/acme/app/hooks/77');
});

test('an unconnected user deletes the site without calling github', function () {
    Http::fake();

    $this->actingAs($this->user)
        ->delete(route('sites.destroy', $this->site))
        ->assertRedirect(route('sites.index'));

    expect(Site::find($this->site->id))->toBeNull();
    Http::assertNothingSent();
});

test('a site with no recorded github resources calls nothing', function () {
    Http::fake();

    $this->site->update(['github_key_id' => null, 'github_hook_id' => null]);

    $this->actingAs(connectUser($this->user))
        ->delete(route('sites.destroy', $this->site))
        ->assertRedirect(route('sites.index'));

    Http::assertNothingSent();
});

test('a site with a malformed repository slug deletes without calling github', function () {
    $this->site->update(['repository' => 'not-a-github-url']);

    Http::fake();

    $this->actingAs(connectUser($this->user))
        ->delete(route('sites.destroy', $this->site))
        ->assertRedirect(route('sites.index'));

    expect(Site::find($this->site->id))->toBeNull();
    Http::assertNothingSent();
});
