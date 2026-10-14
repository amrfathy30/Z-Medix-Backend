<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A short heading for a Fellowship question, alongside — never instead of —
 * its `question_text`.
 *
 * Deliberately outside `content_hash`, which is derived from the question text
 * alone: duplicate detection must keep matching the same rows it matched
 * before, so adding or changing a title never makes an existing question look
 * like a different one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fellowship_questions', function (Blueprint $table) {
            $table->string('title', 255)->nullable()->after('fellowship_id');
        });
    }

    public function down(): void
    {
        Schema::table('fellowship_questions', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
