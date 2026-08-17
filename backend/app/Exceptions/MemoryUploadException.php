<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\GoogleDrive\GoogleDriveException;
use RuntimeException;
use Throwable;

/**
 * A memory that could not be saved, phrased for the person who was trying to
 * save it. Carries whether trying again is likely to help.
 */
class MemoryUploadException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = true,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromDrive(GoogleDriveException $e): self
    {
        if ($e->isQuotaExhausted()) {
            return new self(
                "There's no space left in the connected Google Drive, so this memory wasn't saved. "
                .'Free up some room and try again.',
                retryable: false,
                previous: $e,
            );
        }

        return new self(
            "We couldn't finish uploading your memory. It hasn't been added yet — please try again.",
            retryable: $e->isRetryable(),
            previous: $e,
        );
    }
}
