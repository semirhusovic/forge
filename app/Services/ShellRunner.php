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
        $command = $this->nonInteractiveSudo($command);

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
     * The exception message includes the full command and its output, which
     * may end up in logs — never embed secrets in command strings.
     *
     * @throws RuntimeException when the command exits non-zero
     */
    public function runOrFail(string $command, ?string $cwd = null, int $timeout = 1800, ?callable $onOutput = null): ShellResult
    {
        $result = $this->run($command, $cwd, $timeout, $onOutput);

        if (! $result->successful()) {
            throw new RuntimeException(
                "Command failed ({$result->exitCode}): {$command}\n{$result->output}".$this->sudoHint($result->output)
            );
        }

        return $result;
    }

    /**
     * Force `sudo` to run non-interactively so a missing NOPASSWD grant fails
     * immediately instead of blocking on a password prompt with no terminal.
     */
    private function nonInteractiveSudo(string $command): string
    {
        return preg_replace('/(^|\s)sudo\s+(?!-n\b)/', '$1sudo -n ', $command) ?? $command;
    }

    /**
     * When sudo refused for lack of a password, the real cause is almost always
     * that this process is not running as the `forge` user (whose NOPASSWD
     * rules live in /etc/sudoers.d/forge-panel). Point the operator at that.
     */
    private function sudoHint(string $output): string
    {
        if (! str_contains($output, 'password is required') && ! str_contains($output, 'a terminal is required')) {
            return '';
        }

        return "\n\nHINT: passwordless sudo was refused. Run the queue worker as the 'forge' user "
            .'(systemd: User=forge) so the NOPASSWD rules in /etc/sudoers.d/forge-panel apply. '
            .'Verify with: sudo -l -U forge';
    }

    /**
     * Write contents to a root-owned path: write a temp file as the app user,
     * then sudo cp it into place (cp targets are whitelisted in sudoers).
     */
    public function writeAsRoot(string $contents, string $destination): void
    {
        if ($this->isFake()) {
            // Contents may hold secrets (.env files) — log size only.
            Log::info("[fake-shell] write {$destination}", ['bytes' => strlen($contents)]);

            return;
        }

        $temp = tempnam(sys_get_temp_dir(), 'forge-');

        if ($temp === false) {
            throw new RuntimeException('Failed to create a temporary file for a privileged write.');
        }

        File::put($temp, $contents);

        try {
            $this->runOrFail(sprintf('sudo cp %s %s', escapeshellarg($temp), escapeshellarg($destination)));
            $this->runOrFail(sprintf('sudo chmod 644 %s', escapeshellarg($destination)));
        } finally {
            File::delete($temp);
        }
    }
}
