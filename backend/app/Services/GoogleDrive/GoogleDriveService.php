<?php

declare(strict_types=1);

namespace App\Services\GoogleDrive;

use Carbon\CarbonInterface;
use Google\Http\MediaFileUpload;
use Google\Service\Drive\DriveFile as GoogleDriveFile;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The one place in the application that speaks to Google Drive.
 *
 * Everything above this class deals in file ids and streams; nothing above it
 * imports a Google namespace. Uploads and downloads are streamed chunk by
 * chunk, so a two-gigabyte video costs the same memory as a thumbnail.
 */
class GoogleDriveService
{
    private const FOLDER_MIME = 'application/vnd.google-apps.folder';

    /** Fields worth asking for; anything else is wasted payload. */
    private const FILE_FIELDS = 'id,name,mimeType,size,webViewLink,thumbnailLink,md5Checksum,imageMediaMetadata,videoMediaMetadata';

    public function __construct(
        private readonly GoogleDriveClientFactory $factory,
        private readonly CacheRepository $cache,
    ) {}

    public function isConfigured(): bool
    {
        return $this->factory->isConfigured();
    }

    /**
     * Stream a local file into Drive using a resumable upload.
     *
     * @param  callable(int, int): void|null  $onProgress  receives (bytesSent, totalBytes)
     *
     * @throws GoogleDriveException
     */
    public function uploadFile(
        string $localPath,
        string $name,
        string $mimeType,
        string $folderId,
        ?callable $onProgress = null,
    ): DriveFile {
        if (! is_readable($localPath)) {
            throw new GoogleDriveException("Upload source is not readable: {$localPath}");
        }

        $size = filesize($localPath);

        if ($size === false) {
            throw new GoogleDriveException("Could not determine the size of {$localPath}.");
        }

        $client = $this->factory->client();
        $drive = $this->factory->drive();
        $chunkSize = $this->uploadChunkSize();

        $metadata = new GoogleDriveFile([
            'name' => $name,
            'parents' => [$folderId],
        ]);

        $handle = null;
        $client->setDefer(true);

        try {
            /*
             | With defer enabled the client hands back the PSR-7 request
             | instead of executing it, which is what MediaFileUpload needs in
             | order to turn it into a resumable session. The declared return
             | type still says DriveFile, hence the annotation.
             |
             | @var \Psr\Http\Message\RequestInterface $request
             */
            $request = $drive->files->create($metadata, [
                'fields' => self::FILE_FIELDS,
                'supportsAllDrives' => true,
            ]);

            $media = new MediaFileUpload($client, $request, $mimeType, null, true, $chunkSize);
            $media->setFileSize($size);

            $handle = fopen($localPath, 'rb');

            if ($handle === false) {
                throw new GoogleDriveException("Could not open {$localPath} for reading.");
            }

            $uploaded = false;
            $sent = 0;

            while ($uploaded === false && ! feof($handle)) {
                $chunk = fread($handle, $chunkSize);

                if ($chunk === false) {
                    throw new GoogleDriveException("Failed while reading {$localPath}.");
                }

                // Each call ships exactly one chunk; only the final one comes
                // back as a file resource instead of false.
                $uploaded = $media->nextChunk($chunk);

                $sent += strlen($chunk);

                if ($onProgress !== null) {
                    $onProgress($sent, $size);
                }
            }

            if ($uploaded === false) {
                throw new GoogleDriveException(
                    'Drive accepted every chunk but never confirmed the upload.'
                );
            }

            $file = DriveFile::fromGoogle($uploaded);

            Log::info('Drive file created.', [
                'drive_file_id' => $file->id,
                'bytes' => $size,
                'mime_type' => $mimeType,
            ]);

            return $file;
        } catch (GoogleDriveException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw GoogleDriveException::from($e, "Uploading {$name} to Drive failed");
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            // setDefer is global to the client; leaving it on would turn every
            // later call into a bare Request object.
            $client->setDefer(false);
        }
    }

    /**
     * Permanently remove a file. A file that is already gone counts as success
     * — the goal is "not in Drive", and it is not.
     *
     * @throws GoogleDriveException
     */
    public function deleteFile(string $fileId): void
    {
        try {
            $this->factory->drive()->files->delete($fileId, ['supportsAllDrives' => true]);

            Log::info('Drive file deleted.', ['drive_file_id' => $fileId]);
        } catch (Throwable $e) {
            $exception = GoogleDriveException::from($e, "Deleting Drive file {$fileId} failed");

            if ($exception->isNotFound()) {
                Log::info('Drive file was already gone.', ['drive_file_id' => $fileId]);

                return;
            }

            throw $exception;
        }
    }

    /**
     * @throws GoogleDriveException
     */
    public function getFile(string $fileId): ?DriveFile
    {
        try {
            $file = $this->factory->drive()->files->get($fileId, [
                'fields' => self::FILE_FIELDS,
                'supportsAllDrives' => true,
            ]);

            return DriveFile::fromGoogle($file);
        } catch (Throwable $e) {
            $exception = GoogleDriveException::from($e, "Reading Drive file {$fileId} failed");

            if ($exception->isNotFound()) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Download a file's bytes, optionally a byte range.
     *
     * The response body is a stream, never a string: video responses are
     * forwarded to the browser as they arrive so seeking works and memory
     * stays flat.
     *
     * @param  string|null  $range  a raw HTTP Range header value, e.g. "bytes=0-1048575"
     *
     * @throws GoogleDriveException
     */
    public function download(string $fileId, ?string $range = null): ResponseInterface
    {
        $http = $this->factory->client()->authorize();

        $url = sprintf(
            'https://www.googleapis.com/drive/v3/files/%s?alt=media&supportsAllDrives=true',
            rawurlencode($fileId),
        );

        $headers = [];

        if ($range !== null && $range !== '') {
            $headers['Range'] = $range;
        }

        try {
            return $http->request('GET', $url, [
                'stream' => true,
                'headers' => $headers,
                // Long videos stream for minutes; the read timeout applies per
                // read, not to the whole transfer.
                'timeout' => 0,
                /*
                 | Status codes are the caller's business here: a range request
                 | legitimately answers 206, and 416 has to reach the browser
                 | as 416 rather than as a server error.
                 */
                'http_errors' => false,
            ]);
        } catch (Throwable $e) {
            throw GoogleDriveException::from($e, "Downloading Drive file {$fileId} failed");
        }
    }

    /**
     * Move a file into a different folder.
     *
     * Drive has no notion of a path — a file simply belongs to one or more
     * parents — so moving is detaching from the old parent and attaching to
     * the new one in a single call.
     *
     * @throws GoogleDriveException
     */
    public function moveFile(string $fileId, string $folderId): void
    {
        try {
            $current = $this->factory->drive()->files->get($fileId, [
                'fields' => 'parents',
                'supportsAllDrives' => true,
            ]);

            $parents = $current->getParents() ?? [];

            if (in_array($folderId, $parents, true) && count($parents) === 1) {
                return;
            }

            $this->factory->drive()->files->update($fileId, new GoogleDriveFile, [
                'addParents' => $folderId,
                'removeParents' => implode(',', $parents),
                'fields' => 'id,parents',
                'supportsAllDrives' => true,
            ]);

            Log::info('Drive file moved.', ['drive_file_id' => $fileId, 'folder' => $folderId]);
        } catch (Throwable $e) {
            throw GoogleDriveException::from($e, "Moving Drive file {$fileId} failed");
        }
    }

    /**
     * Fetch an arbitrary Google-hosted URL with the archive's credentials
     * attached — specifically the thumbnail links returned by the Drive API,
     * which are not part of the REST surface but still require authorisation.
     *
     * @throws GoogleDriveException
     */
    public function downloadUrl(string $url): ResponseInterface
    {
        try {
            return $this->factory->client()->authorize()->request('GET', $url, [
                'stream' => false,
                'http_errors' => true,
            ]);
        } catch (Throwable $e) {
            throw GoogleDriveException::from($e, 'Fetching a Drive-hosted URL failed');
        }
    }

    /**
     * Drive's own thumbnail URL, refetched because the stored one expires.
     * Used for video posters, where there is no local frame to work from.
     */
    public function thumbnailUrl(string $fileId): ?string
    {
        return $this->getFile($fileId)?->thumbnailLink;
    }

    /**
     * Resolve — creating on demand — the folder a given media file belongs in:
     *
     *   Memory Archive/Images/2026/08 August
     *
     * The date here is the memory's own date: when the thing being remembered
     * happened, not when the file was uploaded and not what the camera wrote
     * into the file. A photograph from 2019 imported today belongs under 2019,
     * because that is where anyone looking for it would go.
     *
     * @throws GoogleDriveException
     */
    public function folderForMedia(string $type, CarbonInterface $date, ?string $album = null): string
    {
        /*
         | An album overrides the date layout entirely: everything belonging to
         | it sits together, photographs and videos side by side, which is the
         | whole point of naming one. Filenames still begin with the date, so
         | the folder is in chronological order without any subfolders.
         |
         | It hangs off an "Albums" parent so that an album called "Images"
         | cannot collide with the type folder of the same name.
         */
        if ($album !== null && $album !== '') {
            $albums = $this->findOrCreateFolder('Albums', $this->rootFolderId());

            return $this->findOrCreateFolder($album, $albums);
        }

        $segment = config("googledrive.folders.{$type}");

        if (! is_string($segment) || $segment === '') {
            throw new GoogleDriveException("No Drive folder is configured for media type [{$type}].");
        }

        $folderId = $this->findOrCreateFolder($segment, $this->rootFolderId());

        if (! config('googledrive.year_folders')) {
            return $folderId;
        }

        $folderId = $this->findOrCreateFolder($date->format('Y'), $folderId);

        if (config('googledrive.month_folders')) {
            $folderId = $this->findOrCreateFolder($this->monthFolderName($date), $folderId);
        }

        return $folderId;
    }

    /**
     * "08 August" — the number so Drive's name sort is calendar order, the
     * word so it is readable. Deliberately not localised: folder names should
     * not change because the application's locale did.
     */
    private function monthFolderName(CarbonInterface $date): string
    {
        $months = [
            1 => 'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];

        $month = (int) $date->format('n');

        return sprintf('%02d %s', $month, $months[$month]);
    }

    /**
     * The archive's top-level folder. Created by the app so that the narrow
     * drive.file scope is enough to see it again later.
     *
     * @throws GoogleDriveException
     */
    public function rootFolderId(): string
    {
        $configured = config('googledrive.root_folder_id');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $this->findOrCreateFolder((string) config('googledrive.root_folder_name'), null);
    }

    /**
     * @throws GoogleDriveException
     */
    public function findOrCreateFolder(string $name, ?string $parentId): string
    {
        $cacheKey = $this->folderCacheKey($name, $parentId);
        $cached = $this->cache->get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        /*
         | Two uploads starting together would otherwise each create their own
         | "2026" folder, silently splitting the year in half. The lock makes
         | folder creation a single-winner operation; the loser reads the
         | winner's id from the cache.
         */
        $lock = Cache::lock("drive:folder-lock:{$cacheKey}", 30);

        try {
            $lock->block(15);
        } catch (Throwable) {
            // Waited long enough that whoever held the lock has finished or
            // died. Fall through and resolve it ourselves — noted, because
            // two winners here is how a month ends up with two folders.
            Log::info('Resolving a Drive folder without the lock.', ['folder' => $cacheKey]);
        }

        try {
            $cached = $this->cache->get($cacheKey);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            $folderId = $this->findFolder($name, $parentId) ?? $this->createFolder($name, $parentId);

            $this->cache->put($cacheKey, $folderId, config('memories.cache.ttl.drive_folder'));

            return $folderId;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @throws GoogleDriveException
     */
    public function findFolder(string $name, ?string $parentId): ?string
    {
        $query = sprintf(
            "mimeType = '%s' and name = '%s' and '%s' in parents and trashed = false",
            self::FOLDER_MIME,
            $this->escapeQueryValue($name),
            $this->escapeQueryValue($parentId ?? 'root'),
        );

        try {
            $result = $this->factory->drive()->files->listFiles([
                'q' => $query,
                'fields' => 'files(id,name)',
                'pageSize' => 1,
                'spaces' => 'drive',
                'supportsAllDrives' => true,
                'includeItemsFromAllDrives' => true,
            ]);

            $files = $result->getFiles();

            return $files === [] ? null : $files[0]->getId();
        } catch (Throwable $e) {
            throw GoogleDriveException::from($e, "Looking up Drive folder [{$name}] failed");
        }
    }

    /**
     * @throws GoogleDriveException
     */
    public function createFolder(string $name, ?string $parentId): string
    {
        try {
            $folder = $this->factory->drive()->files->create(
                new GoogleDriveFile([
                    'name' => $name,
                    'mimeType' => self::FOLDER_MIME,
                    'parents' => [$parentId ?? 'root'],
                ]),
                ['fields' => 'id', 'supportsAllDrives' => true],
            );

            Log::info('Drive folder created.', ['name' => $name, 'drive_folder_id' => $folder->getId()]);

            return $folder->getId();
        } catch (Throwable $e) {
            throw GoogleDriveException::from($e, "Creating Drive folder [{$name}] failed");
        }
    }

    /**
     * Every file this application has put in Drive.
     *
     * The `drive.file` scope means Drive only ever shows us what we created,
     * so this is the archive's own footprint and nothing else in the account.
     * It answers the one question the database cannot: is there anything up
     * there that nothing down here knows about?
     *
     * Every fact is asked for in the listing itself — dimensions, duration,
     * checksum — so recovering a memory from these files costs one call rather
     * than one per file.
     *
     * @return array<int, array{file: DriveFile, parent: string|null}>
     *
     * @throws GoogleDriveException
     */
    public function listOwnFiles(): array
    {
        $files = [];
        $pageToken = null;

        try {
            do {
                $result = $this->factory->drive()->files->listFiles([
                    'q' => sprintf("mimeType != '%s' and trashed = false", self::FOLDER_MIME),
                    'fields' => 'nextPageToken, files(parents,'.self::FILE_FIELDS.')',
                    'pageSize' => 1000,
                    'spaces' => 'drive',
                    'pageToken' => $pageToken,
                    'supportsAllDrives' => true,
                    'includeItemsFromAllDrives' => true,
                ]);

                foreach ($result->getFiles() as $file) {
                    $parents = $file->getParents();

                    $files[] = [
                        'file' => DriveFile::fromGoogle($file),
                        'parent' => is_array($parents) && $parents !== [] ? (string) $parents[0] : null,
                    ];
                }

                $pageToken = $result->getNextPageToken();
            } while ($pageToken !== null);
        } catch (Throwable $e) {
            throw GoogleDriveException::from($e, 'Listing the files in Drive failed');
        }

        return $files;
    }

    /**
     * The name of one folder, for turning a parent id back into something a
     * person can go and find in Drive.
     */
    public function folderName(string $folderId): ?string
    {
        try {
            return $this->factory->drive()->files->get($folderId, [
                'fields' => 'name',
                'supportsAllDrives' => true,
            ])->getName();
        } catch (Throwable $e) {
            Log::info('Could not read a Drive folder name.', [
                'folder' => $folderId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Storage headroom and the connected account, for the health check.
     *
     * @return array{email: string|null, limit: int|null, usage: int|null}
     *
     * @throws GoogleDriveException
     */
    public function about(): array
    {
        try {
            $about = $this->factory->drive()->about->get(['fields' => 'user(emailAddress),storageQuota']);
            $quota = $about->getStorageQuota();

            return [
                'email' => $about->getUser()?->getEmailAddress(),
                'limit' => $quota?->getLimit() !== null ? (int) $quota->getLimit() : null,
                'usage' => $quota?->getUsage() !== null ? (int) $quota->getUsage() : null,
            ];
        } catch (Throwable $e) {
            throw GoogleDriveException::from($e, 'Reading Drive account information failed');
        }
    }

    /**
     * Folder ids are memoised for a month. If the folder tree is moved or
     * deleted by hand in Drive, `php artisan cache:clear` is what re-discovers
     * it — there is no separate command for the same job.
     */
    private function folderCacheKey(string $name, ?string $parentId): string
    {
        return 'drive:folder:'.sha1(($parentId ?? 'root').'/'.$name);
    }

    /**
     * Drive's query language is string-delimited, so a folder name containing
     * a quote or backslash has to be escaped or it changes the query's meaning.
     */
    private function escapeQueryValue(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private function uploadChunkSize(): int
    {
        $configured = (int) config('googledrive.upload_chunk_bytes');
        $quantum = 256 * 1024;

        // Google rejects resumable chunks that are not a multiple of 256 KiB.
        $chunks = max(1, (int) floor($configured / $quantum));

        return $chunks * $quantum;
    }
}
