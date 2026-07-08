<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class ShellRunner
{
    public function isFake(): bool
    {
        return (bool) config('forge.fake_shell');
    }

    /**
     * Run a shell command, streaming combined stdout/stderr to $onOutput.
     */
    public function run(string $command, ?string $cwd = null, int $timeout = 1800, ?callable $onOutput = null): ShellResult
    {
        if ($this->isFake()) {
            $line = '[fake-shell] $ '.$command."\n";
            Log::info($line, ['cwd' => $cwd]);
            if ($onOutput !== null) {
                $onOutput($line);
            }

            return new ShellResult(0, $line);
        }

        $output = '';

        $pending = Process::timeout($timeout);

        if ($cwd !== null) {
            $pending = $pending->path($cwd);
        }

        $result = $pending->run($command, function (string $type, string $buffer) use (&$output, $onOutput): void {
            $output .= $buffer;
            if ($onOutput !== null) {
                $onOutput($buffer);
            }
        });

        return new ShellResult($result->exitCode() ?? 1, $output);
    }

    /**
     * @throws RuntimeException when the command exits non-zero
     */
    public function runOrFail(string $command, ?string $cwd = null, int $timeout = 1800, ?callable $onOutput = null): ShellResult
    {
        $result = $this->run($command, $cwd, $timeout, $onOutput);

        if (! $result->successful()) {
            throw new RuntimeException("Command failed ({$result->exitCode}): {$command}\n{$result->output}");
        }

        return $result;
    }

    /**
     * Write contents to a root-owned path: write a temp file as the app user,
     * then sudo cp it into place (cp targets are whitelisted in sudoers).
     */
    public function writeAsRoot(string $contents, string $destination): void
    {
        if ($this->isFake()) {
            Log::info("[fake-shell] write {$destination}", ['contents' => $contents]);

            return;
        }

        $temp = tempnam(sys_get_temp_dir(), 'forge-');
        File::put($temp, $contents);

        try {
            $this->runOrFail(sprintf('sudo cp %s %s', escapeshellarg($temp), escapeshellarg($destination)));
            $this->runOrFail(sprintf('sudo chmod 644 %s', escapeshellarg($destination)));
        } finally {
            File::delete($temp);
        }
    }
}
