<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TimelineQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The timeline itself: memories newest first, walked with a cursor.
 *
 * Cursor rather than page numbers, because adding a memory shifts every offset
 * and would make someone scrolling see a duplicate or skip one entirely.
 */
class TimelineController extends Controller
{
    public function index(Request $request, TimelineQuery $timeline): JsonResponse
    {
        $perPage = min(
            max((int) $request->integer('limit', (int) config('memories.timeline.per_page')), 1),
            (int) config('memories.timeline.max_per_page'),
        );

        // /timeline?year=2026 and /memories/year/2026 are the same query.
        $year = $request->route('year') ?? ($request->filled('year') ? $request->integer('year') : null);
        $year = $year !== null ? (int) $year : null;

        $cursor = $request->string('cursor')->value() ?: null;

        return response()->json($timeline->page($request, $year, $cursor, $perPage));
    }

    /**
     * The years the archive spans, with counts.
     */
    public function years(TimelineQuery $timeline): JsonResponse
    {
        return response()->json(['data' => $timeline->years()]);
    }
}
