<?php

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['forge.fake_shell' => true]);

    $this->site = Site::create([
        'domain' => 'app.example.com',
        'repository' => 'git@github.com:acme/app.git',
        'branch' => 'main',
        'root_path' => '/home/forge/app.example.com',
        'webhook_token' => str_repeat('a', 48),
        'deploy_script' => 'echo deploy',
        'status' => 'installed',
        'php_version' => '8.4',
    ]);
});

test('a queue worker can be created', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('sites.workers.store', $this->site), ['command' => 'queue:work --tries=3'])
        ->assertSessionHasNoErrors();

    expect($this->site->workers()->sole()->command)->toBe('queue:work --tries=3');
});

test('an inertia ssr worker can be created', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('sites.workers.store', $this->site), ['command' => 'inertia:start-ssr'])
        ->assertSessionHasNoErrors();

    expect($this->site->workers()->sole()->command)->toBe('inertia:start-ssr');
});

test('arbitrary artisan commands are rejected', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('sites.workers.store', $this->site), ['command' => 'tinker'])
        ->assertSessionHasErrors('command');

    expect($this->site->workers()->count())->toBe(0);
});
