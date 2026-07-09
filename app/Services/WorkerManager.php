<?php

namespace App\Services;

use App\Models\Worker;

class WorkerManager
{
    public function __construct(private ShellRunner $shell) {}

    public function install(Worker $worker): void
    {
        $unit = view('server.worker-unit', ['worker' => $worker, 'site' => $worker->site])->render();

        $this->shell->writeAsRoot($unit, "/etc/systemd/system/{$worker->unitName()}");
        $this->shell->runOrFail('sudo systemctl daemon-reload');
        $this->shell->runOrFail("sudo systemctl enable --now {$worker->unitName()}");
    }

    public function restart(Worker $worker): void
    {
        $this->shell->runOrFail("sudo systemctl restart {$worker->unitName()}");
    }

    public function remove(Worker $worker): void
    {
        $this->shell->run("sudo systemctl disable --now {$worker->unitName()}");
        $this->shell->run("sudo rm /etc/systemd/system/{$worker->unitName()}");
        $this->shell->run('sudo systemctl daemon-reload');
    }
}
