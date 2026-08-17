<?php

declare(strict_types=1);

namespace App\Services\Media;

use GdImage;
use Throwable;

/**
 * A very small GD wrapper: open a file, correct its orientation, scale it down.
 *
 * Deliberately thin — the archive only ever needs to shrink an original into a
 * few display sizes.
 */
final class ImageEditor
{
    /**
     * Decode an image and rotate it upright. Returns null for formats GD on
     * this host cannot read (HEIC, most notably).
     */
    public static function open(string $path): ?GdImage
    {
        $info = @getimagesize($path);

        if ($info === false) {
            return null;
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => (imagetypes() & IMG_WEBP) ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        if (! $image instanceof GdImage) {
            return null;
        }

        return self::applyExifOrientation($image, $path, $info[2]);
    }

    /**
     * Proportional downscale. Images already narrower than the target are
     * returned untouched rather than blown up.
     */
    public static function resizeToWidth(GdImage $source, int $targetWidth): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);

        $targetWidth = max(1, min($targetWidth, $width));
        $targetHeight = max(1, (int) round($height * ($targetWidth / $width)));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        // Flatten transparency onto white: everything is served as JPEG, and
        // an unflattened alpha channel comes out black.
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $canvas;
    }

    public static function toJpeg(GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    private static function applyExifOrientation(GdImage $image, string $path, int $imageType): GdImage
    {
        if ($imageType !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        try {
            $exif = @exif_read_data($path);
        } catch (Throwable) {
            return $image;
        }

        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => null,
        };

        // GdImage instances are released by the garbage collector; imagedestroy()
        // is a no-op and deprecated as of PHP 8.5.
        if ($rotated instanceof GdImage) {
            $image = $rotated;
        }

        // Orientations 2/4/5/7 are additionally mirrored.
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }
}
