<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SplFileObject;

/**
 * The log, without having to know where it is.
 *
 * The daily driver writes `laravel-2026-08-18.log`, not `laravel.log`, which
 * is exactly the file every tail command and log viewer looks for first. This
 * finds whichever file is actually being written to and shows the end of it,
 * so diagnosing something from a server console does not begin with guessing
 * a filename.
 */
class LogsCommand extends Command
{
    protected $signature = 'memories:logs
        {--lines=80 : How many entries to show}
        {--level= : Only entries at this level (error, warning, info, debug)}
        {--grep= : Only entries containing this text}
        {--files : List the log files instead of reading one}';

    protected $description = 'Show the most recent entries from the application log';

    public function handle(): int
    {
        $files = $this->logFiles();

        if ($files === []) {
            $this->components->warn('No log files yet in '.storage_path('logs'));
            $this->line('  Nothing has been written. That is either very good news or a permissions problem:');
            $this->line('  the directory must be writable by the web server user.');

            return self::SUCCESS;
        }

        if ($this->option('files')) {
            foreach ($files as $path) {
                $this->line(sprintf('  %-40s %8s  %s',
                    basename($path),
                    $this->human((int) filesize($path)),
                    date('Y-m-d H:i', (int) filemtime($path)),
                ));
            }

            return self::SUCCESS;
        }

        $newest = $files[0];
        $entries = $this->entries($newest);

        $level = strtolower((string) $this->option('level'));
        $needle = (string) $this->option('grep');

        if ($level !== '') {
            $entries = array_values(array_filter(
                $entries,
                fn (string $entry): bool => str_contains(strtolower($entry), '.'.$level.':'),
            ));
        }

        if ($needle !== '') {
            $entries = array_values(array_filter(
                $entries,
                fn (string $entry): bool => stripos($entry, $needle) !== false,
            ));
        }

        $lines = max(1, (int) $this->option('lines'));
        $entries = array_slice($entries, -$lines);

        $this->components->info(sprintf('%s — %d entr%s',
            basename($newest),
            count($entries),
            count($entries) === 1 ? 'y' : 'ies',
        ));

        if ($entries === []) {
            $this->line('  Nothing matching.');

            return self::SUCCESS;
        }

        foreach ($entries as $entry) {
            $this->line($this->colour(rtrim($entry)));
        }

        return self::SUCCESS;
    }

    /**
     * Every log file, newest first.
     *
     * @return array<int, string>
     */
    private function logFiles(): array
    {
        $files = glob(storage_path('logs').'/*.log') ?: [];

        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files;
    }

    /**
     * Whole entries, not lines: a stack trace is dozens of lines belonging to
     * one thing that went wrong, and splitting it loses the message at the top.
     *
     * @return array<int, string>
     */
    private function entries(string $path): array
    {
        $file = new SplFileObject($path);
        $entries = [];
        $current = '';

        while (! $file->eof()) {
            $line = (string) $file->fgets();

            // A new entry starts with its timestamp in brackets.
            if (preg_match('/^\[\d{4}-\d{2}-\d{2}[ T]/', $line) === 1) {
                if ($current !== '') {
                    $entries[] = $current;
                }

                $current = $line;

                continue;
            }

            $current .= $line;
        }

        if ($current !== '') {
            $entries[] = $current;
        }

        return $entries;
    }

    private function colour(string $entry): string
    {
        return match (true) {
            str_contains($entry, '.ERROR:'), str_contains($entry, '.CRITICAL:'),
            str_contains($entry, '.EMERGENCY:'), str_contains($entry, '.ALERT:') => "<fg=red>{$entry}</>",
            str_contains($entry, '.WARNING:') => "<fg=yellow>{$entry}</>",
            str_contains($entry, '.INFO:') => "<fg=default>{$entry}</>",
            default => "<fg=gray>{$entry}</>",
        };
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 1).$units[$power];
    }
}
