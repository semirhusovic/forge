<?php

namespace App\Services;

use App\Models\Site;

class SchedulerManager
{
    public function __construct(private ShellRunner $shell) {}

    public function enable(Site $site): void
    {
        $php = config('forge.php_binary');
        $cron = "* * * * * forge {$php} {$site->root_path}/artisan schedule:run >> /dev/null 2>&1\n";

        $this->shell->writeAsRoot($cron, $this->cronPath($site));
        $site->update(['has_scheduler' => true]);
    }

    /**
     * A leftover cron file keeps executing every minute, so removal must be
     * verified before the flag flips — unlike best-effort systemd cleanup.
     * `-f` keeps it idempotent; the sudoers whitelist includes the flag.
     */
    public function disable(Site $site): void
    {
        $this->shell->runOrFail('sudo rm -f '.escapeshellarg($this->cronPath($site)));
        $site->update(['has_scheduler' => false]);
    }

    private function cronPath(Site $site): string
    {
        return "/etc/cron.d/forge-site-{$site->id}";
    }
}
