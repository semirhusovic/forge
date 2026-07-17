<?php

use App\Jobs\DeploySite;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['forge.fake_shell' => true]);
    Queue::fake();

    $this->site = Site::create([
        'domain' => 'app.example.com',
        'repository' => 'git@github.com:acme/app.git',
        'branch' => 'main',
        'root_path' => '/home/forge/app.example.com',
        'webhook_token' => str_repeat('a', 48),
        'deploy_script' => 'echo deploy',
        'status' => 'installed',
        'auto_deploy' => true,
        'php_version' => '8.4',
    ]);
});

test('a json webhook push to the site branch queues a deploy', function () {
    $this->postJson($this->site->webhookUrl(), ['ref' => 'refs/heads/main'])
        ->assertOk()
        ->assertJson(['status' => 'queued']);

    Queue::assertPushed(DeploySite::class);
});

test('a form-encoded webhook push queues a deploy', function () {
    // GitHub's default webhook content type wraps the JSON body in a
    // `payload` form field.
    $this->post($this->site->webhookUrl(), [
        'payload' => json_encode(['ref' => 'refs/heads/main']),
    ])
        ->assertOk()
        ->assertJson(['status' => 'queued']);

    Queue::assertPushed(DeploySite::class);
});

test('pushes to other branches are ignored', function () {
    $this->postJson($this->site->webhookUrl(), ['ref' => 'refs/heads/develop'])
        ->assertOk()
        ->assertJson(['status' => 'ignored', 'reason' => 'branch mismatch']);

    Queue::assertNothingPushed();
});
