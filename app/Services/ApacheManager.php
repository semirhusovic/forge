<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\File;
use RuntimeException;

class ApacheManager
{
    public function __construct(private ShellRunner $shell) {}

    /**
     * Current on-disk vhost, or the freshly rendered template when the site
     * hasn't been installed yet. The file is world-readable (0644), so this
     * reads it directly without sudo.
     */
    public function readVhost(Site $site): string
    {
        $path = $this->vhostPath($site);

        return File::exists($path)
            ? File::get($path)
            : view('server.vhost', ['site' => $site])->render();
    }

    /**
     * Replace the site's vhost with operator-edited config. configtest gates
     * the reload; a config that fails is rolled back to the previous content so
     * a bad edit can never leave Apache unable to reload later.
     *
     * @throws RuntimeException when the new config fails configtest
     */
    public function updateVhost(Site $site, string $conf): void
    {
        $path = $this->vhostPath($site);
        $previous = File::exists($path) ? File::get($path) : null;

        $this->writeVhost($conf, $path);

        $test = $this->shell->run('sudo apache2ctl configtest');

        if (! $test->successful()) {
            if ($previous !== null) {
                $this->writeVhost($previous, $path);
            }

            throw new RuntimeException("Apache configtest failed, changes reverted:\n{$test->output}");
        }

        $this->shell->runOrFail('sudo systemctl reload apache2');
    }

    /**
     * Write and enable the HTTP vhost. configtest gates the reload; on
     * failure the site is disabled again so Apache keeps serving.
     *
     * @throws RuntimeException
     */
    public function installVhost(Site $site, ?callable $onOutput = null): void
    {
        $conf = view('server.vhost', ['site' => $site])->render();

        $this->shell->writeAsRoot($conf, "/etc/apache2/sites-available/{$site->domain}.conf");
        $this->shell->runOrFail(sprintf('sudo a2ensite %s', escapeshellarg("{$site->domain}.conf")), onOutput: $onOutput);

        $test = $this->shell->run('sudo apache2ctl configtest', onOutput: $onOutput);

        if (! $test->successful()) {
            $this->shell->run(sprintf('sudo a2dissite %s', escapeshellarg("{$site->domain}.conf")));

            throw new RuntimeException("Apache configtest failed, site disabled again:\n{$test->output}");
        }

        $this->shell->runOrFail('sudo systemctl reload apache2', onOutput: $onOutput);
    }

    /**
     * Best-effort removal of both vhosts: certbot --apache creates a second
     * {domain}-le-ssl.conf, and its cert must be deleted too or the renewal
     * timer keeps the removed site alive on 443 (then fails noisily forever).
     */
    public function removeVhost(Site $site): void
    {
        $this->shell->run(sprintf('sudo a2dissite %s', escapeshellarg("{$site->domain}.conf")));
        $this->shell->run(sprintf('sudo a2dissite %s', escapeshellarg("{$site->domain}-le-ssl.conf")));
        $this->shell->run(sprintf('sudo rm %s', escapeshellarg("/etc/apache2/sites-available/{$site->domain}.conf")));
        $this->shell->run(sprintf('sudo rm %s', escapeshellarg("/etc/apache2/sites-available/{$site->domain}-le-ssl.conf")));

        if ($site->ssl_enabled) {
            $this->shell->run(sprintf('sudo certbot delete --cert-name %s --non-interactive', escapeshellarg($site->domain)));
        }

        $this->shell->run('sudo systemctl reload apache2');
    }

    private function vhostPath(Site $site): string
    {
        return $this->shell->isFake()
            ? storage_path("app/fake-sites/{$site->domain}.conf")
            : "/etc/apache2/sites-available/{$site->domain}.conf";
    }

    /**
     * The real vhost is root-owned in /etc/apache2, so it goes through the
     * sudo-cp path (whitelisted in sudoers); in fake mode the file is written
     * directly so the editor round-trips on a dev machine.
     */
    private function writeVhost(string $conf, string $path): void
    {
        if ($this->shell->isFake()) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $conf);

            return;
        }

        $this->shell->writeAsRoot($conf, $path);
    }
}
