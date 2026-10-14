<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a chapter's educational content was taken from, as the admin typed it.
 *
 * A single nullable string rather than a table of its own: the reference is one
 * line of provenance shown with the chapter, never queried, listed or shared
 * between chapters, so a relation would buy nothing and cost a join on every
 * read. Chapters that predate it keep `null`, which reads as "not recorded".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->string('references', 255)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropColumn('references');
        });
    }
};
