<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\ShellRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class IssueCertificate implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public Site $site) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('issue-cert:'.$this->site->id))->dontRelease(),
        ];
    }

    public function handle(ShellRunner $shell): void
    {
        $site = $this->site;
        $site->appendProvisionLog("\n--- Issuing SSL certificate ---\n");

        // --force-renewal so a manual re-issue replaces a still-valid cert
        // instead of certbot exiting 0 with "not yet due for renewal".
        $result = $shell->run(sprintf(
            'sudo certbot --apache --non-interactive --agree-tos --redirect --force-renewal -m %s -d %s',
            escapeshellarg((string) config('forge.certbot_email')),
            escapeshellarg($site->domain),
        ), timeout: 570, onOutput: fn (string $chunk) => $site->appendProvisionLog($chunk));

        if ($result->successful()) {
            // Certbot's own systemd timer renews; 90 days is Let's Encrypt's validity.
            $site->update(['ssl_enabled' => true, 'ssl_expires_at' => now()->addDays(90)]);
            $site->appendProvisionLog("\nSSL enabled.\n");
        } else {
            $site->appendProvisionLog("\nSSL ISSUANCE FAILED.\n");
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->site->appendProvisionLog(
            "\nSSL ISSUANCE FAILED: ".($exception?->getMessage() ?? 'unknown error')."\n"
        );
    }
}
