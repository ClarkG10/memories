<?php

declare(strict_types=1);

namespace App\Services\Media;

use RuntimeException;

/**
 * Thrown when a file is not something this archive can hold — carrying a
 * message written for the person who chose the file, not for a log.
 */
class UnsupportedMediaException extends RuntimeException
{
    public static function type(string $detected): self
    {
        return new self(
            "That file type isn't supported ({$detected}). Photos and videos only."
        );
    }

    public static function unreadable(): self
    {
        return new self("We couldn't read that file. It may have been corrupted on the way up.");
    }

    public static function tooLarge(string $type, int $size, int $limit): self
    {
        return new self(sprintf(
            'That %s is %s, which is over the %s limit.',
            $type,
            self::humanBytes($size),
            self::humanBytes($limit),
        ));
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $power > 1 ? 1 : 0).' '.$units[$power];
    }
}
