<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A staging area between the browser and Drive.
     *
     * The browser sends fixed-size chunks that land in a temp file; only once
     * every chunk has arrived does Laravel stream the assembled file to Drive.
     * That keeps single requests small, makes retries cheap, and means a
     * dropped connection never costs more than one chunk.
     */
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('original_name');

            // What the browser claimed, kept only for diagnostics.
            $table->string('client_mime_type', 128)->nullable();

            // What the bytes actually are. This is what validation trusts.
            $table->string('mime_type', 128)->nullable();
            $table->string('type', 16)->nullable();

            /*
             | Everything learned while the file was being checked. Recording
             | it here means creating the memory does not have to re-hash and
             | re-decode a file that has already been examined once — which,
             | for a batch of large photos, is the difference between a short
             | database transaction and one held open for seconds.
             */
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('placeholder')->nullable();

            $table->unsignedBigInteger('size');
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('received_chunks')->default(0);

            // Indices already stored, so a resumed upload can skip them.
            $table->json('chunk_map')->nullable();

            $table->string('path');

            // pending → ready → consumed
            //         ↘ failed / expired
            $table->string('status', 16)->default('pending');
            $table->char('checksum', 64)->nullable();
            $table->text('error')->nullable();

            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
    }
};
