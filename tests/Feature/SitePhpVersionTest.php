<?php

use App\Models\Site;
use App\Models\User;
use App\Services\SchedulerManager;
use App\Services\ShellRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['forge.fake_shell' => true]);
});

function makeSite(string $phpVersion): Site
{
    return Site::create([
        'domain' => 'app.example.com',
        'repository' => 'git@github.com:acme/app.git',
        'branch' => 'main',
        'root_path' => '/home/forge/app.example.com',
        'webhook_token' => str_repeat('a', 48),
        'deploy_script' => 'echo deploy',
        'php_version' => $phpVersion,
    ]);
}

test('site creation rejects php versions the server does not provide', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('sites.store'), [
            'domain' => 'app.example.com',
            'repository' => 'git@github.com:acme/app.git',
            'branch' => 'main',
            'php_version' => '7.4',
        ])
        ->assertSessionHasErrors('php_version');

    expect(Site::count())->toBe(0);
});

test('site creation stores the chosen php version and bakes it into the deploy script', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('sites.store'), [
            'domain' => 'app.example.com',
            'repository' => 'git@github.com:acme/app.git',
            'branch' => 'main',
            'php_version' => '8.3',
        ])
        ->assertSessionHasNoErrors();

    $site = Site::sole();

    expect($site->php_version)->toBe('8.3')
        ->and($site->phpBinary())->toBe('/usr/bin/php8.3')
        ->and($site->deploy_script)->toContain('/usr/bin/php8.3 /usr/bin/composer update')
        ->and($site->deploy_script)->toContain('/usr/bin/php8.3 artisan migrate --force');
});

test('vhost routes php through the fpm socket matching the site version', function () {
    $site = new Site(['domain' => 'app.example.com', 'root_path' => '/home/forge/app.example.com', 'web_root_suffix' => '/public', 'php_version' => '8.3']);

    expect(view('server.vhost', ['site' => $site])->render())
        ->toContain('proxy:unix:/run/php/php-fpm-forge-8.3.sock');
});

test('worker units run artisan on the site php binary', function () {
    $site = makeSite('8.3');
    $worker = $site->workers()->create(['command' => 'queue:work', 'status' => 'running']);

    expect(view('server.worker-unit', ['worker' => $worker, 'site' => $site])->render())
        ->toContain('ExecStart=/usr/bin/php8.3 artisan queue:work');
});

test('scheduler cron entries run artisan on the site php binary', function () {
    $site = makeSite('8.3');

    $shell = $this->mock(ShellRunner::class);
    $shell->shouldReceive('writeAsRoot')->once()->withArgs(
        fn (string $cron, string $path): bool => str_contains($cron, '/usr/bin/php8.3 /home/forge/app.example.com/artisan schedule:run')
            && $path === "/etc/cron.d/forge-site-{$site->id}"
    );

    app(SchedulerManager::class)->enable($site);

    expect($site->refresh()->has_scheduler)->toBeTrue();
});
