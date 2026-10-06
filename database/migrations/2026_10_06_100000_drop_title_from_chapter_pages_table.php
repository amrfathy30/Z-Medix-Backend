<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A chapter page has no business title: its identity is its position inside the
 * chapter, which the admin reads as "Page 1", "Page 2", … derived from `order`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chapter_pages', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }

    public function down(): void
    {
        Schema::table('chapter_pages', function (Blueprint $table) {
            // Nullable on the way back: the titles that were dropped cannot be
            // recovered, so existing rows must be allowed to have none.
            $table->string('title')->nullable()->after('chapter_id');
        });
    }
};
