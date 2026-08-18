<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memories', function (Blueprint $table): void {
            /*
             | An optional name that decides where in Drive this memory's files
             | are filed: Memory Archive/Albums/<album>/ instead of the usual
             | date folders. Kept here rather than in a table of its own — an
             | album is a label, not a thing with a life of its own, and the
             | list offered in the interface is simply the distinct values.
             */
            $table->string('album', 80)->nullable()->after('location');

            $table->index('album');
        });
    }

    public function down(): void
    {
        Schema::table('memories', function (Blueprint $table): void {
            $table->dropIndex(['album']);
            $table->dropColumn('album');
        });
    }
};
