<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Duplicate-submission protection that does not depend on the client.
     *
     * A disabled button is a courtesy, not a guarantee: double taps, flaky
     * networks and browser retries all replay the same request. The client
     * sends one key per intent, and the unique index below makes the *database*
     * the thing that decides whether a memory already exists.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 128);
            $table->string('endpoint', 128);

            // Guards against a key being reused for a different payload.
            $table->char('request_hash', 64);

            // in_progress → completed
            $table->string('status', 16)->default('in_progress');

            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();

            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
