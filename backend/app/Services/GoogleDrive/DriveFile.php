<?php

declare(strict_types=1);

namespace App\Services\GoogleDrive;

use Google\Service\Drive\DriveFile as GoogleDriveFile;

/**
 * A Drive file reduced to the handful of facts this application stores.
 *
 * Keeping Google's model out of the rest of the codebase means the storage
 * layer stays swappable without a rewrite everywhere else.
 */
final readonly class DriveFile
{
    public function __construct(
        public string $id,
        public string $name,
        public string $mimeType,
        public ?int $size = null,
        public ?string $webViewLink = null,
        public ?string $thumbnailLink = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $durationMs = null,
        public ?string $md5 = null,
    ) {}

    public static function fromGoogle(GoogleDriveFile $file): self
    {
        $image = $file->getImageMediaMetadata();
        $video = $file->getVideoMediaMetadata();

        $width = $image?->getWidth() ?? $video?->getWidth();
        $height = $image?->getHeight() ?? $video?->getHeight();

        /*
         | Drive reports the stored pixel dimensions plus a separate rotation
         | flag. Swap the axes so callers get the dimensions as displayed.
         */
        if ($image !== null && in_array($image->getRotation(), [90, 270], true)) {
            [$width, $height] = [$height, $width];
        }

        $duration = $video?->getDurationMillis();

        return new self(
            id: $file->getId(),
            name: $file->getName(),
            mimeType: (string) $file->getMimeType(),
            size: $file->getSize() !== null ? (int) $file->getSize() : null,
            webViewLink: $file->getWebViewLink(),
            thumbnailLink: $file->getThumbnailLink(),
            width: $width !== null ? (int) $width : null,
            height: $height !== null ? (int) $height : null,
            durationMs: $duration !== null ? (int) $duration : null,
            md5: $file->getMd5Checksum(),
        );
    }
}
