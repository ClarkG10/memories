<?php

declare(strict_types=1);

namespace App\Services\GoogleDrive;

use Google\Service\Exception as GoogleServiceException;
use RuntimeException;
use Throwable;

/**
 * Everything that can go wrong between here and Drive, classified by what the
 * caller should do about it: give up, try again later, or tell the owner their
 * Drive is full.
 */
class GoogleDriveException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $reason = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function from(Throwable $e, string $context): self
    {
        if ($e instanceof GoogleServiceException) {
            $errors = $e->getErrors() ?? [];
            $reason = $errors[0]['reason'] ?? null;

            return new self(
                sprintf('%s: %s', $context, $e->getMessage()),
                $e->getCode(),
                is_string($reason) ? $reason : null,
                $e,
            );
        }

        return new self(sprintf('%s: %s', $context, $e->getMessage()), 0, null, $e);
    }

    /**
     * The file is not there. For a delete that is success; for a read it is a
     * sign the catalogue and Drive have drifted apart.
     */
    public function isNotFound(): bool
    {
        return $this->status === 404 || $this->reason === 'notFound';
    }

    /**
     * Out of Drive space, or a service account with no quota of its own. No
     * amount of retrying fixes this — the owner has to act.
     */
    public function isQuotaExhausted(): bool
    {
        return in_array($this->reason, [
            'storageQuotaExceeded',
            'quotaExceeded',
            'teamDriveFileLimitExceeded',
        ], true);
    }

    /**
     * Transient: rate limits, backend hiccups, network trouble.
     */
    public function isRetryable(): bool
    {
        if ($this->isQuotaExhausted()) {
            return false;
        }

        if (in_array($this->reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'backendError'], true)) {
            return true;
        }

        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }
}
