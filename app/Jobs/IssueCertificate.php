<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\ShellRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IssueCertificate implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public Site $site) {}

    public function handle(ShellRunner $shell): void
    {
        $site = $this->site;
        $site->appendProvisionLog("\n--- Issuing SSL certificate ---\n");

        $result = $shell->run(sprintf(
            'sudo certbot --apache --non-interactive --agree-tos --redirect -m %s -d %s',
            escapeshellarg((string) config('forge.certbot_email')),
            escapeshellarg($site->domain),
        ), onOutput: fn (string $chunk) => $site->appendProvisionLog($chunk));

        if ($result->successful()) {
            // Certbot's own systemd timer renews; 90 days is Let's Encrypt's validity.
            $site->update(['ssl_enabled' => true, 'ssl_expires_at' => now()->addDays(90)]);
            $site->appendProvisionLog("\nSSL enabled.\n");
        } else {
            $site->appendProvisionLog("\nSSL ISSUANCE FAILED.\n");
        }
    }
}
