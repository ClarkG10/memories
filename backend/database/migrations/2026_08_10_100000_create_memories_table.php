<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memories', function (Blueprint $table): void {
            $table->id();

            // Public identifier. Sequential ids never leave the server.
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->date('memory_date');
            $table->string('location')->nullable();

            // Denormalised so the timeline never counts rows per memory.
            $table->unsignedInteger('media_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // The timeline query: not deleted, newest first, id as tiebreaker.
            $table->index(['deleted_at', 'memory_date', 'id'], 'memories_timeline_index');
            $table->index('memory_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memories');
    }
};
