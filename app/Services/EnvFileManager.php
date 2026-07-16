<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\File;

class EnvFileManager
{
    public function __construct(private ShellRunner $shell) {}

    public function read(Site $site): string
    {
        $path = $this->path($site);

        return File::exists($path) ? File::get($path) : '';
    }

    public function write(Site $site, string $content): void
    {
        $path = $this->path($site);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);

        $this->shell->run(escapeshellarg($site->phpBinary()).' artisan optimize:clear', cwd: $site->root_path);
    }

    private function path(Site $site): string
    {
        return $this->shell->isFake()
            ? storage_path("app/fake-sites/{$site->domain}.env")
            : $site->root_path.'/.env';
    }
}
