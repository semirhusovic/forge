<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\File;
use RuntimeException;

class LogFileManager
{
    /**
     * Hard byte ceiling on a single read. Laravel logs routinely reach
     * gigabytes; reading one whole into a PHP string would exhaust php-fpm's
     * memory_limit and take the panel down with it. Seeking to the tail keeps
     * a read O(1) in file size.
     */
    private const MAX_BYTES = 262144;

    private const MAX_LINES = 500;

    public function __construct(private ShellRunner $shell) {}

    /**
     * Log files in the site's storage/logs, newest first. Covers both the
     * single-file `stack` driver (laravel.log) and `daily` rotation
     * (laravel-YYYY-MM-DD.log) without the panel having to know which is used.
     *
     * @return list<array{name: string, size: int, modified_at: string}>
     */
    public function files(Site $site): array
    {
        $directory = $this->logsPath($site);

        if (! File::isDirectory($directory)) {
            return [];
        }

        $found = array_values(array_filter(
            File::files($directory),
            fn ($file) => strtolower($file->getExtension()) === 'log',
        ));

        usort($found, fn ($a, $b) => $b->getMTime() <=> $a->getMTime());

        $logs = [];

        foreach ($found as $file) {
            $logs[] = [
                'name' => $file->getFilename(),
                // SplFileInfo returns false when a stat fails (file removed
                // mid-listing by rotation); treat that as an empty file
                // rather than letting false leak into the payload.
                'size' => $file->getSize() ?: 0,
                'modified_at' => date(DATE_ATOM, $file->getMTime() ?: 0),
            ];
        }

        return $logs;
    }

    /**
     * Tail of one log file, or null when the site has no logs yet. Defaults to
     * the most recently modified file.
     *
     * @return array{name: string, content: string, size: int, truncated: bool}|null
     */
    public function tail(Site $site, ?string $name = null): ?array
    {
        $files = $this->files($site);
        $name = $this->resolveName($name, $files);

        if ($name === null) {
            return null;
        }

        $path = $this->logsPath($site).'/'.$name;
        $size = File::size($path);

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Could not open log file [{$name}].");
        }

        $offset = max(0, $size - self::MAX_BYTES);

        if ($offset > 0) {
            fseek($handle, $offset);
        }

        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        $truncated = $offset > 0;

        if ($truncated) {
            // Seeking mid-file almost always lands inside a line; drop that
            // fragment so the view never opens on half a stack trace frame.
            $newline = strpos($content, "\n");
            $content = $newline === false ? '' : substr($content, $newline + 1);
        }

        $lines = explode("\n", $content);

        if (count($lines) > self::MAX_LINES) {
            $lines = array_slice($lines, -self::MAX_LINES);
            $truncated = true;
        }

        return [
            'name' => $name,
            'content' => implode("\n", $lines),
            'size' => $size,
            'truncated' => $truncated,
        ];
    }

    /**
     * Empty a log file in place.
     *
     * Truncated rather than deleted on purpose: php-fpm and the site's queue
     * workers hold open handles to this inode. Unlinking it would leave them
     * writing to a file with no directory entry, so the log would silently
     * stop appearing until every one of them restarted.
     *
     * @throws RuntimeException when the site has no matching log file
     */
    public function clear(Site $site, ?string $name = null): string
    {
        $files = $this->files($site);
        $name = $this->resolveName($name, $files);

        if ($name === null) {
            throw new RuntimeException('No log file to clear.');
        }

        File::put($this->logsPath($site).'/'.$name, '');

        return $name;
    }

    /**
     * Resolve a caller-supplied filename against the actual directory listing.
     *
     * The listing is the whitelist: a name only survives if it is already
     * present, so a crafted `?log=../../.env` cannot escape storage/logs. Any
     * unknown name falls back to the newest file rather than erroring.
     *
     * @param  list<array{name: string, size: int, modified_at: string}>  $files
     */
    private function resolveName(?string $name, array $files): ?string
    {
        $names = array_column($files, 'name');

        if ($name !== null && in_array($name, $names, true)) {
            return $name;
        }

        return $names[0] ?? null;
    }

    private function logsPath(Site $site): string
    {
        return $this->shell->isFake()
            ? storage_path("app/fake-sites/{$site->domain}-logs")
            : $site->root_path.'/storage/logs';
    }
}
