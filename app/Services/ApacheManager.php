<?php

namespace App\Services;

use App\Models\Site;
use RuntimeException;

class ApacheManager
{
    public function __construct(private ShellRunner $shell) {}

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
}
