<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ArchiveController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MemoryController;
use App\Http\Controllers\Api\MemoryMediaController;
use App\Http\Controllers\Api\TimelineController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The archive's API
|--------------------------------------------------------------------------
|
| Reading is open to whoever the archive is configured for. Everything that
| changes or costs storage is owner-only, and there is no separate management
| surface — these are the same endpoints the timeline itself uses.
|
*/

// Available even on a private archive, so the front end knows to ask for a
// sign-in rather than showing an error.
Route::get('archive', [ArchiveController::class, 'show'])
    ->middleware('throttle:api')
    ->name('archive.show');

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('auth.login');

/*
 | Reading the archive.
 */
Route::middleware(['archive.viewable', 'throttle:api'])->group(function (): void {
    Route::get('timeline', [TimelineController::class, 'index'])->name('timeline.index');
    Route::get('timeline/years', [TimelineController::class, 'years'])->name('timeline.years');
    Route::get('albums', [TimelineController::class, 'albums'])->name('albums.index');

    // Kept as an alias of the timeline for a single year.
    Route::get('memories/year/{year}', [TimelineController::class, 'index'])
        ->whereNumber('year')
        ->name('memories.year');

    Route::get('memories', [TimelineController::class, 'index'])->name('memories.index');
    Route::get('memories/{memory}', [MemoryController::class, 'show'])->name('memories.show');
});

/*
 | Media is proxied rather than linked: Drive files are private, and the browser
 | must never learn a Drive file id.
 |
 | Its own throttle, separate from the JSON reads, because one screenful of the
 | timeline is dozens of these and a playing video is many more — but they are
 | also the expensive requests, so they are the ones that most need a ceiling.
 */
Route::middleware(['media.viewable', 'throttle:media'])->group(function (): void {
    Route::get('media/{media}/image', [MediaController::class, 'image'])->name('media.image');
    Route::get('media/{media}/poster', [MediaController::class, 'poster'])->name('media.poster');
    Route::get('media/{media}/stream', [MediaController::class, 'stream'])->name('media.stream');
});

/*
 | Owner-only. Nothing below this line is reachable without a token.
 */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::middleware('throttle:api')->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    });

    Route::middleware('throttle:writes')->group(function (): void {
        Route::post('memories', [MemoryController::class, 'store'])->name('memories.store');
        Route::match(['put', 'patch'], 'memories/{memory}', [MemoryController::class, 'update'])
            ->name('memories.update');
        Route::delete('memories/{memory}', [MemoryController::class, 'destroy'])->name('memories.destroy');

        Route::post('memories/{memory}/media', [MemoryMediaController::class, 'store'])
            ->name('memories.media.store');
        Route::delete('media/{media}', [MemoryMediaController::class, 'destroy'])->name('media.destroy');
    });

    /*
     | Chunked uploads. Higher limit than other writes because a single video
     | is many requests.
     */
    Route::middleware('throttle:uploads')->group(function (): void {
        Route::post('uploads', [UploadController::class, 'store'])->name('uploads.store');
        Route::get('uploads/{upload}', [UploadController::class, 'show'])->name('uploads.show');
        Route::put('uploads/{upload}/chunks/{index}', [UploadController::class, 'chunk'])
            ->whereNumber('index')
            ->name('uploads.chunk');
        Route::post('uploads/{upload}/complete', [UploadController::class, 'complete'])
            ->name('uploads.complete');
        Route::delete('uploads/{upload}', [UploadController::class, 'destroy'])->name('uploads.destroy');
    });
});
