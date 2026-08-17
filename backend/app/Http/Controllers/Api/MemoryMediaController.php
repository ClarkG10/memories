<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttachMediaRequest;
use App\Http\Resources\MemoryResource;
use App\Models\Memory;
use App\Models\MemoryMedia;
use App\Services\IdempotencyService;
use App\Services\MemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemoryMediaController extends Controller
{
    public function __construct(
        private readonly MemoryService $memories,
        private readonly IdempotencyService $idempotency,
    ) {}

    /**
     * Add more photos or videos to a memory that already exists.
     */
    public function store(AttachMediaRequest $request, Memory $memory): JsonResponse
    {
        $user = $request->user();

        return $this->idempotency->run(
            $user,
            $request->idempotencyKey(),
            'memories.media.store',
            $request->idempotencyPayload(),
            function () use ($request, $user, $memory): JsonResponse {
                $updated = $this->memories->attachMedia($memory, $user, $request->uploadUuids());

                return response()->json(
                    ['data' => (new MemoryResource($updated))->toArray($request)],
                    201,
                );
            },
        );
    }

    /**
     * Remove one file, leaving the rest of the memory intact.
     */
    public function destroy(Request $request, MemoryMedia $media): JsonResponse
    {
        $memory = $media->memory;

        abort_if($memory === null, 404);

        $this->authorize('update', $memory);

        $this->memories->deleteMedia($media);

        return response()->json(['data' => ['removed' => true]]);
    }
}
