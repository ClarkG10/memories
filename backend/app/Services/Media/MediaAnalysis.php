<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * What a file turned out to be once its bytes were examined — as opposed to
 * what the browser claimed it was.
 */
final readonly class MediaAnalysis
{
    public function __construct(
        public string $mimeType,
        public string $type,
        public string $extension,
        public int $size,
        public string $checksum,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $durationMs = null,
        public ?string $placeholder = null,
    ) {}

    public function isImage(): bool
    {
        return $this->type === 'image';
    }

    public function isVideo(): bool
    {
        return $this->type === 'video';
    }
}
