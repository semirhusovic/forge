<?php

namespace App\Services;

class ShellResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
