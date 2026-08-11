<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    config([
        'services.github.client_id' => 'client-id',
        'services.github.client_secret' => 'client-secret',
    ]);

    $this->user = User::factory()->create();
});

test('the settings page renders', function () {
    // A full page (non-JSON) visit makes Inertia attempt SSR against the
    // Vite dev server when a stale `public/hot` file is present locally;
    // stub it so that unrelated, environment-dependent call doesn't trip
    // preventStrayRequests().
    Http::fake(['*/__inertia_ssr' => Http::response(null, 204)]);

    $this->actingAs($this->user)
        ->get(route('github.edit'))
        ->assertOk();
});

test('connecting redirects to github with the requested scopes', function () {
    $response = $this->actingAs($this->user)->post(route('github.connect'));

    $response->assertRedirectContains('https://github.com/login/oauth/authorize')
        ->assertRedirectContains('client_id=client-id')
        ->assertRedirectContains('scope=repo%2Cadmin%3Arepo_hook');

    expect(session('github_oauth_state'))->toBeString();
});

test('connecting without credentials configured explains what is missing', function () {
    config(['services.github.client_id' => null]);

    $this->actingAs($this->user)
        ->post(route('github.connect'))
        ->assertSessionHas('error');
});

test('the callback stores the token, login and avatar', function () {
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_token']),
        'api.github.com/user' => Http::response(['login' => 'octocat', 'avatar_url' => 'https://example.com/a.png']),
    ]);

    $this->actingAs($this->user)
        ->withSession(['github_oauth_state' => 'state-value'])
        ->get(route('github.callback', ['code' => 'the-code', 'state' => 'state-value']))
        ->assertRedirect(route('github.edit'))
        ->assertSessionHas('success');

    $user = $this->user->fresh();

    expect($user->github_token)->toBe('gho_token')
        ->and($user->github_login)->toBe('octocat')
        ->and($user->github_avatar_url)->toBe('https://example.com/a.png')
        ->and($user->github_connected_at)->not->toBeNull();
});

test('a mismatched state is rejected', function () {
    Http::fake();

    $this->actingAs($this->user)
        ->withSession(['github_oauth_state' => 'state-value'])
        ->get(route('github.callback', ['code' => 'the-code', 'state' => 'forged']))
        ->assertForbidden();

    expect($this->user->fresh()->hasGitHubConnection())->toBeFalse();
    Http::assertNothingSent();
});

test('a callback with no session state at all is rejected', function () {
    Http::fake();

    $this->actingAs($this->user)
        ->get(route('github.callback', ['code' => 'the-code', 'state' => 'anything']))
        ->assertForbidden();

    expect($this->user->fresh()->hasGitHubConnection())->toBeFalse();
    Http::assertNothingSent();
});

test('a callback with a missing code query param stores nothing', function () {
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['error_description' => 'The code is missing.']),
    ]);

    $this->actingAs($this->user)
        ->withSession(['github_oauth_state' => 'state-value'])
        ->get(route('github.callback', ['state' => 'state-value']))
        ->assertRedirect(route('github.edit'))
        ->assertSessionHas('error');

    expect($this->user->fresh()->hasGitHubConnection())->toBeFalse();
});

test('a failed viewer lookup after a successful token exchange stores nothing', function () {
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_token']),
        'api.github.com/user' => Http::response(['message' => 'Internal Server Error'], 500),
    ]);

    $this->actingAs($this->user)
        ->withSession(['github_oauth_state' => 'state-value'])
        ->get(route('github.callback', ['code' => 'the-code', 'state' => 'state-value']))
        ->assertRedirect(route('github.edit'))
        ->assertSessionHas('error');

    expect($this->user->fresh()->hasGitHubConnection())->toBeFalse();
});

test('a failed token exchange stores nothing', function () {
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['error_description' => 'The code is expired.']),
    ]);

    $this->actingAs($this->user)
        ->withSession(['github_oauth_state' => 'state-value'])
        ->get(route('github.callback', ['code' => 'the-code', 'state' => 'state-value']))
        ->assertRedirect(route('github.edit'))
        ->assertSessionHas('error');

    expect($this->user->fresh()->hasGitHubConnection())->toBeFalse();
});

test('disconnecting clears the connection', function () {
    $this->user->github_token = 'gho_token';
    $this->user->github_login = 'octocat';
    $this->user->save();

    $this->actingAs($this->user)
        ->delete(route('github.disconnect'))
        ->assertRedirect(route('github.edit'));

    expect($this->user->fresh()->hasGitHubConnection())->toBeFalse();
});

test('an inertia xhr connect returns a client-side location instead of a cross-origin redirect', function () {
    // A 302 to github.com is followed by the XHR as a cross-origin fetch and
    // blocked by CORS. Inertia's 409 + X-Inertia-Location makes the client
    // navigate the whole page instead.
    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
        ->post(route('github.connect'));

    $response->assertStatus(409);

    expect($response->headers->get('X-Inertia-Location'))
        ->toStartWith('https://github.com/login/oauth/authorize')
        ->toContain('client_id=client-id');

    expect(session('github_oauth_state'))->toBeString();
});
