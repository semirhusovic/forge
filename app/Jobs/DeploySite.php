<?php

namespace App\Jobs;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Services\ShellRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class DeploySite implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public Deployment $deployment) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('deploy-site:'.$this->deployment->site_id))
                ->releaseAfter(30)
                ->expireAfter(1800),
        ];
    }

    public function handle(ShellRunner $shell): void
    {
        $deployment = $this->deployment;
        $site = $deployment->site;

        $deployment->update(['status' => DeploymentStatus::Running, 'started_at' => now()]);

        $script = "set -e\n".$site->deploy_script;

        $result = $shell->run(
            $script,
            cwd: $site->root_path,
            onOutput: fn (string $chunk) => $deployment->appendOutput($chunk),
        );

        $commit = $shell->run("git log -1 --pretty=format:'%H|%s'", cwd: $site->root_path);

        if ($commit->successful() && str_contains($commit->output, '|')) {
            [$hash, $message] = explode('|', trim($commit->output), 2);
            $deployment->fill(['commit_hash' => $hash, 'commit_message' => $message]);
        }

        $deployment->fill([
            'status' => $result->successful() ? DeploymentStatus::Success : DeploymentStatus::Failed,
            'finished_at' => now(),
        ])->save();
    }
}
