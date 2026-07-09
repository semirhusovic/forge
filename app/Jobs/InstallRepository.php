<?php

namespace App\Jobs;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\ApacheManager;
use App\Services\ShellRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\File;
use Throwable;

class InstallRepository implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public Site $site) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('install-site:'.$this->site->id))->dontRelease(),
        ];
    }

    public function handle(ShellRunner $shell, ApacheManager $apache): void
    {
        $site = $this->site;
        $site->update(['status' => SiteStatus::Installing, 'provision_log' => '']);

        $log = fn (string $chunk) => $site->appendProvisionLog($chunk);
        $php = config('forge.php_binary');

        try {
            if (! $shell->isFake() && File::isDirectory($site->root_path)) {
                // A previous failed install leaves a partial clone; git clone refuses
                // non-empty targets, so retries must start clean.
                File::deleteDirectory($site->root_path);
            }

            $shell->runOrFail(sprintf(
                'git clone --branch %s %s %s',
                escapeshellarg($site->branch),
                escapeshellarg($site->cloneUrl()),
                escapeshellarg($site->root_path),
            ), onOutput: $log);

            $shell->run('cp .env.example .env', cwd: $site->root_path, onOutput: $log);
            $shell->runOrFail('composer install --no-dev --no-interaction --prefer-dist', cwd: $site->root_path, timeout: 1800, onOutput: $log);
            $shell->run(escapeshellarg($php).' artisan key:generate --force', cwd: $site->root_path, onOutput: $log);

            $apache->installVhost($site, $log);

            $site->update(['status' => SiteStatus::Installed]);
            $site->appendProvisionLog("\nInstall complete.\n");
        } catch (Throwable $e) {
            $site->appendProvisionLog("\nINSTALL FAILED: {$e->getMessage()}\n");
            $site->update(['status' => SiteStatus::Failed]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->site->appendProvisionLog("\nINSTALL FAILED: ".($exception?->getMessage() ?? 'unknown error')."\n");
        $this->site->update(['status' => SiteStatus::Failed]);
    }
}
