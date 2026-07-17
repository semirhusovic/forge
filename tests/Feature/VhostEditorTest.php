<?php

use App\Models\Site;
use App\Models\User;
use App\Services\ApacheManager;
use App\Services\ShellResult;
use App\Services\ShellRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

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

    File::delete(storage_path("app/fake-sites/{$this->site->domain}.conf"));
});

test('reading a vhost with no file on disk falls back to the rendered template', function () {
    $conf = app(ApacheManager::class)->readVhost($this->site);

    expect($conf)
        ->toContain('ServerName app.example.com')
        ->toContain('proxy:unix:/run/php/php-fpm-forge-8.4.sock');
});

test('updating the vhost persists the new config and can be read back', function () {
    $custom = "<VirtualHost *:80>\n    ServerName app.example.com\n    # custom\n</VirtualHost>\n";

    app(ApacheManager::class)->updateVhost($this->site, $custom);

    expect(app(ApacheManager::class)->readVhost($this->site))->toBe($custom);
});

test('a config that fails apache configtest is rolled back to the previous content', function () {
    $manager = app(ApacheManager::class);
    $manager->updateVhost($this->site, "# good config\n");

    // Force configtest to fail so the bad write must be reverted.
    $shell = Mockery::mock(ShellRunner::class);
    $shell->shouldReceive('isFake')->andReturn(true);
    $shell->shouldReceive('run')->with('sudo apache2ctl configtest')
        ->andReturn(new ShellResult(1, 'Syntax error on line 2'));

    $manager = new ApacheManager($shell);

    expect(fn () => $manager->updateVhost($this->site, "# broken config\n"))
        ->toThrow(RuntimeException::class);

    // The good config is restored on disk.
    expect(File::get(storage_path("app/fake-sites/{$this->site->domain}.conf")))->toBe("# good config\n");
});

test('the vhost editor page saves through the controller', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('sites.vhost.update', $this->site), ['content' => "# edited\n"])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect(app(ApacheManager::class)->readVhost($this->site))->toContain('# edited');
});

test('editing the vhost requires an installed site', function () {
    $this->site->update(['status' => 'pending']);

    $this->actingAs(User::factory()->create())
        ->put(route('sites.vhost.update', $this->site), ['content' => "# edited\n"])
        ->assertStatus(422);
});
