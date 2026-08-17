<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication strategy
    |--------------------------------------------------------------------------
    |
    | "oauth"           — the archive owner authorises the app once and Laravel
    |                     keeps a refresh token. Files are owned by, and billed
    |                     to, that Google account. This is the right choice for
    |                     a personal archive on a normal Google account.
    |
    | "service_account" — only workable when the service account can write
    |                     somewhere with real storage quota: a Workspace shared
    |                     drive, or an impersonated user via domain-wide
    |                     delegation. A bare service account has no quota of its
    |                     own and every upload fails with a quota error.
    |
    */

    'auth' => env('GOOGLE_DRIVE_AUTH', 'oauth'),

    'oauth' => [
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        'refresh_token' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),
        'redirect_uri' => env('GOOGLE_DRIVE_REDIRECT_URI', 'http://localhost:8000/drive/callback'),
    ],

    'service_account' => [
        'credentials_path' => env('GOOGLE_DRIVE_CREDENTIALS_PATH'),
        'credentials_json_base64' => env('GOOGLE_DRIVE_CREDENTIALS_JSON_BASE64'),
        'impersonate' => env('GOOGLE_DRIVE_IMPERSONATE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    |
    | drive.file is the narrowest scope that works: it grants access to files
    | this application created and nothing else in the owner's Drive. Because
    | the app creates its own "Memory Archive" folder tree, that is sufficient.
    |
    | Pinning GOOGLE_DRIVE_ROOT_FOLDER_ID to a folder you made by hand requires
    | the broader "drive" scope, since drive.file cannot see foreign files.
    |
    */

    'scope' => env('GOOGLE_DRIVE_SCOPE', 'https://www.googleapis.com/auth/drive.file'),

    /*
    |--------------------------------------------------------------------------
    | Folder layout
    |--------------------------------------------------------------------------
    |
    |   Memory Archive/
    |     Images/2026/08 August/…
    |     Videos/2026/08 August/…
    |
    | Readable if you ever open Drive by hand, and shallow enough that it never
    | turns into thousands of folders.
    |
    | Month folders are named "08 August" rather than "08" or "August": the
    | number keeps them in calendar order when Drive sorts by name, and the
    | word means you can tell at a glance which one you are looking at.
    |
    */

    'root_folder_name' => env('GOOGLE_DRIVE_ROOT_FOLDER_NAME', 'Memory Archive'),

    'root_folder_id' => env('GOOGLE_DRIVE_ROOT_FOLDER_ID'),

    'year_folders' => (bool) env('GOOGLE_DRIVE_YEAR_FOLDERS', true),

    // Ignored when year folders are off — a month with no year to sit in
    // would put every August together regardless of which year it was.
    'month_folders' => (bool) env('GOOGLE_DRIVE_MONTH_FOLDERS', true),

    'folders' => [
        'image' => 'Images',
        'video' => 'Videos',
    ],

    /*
    |--------------------------------------------------------------------------
    | Transfer
    |--------------------------------------------------------------------------
    |
    | Resumable uploads are streamed from disk this many bytes at a time, so
    | peak memory stays flat no matter how large the video is. Google requires
    | a multiple of 256 KiB.
    |
    */

    'upload_chunk_bytes' => (int) env('GOOGLE_DRIVE_UPLOAD_CHUNK_BYTES', 8 * 1024 * 1024),

    'timeout' => (int) env('GOOGLE_DRIVE_TIMEOUT', 120),

    'connect_timeout' => (int) env('GOOGLE_DRIVE_CONNECT_TIMEOUT', 10),

    'retries' => (int) env('GOOGLE_DRIVE_RETRIES', 3),

];
