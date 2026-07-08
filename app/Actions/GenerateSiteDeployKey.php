<?php

namespace App\Actions;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Services\ShellRunner;
use Illuminate\Support\Facades\File;

class GenerateSiteDeployKey
{
    public function __construct(private ShellRunner $shell) {}

    public function handle(Site $site): void
    {
        if ($this->shell->isFake()) {
            $site->update([
                'deploy_key_public' => "ssh-ed25519 AAAA-FAKE-KEY forge-site-{$site->id}",
                'status' => SiteStatus::KeyGenerated,
            ]);

            return;
        }

        $sshPath = config('forge.ssh_path');
        $keyFile = "{$sshPath}/site-{$site->id}";

        $this->shell->runOrFail(sprintf(
            'ssh-keygen -t ed25519 -N "" -C %s -f %s',
            escapeshellarg("forge-site-{$site->id}"),
            escapeshellarg($keyFile),
        ));

        File::append("{$sshPath}/config", implode("\n", [
            '',
            "Host {$site->gitHostAlias()}",
            '    HostName github.com',
            "    IdentityFile {$keyFile}",
            '    IdentitiesOnly yes',
            '',
        ]));

        $site->update([
            'deploy_key_public' => trim(File::get("{$keyFile}.pub")),
            'status' => SiteStatus::KeyGenerated,
        ]);
    }
}
