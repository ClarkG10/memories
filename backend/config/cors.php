<?php

declare(strict_types=1);

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FRONTEND_URL', 'http://localhost:5173'))
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     | The React app is the only browser client, so the allow-list is exactly
     | FRONTEND_URL (comma-separate to permit preview deployments).
     */
    'allowed_origins' => $origins,

    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL_PATTERNS', ''))
    ))),

    'allowed_headers' => ['*'],

    /*
     | Range/Content-Range are needed so the video player can seek,
     | Content-Disposition so downloads keep their filename, and X-Request-Id
     | so a failure the browser saw can be quoted back and found in the log.
     */
    'exposed_headers' => [
        'Content-Length',
        'Content-Range',
        'Accept-Ranges',
        'Content-Disposition',
        'X-Request-Id',
    ],

    'max_age' => 3600,

    /*
     | Bearer tokens, not cookies: nothing here needs credentialed requests.
     */
    'supports_credentials' => false,

];
