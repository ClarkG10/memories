<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GoogleDrive\GoogleDriveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the front end needs before it can draw anything: the archive's name,
 * its epigraph, and whether the person looking at it may change it.
 */
class ArchiveController extends Controller
{
    public function show(Request $request, GoogleDriveService $drive): JsonResponse
    {
        return response()->json([
            'data' => [
                'title' => config('memories.title'),
                'quote' => config('memories.quote'),
                'public' => (bool) config('memories.public'),
                'can_manage' => $request->user() !== null,

                /*
                 | Surfaced so the upload flow can say "Drive isn't connected"
                 | up front, instead of letting someone pick files and fill in
                 | a form only to fail at the last step.
                 */
                'storage_connected' => $drive->isConfigured(),
                'upload' => [
                    'chunk_bytes' => (int) config('memories.uploads.chunk_bytes'),
                    'max_files' => (int) config('memories.uploads.max_files_per_memory'),
                    'max_image_bytes' => (int) config('memories.uploads.max_bytes.image'),
                    'max_video_bytes' => (int) config('memories.uploads.max_bytes.video'),
                    'accepts' => array_keys((array) config('memories.uploads.mime_types')),
                ],
            ],
        ]);
    }
}
