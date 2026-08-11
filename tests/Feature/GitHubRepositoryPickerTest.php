<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->github_token = 'gho_secret';
    $this->user->github_login = 'octocat';
    $this->user->save();
});

function fakeRepositories(array $names): void
{
    Http::fake([
        'api.github.com/user/repos*' => Http::response(
            collect($names)->map(fn (string $name): array => [
                'full_name' => $name,
                'private' => false,
                'default_branch' => 'main',
            ])->all(),
        ),
    ]);
}

test('the repository list requires a github connection', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('github.repositories'))
        ->assertForbidden();
});

test('repositories are returned for an empty query', function () {
    fakeRepositories(['acme/app', 'acme/site']);

    $this->actingAs($this->user)
        ->getJson(route('github.repositories'))
        ->assertOk()
        ->assertJsonCount(2, 'repositories')
        ->assertJsonPath('repositories.0.full_name', 'acme/app');
});

test('the query filters the list case insensitively', function () {
    fakeRepositories(['acme/app', 'acme/Website', 'other/thing']);

    $this->actingAs($this->user)
        ->getJson(route('github.repositories', ['q' => 'WEB']))
        ->assertOk()
        ->assertJsonCount(1, 'repositories')
        ->assertJsonPath('repositories.0.full_name', 'acme/Website');
});

test('the cached list means two searches cost one github call', function () {
    fakeRepositories(['acme/app', 'acme/site']);

    $this->actingAs($this->user)->getJson(route('github.repositories', ['q' => 'app']))->assertOk();
    $this->actingAs($this->user)->getJson(route('github.repositories', ['q' => 'site']))->assertOk();

    Http::assertSentCount(1);
});

test('branches are returned with the default branch', function () {
    Http::fake([
        'api.github.com/repos/acme/app/branches*' => Http::response([['name' => 'main'], ['name' => 'develop']]),
        'api.github.com/repos/acme/app' => Http::response(['default_branch' => 'develop']),
    ]);

    $this->actingAs($this->user)
        ->getJson(route('github.branches', ['repository' => 'acme/app']))
        ->assertOk()
        ->assertJson(['branches' => ['main', 'develop'], 'default_branch' => 'develop']);
});

test('a malformed repository slug is rejected', function () {
    $this->actingAs($this->user)
        ->getJson(route('github.branches', ['repository' => 'not-a-slug']))
        ->assertStatus(422);
});

test('a github failure is reported as a readable error', function () {
    Http::fake([
        'api.github.com/repos/acme/app/branches*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $this->actingAs($this->user)
        ->getJson(route('github.branches', ['repository' => 'acme/app']))
        ->assertStatus(422)
        ->assertJson(['message' => 'Not Found']);
});
