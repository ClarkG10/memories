<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_media', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('memory_id')->constrained()->cascadeOnDelete();

            $table->string('type', 16);

            /*
             | Drive is the source of truth for the bytes, and the file id is
             | the only reliable handle on them — names can be changed by hand
             | in Drive at any time, ids cannot.
             */
            $table->string('drive_file_id', 191)->unique();
            $table->string('drive_folder_id', 191);
            $table->string('drive_web_view_url', 1024)->nullable();
            $table->string('drive_thumbnail_url', 1024)->nullable();

            $table->string('file_name');
            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('file_size');

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // sha256 of the original bytes: dedupes accidental re-uploads.
            $table->char('checksum', 64)->nullable();

            // Tiny inline blur-up image (data URI) sent with timeline payloads.
            $table->text('placeholder')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            /*
             | Deleting a memory means deleting bytes out of a third-party API
             | that can fail halfway. The state machine keeps every file
             | accounted for so a failure can be retried instead of forgotten.
             |
             |   active → deleting → deleted
             |                    ↘ delete_failed → (retry) → deleting
             */
            $table->string('deletion_state', 16)->default('active');
            $table->text('deletion_error')->nullable();
            $table->unsignedSmallInteger('deletion_attempts')->default(0);
            $table->timestamp('deletion_requested_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['memory_id', 'sort_order']);
            $table->index('type');
            $table->index('deleted_at');
            $table->index(['deletion_state', 'deletion_attempts'], 'media_deletion_sweep_index');

            // The same file cannot be attached to one memory twice.
            $table->unique(['memory_id', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_media');
    }
};
