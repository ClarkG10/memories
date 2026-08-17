<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | The archive
    |--------------------------------------------------------------------------
    |
    | A memory archive belongs to exactly one person. "public" only controls
    | whether visitors may *read* the timeline — every write is owner-only.
    |
    */

    'public' => (bool) env('ARCHIVE_PUBLIC', true),

    'title' => env('ARCHIVE_TITLE', 'Our Memories'),

    'quote' => env('ARCHIVE_QUOTE', 'Every moment is worth remembering.'),

    'owner' => [
        'name' => env('ARCHIVE_OWNER_NAME', 'Owner'),
        'email' => env('ARCHIVE_OWNER_EMAIL'),
        'password' => env('ARCHIVE_OWNER_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | The browser never posts a whole video in one request. It opens an upload
    | session, sends fixed-size chunks, and Laravel streams the assembled file
    | to Drive. Keep "chunk_bytes" comfortably under the web server's body
    | limit (Forge defaults to 100M on nginx).
    |
    */

    'uploads' => [
        /*
         | 4 MiB sits safely under PHP's stock 8M post_max_size, so uploads work
         | on an untuned server. Raising both (see DEPLOYMENT.md) means fewer
         | round trips on large videos. The browser is told which value to use,
         | so changing it here is enough.
         */
        'chunk_bytes' => (int) env('UPLOAD_CHUNK_BYTES', 4 * 1024 * 1024),
        'session_ttl_minutes' => (int) env('UPLOAD_SESSION_TTL_MINUTES', 180),
        'max_files_per_memory' => (int) env('MEDIA_MAX_FILES_PER_MEMORY', 40),

        'max_bytes' => [
            'image' => (int) env('MEDIA_MAX_IMAGE_BYTES', 50 * 1024 * 1024),
            'video' => (int) env('MEDIA_MAX_VIDEO_BYTES', 2 * 1024 * 1024 * 1024),
        ],

        /*
         | Allowed media, keyed by the MIME type detected from the file's own
         | bytes — never from the client-supplied Content-Type.
         */
        'mime_types' => [
            'image/jpeg' => ['type' => 'image', 'ext' => 'jpg'],
            'image/png' => ['type' => 'image', 'ext' => 'png'],
            'image/webp' => ['type' => 'image', 'ext' => 'webp'],
            'image/gif' => ['type' => 'image', 'ext' => 'gif'],
            'image/heic' => ['type' => 'image', 'ext' => 'heic'],
            'image/heif' => ['type' => 'image', 'ext' => 'heif'],
            'video/mp4' => ['type' => 'video', 'ext' => 'mp4'],
            'video/quicktime' => ['type' => 'video', 'ext' => 'mov'],
            'video/webm' => ['type' => 'video', 'ext' => 'webm'],
            'video/x-matroska' => ['type' => 'video', 'ext' => 'mkv'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Derivatives
    |--------------------------------------------------------------------------
    |
    | Drive holds the original forever. Everything the timeline actually
    | renders is a derivative generated once and cached on the server's disk,
    | so browsing the archive does not hammer the Drive API.
    |
    | "placeholder_width" is the tiny blur-up image inlined into API responses;
    | keep it small enough to stay under a couple of KB of base64.
    |
    */

    'derivatives' => [
        'disk' => 'derivatives',

        /*
         | Exactly the widths MemoryMediaResource asks for — thumb, display,
         | full — and no others. Every extra rung is rendered on the first
         | request and then never served, which over a full archive is a
         | meaningful amount of disk and CPU spent on files nobody reads.
         | Change these together with the widths in that resource.
         */
        'sizes' => [640, 1600, 2400],

        'quality' => 82,
        'placeholder_width' => 20,

        /*
         | A ceiling on the cache, enforced weekly by `derivatives:prune`.
         | This directory is pure cache — anything removed is regenerated on
         | the next request — but left unbounded it will eventually fill the
         | server's disk, which takes the whole archive down with it.
         */
        'max_bytes' => (int) env('MEDIA_DERIVATIVE_CACHE_BYTES', 8 * 1024 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Every cached list is namespaced by a version counter. Bumping the counter
    | retires all of them at once, which keeps invalidation correct without
    | depending on a taggable cache driver.
    |
    */

    'cache' => [
        'ttl' => [
            'timeline' => 900,
            'years' => 3600,
            'memory' => 900,
            'drive_folder' => 86400 * 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeline
    |--------------------------------------------------------------------------
    */

    'timeline' => [
        'per_page' => 24,
        'max_per_page' => 60,
    ],

];
