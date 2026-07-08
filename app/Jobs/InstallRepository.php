<?php

namespace App\Jobs;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\ApacheManager;
use App\Services\ShellRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class InstallRepository implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public Site $site) {}

    public function handle(ShellRunner $shell, ApacheManager $apache): void
    {
        $site = $this->site;
        $site->update(['status' => SiteStatus::Installing, 'provision_log' => '']);

        $log = fn (string $chunk) => $site->appendProvisionLog($chunk);
        $php = config('forge.php_binary');

        try {
            $shell->runOrFail(sprintf(
                'git clone --branch %s %s %s',
                escapeshellarg($site->branch),
                escapeshellarg($site->cloneUrl()),
                escapeshellarg($site->root_path),
            ), onOutput: $log);

            $shell->run('cp .env.example .env', cwd: $site->root_path, onOutput: $log);
            $shell->runOrFail('composer install --no-dev --no-interaction --prefer-dist', cwd: $site->root_path, timeout: 1800, onOutput: $log);
            $shell->run("{$php} artisan key:generate --force", cwd: $site->root_path, onOutput: $log);

            $apache->installVhost($site, $log);

            $site->update(['status' => SiteStatus::Installed]);
            $site->appendProvisionLog("\nInstall complete.\n");
        } catch (Throwable $e) {
            $site->appendProvisionLog("\nINSTALL FAILED: {$e->getMessage()}\n");
            $site->update(['status' => SiteStatus::Failed]);
        }
    }
}
