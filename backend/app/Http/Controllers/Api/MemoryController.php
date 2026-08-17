<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemoryRequest;
use App\Http\Requests\UpdateMemoryRequest;
use App\Http\Resources\MemoryResource;
use App\Models\Memory;
use App\Services\IdempotencyService;
use App\Services\MemoryService;
use App\Services\TimelineQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemoryController extends Controller
{
    public function __construct(
        private readonly MemoryService $memories,
        private readonly IdempotencyService $idempotency,
    ) {}

    public function show(Request $request, string $memory, TimelineQuery $timeline): JsonResponse
    {
        $data = $timeline->find($request, $memory);

        abort_if($data === null, 404, 'That memory is no longer here.');

        return response()->json(['data' => $data]);
    }

    /**
     * Create a memory from files that have already finished uploading.
     *
     * Wrapped in an idempotency key so that a double tap, a retry after a
     * timeout, or a browser replaying the request produces one memory rather
     * than several — and, more importantly, does not upload the same video to
     * Drive twice.
     */
    public function store(StoreMemoryRequest $request): JsonResponse
    {
        $user = $request->user();

        return $this->idempotency->run(
            $user,
            $request->idempotencyKey(),
            'memories.store',
            $request->idempotencyPayload(),
            function () use ($request, $user): JsonResponse {
                $memory = $this->memories->create(
                    $user,
                    $request->memoryAttributes(),
                    $request->uploadUuids(),
                );

                return response()->json(
                    ['data' => (new MemoryResource($memory))->toArray($request)],
                    201,
                );
            },
        );
    }

    public function update(UpdateMemoryRequest $request, Memory $memory): JsonResponse
    {
        $memory = $this->memories->update($memory, $request->validated());

        return response()->json([
            'data' => (new MemoryResource($memory))->toArray($request),
        ]);
    }

    /**
     * Take a memory out of the timeline. The Drive files follow on the queue;
     * see MemoryService::delete.
     */
    public function destroy(Request $request, Memory $memory): JsonResponse
    {
        $this->authorize('delete', $memory);

        $this->memories->delete($memory);

        return response()->json(['data' => ['removed' => true]]);
    }
}
