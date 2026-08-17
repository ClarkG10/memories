<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OpenUploadRequest;
use App\Models\UploadSession;
use App\Services\UploadSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The three steps of getting one file onto the server: open a session, send
 * its chunks, close it.
 *
 * Nothing here touches Drive. A session is just bytes on local disk until a
 * memory claims them.
 */
class UploadController extends Controller
{
    public function __construct(private readonly UploadSessionService $uploads) {}

    public function store(OpenUploadRequest $request): JsonResponse
    {
        $session = $this->uploads->open(
            $request->user(),
            $request->string('file_name')->value(),
            $request->integer('size'),
            $request->string('mime_type')->value() ?: null,
        );

        return response()->json(['data' => $this->present($session)], 201);
    }

    /**
     * Where an upload got to.
     *
     * Read by the browser before retrying, so an interrupted transfer sends
     * only the pieces that never arrived rather than starting a large video
     * again from the beginning.
     */
    public function show(Request $request, UploadSession $upload): JsonResponse
    {
        $this->authoriseSession($request, $upload);

        return response()->json(['data' => $this->present($upload)]);
    }

    /**
     * Receive one chunk.
     *
     * The body is raw bytes rather than a multipart form: there is nothing to
     * parse, PHP never buffers it, and it goes straight from the socket to its
     * offset in the destination file.
     */
    public function chunk(Request $request, UploadSession $upload, int $index): JsonResponse
    {
        $this->authoriseSession($request, $upload);

        $stream = $request->getContent(asResource: true);

        $session = $this->uploads->storeChunk($upload, $index, $stream);

        return response()->json(['data' => $this->present($session)]);
    }

    /**
     * Seal the session once every chunk has landed.
     */
    public function complete(Request $request, UploadSession $upload): JsonResponse
    {
        $this->authoriseSession($request, $upload);

        $session = $this->uploads->complete($upload);

        return response()->json(['data' => $this->present($session)]);
    }

    /**
     * Abandon an upload — the person removed the file before saving.
     */
    public function destroy(Request $request, UploadSession $upload): JsonResponse
    {
        $this->authoriseSession($request, $upload);

        $this->uploads->discardFile($upload);
        $upload->forceFill(['status' => UploadSession::STATUS_EXPIRED])->save();

        return response()->json(['data' => ['discarded' => true]]);
    }

    private function authoriseSession(Request $request, UploadSession $session): void
    {
        abort_unless($session->user_id === $request->user()?->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(UploadSession $session): array
    {
        return [
            'id' => $session->uuid,
            'status' => $session->status,
            'type' => $session->type,
            'chunk_size' => $session->chunk_size,
            'total_chunks' => $session->total_chunks,
            'received_chunks' => $session->received_chunks,

            // Lets an interrupted upload resume instead of starting over.
            'missing_chunks' => $session->missingChunks(),
            'expires_at' => $session->expires_at->toIso8601String(),
        ];
    }
}
