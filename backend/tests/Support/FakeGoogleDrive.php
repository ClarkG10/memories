<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\GoogleDrive\DriveFile;
use Carbon\CarbonInterface;
use App\Services\GoogleDrive\GoogleDriveException;
use App\Services\GoogleDrive\GoogleDriveService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;

/**
 * A Google Drive that lives in an array.
 *
 * Every test needs Drive to behave in a specific way — succeed, run out of
 * space, fail on the third file, refuse to delete — and none of them should
 * touch the network. This records what was asked of it and can be told to
 * fail on cue.
 */
class FakeGoogleDrive extends GoogleDriveService
{
    /** @var array<string, array{name: string, folder: string, bytes: int, contents?: string}> */
    public array $files = [];

    /** How many times an original has been pulled back out of Drive. */
    public int $downloads = 0;

    /** @var array<int, string> */
    public array $deleted = [];

    /** @var array<int, string> */
    public array $uploadedNames = [];

    /** Fail the upload of the Nth file (1-based); null never fails. */
    public ?int $failUploadAtCall = null;

    public ?GoogleDriveException $uploadException = null;

    public ?GoogleDriveException $deleteException = null;

    /** Simulates Drive being unreachable when an original is fetched back. */
    public ?GoogleDriveException $downloadException = null;

    public bool $configured = true;

    /** Forces every upload to come back with the same id, to provoke a clash. */
    public ?string $fixedFileId = null;

    private int $uploadCalls = 0;

    public function __construct()
    {
        // Deliberately does not call the parent constructor: there is no
        // client and no cache, because nothing here talks to Google.
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function uploadFile(
        string $localPath,
        string $name,
        string $mimeType,
        string $folderId,
        ?callable $onProgress = null,
    ): DriveFile {
        $this->uploadCalls++;

        if ($this->failUploadAtCall !== null && $this->uploadCalls === $this->failUploadAtCall) {
            throw $this->uploadException ?? new GoogleDriveException('Drive is unavailable.', 503);
        }

        $id = $this->fixedFileId ?? 'drive-'.Str::random(24);
        $size = is_readable($localPath) ? (int) filesize($localPath) : 0;

        $this->files[$id] = ['name' => $name, 'folder' => $folderId, 'bytes' => $size];
        $this->uploadedNames[] = $name;

        if ($onProgress !== null) {
            $onProgress($size, $size);
        }

        return new DriveFile(
            id: $id,
            name: $name,
            mimeType: $mimeType,
            size: $size,
            webViewLink: "https://drive.google.com/file/d/{$id}/view",
            thumbnailLink: "https://lh3.googleusercontent.com/{$id}=s220",
        );
    }

    public function deleteFile(string $fileId): void
    {
        if ($this->deleteException !== null) {
            throw $this->deleteException;
        }

        unset($this->files[$fileId]);
        $this->deleted[] = $fileId;
    }

    public function getFile(string $fileId): ?DriveFile
    {
        if (! isset($this->files[$fileId])) {
            return null;
        }

        return new DriveFile(
            id: $fileId,
            name: $this->files[$fileId]['name'],
            mimeType: 'image/jpeg',
            size: $this->files[$fileId]['bytes'],
        );
    }

    public function download(string $fileId, ?string $range = null): ResponseInterface
    {
        $this->downloads++;

        if ($this->downloadException !== null) {
            throw $this->downloadException;
        }

        if (! isset($this->files[$fileId])) {
            return new Response(404);
        }

        $body = $this->files[$fileId]['contents']
            ?? str_repeat('x', max(1, $this->files[$fileId]['bytes']));

        if ($range === null) {
            return new Response(200, ['Content-Length' => (string) strlen($body)], $body);
        }

        return new Response(206, [
            'Content-Length' => (string) strlen($body),
            'Content-Range' => 'bytes 0-'.(strlen($body) - 1).'/'.strlen($body),
        ], $body);
    }

    public function downloadUrl(string $url): ResponseInterface
    {
        return new Response(200, [], 'thumbnail-bytes');
    }

    public function thumbnailUrl(string $fileId): ?string
    {
        return isset($this->files[$fileId])
            ? "https://lh3.googleusercontent.com/{$fileId}=s220"
            : null;
    }

    public function folderForMedia(string $type, CarbonInterface $date): string
    {
        // Mirrors the real layout closely enough that tests can assert on the
        // path a file was filed under.
        return "folder-{$type}-{$date->format('Y')}-{$date->format('m')}";
    }

    public function rootFolderId(): string
    {
        return 'folder-root';
    }

    public function findOrCreateFolder(string $name, ?string $parentId): string
    {
        return 'folder-'.Str::slug($name);
    }

    /**
     * @return array{email: string|null, limit: int|null, usage: int|null}
     */
    public function about(): array
    {
        return ['email' => 'archive@example.com', 'limit' => 15_000_000_000, 'usage' => 1_000_000];
    }

    public function uploadCount(): int
    {
        return $this->uploadCalls;
    }
}
