<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Room to actually say something.
     *
     * The original widths were the defaults rather than decisions: a title of
     * 160 characters and a description of 5,000 are limits on how much of a
     * day someone is allowed to write down, which is not a limit an archive of
     * their own memories should be imposing.
     *
     * `description` becomes LONGTEXT rather than a wider TEXT because TEXT is
     * 65,535 *bytes*, and an emoji costs four of them — a description full of
     * them would be cut off a quarter of the way through the stated limit.
     */
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table): void {
            $table->string('title', 500)->change();
            $table->longText('description')->nullable()->change();
            $table->string('location', 255)->nullable()->change();
            /*
             | 190 rather than more: this column is indexed for the album
             | picker, and an index entry has a size limit of its own.
             */
            $table->string('album', 190)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table): void {
            $table->string('title', 255)->change();
            $table->text('description')->nullable()->change();
            $table->string('location', 255)->nullable()->change();
            $table->string('album', 80)->nullable()->change();
        });
    }
};
