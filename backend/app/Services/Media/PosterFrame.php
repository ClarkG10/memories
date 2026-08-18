<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A still frame supplied by the browser for a video it has just uploaded.
 *
 * Nothing here is trusted. The value arrives as a data URI from a page we do
 * not control, so it is decoded, checked to be a real image, and re-encoded
 * through GD before it is written anywhere. What gets stored is a JPEG this
 * server produced, not bytes a client sent.
 */
final class PosterFrame
{
    /** Refuse anything larger before decoding: a decode is where the cost is. */
    private const MAX_ENCODED_BYTES = 4 * 1024 * 1024;

    private const MAX_EDGE = 1280;

    /**
     * Turn a `data:image/...;base64,...` string into JPEG bytes, or null if it
     * is not something this server is willing to store.
     */
    public static function fromDataUri(?string $dataUri): ?string
    {
        if ($dataUri === null || $dataUri === '') {
            return null;
        }

        if (strlen($dataUri) > self::MAX_ENCODED_BYTES) {
            return null;
        }

        if (! preg_match('#^data:image/(jpeg|png|webp);base64,#i', $dataUri)) {
            return null;
        }

        $encoded = substr($dataUri, (int) strpos($dataUri, ',') + 1);
        $bytes = base64_decode($encoded, true);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        // The bytes must actually be an image, whatever the data URI claimed.
        $info = @getimagesizefromstring($bytes);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        if ($info[0] * $info[1] > MediaInspector::MAX_DECODE_PIXELS) {
            return null;
        }

        try {
            $image = @imagecreatefromstring($bytes);

            if ($image === false) {
                return null;
            }

            $width = imagesx($image);
            $target = min($width, self::MAX_EDGE);

            $rendition = ImageEditor::resizeToWidth($image, $target);

            return ImageEditor::toJpeg($rendition, 82);
        } catch (Throwable $e) {
            Log::warning('Could not use the poster frame the browser sent.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The tiny blur-up version, inlined into API responses so a video card has
     * something on screen before its poster has been fetched.
     */
    public static function placeholder(string $jpeg): ?string
    {
        try {
            $image = @imagecreatefromstring($jpeg);

            if ($image === false) {
                return null;
            }

            $thumb = ImageEditor::resizeToWidth(
                $image,
                (int) config('memories.derivatives.placeholder_width', 20),
            );

            return 'data:image/jpeg;base64,'.base64_encode(ImageEditor::toJpeg($thumb, 45));
        } catch (Throwable) {
            return null;
        }
    }
}
